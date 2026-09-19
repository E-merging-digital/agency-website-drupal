#!/usr/bin/env python3
"""Bounded active Nginx identity diagnostic for Agency #1250."""

from __future__ import annotations

import glob
import hashlib
import importlib.util
import json
import os
import re
import shlex
import stat
import sys
from pathlib import Path, PurePosixPath
from typing import Any

NGINX_ROOT = PurePosixPath("/etc/nginx")
MAX_FILES = 64
MAX_DEPTH = 6


def fail(message: str) -> None:
    print(f"[nginx-active-identity] ERROR: {message}", file=sys.stderr)
    raise SystemExit(2)


def under_nginx(path: PurePosixPath) -> bool:
    return path == NGINX_ROOT or NGINX_ROOT in path.parents


def require_virtual(path: str) -> PurePosixPath:
    value = PurePosixPath(path)
    if not value.is_absolute() or not under_nginx(value):
        fail("path must be an absolute bounded /etc/nginx path")
    if ".." in value.parts:
        fail("parent traversal is forbidden")
    return value


def physical(root: Path, virtual: str) -> Path:
    value = require_virtual(virtual)
    return root / str(value).lstrip("/")


def virtual_from_physical(root: Path, value: Path) -> str:
    try:
        relative = value.resolve(strict=True).relative_to(root.resolve(strict=True))
    except (FileNotFoundError, ValueError, OSError):
        fail("resolved path escapes bounded fixture/runtime root")
    virtual = PurePosixPath("/") / PurePosixPath(relative.as_posix())
    require_virtual(str(virtual))
    return str(virtual)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(131072), b""):
            digest.update(chunk)
    return digest.hexdigest()


def path_identity(root: Path, virtual: str) -> dict[str, Any]:
    source = physical(root, virtual)
    try:
        metadata = source.lstat()
    except FileNotFoundError:
        return {
            "path": virtual,
            "presence": "ABSENT",
            "type": "ABSENT",
            "is_symlink": False,
            "resolved_path": "ABSENT",
            "sha256": "ABSENT",
        }

    if stat.S_ISLNK(metadata.st_mode):
        kind = "SYMLINK"
        is_symlink = True
    elif stat.S_ISREG(metadata.st_mode):
        kind = "REGULAR"
        is_symlink = False
    else:
        return {
            "path": virtual,
            "presence": "PRESENT",
            "type": "OTHER",
            "is_symlink": False,
            "resolved_path": "UNPROVEN",
            "sha256": "UNPROVEN",
        }

    try:
        resolved = source.resolve(strict=True)
    except OSError:
        fail(f"cannot resolve {virtual}")
    resolved_virtual = virtual_from_physical(root, resolved)
    if not resolved.is_file():
        fail(f"resolved target for {virtual} is not a regular file")
    return {
        "path": virtual,
        "presence": "PRESENT",
        "type": kind,
        "is_symlink": is_symlink,
        "resolved_path": resolved_virtual,
        "sha256": sha256_file(resolved),
    }


def uncomment(line: str) -> str:
    quote: str | None = None
    escaped = False
    out: list[str] = []
    for char in line:
        if escaped:
            out.append(char)
            escaped = False
            continue
        if char == "\\":
            out.append(char)
            escaped = True
            continue
        if quote is not None:
            out.append(char)
            if char == quote:
                quote = None
            continue
        if char in {"'", '"'}:
            quote = char
            out.append(char)
            continue
        if char == "#":
            break
        out.append(char)
    return "".join(out)


def include_directives(text: str) -> list[str]:
    values: list[str] = []
    for raw_line in text.splitlines():
        line = uncomment(raw_line).strip()
        match = re.fullmatch(r"include\s+(.+?)\s*;", line)
        if not match:
            continue
        tokens = shlex.split(match.group(1))
        if len(tokens) != 1:
            fail("Nginx include directive must contain exactly one path")
        value = tokens[0]
        if "$" in value:
            fail("variable Nginx include is unproven")
        require_virtual(value)
        values.append(value)
    return values


def safe_read(root: Path, virtual: str) -> str:
    source = physical(root, virtual)
    try:
        resolved = source.resolve(strict=True)
    except OSError:
        fail(f"cannot resolve included Nginx file {virtual}")
    virtual_from_physical(root, resolved)
    if not resolved.is_file():
        fail(f"included Nginx source is not a regular file: {virtual}")
    try:
        return resolved.read_text(encoding="utf-8")
    except OSError:
        fail(f"included Nginx source is unreadable: {virtual}")


def expand_include(root: Path, pattern: str) -> list[str]:
    require_virtual(pattern)
    physical_pattern = str(root / pattern.lstrip("/"))
    matches: list[str] = []
    for candidate in sorted(glob.glob(physical_pattern)):
        source = Path(candidate)
        try:
            resolved = source.resolve(strict=True)
        except OSError:
            fail("included Nginx source cannot be resolved")
        virtual_from_physical(root, resolved)
        if not resolved.is_file():
            continue
        relative = source.relative_to(root).as_posix()
        matches.append("/" + relative)
    if not matches and not any(char in pattern for char in "*?["):
        fail(f"required Nginx include is missing: {pattern}")
    return matches


def include_graph(root: Path, main_config: str) -> tuple[dict[str, str], dict[str, tuple[str, str]], bool]:
    queue: list[tuple[str, int]] = [(main_config, 0)]
    files: dict[str, str] = {}
    parent: dict[str, tuple[str, str]] = {}
    unproven = False

    while queue:
        virtual, depth = queue.pop(0)
        if virtual in files:
            continue
        if depth > MAX_DEPTH or len(files) >= MAX_FILES:
            return files, parent, True
        text = safe_read(root, virtual)
        files[virtual] = text
        # Once a source defines server blocks, its nested location-level
        # includes (for example fastcgi_params) are not part of the bounded
        # server-source discovery chain required by #1250.
        if re.search(r"(?m)^\s*server\s*\{", text):
            continue
        try:
            directives = include_directives(text)
        except SystemExit:
            return files, parent, True
        for directive in directives:
            try:
                matches = expand_include(root, directive)
            except SystemExit:
                return files, parent, True
            for child in matches:
                if child not in parent:
                    parent[child] = (virtual, directive)
                queue.append((child, depth + 1))
    return files, parent, unproven


def relevant_chain(main_config: str, enabled_site: str, parent: dict[str, tuple[str, str]]) -> list[dict[str, str]]:
    if enabled_site == main_config:
        return []
    if enabled_site not in parent:
        return []
    current = enabled_site
    edges: list[dict[str, str]] = []
    while current != main_config:
        if current not in parent:
            return []
        source, directive = parent[current]
        edges.append({"source": source, "include": directive, "target": current})
        current = source
        if len(edges) > MAX_DEPTH + 2:
            return []
    edges.reverse()
    return edges


def load_route_helper(path: str) -> Any:
    spec = importlib.util.spec_from_file_location("agency_nginx_route_helper", path)
    if spec is None or spec.loader is None:
        fail("route helper cannot be loaded")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def source_id(virtual: str, sha: str, main_config: str, canonical: str, enabled: str) -> str:
    if virtual == main_config:
        return "nginx.conf"
    if virtual == canonical:
        return "sites-available/agency-preprod"
    if virtual == enabled:
        return "sites-enabled/agency-preprod"
    return f"included:{sha[:12]}"


def classify_loaded(
    files: dict[str, str],
    route: Any,
    hostname: str,
    machine_path: str,
    main_config: str,
    canonical: str,
    enabled: str,
    root: Path,
) -> tuple[list[dict[str, Any]], int, int]:
    candidates: list[dict[str, Any]] = []
    tls_count = 0
    http_count = 0
    for virtual, text in files.items():
        sha = sha256_file(physical(root, virtual).resolve(strict=True))
        for server in route.parsed_servers(text, hostname, machine_path):
            if not server["server_name_match"]:
                continue
            if server["listens_443"] and server["tls_enabled"]:
                tls_count += 1
            if server["listens_80"]:
                http_count += 1
            candidates.append({
                "source_id": source_id(virtual, sha, main_config, canonical, enabled),
                "source_sha256": sha,
                "server_block_id": server["id"],
                "listens_80": server["listens_80"],
                "listens_443": server["listens_443"],
                "tls_enabled": server["tls_enabled"],
                "server_name_match": True,
                "server_auth_basic": server["server_auth_basic"],
                "machine_route_count": server["exact_machine_route_count"],
            })
    return candidates, tls_count, http_count


def candidate_identity(
    files: dict[str, str],
    route: Any,
    template: str,
    hostname: str,
    machine_path: str,
    enabled: str,
    canonical_sha: str,
    enabled_sha: str,
    tls_count: int,
    root: Path,
) -> dict[str, Any]:
    source_match = canonical_sha == enabled_sha and enabled in files
    if not source_match:
        return {
            "status": "CANDIDATE_SOURCE_MISMATCH",
            "source_sha": enabled_sha,
            "candidate_sha": "UNPROVEN",
            "target_server": "UNPROVEN",
            "target_tls": False,
        }
    if tls_count != 1:
        return {
            "status": "AMBIGUOUS_TLS" if tls_count > 1 else "ABSENT_TLS",
            "source_sha": enabled_sha,
            "candidate_sha": "UNPROVEN",
            "target_server": "UNPROVEN",
            "target_tls": False,
        }

    source = files[enabled]
    servers = route.parsed_servers(source, hostname, machine_path)
    tls_id = route.unique_server_id(
        servers,
        lambda server: server["server_name_match"] and server["listens_443"] and server["tls_enabled"],
    )
    if tls_id in {"ABSENT", "AMBIGUOUS"}:
        return {
            "status": "TLS_NOT_UNIQUE_IN_ENABLED_SOURCE",
            "source_sha": enabled_sha,
            "candidate_sha": "UNPROVEN",
            "target_server": tls_id,
            "target_tls": False,
        }

    total_routes = sum(server["exact_machine_route_count"] for server in servers)
    if total_routes == 0:
        candidate, target = route.insert_into_tls(source, template, hostname, machine_path)
    else:
        candidate, target = source, tls_id
    target_server = route.get_server(route.parsed_servers(candidate, hostname, machine_path), target)
    target_tls = bool(
        target_server
        and target_server["server_name_match"]
        and target_server["listens_443"]
        and target_server["tls_enabled"]
    )
    return {
        "status": "BOUND",
        "source_sha": enabled_sha,
        "candidate_sha": hashlib.sha256(candidate.encode("utf-8")).hexdigest(),
        "target_server": target,
        "target_tls": target_tls,
    }


def diagnose(argv: list[str]) -> dict[str, Any]:
    if len(argv) != 9:
        fail("diagnose requires root, main, canonical, enabled, route helper, template, hostname and machine path")
    root = Path(argv[1]).resolve(strict=True)
    main_config, canonical, enabled = argv[2], argv[3], argv[4]
    route_helper_path, template_path, hostname, machine_path = argv[5:9]
    require_virtual(main_config)
    require_virtual(canonical)
    require_virtual(enabled)

    canonical_id = path_identity(root, canonical)
    if canonical_id["presence"] != "PRESENT" or canonical_id["type"] not in {"REGULAR", "SYMLINK"}:
        fail("canonical PREPROD site identity cannot be proven")

    enabled_id = path_identity(root, enabled)
    canonical_sha = str(canonical_id["sha256"])
    enabled_sha = str(enabled_id["sha256"])
    enabled_class = "ABSENT"
    if enabled_id["presence"] == "PRESENT":
        if enabled_id["type"] == "SYMLINK":
            if enabled_id["resolved_path"] != canonical_id["resolved_path"]:
                enabled_class = "UNEXPECTED_SYMLINK_TARGET"
            else:
                enabled_class = "CANONICAL_SYMLINK"
        elif enabled_id["type"] == "REGULAR":
            enabled_class = "EQUIVALENT_COPY" if enabled_sha == canonical_sha else "DIVERGENT"
        else:
            enabled_class = "WRONG_TYPE"

    files, parent, graph_unproven = include_graph(root, main_config)
    chain = relevant_chain(main_config, enabled, parent)
    sites_enabled_included = enabled in files and (enabled == main_config or bool(chain))
    include_status = "UNPROVEN" if graph_unproven else ("PROVEN" if sites_enabled_included else "NOT_LOADED")

    route = load_route_helper(route_helper_path)
    candidates, tls_count, http_count = classify_loaded(
        files, route, hostname, machine_path, main_config, canonical, enabled, root
    )
    tls_class = "UNIQUE" if tls_count == 1 else ("ABSENT" if tls_count == 0 else "AMBIGUOUS")
    loaded_shas = sorted({item["source_sha256"] for item in candidates})
    canonical_equals_enabled = enabled_sha == canonical_sha and enabled_sha not in {"ABSENT", "UNPROVEN"}
    enabled_equals_loaded = sites_enabled_included and any(
        item["source_sha256"] == enabled_sha for item in candidates
    )
    template = Path(template_path).read_text(encoding="utf-8")
    candidate = candidate_identity(
        files, route, template, hostname, machine_path, enabled,
        canonical_sha, enabled_sha, tls_count, root
    )

    status = "PASS"
    if enabled_class in {"ABSENT", "UNEXPECTED_SYMLINK_TARGET", "WRONG_TYPE"}:
        status = "FAIL_CLOSED"
    elif graph_unproven or not sites_enabled_included:
        status = "UNPROVEN"
    elif tls_count > 1:
        status = "AMBIGUOUS"
    elif not canonical_equals_enabled or candidate["status"] != "BOUND":
        status = "CANDIDATE_SOURCE_MISMATCH"

    return {
        "status": status,
        "nginx_main_config": main_config,
        "canonical_site": canonical_id,
        "enabled_site": {
            **enabled_id,
            "classification": enabled_class,
            "matches_canonical": canonical_equals_enabled,
        },
        "include_chain": {
            "status": include_status,
            "sites_enabled_included": sites_enabled_included,
            "directives": [edge["include"] for edge in chain],
        },
        "loaded_preprod_candidates": candidates,
        "loaded_preprod_tls_match_count": tls_count,
        "loaded_preprod_http_match_count": http_count,
        "duplicate_tls": "AMBIGUOUS" if tls_count > 1 else "NONE",
        "loaded_preprod_site_sha": loaded_shas,
        "canonical_equals_enabled": canonical_equals_enabled,
        "enabled_equals_loaded": enabled_equals_loaded,
        "candidate": candidate,
    }


def main_config(master_args: str, version_output: str) -> str:
    args = master_args
    marker = "nginx: master process "
    if marker in args:
        args = args.split(marker, 1)[1]
    try:
        tokens = shlex.split(args)
    except ValueError:
        fail("Nginx master process args are not parseable")
    for index, token in enumerate(tokens):
        if token == "-c":
            if index + 1 >= len(tokens):
                fail("Nginx -c has no value")
            path = tokens[index + 1]
            require_virtual(path)
            return path
        if token.startswith("-c") and len(token) > 2:
            path = token[2:]
            require_virtual(path)
            return path
    match = re.search(r"(?:^|\s)--conf-path=([^\s]+)", version_output)
    if not match:
        fail("Nginx compiled main config path is unproven")
    path = match.group(1)
    require_virtual(path)
    return path


def main() -> None:
    if len(sys.argv) < 2:
        fail("mode required")
    mode = sys.argv[1]
    if mode == "main-config":
        if len(sys.argv) != 4:
            fail("main-config requires master args and nginx -V output")
        print(main_config(sys.argv[2], sys.argv[3]))
        return
    if mode == "diagnose":
        print(json.dumps(diagnose(sys.argv[1:]), sort_keys=True, separators=(",", ":")))
        return
    fail("unsupported mode")


if __name__ == "__main__":
    main()

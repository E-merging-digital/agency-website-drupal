#!/usr/bin/env python3
"""Bounded structural Nginx diagnostic and insertion helper for Agency #1248."""

from __future__ import annotations

import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit


def fail(message: str) -> None:
    print(f"[nginx-route-diagnostic] ERROR: {message}", file=sys.stderr)
    raise SystemExit(2)


def mask_structure(text: str) -> str:
    out = list(text)
    quote: str | None = None
    escaped = False
    comment = False
    for index, char in enumerate(text):
        if comment:
            if char == "\n":
                comment = False
            else:
                out[index] = " "
            continue
        if quote is not None:
            if escaped:
                escaped = False
                out[index] = " "
                continue
            if char == "\\":
                escaped = True
                out[index] = " "
                continue
            if char == quote:
                quote = None
            out[index] = " "
            continue
        if char in ("'", '"'):
            quote = char
            out[index] = " "
            continue
        if char == "#":
            comment = True
            out[index] = " "
    return "".join(out)


def matching_brace(masked: str, open_index: int) -> int:
    depth = 0
    for index in range(open_index, len(masked)):
        char = masked[index]
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return index
    fail("unbalanced Nginx braces")


def depth_at(masked: str, start: int, index: int) -> int:
    depth = 0
    for char in masked[start:index]:
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
    return depth


@dataclass(frozen=True)
class Block:
    header: str
    header_start: int
    open_brace: int
    close_brace: int

    @property
    def body_start(self) -> int:
        return self.open_brace + 1

    @property
    def body_end(self) -> int:
        return self.close_brace


def direct_nested_blocks(text: str, masked: str, start: int, end: int) -> list[Block]:
    blocks: list[Block] = []
    index = start
    boundary = start
    depth = 0
    while index < end:
        char = masked[index]
        if char == ";" and depth == 0:
            boundary = index + 1
        elif char == "{" and depth == 0:
            raw_header = text[boundary:index]
            header = raw_header.strip()
            leading = len(raw_header) - len(raw_header.lstrip())
            header_start = boundary + leading
            close = matching_brace(masked, index)
            if close > end:
                fail("nested block extends beyond parent")
            blocks.append(Block(header, header_start, index, close))
            index = close
            boundary = close + 1
        index += 1
    return blocks


def direct_directives(text: str, masked: str, start: int, end: int) -> list[str]:
    directives: list[str] = []
    index = start
    boundary = start
    depth = 0
    while index < end:
        char = masked[index]
        if char == "{":
            if depth == 0:
                close = matching_brace(masked, index)
                index = close
                boundary = close + 1
            else:
                depth += 1
        elif char == ";" and depth == 0:
            raw = text[boundary:index + 1].strip()
            if raw:
                directives.append(raw)
            boundary = index + 1
        index += 1
    return directives


def directive_values(directives: list[str], name: str) -> list[str]:
    values: list[str] = []
    pattern = re.compile(rf"^\s*{re.escape(name)}\s+(.+?)\s*;\s*$", re.S)
    for directive in directives:
        match = pattern.match(directive)
        if match:
            values.append(" ".join(match.group(1).split()))
    return values


def auth_state(directives: list[str]) -> str:
    values = directive_values(directives, "auth_basic")
    if not values:
        return "ABSENT"
    lowered = [value.lower() for value in values]
    if all(value == "off" for value in lowered):
        return "OFF"
    if all(value != "off" for value in lowered):
        return "ON"
    return "UNKNOWN"


def listen_has_port(value: str, port: int) -> bool:
    first = value.split()[0] if value.split() else ""
    if first == str(port):
        return True
    if re.search(rf":{port}$", first):
        return True
    return False


def server_blocks(text: str) -> list[Block]:
    masked = mask_structure(text)
    blocks: list[Block] = []
    for match in re.finditer(r"\bserver\s*\{", masked):
        open_brace = masked.find("{", match.start(), match.end())
        if open_brace < 0:
            continue
        if depth_at(masked, 0, match.start()) != 0:
            continue
        close = matching_brace(masked, open_brace)
        blocks.append(Block("server", match.start(), open_brace, close))
    return blocks


def location_blocks(text: str, server: Block) -> list[Block]:
    masked = mask_structure(text)
    return [
        block
        for block in direct_nested_blocks(text, masked, server.body_start, server.body_end)
        if re.match(r"^location\b", " ".join(block.header.split()))
    ]


def location_selector(header: str) -> str:
    normalized = " ".join(header.split())
    return normalized[len("location"):].strip() if normalized.startswith("location") else ""


def is_root_location(block: Block) -> bool:
    return location_selector(block.header) == "/"


def is_machine_location(block: Block, path: str) -> bool:
    return location_selector(block.header) == f"= {path}"


def parse_server(text: str, block: Block, index: int, hostname: str, path: str) -> dict[str, Any]:
    masked = mask_structure(text)
    directives = direct_directives(text, masked, block.body_start, block.body_end)
    locations = location_blocks(text, block)
    listens = directive_values(directives, "listen")
    names: list[str] = []
    for value in directive_values(directives, "server_name"):
        names.extend(value.split())

    listens_80 = any(listen_has_port(value, 80) for value in listens)
    listens_443 = any(listen_has_port(value, 443) for value in listens)
    tls_enabled = listens_443 and any(
        listen_has_port(value, 443) and "ssl" in value.split()[1:]
        for value in listens
    )
    machine_locations = [loc for loc in locations if is_machine_location(loc, path)]

    returns = directive_values(directives, "return")
    redirect_source = "SERVER" if returns else "NONE"
    root_returns: list[str] = []
    if not returns:
        for location in locations:
            if not is_root_location(location):
                continue
            location_directives = direct_directives(text, masked, location.body_start, location.body_end)
            root_returns = directive_values(location_directives, "return")
            if root_returns:
                returns = root_returns
                redirect_source = "ROOT_LOCATION"
                break

    conditional_redirect = False
    for nested in direct_nested_blocks(text, masked, block.body_start, block.body_end):
        if not re.match(r"^if\b", " ".join(nested.header.split())):
            continue
        nested_directives = direct_directives(text, masked, nested.body_start, nested.body_end)
        for value in directive_values(nested_directives, "return"):
            parts = value.split(maxsplit=1)
            if parts and parts[0].isdigit() and 300 <= int(parts[0]) <= 399:
                conditional_redirect = True
                break
        if conditional_redirect:
            break

    return_status = "NONE"
    redirects = conditional_redirect
    fixed_status = False
    for value in returns:
        parts = value.split(maxsplit=1)
        if parts and parts[0].isdigit():
            return_status = parts[0]
            fixed_status = True
            if 300 <= int(parts[0]) <= 399:
                redirects = True
            break
    if conditional_redirect and redirect_source == "SERVER":
        redirect_source = "SERVER_AND_IF"
    elif conditional_redirect:
        redirect_source = "SERVER_IF"

    return {
        "id": f"server-{index}",
        "server_name_match": hostname in names,
        "listens_80": listens_80,
        "listens_443": listens_443,
        "tls_enabled": tls_enabled,
        "server_auth_basic": auth_state(directives),
        "location_root_present": any(is_root_location(loc) for loc in locations),
        "exact_machine_route_count": len(machine_locations),
        "contains_locations": bool(locations),
        "redirects": redirects,
        "fixed_status": fixed_status,
        "return_status": return_status,
        "redirect_source": redirect_source,
        "_block": block,
        "_locations": locations,
    }


def bounded_server(server: dict[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in server.items() if not key.startswith("_")}


def parsed_servers(text: str, hostname: str, path: str) -> list[dict[str, Any]]:
    return [
        parse_server(text, block, index, hostname, path)
        for index, block in enumerate(server_blocks(text), start=1)
    ]


def unique_server_id(servers: list[dict[str, Any]], predicate: Any) -> str:
    candidates = [server["id"] for server in servers if predicate(server)]
    if len(candidates) == 1:
        return candidates[0]
    if not candidates:
        return "ABSENT"
    return "AMBIGUOUS"


def get_server(servers: list[dict[str, Any]], server_id: str) -> dict[str, Any] | None:
    for server in servers:
        if server["id"] == server_id:
            return server
    return None


def legacy_first_root_target(servers: list[dict[str, Any]]) -> str:
    roots: list[tuple[int, str]] = []
    for server in servers:
        for location in server["_locations"]:
            if is_root_location(location):
                roots.append((location.header_start, server["id"]))
    if not roots:
        return "ABSENT"
    roots.sort()
    return roots[0][1]


def unique_php_socket(text: str, server: dict[str, Any]) -> str | None:
    masked = mask_structure(text)
    sockets: set[str] = set()
    for location in server["_locations"]:
        directives = direct_directives(text, masked, location.body_start, location.body_end)
        for value in directive_values(directives, "fastcgi_pass"):
            match = re.fullmatch(r"unix:([^;\s]+)", value)
            if match:
                sockets.add(match.group(1))
    if len(sockets) != 1:
        return None
    return next(iter(sockets))


def machine_block_from_template(template: str, path: str) -> str:
    matches: list[Block] = []
    for server in server_blocks(template):
        matches.extend(
            location
            for location in location_blocks(template, server)
            if is_machine_location(location, path)
        )
    if len(matches) != 1:
        fail("approved template must contain exactly one exact machine location")
    block = matches[0]
    return template[block.header_start:block.close_brace + 1].strip()


def insert_into_tls(text: str, template: str, hostname: str, path: str) -> tuple[str, str]:
    servers = parsed_servers(text, hostname, path)
    tls_id = unique_server_id(
        servers,
        lambda server: server["server_name_match"] and server["listens_443"] and server["tls_enabled"],
    )
    if tls_id in {"ABSENT", "AMBIGUOUS"}:
        fail(f"effective PREPROD TLS server is {tls_id}")

    target = get_server(servers, tls_id)
    assert target is not None
    if target["exact_machine_route_count"] != 0:
        fail("live TLS server already contains the exact machine route")

    roots = [location for location in target["_locations"] if is_root_location(location)]
    if len(roots) != 1:
        fail("effective PREPROD TLS server must contain exactly one root location")

    socket = unique_php_socket(text, target)
    if socket is None or not socket.startswith("/"):
        fail("effective PREPROD TLS server does not expose exactly one bounded PHP-FPM socket")

    route = machine_block_from_template(template, path).replace("@@PHP_SOCKET@@", socket)
    if "@@" in route:
        fail("approved machine route contains unresolved placeholders")

    insert_at = roots[0].header_start
    indentation = re.match(r"[ \t]*", text[insert_at:]).group(0)
    indented_route = "\n".join(
        indentation + line if line.strip() else line
        for line in route.splitlines()
    )
    candidate = text[:insert_at] + indented_route + "\n\n" + text[insert_at:]
    return candidate, tls_id


def route_contract(text: str, server: dict[str, Any], path: str) -> dict[str, Any]:
    masked = mask_structure(text)
    locations = [location for location in server["_locations"] if is_machine_location(location, path)]
    if len(locations) != 1:
        return {
            "count": len(locations),
            "location_auth_basic": "ABSENT" if not locations else "UNKNOWN",
            "auth_basic_off": False,
            "http_authorization_forwarded": False,
            "script_filename": "UNPROVEN",
            "fastcgi_pass": "UNPROVEN",
        }

    directives = direct_directives(text, masked, locations[0].body_start, locations[0].body_end)
    fastcgi_params = directive_values(directives, "fastcgi_param")
    authorization_forwarded = "HTTP_AUTHORIZATION $http_authorization" in fastcgi_params
    script_filename = "SCRIPT_FILENAME $realpath_root/index.php" in fastcgi_params
    fastcgi_values = directive_values(directives, "fastcgi_pass")
    socket = unique_php_socket(text, server)
    fastcgi_expected = socket is not None and fastcgi_values == [f"unix:{socket}"]

    return {
        "count": 1,
        "location_auth_basic": auth_state(directives),
        "auth_basic_off": auth_state(directives) == "OFF",
        "http_authorization_forwarded": authorization_forwarded,
        "script_filename": "EXPECTED" if script_filename else "MISMATCH",
        "fastcgi_pass": "EXPECTED" if fastcgi_expected else "MISMATCH",
    }


def diagnose(text: str, template: str, hostname: str, path: str) -> dict[str, Any]:
    servers = parsed_servers(text, hostname, path)
    if not servers:
        fail("no top-level Nginx server blocks found")

    tls_id = unique_server_id(
        servers,
        lambda server: server["server_name_match"] and server["listens_443"] and server["tls_enabled"],
    )
    http_id = unique_server_id(
        servers,
        lambda server: server["server_name_match"] and server["listens_80"],
    )
    legacy_target = legacy_first_root_target(servers)
    legacy_server = get_server(servers, legacy_target)
    legacy_is_tls = (
        legacy_server is not None
        and legacy_server["server_name_match"]
        and legacy_server["listens_443"]
        and legacy_server["tls_enabled"]
    )

    current_contracts = {
        server["id"]: route_contract(text, server, path)
        for server in servers
    }
    current_total = sum(server["exact_machine_route_count"] for server in servers)

    corrected_target = tls_id
    if current_total == 0:
        candidate, corrected_target = insert_into_tls(text, template, hostname, path)
        candidate_servers = parsed_servers(candidate, hostname, path)
        candidate_server = get_server(candidate_servers, corrected_target)
        assert candidate_server is not None
        route_counts = {
            server["id"]: server["exact_machine_route_count"]
            for server in candidate_servers
        }
        contract = route_contract(candidate, candidate_server, path)
        simulation_status = "SIMULATED"
    else:
        route_counts = {
            server["id"]: server["exact_machine_route_count"]
            for server in servers
        }
        candidate_server = get_server(servers, corrected_target)
        contract = (
            route_contract(text, candidate_server, path)
            if candidate_server is not None
            else {
                "count": 0,
                "location_auth_basic": "UNPROVEN",
                "auth_basic_off": False,
                "http_authorization_forwarded": False,
                "script_filename": "UNPROVEN",
                "fastcgi_pass": "UNPROVEN",
            }
        )
        simulation_status = "NOT_SIMULATED_ROUTE_PRESENT"

    return {
        "server_blocks": [bounded_server(server) for server in servers],
        "machine_location_contracts": current_contracts,
        "effective_preprod_tls_server_block": tls_id,
        "effective_preprod_http_server_block": http_id,
        "legacy_first_root_insertion_server_block": legacy_target,
        "legacy_first_root_insertion_is_tls": legacy_is_tls,
        "corrected_candidate_server_block": corrected_target,
        "corrected_candidate_is_tls": corrected_target == tls_id and tls_id not in {"ABSENT", "AMBIGUOUS"},
        "candidate_simulation_status": simulation_status,
        "simulated_candidate_route_counts": route_counts,
        "simulated_candidate_contract": contract,
    }


def redirect_metadata(status: str, location: str, hostname: str, scheme: str) -> dict[str, str]:
    if not status.isdigit() or not (300 <= int(status) <= 399):
        return {"status": status, "location_header_kind": "none", "normalized_path": ""}
    if not location:
        return {"status": status, "location_header_kind": "other", "normalized_path": ""}

    parsed = urlsplit(location)
    target_host = parsed.hostname or hostname
    target_scheme = parsed.scheme or scheme
    path = parsed.path or "/"

    if parsed.hostname and target_host.lower() != hostname.lower():
        kind = "host-change"
    elif parsed.scheme and target_scheme.lower() != scheme.lower():
        kind = "scheme"
    elif re.match(r"^/(?:fr|nl|de|en)(?:/|$)", path, re.I):
        kind = "language-prefix"
    else:
        kind = "same-host"

    return {"status": status, "location_header_kind": kind, "normalized_path": path}


def nginx_redirect_origin(text: str, hostname: str, path: str, status: str) -> str:
    if not status.isdigit() or not (300 <= int(status) <= 399):
        return "UNKNOWN"
    servers = parsed_servers(text, hostname, path)
    tls_id = unique_server_id(
        servers,
        lambda server: server["server_name_match"] and server["listens_443"] and server["tls_enabled"],
    )
    tls = get_server(servers, tls_id)
    if tls is None:
        return "UNKNOWN"
    if tls["redirects"] and tls["return_status"] == status:
        return "NGINX_REDIRECT"

    masked = mask_structure(text)
    locations = [location for location in tls["_locations"] if is_machine_location(location, path)]
    if len(locations) == 1:
        directives = direct_directives(text, masked, locations[0].body_start, locations[0].body_end)
        returns = directive_values(directives, "return")
        if any(value.split(maxsplit=1)[0] == status for value in returns if value.split()):
            return "NGINX_REDIRECT"
    return "UNKNOWN"


def read_regular(path: str) -> str:
    target = Path(path)
    if target.is_symlink() or not target.is_file():
        fail(f"unsafe or missing file: {path}")
    return target.read_text(encoding="utf-8")


def main() -> None:
    if len(sys.argv) < 2:
        fail("mode is required")
    mode = sys.argv[1]

    if mode in {"diagnose", "insert"}:
        if len(sys.argv) != 6:
            fail(f"usage: {mode} LIVE TEMPLATE HOSTNAME PATH")
        live_path, template_path, hostname, path = sys.argv[2:6]
        live = read_regular(live_path)
        template = read_regular(template_path)
        if mode == "diagnose":
            print(json.dumps(diagnose(live, template, hostname, path), sort_keys=True, separators=(",", ":")))
            return
        candidate, target = insert_into_tls(live, template, hostname, path)
        Path(live_path).write_text(candidate, encoding="utf-8")
        print(json.dumps({"status": "INSERTED", "server_block": target}, sort_keys=True, separators=(",", ":")))
        return

    if mode == "redirect":
        if len(sys.argv) != 6:
            fail("usage: redirect STATUS LOCATION HOSTNAME SCHEME")
        print(json.dumps(redirect_metadata(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]), sort_keys=True, separators=(",", ":")))
        return

    if mode == "origin":
        if len(sys.argv) != 6:
            fail("usage: origin LIVE HOSTNAME PATH STATUS")
        live = read_regular(sys.argv[2])
        print(nginx_redirect_origin(live, sys.argv[3], sys.argv[4], sys.argv[5]))
        return

    fail("unsupported mode")


if __name__ == "__main__":
    main()

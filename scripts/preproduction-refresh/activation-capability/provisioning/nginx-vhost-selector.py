#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from dataclasses import dataclass
from pathlib import Path

VHOST = Path("/etc/nginx/sites-available/agency-preprod")
HOSTNAME = "preprod.emergingdigital.be"
APP_ROOT = "/var/www/agency-preprod/current/web"
PHP_UPSTREAM = "unix:/run/php/php8.4-fpm-agency-preprod.sock"
FENCE_INCLUDE = "/etc/nginx/snippets/agency-preprod-refresh-fence.conf"
FENCE_LINE = f"include {FENCE_INCLUDE};"


class SelectorError(RuntimeError):
    pass


@dataclass(frozen=True)
class Token:
    text: str
    start: int
    end: int


@dataclass(frozen=True)
class Directive:
    name: str
    args: tuple[str, ...]
    start: int
    end: int
    depth: int


@dataclass
class ServerBlock:
    open_pos: int
    close_pos: int
    directives: list[Directive]


def _unquote(value: str) -> str:
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        return value[1:-1]
    return value


def tokenize(text: str) -> list[Token]:
    tokens: list[Token] = []
    i = 0
    while i < len(text):
        char = text[i]
        if char.isspace():
            i += 1
            continue
        if char == "#":
            while i < len(text) and text[i] != "\n":
                i += 1
            continue
        if char in "{};":
            tokens.append(Token(char, i, i + 1))
            i += 1
            continue
        if char in {"'", '"'}:
            quote = char
            start = i
            i += 1
            while i < len(text):
                if text[i] == "\\" and i + 1 < len(text):
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            else:
                raise SelectorError("unterminated quoted Nginx token")
            tokens.append(Token(text[start:i], start, i))
            continue
        start = i
        while i < len(text) and not text[i].isspace() and text[i] not in "{};#\"'":
            i += 1
        tokens.append(Token(text[start:i], start, i))
    return tokens


def parse_server_blocks(text: str) -> list[ServerBlock]:
    tokens = tokenize(text)
    blocks: list[ServerBlock] = []
    server_stack: list[tuple[ServerBlock, int]] = []
    statement: list[Token] = []
    depth = 0

    for token in tokens:
        if token.text == ";":
            if statement and server_stack:
                block, base_depth = server_stack[-1]
                block.directives.append(
                    Directive(
                        name=statement[0].text,
                        args=tuple(_unquote(part.text) for part in statement[1:]),
                        start=statement[0].start,
                        end=token.end,
                        depth=depth - base_depth,
                    )
                )
            statement = []
            continue

        if token.text == "{":
            name = statement[0].text if statement else ""
            if name == "server" and depth == 0:
                block = ServerBlock(open_pos=token.end, close_pos=-1, directives=[])
                blocks.append(block)
                server_stack.append((block, depth + 1))
            statement = []
            depth += 1
            continue

        if token.text == "}":
            statement = []
            if depth <= 0:
                raise SelectorError("unbalanced Nginx closing brace")
            if server_stack:
                block, base_depth = server_stack[-1]
                if depth == base_depth:
                    block.close_pos = token.start
                    server_stack.pop()
            depth -= 1
            continue

        statement.append(token)

    if depth != 0 or server_stack:
        raise SelectorError("unbalanced Nginx braces")
    return blocks


def directives(block: ServerBlock, name: str, *, direct_only: bool = False) -> list[Directive]:
    return [
        directive
        for directive in block.directives
        if directive.name == name and (not direct_only or directive.depth == 0)
    ]


def server_names(block: ServerBlock) -> list[str]:
    return [
        arg
        for directive in directives(block, "server_name", direct_only=True)
        for arg in directive.args
    ]


def exact_root_present(block: ServerBlock) -> bool:
    return any(
        directive.args == (APP_ROOT,)
        for directive in directives(block, "root", direct_only=True)
    )


def exact_php_upstream_present(block: ServerBlock) -> bool:
    return any(
        directive.args == (PHP_UPSTREAM,)
        for directive in directives(block, "fastcgi_pass")
    )


def is_application_block(block: ServerBlock) -> bool:
    direct_returns = directives(block, "return", direct_only=True)
    return (
        server_names(block).count(HOSTNAME) == 1
        and exact_root_present(block)
        and exact_php_upstream_present(block)
        and not direct_returns
    )


def _is_https_redirect(directive: Directive) -> bool:
    if directive.name != "return" or len(directive.args) != 2:
        return False
    if directive.args[0] not in {"301", "302", "307", "308"}:
        return False
    return directive.args[1] in {
        "https://$host$request_uri",
        f"https://{HOSTNAME}$request_uri",
    }


def _is_plain_404(directive: Directive) -> bool:
    return directive.name == "return" and directive.args == ("404",)


def is_safe_auxiliary_hostname_block(block: ServerBlock) -> bool:
    if server_names(block).count(HOSTNAME) != 1 or is_application_block(block):
        return False

    if any(
        directive.name
        in {
            "fastcgi_pass",
            "proxy_pass",
            "uwsgi_pass",
            "scgi_pass",
            "grpc_pass",
            "try_files",
        }
        for directive in block.directives
    ):
        return False

    if any(
        directive.name == "include" and FENCE_INCLUDE in directive.args
        for directive in block.directives
    ):
        return False

    returns = directives(block, "return")
    if not returns:
        return False
    if any(not (_is_https_redirect(directive) or _is_plain_404(directive)) for directive in returns):
        return False

    direct_returns = directives(block, "return", direct_only=True)
    unconditional_redirect = any(_is_https_redirect(directive) for directive in direct_returns)
    certbot_style_fallback = (
        any(_is_https_redirect(directive) for directive in returns)
        and any(_is_plain_404(directive) for directive in direct_returns)
    )
    return unconditional_redirect or certbot_style_fallback


def fence_include_count(block: ServerBlock, *, direct_only: bool = False) -> int:
    return sum(
        1
        for directive in directives(block, "include", direct_only=direct_only)
        if directive.args == (FENCE_INCLUDE,)
    )


def analyze_text(text: str) -> dict[str, object]:
    blocks = parse_server_blocks(text)
    hostname_blocks = [block for block in blocks if HOSTNAME in server_names(block)]
    application_blocks = [block for block in hostname_blocks if is_application_block(block)]
    auxiliary_blocks = [
        block for block in hostname_blocks if is_safe_auxiliary_hostname_block(block)
    ]

    return {
        "server_blocks": blocks,
        "hostname_blocks": hostname_blocks,
        "application_blocks": application_blocks,
        "auxiliary_blocks": auxiliary_blocks,
        "server_block_count": len(blocks),
        "hostname_declaration_count": sum(
            server_names(block).count(HOSTNAME) for block in blocks
        ),
        "hostname_block_count": len(hostname_blocks),
        "application_block_count": len(application_blocks),
        "safe_auxiliary_block_count": len(auxiliary_blocks),
        "application_fence_include_count": sum(
            fence_include_count(block, direct_only=True) for block in application_blocks
        ),
        "total_fence_include_count": sum(fence_include_count(block) for block in blocks),
    }


def validate_topology(analysis: dict[str, object]) -> None:
    if analysis["hostname_declaration_count"] != analysis["hostname_block_count"]:
        raise SelectorError("duplicate PREPROD hostname declaration within a server block")
    if analysis["application_block_count"] != 1:
        raise SelectorError("canonical PREPROD application-serving block is ambiguous")
    if analysis["hostname_block_count"] != 1 + analysis["safe_auxiliary_block_count"]:
        raise SelectorError("PREPROD hostname server-block role is ambiguous")
    if analysis["application_fence_include_count"] not in {0, 1}:
        raise SelectorError("application fence include count is unsafe")
    if analysis["total_fence_include_count"] != analysis["application_fence_include_count"]:
        raise SelectorError("fence include is duplicated or outside the application-serving block")


def bounded_counts(analysis: dict[str, object]) -> dict[str, int]:
    return {
        "vhost_server_block_count": int(analysis["server_block_count"]),
        "vhost_hostname_declaration_count": int(analysis["hostname_declaration_count"]),
        "vhost_hostname_block_count": int(analysis["hostname_block_count"]),
        "vhost_application_block_count": int(analysis["application_block_count"]),
        "vhost_safe_auxiliary_block_count": int(analysis["safe_auxiliary_block_count"]),
        "vhost_application_fence_include_count": int(
            analysis["application_fence_include_count"]
        ),
        "vhost_total_fence_include_count": int(analysis["total_fence_include_count"]),
    }


def observe_text(text: str) -> dict[str, int]:
    analysis = analyze_text(text)
    validate_topology(analysis)
    return bounded_counts(analysis)


def insert_fence_text(text: str) -> tuple[str, bool]:
    analysis = analyze_text(text)
    validate_topology(analysis)
    if analysis["application_fence_include_count"] == 1:
        return text, False

    application_blocks = analysis["application_blocks"]
    assert isinstance(application_blocks, list) and len(application_blocks) == 1
    block = application_blocks[0]
    anchors = [
        directive
        for directive in directives(block, "server_name", direct_only=True)
        if HOSTNAME in directive.args
    ]
    if len(anchors) != 1:
        raise SelectorError("application server_name insertion anchor is ambiguous")
    anchor = anchors[0]

    line_start = text.rfind("\n", 0, anchor.start) + 1
    indent = text[line_start:anchor.start]
    insertion = f"\n{indent}{FENCE_LINE}"
    updated = text[: anchor.end] + insertion + text[anchor.end :]

    post = analyze_text(updated)
    validate_topology(post)
    if post["application_fence_include_count"] != 1:
        raise SelectorError("post-insertion application fence proof failed")
    return updated, True


def load_vhost() -> str:
    if not VHOST.is_file() or VHOST.is_symlink():
        raise SelectorError("canonical PREPROD vhost missing or unsafe")
    try:
        return VHOST.read_text(encoding="utf-8")
    except OSError as exc:
        raise SelectorError("canonical PREPROD vhost is not safely readable") from exc


def print_observation(counts: dict[str, int]) -> None:
    print("vhost_selector_schema=1")
    for key in (
        "vhost_server_block_count",
        "vhost_hostname_declaration_count",
        "vhost_hostname_block_count",
        "vhost_application_block_count",
        "vhost_safe_auxiliary_block_count",
        "vhost_application_fence_include_count",
        "vhost_total_fence_include_count",
    ):
        print(f"{key}={counts[key]}")


def main(argv: list[str]) -> int:
    if len(argv) != 2 or argv[1] not in {"OBSERVE", "APPLY_FENCE"}:
        print("VHOST_SELECTOR_FAIL_CLOSED=invalid fixed selector mode", file=sys.stderr)
        return 64

    mode = argv[1]
    if mode == "APPLY_FENCE" and os.geteuid() != 0:
        print("VHOST_SELECTOR_FAIL_CLOSED=root required for APPLY_FENCE", file=sys.stderr)
        return 65

    try:
        text = load_vhost()
        if mode == "OBSERVE":
            print_observation(observe_text(text))
            return 0

        updated, changed = insert_fence_text(text)
        if changed:
            VHOST.write_text(updated, encoding="utf-8")
        print("VHOST_SELECTOR=PASS")
        print(f"VHOST_FENCE_INSERTION={'CHANGED' if changed else 'ALREADY_EXACT'}")
        return 0
    except SelectorError as exc:
        print(f"VHOST_SELECTOR_FAIL_CLOSED={exc}", file=sys.stderr)
        return 80


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))

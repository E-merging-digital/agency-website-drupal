#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("nginx_vhost_selector", HERE / "nginx-vhost-selector.py")
assert SPEC and SPEC.loader
selector = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(selector)

HOST = selector.HOSTNAME
ROOT = selector.APP_ROOT
PHP = selector.PHP_UPSTREAM
FENCE = selector.FENCE_INCLUDE


def application_block(*, fence: bool = False) -> str:
    include = f"    include {FENCE};\n" if fence else ""
    return f"""server {{
    listen 443 ssl;
    server_name {HOST};
{include}    root {ROOT};
    location / {{ try_files $uri /index.php?$query_string; }}
    location = /index.php {{ fastcgi_pass {PHP}; }}
}}
"""


def certbot_redirect_block() -> str:
    return f"""server {{
    if ($host = {HOST}) {{
        return 301 https://$host$request_uri;
    }}
    listen 80;
    server_name {HOST};
    return 404;
}}
"""


def expect_error(text: str, needle: str) -> None:
    try:
        selector.observe_text(text)
    except selector.SelectorError as exc:
        assert needle in str(exc), (needle, str(exc))
        return
    raise AssertionError(f"expected SelectorError containing {needle!r}")


def test_single_application() -> None:
    counts = selector.observe_text(application_block())
    assert counts["vhost_hostname_declaration_count"] == 1
    assert counts["vhost_application_block_count"] == 1
    assert counts["vhost_safe_auxiliary_block_count"] == 0
    updated, changed = selector.insert_fence_text(application_block())
    assert changed is True
    post = selector.observe_text(updated)
    assert post["vhost_application_fence_include_count"] == 1
    assert post["vhost_total_fence_include_count"] == 1
    print("#889_SINGLE_APPLICATION_TARGET=PASS")


def test_certbot_dual_block() -> None:
    text = application_block() + certbot_redirect_block()
    counts = selector.observe_text(text)
    assert counts["vhost_hostname_declaration_count"] == 2
    assert counts["vhost_hostname_block_count"] == 2
    assert counts["vhost_application_block_count"] == 1
    assert counts["vhost_safe_auxiliary_block_count"] == 1
    updated, changed = selector.insert_fence_text(text)
    assert changed is True
    assert updated.count(f"include {FENCE};") == 1
    assert selector.observe_text(updated)["vhost_application_fence_include_count"] == 1
    print("#889_CERTBOT_REDIRECT_PLUS_APPLICATION=PASS")


def test_ambiguous_application_fails_closed() -> None:
    expect_error(application_block() + application_block(), "application-serving block is ambiguous")
    print("#889_DUPLICATE_APPLICATION=FAIL_CLOSED")


def test_hostname_without_application_fails_closed() -> None:
    expect_error(certbot_redirect_block(), "application-serving block is ambiguous")
    print("#889_HOSTNAME_WITHOUT_APPLICATION=FAIL_CLOSED")


def test_unclassified_hostname_block_fails_closed() -> None:
    extra = f"""server {{
    listen 8080;
    server_name {HOST};
    root /srv/unexpected;
}}
"""
    expect_error(application_block() + extra, "server-block role is ambiguous")
    print("#889_UNCLASSIFIED_HOSTNAME_BLOCK=FAIL_CLOSED")


def test_misplaced_fence_fails_closed() -> None:
    misplaced = f"""server {{
    listen 127.0.0.1:8080;
    include {FENCE};
}}
"""
    expect_error(application_block() + misplaced, "outside the application-serving block")
    print("#889_MISPLACED_FENCE=FAIL_CLOSED")


def test_existing_exact_fence_is_idempotent() -> None:
    text = application_block(fence=True) + certbot_redirect_block()
    updated, changed = selector.insert_fence_text(text)
    assert changed is False
    assert updated == text
    print("#889_EXISTING_APPLICATION_FENCE=NO_OP")


def test_duplicate_hostname_declaration_fails_closed() -> None:
    text = application_block().replace(f"server_name {HOST};", f"server_name {HOST} {HOST};")
    expect_error(text, "duplicate PREPROD hostname declaration")
    print("#889_DUPLICATE_HOSTNAME_DECLARATION=FAIL_CLOSED")


def main() -> None:
    test_single_application()
    test_certbot_dual_block()
    test_ambiguous_application_fails_closed()
    test_hostname_without_application_fails_closed()
    test_unclassified_hostname_block_fails_closed()
    test_misplaced_fence_fails_closed()
    test_existing_exact_fence_is_idempotent()
    test_duplicate_hostname_declaration_fails_closed()
    print("#889_PLAN_APPLY_SHARED_SELECTOR_FIXTURE_MATRIX=PASS")


if __name__ == "__main__":
    main()

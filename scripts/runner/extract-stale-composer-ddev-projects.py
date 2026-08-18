#!/usr/bin/env python3

"""Extract governed Composer DDEV project names from DDEV JSON output."""

from __future__ import annotations

import json
import re
import sys
from typing import Any

_PATTERN = re.compile(
    r"(?<![A-Za-z0-9_-])agency-composer-[0-9]+-[0-9]+(?![A-Za-z0-9_-])"
)


def extract_projects(value: Any) -> set[str]:
    """Return only project names in the governed Agency Composer namespace."""
    matches: set[str] = set()

    def walk(item: Any) -> None:
        if isinstance(item, dict):
            for child in item.values():
                walk(child)
        elif isinstance(item, list):
            for child in item:
                walk(child)
        elif isinstance(item, str):
            matches.update(_PATTERN.findall(item))

    walk(value)
    return matches


def self_test() -> None:
    """Exercise exact values, DDEV warning messages and rejection boundaries."""
    payload = {
        "level": "warning",
        "msg": (
            "Project 'agency-website-drupal' is already used by project "
            "'agency-composer-32194449906-1'."
        ),
        "raw": [
            {"name": "agency-composer-42-2"},
            {"name": "agency-composer-42-2"},
            {"name": "agency-composer-42-2-extra"},
            {"name": "unrelated-project"},
        ],
    }
    expected = {
        "agency-composer-32194449906-1",
        "agency-composer-42-2",
    }
    actual = extract_projects(payload)
    if actual != expected:
        raise SystemExit(
            f"self-test failed: expected {sorted(expected)}, got {sorted(actual)}"
        )


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        self_test()
        print("PASS")
        raise SystemExit(0)
    if sys.argv[1:]:
        raise SystemExit("Usage: extract-stale-composer-ddev-projects.py [--self-test]")

    data = json.load(sys.stdin)
    for project in sorted(extract_projects(data)):
        print(project)

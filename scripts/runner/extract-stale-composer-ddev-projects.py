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


def parse_json_documents(raw: str) -> list[Any]:
    """Parse one or more whitespace-separated JSON documents fail closed."""
    decoder = json.JSONDecoder()
    documents: list[Any] = []
    offset = 0

    while offset < len(raw):
        while offset < len(raw) and raw[offset].isspace():
            offset += 1
        if offset >= len(raw):
            break

        try:
            document, offset = decoder.raw_decode(raw, offset)
        except json.JSONDecodeError as error:
            raise ValueError(
                f"Non-JSON data in DDEV output at character {error.pos}."
            ) from error
        documents.append(document)

    return documents


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
    """Exercise DDEV warnings, streams and rejection boundaries."""
    warning = {
        "level": "warning",
        "msg": (
            "Project 'agency-website-drupal' is already used by project "
            "'agency-composer-32194449906-1'."
        ),
    }
    listing = {
        "raw": [
            {"name": "agency-composer-42-2"},
            {"name": "agency-composer-42-2"},
            {"name": "agency-composer-42-2-extra"},
            {"name": "unrelated-project"},
        ],
    }
    stream = json.dumps(warning) + "\n" + json.dumps(listing)
    documents = parse_json_documents(stream)

    expected = {
        "agency-composer-32194449906-1",
        "agency-composer-42-2",
    }
    actual: set[str] = set()
    for document in documents:
        actual.update(extract_projects(document))
    if actual != expected:
        raise SystemExit(
            f"self-test failed: expected {sorted(expected)}, got {sorted(actual)}"
        )

    try:
        parse_json_documents(stream + "\nnot-json")
    except ValueError:
        pass
    else:
        raise SystemExit("self-test failed: non-JSON input was accepted")


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        self_test()
        print("PASS")
        raise SystemExit(0)
    if sys.argv[1:]:
        raise SystemExit("Usage: extract-stale-composer-ddev-projects.py [--self-test]")

    try:
        parsed = parse_json_documents(sys.stdin.read())
    except ValueError as error:
        raise SystemExit(str(error)) from error

    projects: set[str] = set()
    for document in parsed:
        projects.update(extract_projects(document))
    for project in sorted(projects):
        print(project)

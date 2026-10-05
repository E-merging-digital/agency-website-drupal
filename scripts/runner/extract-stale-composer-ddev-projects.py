#!/usr/bin/env python3

"""Extract stale DDEV project names safe to unlist for Composer runs."""

from __future__ import annotations

import json
import os
import re
import sys
import tempfile
from typing import Any

_COMPOSER_PATTERN = re.compile(
    r"(?<![A-Za-z0-9_-])agency-composer-[0-9]+-[0-9]+(?![A-Za-z0-9_-])"
)
_PARENT_PROJECT = "agency-website-drupal"
_ROOT_KEYS = ("approot", "root")


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


def canonical_path(path: str) -> str:
    """Return one deterministic canonical absolute path."""
    if not isinstance(path, str) or not path:
        raise ValueError("DDEV project root must be a non-empty string.")
    return os.path.realpath(os.path.abspath(path))


def extract_composer_projects(value: Any) -> set[str]:
    """Preserve historical extraction of governed Composer project names."""
    matches: set[str] = set()

    def walk(item: Any) -> None:
        if isinstance(item, dict):
            for child in item.values():
                walk(child)
        elif isinstance(item, list):
            for child in item:
                walk(child)
        elif isinstance(item, str):
            matches.update(_COMPOSER_PATTERN.findall(item))

    walk(value)
    return matches


def parent_roots(value: Any) -> set[str]:
    """Collect exact roots for structured parent-project registrations."""
    roots: set[str] = set()

    def walk(item: Any) -> None:
        if isinstance(item, dict):
            if item.get("name") == _PARENT_PROJECT:
                values = [
                    item[key]
                    for key in _ROOT_KEYS
                    if key in item and item[key] not in (None, "")
                ]
                if not values:
                    raise ValueError(
                        "Exact parent DDEV registration is missing approot/root."
                    )
                canonical = {canonical_path(value) for value in values}
                if len(canonical) != 1:
                    raise ValueError(
                        "Exact parent DDEV registration has ambiguous roots."
                    )
                roots.update(canonical)
            for child in item.values():
                walk(child)
        elif isinstance(item, list):
            for child in item:
                walk(child)

    walk(value)
    return roots


def extract_projects(documents: list[Any], workspace: str | None = None) -> set[str]:
    """Return only DDEV project names explicitly safe to unlist."""
    projects: set[str] = set()
    for document in documents:
        projects.update(extract_composer_projects(document))

    if workspace is None:
        return projects

    expected_root = canonical_path(workspace)
    observed_parent_roots: set[str] = set()
    for document in documents:
        observed_parent_roots.update(parent_roots(document))

    if len(observed_parent_roots) > 1:
        raise ValueError(
            "Multiple roots found for agency-website-drupal; refusing cleanup."
        )
    if observed_parent_roots == {expected_root}:
        projects.add(_PARENT_PROJECT)

    return projects


def assert_projects(
    value: Any,
    expected: set[str],
    workspace: str | None = None,
) -> None:
    """Assert one self-test extraction result."""
    actual = extract_projects([value], workspace)
    if actual != expected:
        raise SystemExit(
            f"self-test failed: expected {sorted(expected)}, got {sorted(actual)}"
        )


def self_test() -> None:
    """Exercise exact parent matching, legacy namespace and fail-closed parsing."""
    with tempfile.TemporaryDirectory() as workspace:
        exact_root = canonical_path(workspace)
        other_root = canonical_path(os.path.join(workspace, "..", "other"))
        longer_root = exact_root + "-suffix"

        # A. Exact parent name + exact workspace is selected.
        assert_projects(
            {"raw": [{"name": _PARENT_PROJECT, "approot": exact_root}]},
            {_PARENT_PROJECT},
            workspace,
        )

        # B. Same parent name at another root is preserved.
        assert_projects(
            {"raw": [{"name": _PARENT_PROJECT, "approot": other_root}]},
            set(),
            workspace,
        )

        # C. Same workspace with another project name is preserved.
        assert_projects(
            {"raw": [{"name": "other-project", "approot": exact_root}]},
            set(),
            workspace,
        )

        # D. Historical governed Composer namespace remains selected.
        assert_projects(
            {"raw": [{"name": "agency-composer-123-1"}]},
            {"agency-composer-123-1"},
            workspace,
        )

        # E. Unrelated projects remain preserved.
        assert_projects(
            {"raw": [{"name": "unrelated-project", "approot": other_root}]},
            set(),
            workspace,
        )

        # F. A longer path containing the workspace string is not an exact match.
        assert_projects(
            {"raw": [{"name": _PARENT_PROJECT, "approot": longer_root}]},
            set(),
            workspace,
        )

        # H. Multiple JSON documents and JSON warning streams remain bounded.
        warning = {
            "level": "warning",
            "msg": (
                "Project 'agency-composer-32194449906-1' is already registered."
            ),
        }
        listing = {
            "raw": [
                {"name": _PARENT_PROJECT, "root": exact_root},
                {"name": "agency-composer-42-2"},
                {"name": "agency-composer-42-2-extra"},
                {"name": "unrelated-project", "approot": other_root},
            ],
        }
        stream = json.dumps(warning) + "\n" + json.dumps(listing)
        documents = parse_json_documents(stream)
        actual = extract_projects(documents, workspace)
        expected = {
            _PARENT_PROJECT,
            "agency-composer-32194449906-1",
            "agency-composer-42-2",
        }
        if actual != expected:
            raise SystemExit(
                f"self-test failed: expected {sorted(expected)}, got {sorted(actual)}"
            )

        # Unknown ambiguous parent roots fail closed.
        ambiguous = {
            "raw": [
                {"name": _PARENT_PROJECT, "approot": exact_root},
                {"name": _PARENT_PROJECT, "approot": other_root},
            ],
        }
        try:
            extract_projects([ambiguous], workspace)
        except ValueError:
            pass
        else:
            raise SystemExit("self-test failed: ambiguous parent roots were accepted")

        # A structured parent without an explicit root also fails closed.
        try:
            extract_projects([{"name": _PARENT_PROJECT}], workspace)
        except ValueError:
            pass
        else:
            raise SystemExit("self-test failed: rootless parent was accepted")

        # G. Malformed/non-JSON output fails closed.
        try:
            parse_json_documents(stream + "\nnot-json")
        except ValueError:
            pass
        else:
            raise SystemExit("self-test failed: non-JSON input was accepted")


def usage() -> str:
    """Return the bounded CLI usage."""
    return (
        "Usage: extract-stale-composer-ddev-projects.py "
        "[--workspace ABSOLUTE_PATH | --self-test]"
    )


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        self_test()
        print("PASS")
        raise SystemExit(0)

    workspace: str | None = None
    if sys.argv[1:]:
        if len(sys.argv) != 3 or sys.argv[1] != "--workspace":
            raise SystemExit(usage())
        workspace = sys.argv[2]

    try:
        parsed = parse_json_documents(sys.stdin.read())
        projects = extract_projects(parsed, workspace)
    except ValueError as error:
        raise SystemExit(str(error)) from error

    for project in sorted(projects):
        print(project)

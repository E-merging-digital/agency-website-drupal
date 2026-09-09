#!/usr/bin/env python3
"""Extract the bounded service PREPROD payload from one merged editorial candidate."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def language_payload(source: str, label: str, langcode: str) -> dict[str, str]:
    pattern = re.compile(
        rf"## {re.escape(label)}\n\n"
        rf"```text\nlangcode = {re.escape(langcode)}\n"
        r"alias = (?P<alias>[^\n]+)\n"
        r"title = (?P<title>[^\n]+)\n```\n\n"
        r"### `field_short_description`\n\n"
        r"(?P<short>.+?)\n\n"
        r"### `field_detailed_description`\n\n"
        r"```html\n(?P<detailed>.*?)\n```",
        re.DOTALL,
    )
    matches = list(pattern.finditer(source))
    if len(matches) != 1:
        fail(f"Expected exactly one {label} service candidate block.")
    match = matches[0]
    values = {
        "alias": match.group("alias").strip(),
        "title": match.group("title").strip(),
        "short_description": match.group("short").strip(),
        "detailed_description_html": match.group("detailed").strip(),
    }
    if not all(values.values()):
        fail(f"{label} service candidate fields cannot be empty.")
    expected_prefix = f"/{langcode}/"
    alias = values["alias"]
    if not alias.startswith(expected_prefix) or "?" in alias or "#" in alias or "//" in alias:
        fail(f"{label} alias must be one explicit localized path.")
    return values


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--issue-number", required=True, type=int)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    source_path = Path(args.source)
    if args.issue_number <= 0:
        fail("issue-number must be positive.")
    expected_name = re.compile(rf"^[a-z0-9-]+-candidate-{args.issue_number}\.md$")
    if source_path.parent.as_posix() != "docs/seo" or expected_name.fullmatch(source_path.name) is None:
        fail("Service candidate source path is outside the bounded docs/seo issue pattern.")
    if not source_path.is_file():
        fail("Service candidate source file is missing.")

    source = source_path.read_text(encoding="utf-8")
    required_markers = [
        "BUNDLE = service",
        "CONTENT_SYNC = FORBIDDEN",
        "PREPROD_DURING_DELIVERY = NONE",
        "PROD = NONE",
    ]
    for marker in required_markers:
        if marker not in source:
            fail(f"Required service candidate marker is missing: {marker}")

    fr = language_payload(source, "FR", "fr")
    en = language_payload(source, "EN", "en")
    if fr["alias"] == en["alias"]:
        fail("FR and EN aliases must differ.")

    route_fr = re.findall(r"^FR_ROUTE = ([^\n]+)$", source, re.MULTILINE)
    route_en = re.findall(r"^EN_ROUTE = ([^\n]+)$", source, re.MULTILINE)
    if route_fr != [fr["alias"]] or route_en != [en["alias"]]:
        fail("Declared FR/EN routes must match the localized candidate aliases exactly.")

    payload = {
        "schema_version": 1,
        "issue_number": args.issue_number,
        "bundle": "service",
        "published": True,
        "aliases": {"fr": fr.pop("alias"), "en": en.pop("alias")},
        "fr": fr,
        "en": en,
    }
    canonical = json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ) + "\n"
    output_path = Path(args.output)
    output_path.write_text(canonical, encoding="utf-8")
    print(hashlib.sha256(canonical.encode("utf-8")).hexdigest())


if __name__ == "__main__":
    main()

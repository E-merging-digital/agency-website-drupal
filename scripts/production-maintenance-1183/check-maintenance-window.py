#!/usr/bin/env python3
"""Bounded #1183 maintenance-window gate using offset-aware timestamps."""

from __future__ import annotations

import argparse
import re
import sys
from datetime import datetime, timezone

TIMESTAMP_RE = re.compile(
    r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$"
)


def parse_timestamp(value: str) -> datetime:
    if not TIMESTAMP_RE.fullmatch(value):
        raise ValueError("timestamp must be offset-aware RFC3339")
    normalized = value[:-1] + "+00:00" if value.endswith("Z") else value
    parsed = datetime.fromisoformat(normalized)
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise ValueError("timestamp offset required")
    return parsed.astimezone(timezone.utc)


def evaluate(window_ref: str, now_epoch: int | None = None) -> str:
    parts = window_ref.split("/")
    if len(parts) != 2:
        return "INVALID"
    try:
        start = parse_timestamp(parts[0])
        end = parse_timestamp(parts[1])
    except ValueError:
        return "INVALID"

    start_epoch = int(start.timestamp())
    end_epoch = int(end.timestamp())
    if start_epoch >= end_epoch:
        return "INVALID"

    current_epoch = (
        int(datetime.now(timezone.utc).timestamp())
        if now_epoch is None
        else now_epoch
    )
    if current_epoch < start_epoch:
        return "TOO_EARLY"
    if current_epoch >= end_epoch:
        return "TOO_LATE"
    return "PASS"


def main() -> int:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("window_ref")
    parser.add_argument("--now-epoch", type=int, default=None)
    args = parser.parse_args()

    result = evaluate(args.window_ref, args.now_epoch)
    print(result)
    return 0 if result == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())

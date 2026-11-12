#!/usr/bin/env python3
"""Bounded metadata-only identity for a native Drupal PREPROD Article WebP.

This does not accept paths, URLs or image profiles from an issue comment. The
bytes and metadata are produced by the pinned PREPROD Drupal File API reader.
The same reader is called again before a production dry-run / promotion.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import re
import subprocess
from pathlib import Path

OWNER = "E-merging-digital"
MARKER = "<!-- agency-editorial-payload:v1 -->"
MIN_NEW_ISSUE = 1542
MAX_BYTES = 5_000_000
UUID = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\Z")
SHA = re.compile(r"[0-9a-f]{64}\Z")
ISSUE = re.compile(r"[1-9][0-9]*\Z")
NAME = re.compile(r"[^\x00-\x1f\\/\x7f]{1,128}\.webp\Z", re.I)


class NativeImageError(ValueError):
    pass


def canonical(value: dict) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True,
                       separators=(",", ":")) + "\n").encode("utf-8")


def digest(value: dict) -> str:
    return hashlib.sha256(canonical(value)).hexdigest()


def owner_payload(comments_b64: Path, issue: int, trusted_main: str | None = None) -> tuple[dict, int, str]:
    """Reuse the existing #576 schema and owner-login admission, unchanged."""
    latest = None
    comments = []
    for line in comments_b64.read_text(encoding="utf-8").splitlines():

        if not line:
            continue
        comment = json.loads(base64.b64decode(line).decode("utf-8"))
        comments.append(comment)
        if comment.get("user", {}).get("login") != OWNER:
            continue
        body = comment.get("body") or ""
        if not body.startswith(MARKER):
            continue
        try:
            value = json.loads(body[len(MARKER):].strip())
        except (ValueError, TypeError):
            continue
        if isinstance(value, dict):
            latest = comment, value
    if latest is None:
        raise NativeImageError("No owner-authored Article candidate.")
    comment, payload = latest
    required = {"schema_version", "issue_number", "bundle", "published", "category", "fr", "en"}
    if (set(payload) != required or payload["schema_version"] != 1
            or payload["issue_number"] != issue or payload["bundle"] != "article"
            or not isinstance(payload["published"], bool)):
        raise NativeImageError("Article payload identity/schema mismatch.")
    if (not isinstance(payload["category"], dict)
            or set(payload["category"]) != {"tid", "name"}):
        raise NativeImageError("Article category schema mismatch.")
    for language in ("fr", "en"):
        if not isinstance(payload[language], dict) or set(payload[language]) != {
            "title", "short_description", "body_html"
        }:
            raise NativeImageError("Article language schema mismatch.")
    revision = int(comment["id"])
    if revision <= 0:
        raise NativeImageError("Invalid candidate revision.")
    payload_hash = digest(payload)
    if trusted_main is not None:
        if not re.fullmatch(r"[0-9a-f]{40}", trusted_main):
            raise NativeImageError("Invalid exact live main.")
        expected = {
            "target": "PREPROD", "candidate_id": f"agency-article-{issue}",
            "candidate_revision": str(revision), "payload_sha256": payload_hash,
            "trusted_main": trusted_main, "prod_write": "NONE",
        }
        receipts = []
        for item in comments:
            if item.get("user", {}).get("login") != "github-actions[bot]":
                continue
            if int(item.get("id") or 0) <= revision:
                continue
            lines = (item.get("body") or "").splitlines()
            if not lines or lines[0] != "### Agency editorial PREPROD candidate apply PASS":
                continue
            fields = {}
            for line in lines[1:]:
                if ": " in line:
                    name, val = line.split(": ", 1)
                    if val.startswith("`") and val.endswith("`"):
                        if name in fields:
                            raise NativeImageError("Duplicate PREPROD receipt field.")
                        fields[name] = val[1:-1]
            if (all(fields.get(key) == val for key, val in expected.items())
                    and ISSUE.fullmatch(fields.get("node_id", ""))):
                receipts.append(item)
        if not receipts:
            raise NativeImageError("Exact PREPROD Article apply receipt missing.")
    return payload, revision, payload_hash


def plain_alt(value: object) -> bool:
    if not isinstance(value, str) or not (1 <= len(value.strip()) <= 300):
        return False
    return value == value.strip() and not any(ord(c) < 32 or ord(c) == 127 for c in value) and "<" not in value and ">" not in value


def validate_snapshot(manifest: dict, asset: bytes, issue: int, revision: int,
                      payload_sha: str, main: str) -> tuple[str, dict, str]:
    """Validate exact live File API snapshot; build an ephemeral #584 profile."""
    if issue < MIN_NEW_ISSUE or not ISSUE.fullmatch(str(issue)):
        raise NativeImageError("Only future new Article issues are eligible.")
    if not SHA.fullmatch(payload_sha) or not re.fullmatch(r"[0-9a-f]{40}", main):
        raise NativeImageError("Invalid source identity.")
    fields = {
        "schema_version", "source", "issue_number", "candidate_id",
        "candidate_revision", "payload_sha256", "trusted_main", "node_id",
        "node_uuid", "node_revision_id", "fid", "file_uuid",
        "filename", "asset_sha256", "mime", "width", "height", "bytes", "alt"
    }
    if not isinstance(manifest, dict) or set(manifest) != fields:
        raise NativeImageError("Invalid closed native image manifest.")
    if (manifest["schema_version"] != 1 or manifest["source"] != "DRUPAL_PREPROD_FILE_API"
            or manifest["issue_number"] != issue
            or manifest["candidate_id"] != f"agency-article-{issue}"
            or manifest["candidate_revision"] != revision
            or manifest["payload_sha256"] != payload_sha
            or manifest["trusted_main"] != main):
        raise NativeImageError("Manifest candidate/main mismatch.")
    if any(not isinstance(manifest[key], int) or isinstance(manifest[key], bool)
           or manifest[key] <= 0 for key in ("node_id", "node_revision_id", "fid", "width", "height", "bytes")):
        raise NativeImageError("Manifest node/File identity is invalid.")
    if (not UUID.fullmatch(str(manifest["node_uuid"]))
            or not UUID.fullmatch(str(manifest["file_uuid"]))):
        raise NativeImageError("Manifest UUIDs are invalid.")
    if (not NAME.fullmatch(str(manifest["filename"])) or
            manifest["mime"] != "image/webp" or
            manifest["width"] > 2400 or manifest["height"] > 1600 or
            manifest["bytes"] > MAX_BYTES or len(asset) != manifest["bytes"]):
        raise NativeImageError("WebP MIME, filename, size or dimensions not allowed.")
    if not isinstance(manifest["alt"], dict) or set(manifest["alt"]) != {"fr", "en"}:
        raise NativeImageError("FR/EN ALT identity missing.")
    if not all(plain_alt(manifest["alt"][lang]) for lang in ("fr", "en")):
        raise NativeImageError("FR/EN ALT must be bounded plain text.")
    if (not SHA.fullmatch(str(manifest["asset_sha256"])) or
            not hashlib.sha256(asset).hexdigest() == manifest["asset_sha256"]):
        raise NativeImageError("Image bytes are stale or untrusted.")
    # Independent container check; the remote File API reader additionally
    # decodes the image with GD. This is deliberately not a remote file path.
    if len(asset) < 16 or asset[:4] != b"RIFF" or asset[8:12] != b"WEBP" or int.from_bytes(asset[4:8], "little") + 8 != len(asset):
        raise NativeImageError("WebP container is malformed.")
    # Do not accept a merely plausible RIFF header: decode actual bytes with
    # the existing PHP GD raster engine on the trusted Actions runner.
    php_code = (
        '$bytes=stream_get_contents(STDIN);'
        '$image=@imagecreatefromstring($bytes);'
        'if (!$image instanceof GdImage) { exit(2); }'
        'echo imagesx($image),"x",imagesy($image);'
    )
    try:
        decoded = subprocess.run(
            ["php", "-r", php_code], input=asset, capture_output=True,
            timeout=10, check=False
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise NativeImageError("The required WebP decoder is unavailable.") from exc
    if decoded.returncode != 0 or decoded.stdout.decode("ascii", "replace").strip() != (
        f"{manifest['width']}x{manifest['height']}"
    ):
        raise NativeImageError("WebP bytes could not be decoded to the proven dimensions.")
    profile = {
        "issue_number": issue,
        "bundle": "article",
        "article_payload_sha256": payload_sha,
        "field_name": "field_feature_image",
        "asset": {
            "path": f"assets/editorial/native-preprod-{issue}.webp",
            "filename": f"agency-native-{manifest['asset_sha256'][:16]}.webp",
            "sha256": manifest["asset_sha256"],
            "mime": "image/webp",
            "width": manifest["width"],
            "height": manifest["height"],
            "max_bytes": manifest["bytes"]
        },
        "alt": manifest["alt"],
    }
    return digest(manifest), profile, digest(profile)


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--issue", type=int, required=True)
    p.add_argument("--payload-comments", type=Path)
    p.add_argument("--payload-output", type=Path)
    p.add_argument("--manifest", type=Path)
    p.add_argument("--asset", type=Path)
    p.add_argument("--profile-output", type=Path)
    p.add_argument("--identity-output", type=Path)
    p.add_argument("--candidate-revision", type=int)
    p.add_argument("--payload-sha256")
    p.add_argument("--trusted-main")
    args = p.parse_args()
    try:
        if args.issue < MIN_NEW_ISSUE:
            raise NativeImageError("Historical issues must use their immutable repository-owned profile.")
        if args.payload_comments:
            if not args.payload_output:
                raise NativeImageError("Payload output is missing.")
            payload, revision, sha = owner_payload(args.payload_comments, args.issue, args.trusted_main)
            args.payload_output.write_bytes(canonical(payload))
            print(json.dumps({"candidate_revision": revision, "payload_sha256": sha}))
        else:
            if not all((args.manifest, args.asset, args.profile_output, args.identity_output,
                        args.candidate_revision, args.payload_sha256, args.trusted_main)):
                raise NativeImageError("Incomplete source inspection inputs.")
            manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
            asset = args.asset.read_bytes()
            manifest_sha, profile, profile_sha = validate_snapshot(
                manifest, asset, args.issue, args.candidate_revision,
                args.payload_sha256, args.trusted_main)
            args.profile_output.write_bytes(canonical({"schema_version": 1, "profiles": {str(args.issue): profile}}))
            args.identity_output.write_bytes(canonical({
                "manifest_sha256": manifest_sha, "profile_sha256": profile_sha,
                "asset_sha256": profile["asset"]["sha256"],
                "node_id": manifest["node_id"], "node_uuid": manifest["node_uuid"],
                "revision_id": manifest["node_revision_id"],
                "fid": manifest["fid"], "file_uuid": manifest["file_uuid"],
                "candidate_revision": manifest["candidate_revision"],
                "payload_sha256": manifest["payload_sha256"],
                "trusted_main": manifest["trusted_main"]
            }))
            print("NATIVE_IMAGE_SNAPSHOT=PASS")
    except (NativeImageError, ValueError, KeyError, TypeError, OSError) as exc:
        print(f"NATIVE_IMAGE_SNAPSHOT=FAIL_CLOSED: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Validate exact PREPROD-first evidence before Article PROD promotion."""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import re
from pathlib import Path
from typing import Any

OWNER = "E-merging-digital"
BOT = "github-actions[bot]"
PREPROD_PREFIX = "https://preprod.emergingdigital.be/"


class ApprovalError(RuntimeError):
    """Raised when the promotion authority is incomplete or stale."""


def load_comments(path: Path) -> list[dict[str, Any]]:
    comments: list[dict[str, Any]] = []
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        if not raw_line.strip():
            continue
        decoded = base64.b64decode(raw_line).decode("utf-8")
        comment = json.loads(decoded)
        if not isinstance(comment, dict):
            raise ApprovalError("Editorial comment evidence must decode to objects.")
        comments.append(comment)
    return comments


def canonical_profile(
    registry_path: Path,
    issue_number: int,
    payload_sha256: str,
    asset_path: Path,
) -> tuple[dict[str, Any], str, str]:
    registry = json.loads(registry_path.read_text(encoding="utf-8"))
    if not isinstance(registry, dict) or set(registry) != {"schema_version", "profiles"}:
        raise ApprovalError("Feature-image profile registry has an invalid closed schema.")
    if registry.get("schema_version") != 1 or not isinstance(registry.get("profiles"), dict):
        raise ApprovalError("Feature-image profile registry v1 is required.")
    profile = registry["profiles"].get(str(issue_number))
    if not isinstance(profile, dict):
        raise ApprovalError("Article promotion requires an exact repository-owned image profile.")
    expected = {
        "issue_number",
        "bundle",
        "article_payload_sha256",
        "field_name",
        "asset",
        "alt",
    }
    if set(profile) != expected:
        raise ApprovalError("Feature-image profile keys are not exact.")
    if profile.get("issue_number") != issue_number:
        raise ApprovalError("Feature-image profile issue_number mismatch.")
    if profile.get("bundle") != "article" or profile.get("field_name") != "field_feature_image":
        raise ApprovalError("Feature-image profile is outside the Article contract.")
    if profile.get("article_payload_sha256") != payload_sha256:
        raise ApprovalError("Feature-image profile is stale for the current Article payload.")

    asset = profile.get("asset")
    alt = profile.get("alt")
    if not isinstance(asset, dict) or not isinstance(alt, dict):
        raise ApprovalError("Feature-image asset/ALT profile is invalid.")
    expected_asset = {"path", "filename", "sha256", "mime", "width", "height", "max_bytes"}
    if set(asset) != expected_asset or set(alt) != {"fr", "en"}:
        raise ApprovalError("Feature-image asset/ALT keys are not exact.")
    if asset.get("mime") != "image/png":
        raise ApprovalError("Feature-image promotion currently requires image/png.")
    if not all(isinstance(alt.get(lang), str) and alt[lang].strip() for lang in ("fr", "en")):
        raise ApprovalError("FR/EN ALT values are mandatory.")
    if not asset_path.is_file():
        raise ApprovalError("Approved feature-image asset is missing.")
    asset_sha = hashlib.sha256(asset_path.read_bytes()).hexdigest()
    if asset_sha != asset.get("sha256"):
        raise ApprovalError("Approved feature-image asset hash mismatch.")

    canonical = json.dumps(
        profile,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ) + "\n"
    profile_sha = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return profile, profile_sha, asset_sha


def backtick_fields(body: str, heading: str) -> dict[str, str] | None:
    lines = body.splitlines()
    if not lines or lines[0] != heading:
        return None
    fields: dict[str, str] = {}
    for line in lines[1:]:
        match = re.fullmatch(r"([a-z][a-z0-9_]*): `([^`\r\n]+)`", line)
        if match is None:
            continue
        key, value = match.groups()
        if key in fields:
            raise ApprovalError(f"Duplicate receipt field: {key}.")
        fields[key] = value
    return fields


def approval_fields(body: str, issue_number: int) -> tuple[dict[str, str], dict[str, str]] | None:
    heading = (
        "## PROJECT LEAD — HUMAN APPROVAL / exact "
        f"#{issue_number} candidate approved for PROD promotion"
    )
    lines = body.splitlines()
    if not lines or lines[0] != heading:
        return None

    fields: dict[str, str] = {}
    urls: dict[str, str] = {}
    for line in lines[1:]:
        match = re.fullmatch(r"([A-Z][A-Z0-9_]*) = ([^\r\n]+)", line)
        if match is not None:
            key, value = match.groups()
            if key in fields:
                raise ApprovalError(f"Duplicate human approval field: {key}.")
            fields[key] = value.strip()
            continue
        url_match = re.fullmatch(r"- (FR|EN): `(https://preprod\.emergingdigital\.be/[^`\s]+)`", line)
        if url_match is not None:
            lang, url = url_match.groups()
            if lang in urls:
                raise ApprovalError(f"Duplicate approved rendered URL: {lang}.")
            urls[lang] = url
    return fields, urls


def matching_bot_receipt(
    comments: list[dict[str, Any]],
    heading: str,
    expected: dict[str, str],
) -> dict[str, Any]:
    matches: list[dict[str, Any]] = []
    for comment in comments:
        if comment.get("user", {}).get("login") != BOT:
            continue
        fields = backtick_fields(str(comment.get("body") or ""), heading)
        if fields is None:
            continue
        if all(fields.get(key) == value for key, value in expected.items()):
            matches.append(comment)
    if len(matches) != 1:
        raise ApprovalError(
            f"Expected exactly one bot receipt '{heading}' for the exact candidate; found {len(matches)}."
        )
    return matches[0]


def validate(args: argparse.Namespace) -> dict[str, Any]:
    if not re.fullmatch(r"[0-9a-f]{64}", args.payload_sha256):
        raise ApprovalError("payload_sha256 must be lowercase SHA-256.")
    if not re.fullmatch(r"[0-9a-f]{40}", args.trusted_main):
        raise ApprovalError("trusted_main must be lowercase Git SHA-1.")
    if args.issue_number <= 0 or args.candidate_revision <= 0:
        raise ApprovalError("Issue number and candidate revision must be positive integers.")

    comments = load_comments(args.comments_b64)
    profile, profile_sha, asset_sha = canonical_profile(
        args.profile_registry,
        args.issue_number,
        args.payload_sha256,
        args.asset_path,
    )
    candidate_id = f"agency-article-{args.issue_number}"

    prod_dry_run = matching_bot_receipt(
        comments,
        "### Agency editorial dry-run PASS",
        {
            "payload_sha256": args.payload_sha256,
            "trusted_main": args.trusted_main,
            "route_outcome": "success",
        },
    )

    human_matches: list[tuple[dict[str, Any], dict[str, str], dict[str, str]]] = []
    for comment in comments:
        if comment.get("user", {}).get("login") != OWNER:
            continue
        if comment.get("author_association") != "OWNER":
            continue
        parsed = approval_fields(str(comment.get("body") or ""), args.issue_number)
        if parsed is None:
            continue
        fields, urls = parsed
        human_matches.append((comment, fields, urls))
    if len(human_matches) != 1:
        raise ApprovalError(
            f"Expected exactly one exact owner-authored Project Lead approval; found {len(human_matches)}."
        )
    approval, fields, urls = human_matches[0]

    exact_values = {
        "CANDIDATE_ID": candidate_id,
        "CANDIDATE_REVISION": str(args.candidate_revision),
        "ARTICLE_PAYLOAD_SHA256": args.payload_sha256,
        "IMAGE_PROFILE_SHA256": profile_sha,
        "IMAGE_ASSET_SHA256": asset_sha,
        "HUMAN_REVIEW": "PASS",
        "CONTENT": "APPROVED",
        "IMAGE": "APPROVED",
        "ALT_FR_EN": "APPROVED",
        "IMAGE_SOURCE_POLICY": "APPROVED",
        "RESPONSIVE_RENDER": "APPROVED",
        "LISTING_DETAIL_RENDER": "APPROVED",
        "EXACT_CANDIDATE_PROMOTION_TO_PROD": "AUTHORIZED",
        "CONTENT_CHANGE_AFTER_APPROVAL": "INVALIDATES_APPROVAL",
        "IMAGE_CHANGE_AFTER_APPROVAL": "INVALIDATES_APPROVAL",
    }
    for key, expected in exact_values.items():
        if fields.get(key) != expected:
            raise ApprovalError(f"Human approval field {key} does not match the exact candidate.")
    if set(urls) != {"FR", "EN"} or not all(url.startswith(PREPROD_PREFIX) for url in urls.values()):
        raise ApprovalError("Human approval must bind exact FR and EN PREPROD rendered URLs.")

    article_apply = re.fullmatch(r"([1-9][0-9]*) / SUCCESS", fields.get("PREPROD_ARTICLE_APPLY", ""))
    preprod_node = re.fullmatch(r"([1-9][0-9]*)", fields.get("PREPROD_NODE_ID", ""))
    article_revision = re.fullmatch(
        r"([1-9][0-9]*)",
        fields.get("PREPROD_ARTICLE_REVISION_AFTER_IMAGE", ""),
    )
    image_apply = re.fullmatch(r"([1-9][0-9]*) / SUCCESS", fields.get("PREPROD_IMAGE_APPLY", ""))
    image_post = re.fullmatch(
        r"([1-9][0-9]*) / SUCCESS / IDEMPOTENT",
        fields.get("PREPROD_IMAGE_POST_APPLY_DRY_RUN", ""),
    )
    if None in (article_apply, preprod_node, article_revision, image_apply, image_post):
        raise ApprovalError("Human approval PREPROD execution fields are incomplete or malformed.")

    article_run = article_apply.group(1)
    node_id = preprod_node.group(1)
    revision_id = article_revision.group(1)
    image_run = image_apply.group(1)
    image_post_run = image_post.group(1)

    preprod_article = matching_bot_receipt(
        comments,
        "### Agency editorial PREPROD candidate apply PASS",
        {
            "target": "PREPROD",
            "candidate_id": candidate_id,
            "candidate_revision": str(args.candidate_revision),
            "payload_sha256": args.payload_sha256,
            "trusted_main": args.trusted_main,
            "run_id": article_run,
            "node_id": node_id,
            "prod_write": "NONE",
        },
    )
    preprod_image = matching_bot_receipt(
        comments,
        "### Agency editorial image apply PASS",
        {
            "profile_sha256": profile_sha,
            "asset_sha256": asset_sha,
            "trusted_main": args.trusted_main,
            "target": "PREPROD",
            "run_id": image_run,
            "route_outcome": "success",
            "node_id": node_id,
            "revision_id": revision_id,
            "prod_write": "NONE",
        },
    )
    preprod_image_post = matching_bot_receipt(
        comments,
        "### Agency editorial image dry-run PASS",
        {
            "profile_sha256": profile_sha,
            "asset_sha256": asset_sha,
            "trusted_main": args.trusted_main,
            "target": "PREPROD",
            "run_id": image_post_run,
            "route_outcome": "success",
            "verdict": "IDEMPOTENT",
            "node_id": node_id,
            "revision_id": revision_id,
            "prod_write": "NONE",
        },
    )

    approval_id = int(approval.get("id") or 0)
    evidence_ids = [
        args.candidate_revision,
        int(preprod_article.get("id") or 0),
        int(preprod_image.get("id") or 0),
        int(preprod_image_post.get("id") or 0),
    ]
    if approval_id <= max(evidence_ids):
        raise ApprovalError("Project Lead approval is stale or predates exact PREPROD evidence.")
    prod_dry_run_id = int(prod_dry_run.get("id") or 0)
    if prod_dry_run_id <= approval_id:
        raise ApprovalError("Fresh PROD dry-run must occur after exact human approval.")

    return {
        "status": "PASS",
        "verdict": "AUTHORIZED",
        "candidate_id": candidate_id,
        "candidate_revision": args.candidate_revision,
        "payload_sha256": args.payload_sha256,
        "trusted_main": args.trusted_main,
        "approval_comment_id": approval_id,
        "profile_sha256": profile_sha,
        "asset_sha256": asset_sha,
        "asset_path": profile["asset"]["path"],
        "preprod_node_id": int(node_id),
        "preprod_revision_id": int(revision_id),
        "fr_url": urls["FR"],
        "en_url": urls["EN"],
        "image_waiver": "UNSUPPORTED",
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--comments-b64", type=Path, required=True)
    parser.add_argument("--issue-number", type=int, required=True)
    parser.add_argument("--candidate-revision", type=int, required=True)
    parser.add_argument("--payload-sha256", required=True)
    parser.add_argument("--trusted-main", required=True)
    parser.add_argument("--profile-registry", type=Path, required=True)
    parser.add_argument("--asset-path", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    try:
        result = validate(args)
    except (ApprovalError, ValueError, KeyError, json.JSONDecodeError) as exc:
        print(f"EDITORIAL_PROMOTION_REFUSED={exc}")
        return 1

    args.output.write_text(
        json.dumps(result, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    print("EDITORIAL_PROMOTION_AUTHORIZED=YES")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

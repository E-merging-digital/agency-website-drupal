#!/usr/bin/env python3
"""Synthetic source-only regression for #1540; never touches Drupal hosts."""
import argparse
import base64
import copy
import hashlib
import json
import runpy
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NATIVE = runpy.run_path(str(ROOT / "runner/editorial-native-image-manifest.py"))
PROMOTION = runpy.run_path(str(ROOT / "runner/validate-editorial-promotion-approval.py"))
ISSUE = 1542
MAIN = "a" * 40
WEBP = base64.b64decode("UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v0n4AA=")
NODE_UUID = "12345678-1234-4234-8234-123456789abc"
FILE_UUID = "abcdefab-1234-4234-8234-abcdefabcdef"
Q = chr(96)


def bot(ident, heading, fields):
    return {
        "id": ident, "user": {"login": "github-actions[bot]"},
        "body": heading + "\n\n" + "\n".join(f"{key}: {Q}{val}{Q}" for key, val in fields.items()),
        "created_at": "2026-11-12T00:00:00Z",
        "updated_at": "2026-11-12T00:00:00Z",
    }


class NativeImageManifestTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.asset = self.base / "asset.webp"
        self.asset.write_bytes(WEBP)
        self.payload = {
            "schema_version": 1, "issue_number": ISSUE, "bundle": "article",
            "published": True, "category": {"tid": 2, "name": "Blog"},
            "fr": {"title": "FR", "short_description": "Résumé", "body_html": "<p>Bonjour</p>"},
            "en": {"title": "EN", "short_description": "Summary", "body_html": "<p>Hello</p>"},
        }
        self.payload_sha = NATIVE["digest"](self.payload)
        self.manifest = {
            "schema_version": 1, "source": "DRUPAL_PREPROD_FILE_API",
            "issue_number": ISSUE, "candidate_id": f"agency-article-{ISSUE}",
            "candidate_revision": 100, "payload_sha256": self.payload_sha,
            "trusted_main": MAIN, "node_id": 46, "node_uuid": NODE_UUID,
            "node_revision_id": 52, "fid": 3, "file_uuid": FILE_UUID,
            "filename": "cover.webp", "asset_sha256": hashlib.sha256(WEBP).hexdigest(),
            "mime": "image/webp", "width": 2, "height": 2, "bytes": len(WEBP),
            "alt": {"fr": "Image du sujet", "en": "Topic illustration"},
        }
        self.manifest_path = self.base / "manifest.json"
        self.registry_path = self.base / "profiles.json"
        self.comments = []

    def tearDown(self):
        self.temp.cleanup()

    def snapshot(self, manifest=None, asset=None):
        return NATIVE["validate_snapshot"](
            manifest if manifest is not None else self.manifest,
            WEBP if asset is None else asset, ISSUE, 100, self.payload_sha, MAIN
        )

    def fixture_approval(self):
        manifest_sha, profile, profile_sha = self.snapshot()
        self.registry_path.write_bytes(NATIVE["canonical"]({
            "schema_version": 1, "profiles": {str(ISSUE): profile}
        }))
        self.manifest_path.write_bytes(NATIVE["canonical"](self.manifest))
        article = {
            "target": "PREPROD", "candidate_id": f"agency-article-{ISSUE}",
            "candidate_revision": 100, "payload_sha256": self.payload_sha,
            "trusted_main": MAIN, "run_id": 701, "node_id": 46, "prod_write": "NONE",
        }
        source = {
            "source": "DRUPAL_PREPROD_FILE_API",
            "issue_number": ISSUE, "candidate_id": f"agency-article-{ISSUE}",
            "candidate_revision": 100, "payload_sha256": self.payload_sha,
            "trusted_main": MAIN, "manifest_sha256": manifest_sha,
            "profile_sha256": profile_sha, "asset_sha256": self.manifest["asset_sha256"],
            "node_id": 46, "node_uuid": NODE_UUID, "revision_id": 52,
            "fid": 3, "file_uuid": FILE_UUID, "run_id": 702,
            "target": "PREPROD", "prod_write": "NONE", "route_outcome": "success",
        }
        approval_fields = {
            "CANDIDATE_ID": f"agency-article-{ISSUE}", "CANDIDATE_REVISION": 100,
            "ARTICLE_PAYLOAD_SHA256": self.payload_sha,
            "IMAGE_PROFILE_SHA256": profile_sha,
            "IMAGE_ASSET_SHA256": self.manifest["asset_sha256"],
            "IMAGE_MANIFEST_SHA256": manifest_sha,
            "PREPROD_NODE_ID": 46, "PREPROD_NODE_UUID": NODE_UUID,
            "PREPROD_ARTICLE_REVISION_AFTER_IMAGE": 52,
            "PREPROD_IMAGE_FID": 3, "PREPROD_IMAGE_FILE_UUID": FILE_UUID,
            "PREPROD_ARTICLE_APPLY": "701 / SUCCESS",
            "PREPROD_IMAGE_MANIFEST": "702 / SUCCESS",
            "HUMAN_REVIEW": "PASS", "CONTENT": "APPROVED",
            "IMAGE": "APPROVED", "ALT_FR_EN": "APPROVED",
            "IMAGE_SOURCE_POLICY": "APPROVED", "RESPONSIVE_RENDER": "APPROVED",
            "LISTING_DETAIL_RENDER": "APPROVED",
            "EXACT_CANDIDATE_PROMOTION_TO_PROD": "AUTHORIZED",
            "CONTENT_CHANGE_AFTER_APPROVAL": "INVALIDATES_APPROVAL",
            "IMAGE_CHANGE_AFTER_APPROVAL": "INVALIDATES_APPROVAL",
        }
        owner_approval = {
            "id": 130,
            "user": {"login": "E-merging-digital", "type": "User"},
            "author_association": "OWNER", "performed_via_github_app": None,
            "created_at": "2026-11-12T00:00:00Z",
            "updated_at": "2026-11-12T00:00:00Z",
            "body": f"## PROJECT LEAD — HUMAN APPROVAL / exact #{ISSUE} candidate approved for PROD promotion\n\n"
                    + "\n".join(f"{k} = {v}" for k, v in approval_fields.items())
                    + f"\n- FR: {Q}https://preprod.emergingdigital.be/fr/blog/test{Q}"
                    + f"\n- EN: {Q}https://preprod.emergingdigital.be/en/blog/test{Q}\n",
        }
        self.comments = [
            {"id": 100, "user": {"login": "E-merging-digital"},
             "body": "<!-- agency-editorial-payload:v1 -->\n" + json.dumps(self.payload)},
            bot(110, "### Agency editorial PREPROD candidate apply PASS", article),
            bot(120, "### Agency editorial native image manifest PASS", source),
            owner_approval,
            bot(140, "### Agency editorial dry-run PASS", {
                "candidate_kind": "article", "payload_sha256": self.payload_sha,
                "trusted_main": MAIN, "route_outcome": "success",
            }),
        ]
        self.args = argparse.Namespace(
            issue_number=ISSUE, candidate_revision="100",
            payload_sha256=self.payload_sha, trusted_main=MAIN,
            profile_registry=self.registry_path, asset_path=self.asset,
            native_manifest=self.manifest_path, native_preflight=False,
        )

    def validate(self):
        return PROMOTION["validate_article"](self.args, self.comments)

    def test_happy_source_manifest_approval_and_exact_replay(self):
        self.fixture_approval()
        self.assertEqual(self.validate()["verdict"], "AUTHORIZED")
        self.assertEqual(self.validate()["verdict"], "AUTHORIZED")
        self.args.native_preflight = True
        self.comments.pop()  # preflight before PROD dry-run can still validate direct GO
        self.assertEqual(self.validate()["verdict"], "AUTHORIZED")

    def test_wrong_issue_uuid_fid_article_hash_and_image_hash(self):
        for key, replacement in (
            ("issue_number", 1543), ("node_uuid", FILE_UUID[:35] + "0"),
            ("file_uuid", NODE_UUID), ("fid", 4),
            ("payload_sha256", "b"*64), ("asset_sha256", "f"*64),
        ):
            with self.subTest(key=key):
                bad = copy.deepcopy(self.manifest)
                bad[key] = replacement
                with self.assertRaises(Exception):
                    self.snapshot(bad)

    def test_mime_size_alt_corruption_remote_source_and_path_refused(self):
        for key, replacement in (
            ("mime", "image/png"), ("bytes", 5000001),
            ("source", "https://untrusted.invalid/file.webp"),
            ("alt", {"fr": "", "en": "Valid"}),
        ):
            with self.subTest(key=key):
                bad = copy.deepcopy(self.manifest)
                bad[key] = replacement
                with self.assertRaises(Exception):
                    self.snapshot(bad)
        injected = copy.deepcopy(self.manifest)
        injected["path"] = "/tmp/attacker.webp"
        with self.assertRaises(Exception):
            self.snapshot(injected)
        fake = b"RIFF" + (12).to_bytes(4, "little") + b"WEBP" + b"abcdabcd"
        bad = copy.deepcopy(self.manifest)
        bad["bytes"] = len(fake)
        bad["asset_sha256"] = hashlib.sha256(fake).hexdigest()
        with self.assertRaises(Exception):
            self.snapshot(bad, fake)

    def test_image_modified_after_approval(self):
        self.fixture_approval()
        self.asset.write_bytes(WEBP[:-1] + b"x")
        with self.assertRaises(Exception):
            self.validate()

    def test_wrong_identity_and_old_new_content_approval(self):
        self.fixture_approval()
        for key, replacement in (
            ("PREPROD_NODE_ID", "99"),
            ("PREPROD_NODE_UUID", FILE_UUID),
            ("PREPROD_IMAGE_FID", "9"),
            ("ARTICLE_PAYLOAD_SHA256", "c"*64),
            ("IMAGE_MANIFEST_SHA256", "d"*64),
        ):
            with self.subTest(key=key):
                original = self.comments[3]["body"]
                self.comments[3]["body"] = original.replace(
                    next(line for line in original.splitlines() if line.startswith(key + " = ")),
                    key + " = " + replacement
                )
                with self.assertRaises(Exception):
                    self.validate()
                self.comments[3]["body"] = original

    def test_no_go_app_go_edited_go_and_prod_direct_refused(self):
        self.fixture_approval()
        old = self.comments[3]
        self.comments.remove(old)
        with self.assertRaises(Exception):
            self.validate()
        self.comments.insert(3, old)
        old["performed_via_github_app"] = {"id": 1}
        with self.assertRaises(Exception):
            self.validate()
        old["performed_via_github_app"] = None
        old["updated_at"] = "2026-11-12T00:00:01Z"
        with self.assertRaises(Exception):
            self.validate()

    def test_new_manifest_replay_after_owner_go_refused(self):
        self.fixture_approval()
        replay = copy.deepcopy(self.comments[2])
        replay["id"] = 150
        replay["body"] = replay["body"].replace("run_id: " + Q + "702" + Q,
                                                "run_id: " + Q + "703" + Q)
        self.comments.append(replay)
        with self.assertRaises(Exception):
            self.validate()

    def test_wrong_owner_payload_or_missing_candidate_apply(self):
        self.fixture_approval()
        b64 = self.base / "comments.b64"
        b64.write_text("\n".join(
            base64.b64encode(json.dumps(c).encode("utf-8")).decode("ascii")
            for c in self.comments
        ), encoding="utf-8")
        self.assertEqual(NATIVE["owner_payload"](b64, ISSUE, MAIN)[2], self.payload_sha)
        self.comments.pop(1)
        b64.write_text("\n".join(
            base64.b64encode(json.dumps(c).encode("utf-8")).decode("ascii")
            for c in self.comments
        ), encoding="utf-8")
        with self.assertRaises(Exception):
            NATIVE["owner_payload"](b64, ISSUE, MAIN)

    def test_historical_issue_not_admitted(self):
        with self.assertRaises(Exception):
            NATIVE["validate_snapshot"](self.manifest, WEBP, 1404, 100, self.payload_sha, MAIN)


if __name__ == "__main__":
    unittest.main()

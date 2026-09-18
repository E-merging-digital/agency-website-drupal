#!/usr/bin/env python3
from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import collect_dependency_maintenance as collector


class DependencyMaintenanceTests(unittest.TestCase):
    def base_inputs(self):
        show = {
            "locked": [
                {"name": "drupal/core-recommended", "version": "11.4.7", "abandoned": False},
                {"name": "drupal/coder", "version": "8.3.31", "abandoned": False},
                {"name": "mglaman/phpstan-drupal", "version": "2.2.0", "abandoned": False},
            ]
        }
        outdated = {
            "locked": [
                {
                    "name": "drupal/coder",
                    "version": "8.3.31",
                    "latest": "9.0.1",
                    "latest-status": "update-possible",
                },
                {
                    "name": "mglaman/phpstan-drupal",
                    "version": "2.2.0",
                    "latest": "2.2.1",
                    "latest-status": "semver-safe-update",
                },
            ]
        }
        audit = {"advisories": [], "abandoned": [], "filter": []}
        package_json = {"devDependencies": {"@playwright/test": "1.62.1"}}
        package_lock = {"packages": {"node_modules/@playwright/test": {"version": "1.62.1"}}}
        config = {
            "version": 1,
            "deferred_updates": {
                "drupal/coder": {
                    "when_latest_major_at_least": 9,
                    "reason": "Coder 9 blocked by #1234",
                }
            },
        }
        return show, outdated, audit, package_json, package_lock, config

    def snapshot(self, **overrides):
        show, outdated, audit, package_json, package_lock, config = self.base_inputs()
        values = {
            "show_data": show,
            "outdated_data": outdated,
            "audit_data": audit,
            "package_json": package_json,
            "package_lock": package_lock,
            "playwright_latest_data": "1.63.0",
            "config": config,
            "repository_sha": "abc123",
            "observed_at": "2026-09-18T00:00:00+00:00",
        }
        values.update(overrides)
        return collector.build_snapshot(**values)

    def by_name(self, snapshot, name):
        return next(item for item in snapshot["components"] if item["component"] == name)

    def test_current_core_is_explicit(self):
        item = self.by_name(self.snapshot(), "drupal/core-recommended")
        self.assertEqual(item["health"], "CURRENT")
        self.assertEqual(item["available_version"], "11.4.7")

    def test_coder_major_is_blocked_by_governed_deferral(self):
        item = self.by_name(self.snapshot(), "drupal/coder")
        self.assertEqual(item["health"], "BLOCKED")
        self.assertEqual(item["update_class"], "MAJOR")
        self.assertEqual(item["available_version"], "9.0.1")
        self.assertIn("#1234", item["impact"])

    def test_semver_safe_patch_is_update_available(self):
        item = self.by_name(self.snapshot(), "mglaman/phpstan-drupal")
        self.assertEqual(item["health"], "UPDATE_AVAILABLE")
        self.assertEqual(item["update_class"], "PATCH")

    def test_playwright_is_project_owned_update(self):
        item = self.by_name(self.snapshot(), "npm:@playwright/test")
        self.assertEqual(item["health"], "UPDATE_AVAILABLE")
        self.assertEqual(item["update_class"], "MINOR")
        self.assertEqual(item["available_version"], "1.63.0")

    def test_security_advisory_overrides_routine_state(self):
        audit = {
            "advisories": {
                "drupal/core-recommended": [{"packageName": "drupal/core-recommended"}]
            },
            "abandoned": [],
        }
        item = self.by_name(self.snapshot(audit_data=audit), "drupal/core-recommended")
        self.assertEqual(item["health"], "SECURITY_ACTION")
        self.assertEqual(item["update_class"], "SECURITY")

    def test_abandoned_package_is_eol(self):
        show, _, _, _, _, _ = self.base_inputs()
        show["locked"][0]["abandoned"] = True
        item = self.by_name(self.snapshot(show_data=show), "drupal/core-recommended")
        self.assertEqual(item["health"], "EOL")
        self.assertEqual(item["update_class"], "EOL")

    def test_missing_audit_fails_closed(self):
        snapshot = self.snapshot(audit_data=None)
        item = self.by_name(snapshot, "drupal/core-recommended")
        self.assertEqual(item["health"], "UNKNOWN")
        self.assertEqual(snapshot["evidence_status"], "incomplete")

    def test_repository_sha_is_preserved(self):
        snapshot = self.snapshot(repository_sha="deadbeef")
        self.assertEqual(snapshot["repository_sha"], "deadbeef")


if __name__ == "__main__":
    unittest.main()

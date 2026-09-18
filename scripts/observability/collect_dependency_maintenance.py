#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

HEALTH_ORDER = {
    "CURRENT": 0,
    "UPDATE_AVAILABLE": 1,
    "BLOCKED": 2,
    "UNKNOWN": 2,
    "SECURITY_ACTION": 3,
    "EOL": 4,
}


def read_json(path: Path) -> Any | None:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def version_triplet(value: str | None) -> tuple[int, int, int] | None:
    if not value:
        return None
    match = re.search(r"(?<!\d)(\d+)\.(\d+)(?:\.(\d+))?", value.lstrip("v"))
    if not match:
        return None
    return int(match.group(1)), int(match.group(2)), int(match.group(3) or 0)


def classify_update(installed: str | None, available: str | None) -> str:
    current = version_triplet(installed)
    latest = version_triplet(available)
    if not current or not latest:
        return "UNKNOWN"
    if latest[0] != current[0]:
        return "MAJOR"
    if latest[1] != current[1]:
        return "MINOR"
    if latest[2] != current[2]:
        return "PATCH"
    if (installed or "").lstrip("v") != (available or "").lstrip("v"):
        return "PATCH"
    return "NONE"


def package_names_from_advisories(raw: Any) -> set[str]:
    names: set[str] = set()
    if isinstance(raw, dict):
        names.update(str(name) for name in raw)
        for items in raw.values():
            if isinstance(items, list):
                for item in items:
                    if isinstance(item, dict):
                        name = item.get("packageName") or item.get("package")
                        if name:
                            names.add(str(name))
    elif isinstance(raw, list):
        for item in raw:
            if isinstance(item, dict):
                name = item.get("packageName") or item.get("package") or item.get("name")
                if name:
                    names.add(str(name))
    return names


def package_names_from_abandoned(raw: Any) -> set[str]:
    if isinstance(raw, dict):
        return {str(name) for name in raw}
    if isinstance(raw, list):
        result: set[str] = set()
        for item in raw:
            if isinstance(item, str):
                result.add(item)
            elif isinstance(item, dict):
                name = item.get("packageName") or item.get("package") or item.get("name")
                if name:
                    result.add(str(name))
        return result
    return set()


def normalized(
    component: str,
    installed: str | None,
    available: str | None,
    update_class: str,
    health: str,
    source: str,
    impact: str,
    action: str,
    observed_at: str,
) -> dict[str, Any]:
    return {
        "component": component,
        "host_or_project": "agency-website",
        "environment": "repository",
        "installed_version": installed,
        "available_version": available,
        "update_class": update_class,
        "health": health,
        "source": source,
        "observed_at": observed_at,
        "freshness": "current",
        "impact": impact,
        "recommended_action": action,
        "execution_capability": "read_only",
    }


def is_deferred(name: str, available: str | None, config: dict[str, Any]) -> str | None:
    deferred = config.get("deferred_updates") or {}
    rule = deferred.get(name) if isinstance(deferred, dict) else None
    if not isinstance(rule, dict):
        return None
    threshold = rule.get("when_latest_major_at_least")
    latest = version_triplet(available)
    if isinstance(threshold, int) and latest and latest[0] >= threshold:
        reason = rule.get("reason")
        return str(reason) if reason else "explicit project deferral"
    return None


def build_snapshot(
    show_data: Any,
    outdated_data: Any,
    audit_data: Any,
    composer_lock: Any,
    package_json: Any,
    package_lock: Any,
    playwright_latest_data: Any,
    config: dict[str, Any],
    repository_sha: str,
    observed_at: str,
) -> dict[str, Any]:
    show_ok = isinstance(show_data, dict) and isinstance(show_data.get("locked"), list)
    outdated_ok = isinstance(outdated_data, dict) and isinstance(outdated_data.get("locked"), list)
    audit_ok = isinstance(audit_data, dict) and "advisories" in audit_data and "abandoned" in audit_data
    npm_ok = isinstance(playwright_latest_data, str) and bool(playwright_latest_data.strip())
    lock_ok = isinstance(composer_lock, dict) and isinstance(composer_lock.get("packages"), list)

    evidence = {
        "composer_lock": "complete" if lock_ok else "unavailable",
        "composer_show_locked_direct": "complete" if show_ok else "unavailable",
        "composer_outdated_locked_direct": "complete" if outdated_ok else "unavailable",
        "composer_audit_locked": "complete" if audit_ok else "unavailable",
        "playwright_registry_latest": "complete" if npm_ok else "unavailable",
    }
    components: list[dict[str, Any]] = []

    outdated_by_name: dict[str, dict[str, Any]] = {}
    if outdated_ok:
        for package in outdated_data["locked"]:
            if isinstance(package, dict) and package.get("name"):
                outdated_by_name[str(package["name"])] = package

    advisory_names = package_names_from_advisories(audit_data.get("advisories") if audit_ok else None)
    abandoned_names = package_names_from_abandoned(audit_data.get("abandoned") if audit_ok else None)

    locked_versions: dict[str, str] = {}
    if lock_ok:
        for section in ("packages", "packages-dev"):
            packages = composer_lock.get(section) or []
            if not isinstance(packages, list):
                continue
            for package in packages:
                if isinstance(package, dict) and package.get("name") and package.get("version"):
                    locked_versions[str(package["name"])] = str(package["version"])

    direct_names: set[str] = set()
    if show_ok:
        for package in sorted(show_data["locked"], key=lambda item: str(item.get("name", ""))):
            if not isinstance(package, dict) or not package.get("name"):
                continue
            name = str(package["name"])
            direct_names.add(name)
            installed = str(package.get("version") or "") or None
            outdated = outdated_by_name.get(name)
            available = str(outdated.get("latest") or "") if outdated else installed
            available = available or None
            source = "composer show/outdated/audit --locked --direct"

            if not outdated_ok or not audit_ok:
                health = "UNKNOWN"
                update_class = "UNKNOWN"
                impact = "Composer latest/security evidence incomplete"
                action = "restore authoritative Composer metadata before concluding dependency state"
            elif name in advisory_names:
                health = "SECURITY_ACTION"
                update_class = "SECURITY"
                impact = "Composer security advisory affects this locked direct dependency"
                action = "open a bounded project maintenance task; do not auto-update"
            elif bool(package.get("abandoned")) or name in abandoned_names:
                health = "EOL"
                update_class = "EOL"
                impact = "Composer reports this direct dependency as abandoned/unsupported"
                action = "plan supported replacement/removal"
            elif outdated:
                update_class = classify_update(installed, available)
                defer_reason = is_deferred(name, available, config)
                if defer_reason:
                    health = "BLOCKED"
                    impact = defer_reason
                    action = "keep visible as deferred; reassess when blocker evidence changes"
                else:
                    health = "UPDATE_AVAILABLE"
                    latest_status = str(outdated.get("latest-status") or "unknown")
                    impact = f"direct dependency update available; composer latest-status={latest_status}"
                    action = "plan project-owned compatibility validation before update"
            else:
                health = "CURRENT"
                update_class = "NONE"
                impact = "no newer direct dependency reported by Composer"
                action = "none"

            components.append(
                normalized(
                    name,
                    installed,
                    available,
                    update_class,
                    health,
                    source,
                    impact,
                    action,
                    observed_at,
                )
            )
    else:
        components.append(
            normalized(
                "composer_evidence",
                None,
                None,
                "UNKNOWN",
                "UNKNOWN",
                "composer show --locked --direct",
                "direct locked dependency inventory unavailable",
                "restore Composer metadata source before concluding project state",
                observed_at,
            )
        )


    if audit_ok:
        for name in sorted(advisory_names - direct_names):
            components.append(
                normalized(
                    name,
                    locked_versions.get(name),
                    None,
                    "SECURITY",
                    "SECURITY_ACTION",
                    "composer audit --locked + composer.lock",
                    "Composer security advisory affects a locked transitive dependency",
                    "open a bounded project maintenance task; do not auto-update",
                    observed_at,
                )
            )
        for name in sorted((abandoned_names - advisory_names) - direct_names):
            components.append(
                normalized(
                    name,
                    locked_versions.get(name),
                    None,
                    "EOL",
                    "EOL",
                    "composer audit --locked + composer.lock",
                    "Composer reports a locked transitive dependency as abandoned/unsupported",
                    "plan supported replacement/removal through the owning direct dependency",
                    observed_at,
                )
            )

    installed_playwright = None
    declared_playwright = None
    if isinstance(package_json, dict):
        declared_playwright = (package_json.get("devDependencies") or {}).get("@playwright/test")
    if isinstance(package_lock, dict):
        packages = package_lock.get("packages")
        if isinstance(packages, dict):
            entry = packages.get("node_modules/@playwright/test")
            if isinstance(entry, dict) and entry.get("version"):
                installed_playwright = str(entry["version"])

    if installed_playwright:
        available = playwright_latest_data.strip() if npm_ok else None
        if not npm_ok:
            health = "UNKNOWN"
            update_class = "UNKNOWN"
            impact = "installed Playwright observed; npm latest metadata unavailable"
            action = "restore npm registry evidence before concluding project state"
        elif available == installed_playwright:
            health = "CURRENT"
            update_class = "NONE"
            impact = f"project-owned Playwright matches npm latest; declared={declared_playwright}"
            action = "none"
        else:
            health = "UPDATE_AVAILABLE"
            update_class = classify_update(installed_playwright, available)
            impact = f"project-owned Playwright update available; declared={declared_playwright}"
            action = "validate browser suite in Agency before changing package-lock.json"
        components.append(
            normalized(
                "npm:@playwright/test",
                installed_playwright,
                available,
                update_class,
                health,
                "package.json + package-lock.json + npm registry",
                impact,
                action,
                observed_at,
            )
        )
    else:
        components.append(
            normalized(
                "npm:@playwright/test",
                None,
                playwright_latest_data.strip() if npm_ok else None,
                "UNKNOWN",
                "UNKNOWN",
                "package.json + package-lock.json",
                "locked Playwright version unavailable",
                "restore package-lock evidence",
                observed_at,
            )
        )

    overall = max(
        (component["health"] for component in components),
        key=lambda health: HEALTH_ORDER.get(health, 2),
        default="UNKNOWN",
    )
    evidence_status = "complete" if all(value == "complete" for value in evidence.values()) else "incomplete"

    return {
        "schema_version": 1,
        "host": "agency-website",
        "platform": "project-dependencies",
        "repository_sha": repository_sha,
        "collected_at_utc": observed_at,
        "collector_identity": "github-actions",
        "overall": overall,
        "evidence_status": evidence_status,
        "evidence": evidence,
        "components": components,
    }


def write_atomic(path: Path, payload: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f"{path.name}.tmp")
    temporary.write_text(payload, encoding="utf-8")
    temporary.replace(path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--show", required=True)
    parser.add_argument("--outdated", required=True)
    parser.add_argument("--audit", required=True)
    parser.add_argument("--composer-lock", default="composer.lock")
    parser.add_argument("--package-json", default="package.json")
    parser.add_argument("--package-lock", default="package-lock.json")
    parser.add_argument("--playwright-latest", required=True)
    parser.add_argument("--config", required=True)
    parser.add_argument("--repository-sha", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    config = read_json(Path(args.config))
    if not isinstance(config, dict) or config.get("version") != 1:
        raise SystemExit("invalid dependency maintenance config")

    latest_raw = read_json(Path(args.playwright_latest))
    playwright_latest = latest_raw if isinstance(latest_raw, str) else None

    observed_at = datetime.now(timezone.utc).isoformat()
    payload = build_snapshot(
        read_json(Path(args.show)),
        read_json(Path(args.outdated)),
        read_json(Path(args.audit)),
        read_json(Path(args.composer_lock)),
        read_json(Path(args.package_json)),
        read_json(Path(args.package_lock)),
        playwright_latest,
        config,
        args.repository_sha,
        observed_at,
    )
    rendered = json.dumps(payload, indent=2, sort_keys=True)
    write_atomic(Path(args.output), rendered)
    print(rendered)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

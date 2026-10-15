#!/usr/bin/env python3
"""Immutable one-shot Agency #1432 full-lock JSON validator (no shell/network)."""
import argparse
import json
import re
from pathlib import Path

CORE = (
    "drupal/core",
    "drupal/core-recommended",
    "drupal/core-composer-scaffold",
    "drupal/core-project-message",
    "drupal/core-recipe-unpack",
    "drupal/core-dev",
)


def entries(lock):
    output = {}
    for scope in ("packages", "packages-dev"):
        pkgs = lock.get(scope)
        if not isinstance(pkgs, list):
            raise ValueError(f"Missing locked {scope} array")
        for item in pkgs:
            name = item.get("name")
            version = item.get("version")
            if not isinstance(name, str) or not isinstance(version, str) or name in output:
                raise ValueError(f"Invalid or duplicate package: {name!r}")
            output[name] = (version, scope, item)
    return output


def version_tuple(raw):
    parsed = re.fullmatch(r"v?([0-9]+)\.([0-9]+)\.([0-9]+)(?:\.([0-9]+))?", raw)
    return tuple(int(v or 0) for v in parsed.groups()) if parsed else None


def category(name, scope):
    if scope == "packages-dev":
        return "dev"
    if name in CORE:
        return "core"
    if name.startswith(("drupal/ai", "drupal/canvas")):
        return "ai-canvas"
    if name.startswith("drupal/"):
        return "contrib"
    if name.startswith("symfony/"):
        return "symfony"
    return "php-other"


def analyze(original, updated):
    old = json.loads(Path(original).read_text(encoding="utf-8"))
    new = json.loads(Path(updated).read_text(encoding="utf-8"))
    if old.get("content-hash") != new.get("content-hash"):
        raise ValueError("Composer root manifest content-hash changed")
    previous, current = entries(old), entries(new)
    core_versions = [current[name][0] for name in CORE]
    core = version_tuple(core_versions[0])
    if len(set(core_versions)) != 1 or core is None or core[0] != 11 or core[:3] < (11, 4, 10):
        raise ValueError("Unpatched, unstable or inconsistent Drupal Core")
    expression = current.get("symfony/expression-language")
    if expression is None or expression[1] != "packages":
        raise ValueError("Expected production Symfony ExpressionLanguage missing")
    symfony = version_tuple(expression[0])
    if symfony is None or symfony[0] != 7 or symfony[:3] < (7, 4, 21):
        raise ValueError("ExpressionLanguage remains vulnerable or changed major")
    changes = []
    for name in sorted(set(previous) | set(current)):
        before = previous.get(name)
        after = current.get(name)
        if before == after:
            continue
        before_v = before[0] if before else None
        after_v = after[0] if after else None
        if after and before_v != after_v:
            new_v = version_tuple(after_v)
            if new_v is None:
                raise ValueError(f"Updated package uses unstable version: {name} {after_v}")
            old_v = version_tuple(before_v) if before_v else None
            if old_v and (old_v[0] != new_v[0] or new_v < old_v):
                raise ValueError(f"Unreviewed major/downgrade: {name} {before_v} -> {after_v}")
        scope = after[1] if after else before[1]
        changes.append({
            "name": name,
            "from": before_v,
            "to": after_v,
            "scope": scope,
            "group": category(name, scope),
            "metadata_changed": bool(before and after and before[2] != after[2]),
        })
    if not changes:
        raise ValueError("No Composer lock update materialized")
    return {
        "core_version": core_versions[0],
        "expression_language_version": expression[0],
        "changed_count": len(changes),
        "package_changes": changes,
        "audit_status": 0,
        "advisory_count": 0,
    }


def main():
    args = argparse.ArgumentParser()
    args.add_argument("original")
    args.add_argument("updated")
    evidence = args.add_mutually_exclusive_group(required=True)
    evidence.add_argument("--audit")
    evidence.add_argument("--verify-result")
    options = args.parse_args()
    proof = analyze(options.original, options.updated)
    if options.audit:
        audit = json.loads(Path(options.audit).read_text(encoding="utf-8"))
        advisories = audit.get("advisories")
        if not isinstance(advisories, (dict, list)) or bool(advisories):
            raise ValueError("Missing audit inventory or nonzero security advisories")
        print(json.dumps(proof, sort_keys=True))
    else:
        envelope = json.loads(Path(options.verify_result).read_text(encoding="utf-8"))
        if envelope.get("mode") != "full-update" or envelope.get("full_update") != proof:
            raise ValueError("Published lock and audit receipt do not match")
        print(json.dumps({"verified": True, "changed_count": proof["changed_count"]}))


if __name__ == "__main__":
    main()

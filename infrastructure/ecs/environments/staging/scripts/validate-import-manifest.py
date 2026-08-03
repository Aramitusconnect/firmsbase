#!/usr/bin/env python3
"""Validate infrastructure/ecs/environments/staging/import-manifest.json.

Exits non-zero and prints every violation found (not just the first) if:
  - an address is missing a classification;
  - an address appears more than once;
  - a classification value is not one of the 5 allowed buckets;
  - the manifest's declared summary counts don't match a fresh recount of
    the resources array, or don't sum to the declared total;
  - an import_unchanged/import_then_migrate entry lacks an import_id
    (a non-null placeholder such as "BLOCKED" counts as present — the
    point is that the gap must be explicit, never silently missing);
  - a new/unmanaged/do_not_import entry has a non-null import_id (i.e.
    carries an implied import command it must not have).

This script only reads the manifest; it does not touch AWS, Terraform
state, or the .tf source. See docs/ecs/state-adoption-plan.md.
"""
import json
import sys
from pathlib import Path

MANIFEST_PATH = Path(__file__).resolve().parents[1] / "import-manifest.json"
ALLOWED_CLASSIFICATIONS = {
    "import_unchanged",
    "import_then_migrate",
    "new",
    "unmanaged",
    "do_not_import",
}
IMPORT_CLASSIFICATIONS = {"import_unchanged", "import_then_migrate"}
NO_IMPORT_CLASSIFICATIONS = {"new", "unmanaged", "do_not_import"}


def fail(errors):
    print(f"FAIL: {len(errors)} manifest violation(s) found in {MANIFEST_PATH}\n", file=sys.stderr)
    for e in errors:
        print(f"  - {e}", file=sys.stderr)
    sys.exit(1)


def main():
    errors = []

    try:
        raw = MANIFEST_PATH.read_text()
    except OSError as exc:
        print(f"FAIL: cannot read {MANIFEST_PATH}: {exc}", file=sys.stderr)
        sys.exit(1)

    try:
        manifest = json.loads(raw)
    except json.JSONDecodeError as exc:
        print(f"FAIL: {MANIFEST_PATH} is not valid JSON: {exc}", file=sys.stderr)
        sys.exit(1)

    resources = manifest.get("resources")
    if not isinstance(resources, list) or not resources:
        print(f"FAIL: {MANIFEST_PATH} has no non-empty 'resources' array.", file=sys.stderr)
        sys.exit(1)

    seen_addresses = {}
    recount = {}

    for i, r in enumerate(resources):
        addr = r.get("address")
        cls = r.get("classification")
        import_id = r.get("import_id")

        if not addr:
            errors.append(f"resources[{i}]: missing 'address'")
            addr = f"<missing address at index {i}>"

        if addr in seen_addresses:
            errors.append(
                f"duplicate address: '{addr}' appears at indices "
                f"{seen_addresses[addr]} and {i}"
            )
        else:
            seen_addresses[addr] = i

        if not cls:
            errors.append(f"'{addr}': missing 'classification'")
            continue

        if cls not in ALLOWED_CLASSIFICATIONS:
            errors.append(
                f"'{addr}': classification '{cls}' is not one of "
                f"{sorted(ALLOWED_CLASSIFICATIONS)}"
            )
            continue

        recount[cls] = recount.get(cls, 0) + 1

        if cls in IMPORT_CLASSIFICATIONS and import_id is None:
            errors.append(
                f"'{addr}': classification '{cls}' requires a non-null "
                f"import_id (use the literal string \"BLOCKED\" with a "
                f"'prerequisite' explanation if the exact ID isn't known yet — "
                f"never leave it null)"
            )

        if cls in NO_IMPORT_CLASSIFICATIONS and import_id is not None:
            errors.append(
                f"'{addr}': classification '{cls}' must not carry an "
                f"import_id (found {import_id!r}) — {cls} entries have no "
                f"import command"
            )

    declared_summary = manifest.get("summary")
    if not isinstance(declared_summary, dict):
        errors.append("top-level 'summary' object is missing")
    else:
        declared_total = declared_summary.get("total")
        recounted_total = sum(recount.values())

        if declared_total != recounted_total:
            errors.append(
                f"summary.total={declared_total!r} does not match a fresh "
                f"recount of resources[]={recounted_total}"
            )

        for cls in ALLOWED_CLASSIFICATIONS:
            declared = declared_summary.get(cls, 0)
            actual = recount.get(cls, 0)
            if declared != actual:
                errors.append(
                    f"summary['{cls}']={declared!r} does not match a fresh "
                    f"recount of resources[]={actual}"
                )

        sum_of_buckets = sum(declared_summary.get(c, 0) for c in ALLOWED_CLASSIFICATIONS)
        if declared_total is not None and sum_of_buckets != declared_total:
            errors.append(
                f"summary classification counts sum to {sum_of_buckets}, "
                f"which does not equal summary.total={declared_total!r}"
            )

    if errors:
        fail(errors)

    print(
        f"PASS: {len(resources)} resource addresses, all uniquely classified, "
        f"summary counts match a fresh recount."
    )
    print(json.dumps(declared_summary, indent=2))


if __name__ == "__main__":
    main()

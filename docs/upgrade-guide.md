# Upgrade guide — legacy releases to 1.0.0-rc.1

## Before upgrading

1. Back up the database, `wp-content` and the currently installed Toolkit ZIP.
2. Record the active Toolkit, WordPress, PHP, Elementor and WooCommerce versions.
3. Do not remove legacy options, CCT tables, Elementor revisions or widgets.
4. Treat this build as a release candidate until the compatibility and human
   approval gates in `docs/compatibility.md` are closed.

## Install safely

Replace the plugin files and activate the build. Activation installs or upgrades
dedicated Toolkit infrastructure only. It does not import definitions, rewrite
Elementor documents, migrate content or publish a Blueprint.

Open **Toolkit > Diagnostics** and confirm infrastructure health. An unavailable
WooCommerce adapter is expected when WooCommerce is absent; it is not a passing
live canary.

## Evaluate or import one Blueprint at a time

This RC supports checksum-bound, read-only shadow import and comparison. It is
not certified for production cutover. Do not confirm apply when the Impact Plan
requires a destructive field/storage transformation, a parameterized route or
an unhealthy adapter; those capability gates remain closed.

1. Review the legacy inventory and its observed record/revision counts.
2. Select only the intended sources and choose **Prepare selected drafts**.
3. Review the checksum-bound selection, then confirm draft creation.
4. Inspect every comparison: count, statuses, data checksum, normalized HTML
   checksum where applicable and query budget.
5. Correct mismatches before considering publication. Never treat partial proof
   as authority to switch runtime.
6. Open the imported System, validate it and review its concrete Impact Map.
7. For a supported non-destructive plan, confirm apply only after the affected
   pages, widgets, fields, records and adapters are understood. Keep production
   cutover blocked for this RC.
8. Reconcile any applied evaluation version before importing the next Blueprint.

Imported drafts preserve stable field IDs and legacy storage aliases. Published
storage keys and slugs are never renamed silently. Elementor revisions are
backup evidence and do not count as active usage.

## Roll back

Rollback reactivates an earlier immutable Blueprint version and its artifacts.
It does not delete data written after that version. Record a factual reason,
reactivate the prior version and reconcile the runtime again.

The legacy widget and legacy administration recovery links remain supported
during 1.x. Keep them until the Impact Map proves usage is zero and a future
major release explicitly removes them.

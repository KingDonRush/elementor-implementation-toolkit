=== Elementor Implementation Toolkit ===
Contributors: guilhermesilva
Tags: elementor, custom content, dynamic tags, filters, frontend forms
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0-rc.1
License: Proprietary

A contract-driven implementation toolkit for structured WordPress systems and Elementor presentation.

== Description ==

Elementor Implementation Toolkit compiles an executable Blueprint into stable field contracts, WordPress or adapter storage, frontend Entry Surfaces, Collections, Filter Surfaces, Elementor connectors, policies and diagnostic evidence.

Implementers work with public names and stable IDs. Runtime requests do not accept arbitrary PHP, SQL, meta keys or selectors. Elementor remains responsible for layout, styling and responsive composition; WooCommerce remains authoritative for product transactions.

The 1.0 release candidate includes:

* CPT, indexed CCT and adapter storage recommendations with explicit overrides;
* 28 semantic field primitives, normalized relations and repeatable groups;
* frontend create/update workflows with policy, ownership and idempotency;
* bounded Collection queries, filters, facets, URL state and Explain Why evidence;
* five Elementor Free connector widgets and optional typed Dynamic Tags;
* compiler-bound read-only legacy shadow comparison across raw, candidate and active authorities;
* recoverable Blueprint apply and rollback for supported non-destructive plans;
* concrete Impact Maps, redacted Flight Recorder events and reproducible QA scenarios.

Legacy CPT, CCT, preset and Filter Controller surfaces remain available during the 1.x compatibility window. Installation and activation never migrate content automatically.

This build can be packaged deterministically for controlled evaluation, but it is not public-ready while the documented compatibility, migration-capability and human visual gates remain open. Its current proprietary license is not represented as WordPress.org repository eligibility.

== Installation ==

1. Back up the WordPress database and plugin files.
2. Upload the ZIP through Plugins > Add New > Upload Plugin.
3. Activate the plugin. Activation installs Toolkit infrastructure only.
4. Open Toolkit > Diagnostics and inspect runtime health.
5. Follow the bundled upgrade guide before importing or publishing a Blueprint.

== Frequently Asked Questions ==

= Does activation convert existing content? =

No. Legacy inspection is read-only. Draft creation requires prepare and confirm; publication uses a separate impact plan and confirmation.

= Does it replace Elementor Theme Builder or WooCommerce? =

No. Elementor owns visual composition. WooCommerce owns price, stock, cart, checkout, orders and payments.

= Does uninstall remove data? =

No. Default uninstall preserves Toolkit options and tables. An explicit developer constant is required for destructive purge; read the bundled uninstall guide first.

== Changelog ==

= 1.0.0-rc.1 =

* Added checksum-bound shadow import, diagnostics, Impact Map and release-candidate packaging.
* Added durable storage claims, interrupted-publication recovery and fail-closed extension contracts.
* Hardened frontend media, relation selection, exact multivalue filtering and factual Explain Why output.
* Verified four compatible local shadow pilots without switching runtime; the `projects` CCT remains explicitly blocked by its unsupported filterable textarea capability.
* Added four non-distributed domain sufficiency fixtures using one primitive grammar.
* Preserved WooCommerce live canary and human visual approval as open release gates.

== Upgrade Notice ==

= 1.0.0-rc.1 =

This is an internal release-candidate build, not a public-ready declaration. Back up first and inspect the compatibility matrix. Evaluate or import one Blueprint at a time through shadow comparison; do not use this build for production cutover while migration-capability gates remain open.

# Elementor Implementation Toolkit

V0.5.0 introduces the executable `eit.dev/v1 Blueprint` kernel behind the
existing dogfood surfaces. A canonical Blueprint now validates stable UUIDs,
typed connections, orphan references, dependency cycles and complete Field
Contracts before deterministic compilation.

Drafts do not mutate runtime. Publication follows `save draft -> validate ->
prepare impact -> confirm -> apply -> reconcile`; published versions and
artifacts are immutable, and rollback reactivates an earlier version without
deleting later data. The visual Systems map and its administrative REST UI are
the next delivery wave, not hidden inside this kernel release.

V0.4.0 remains the trust baseline underneath the compiler: stable published
slugs and keys, verified CCT schema changes, exact legacy matching, public status
boundaries, bounded requests, latest-response-wins concurrency and accessible
loading/error behavior remain enforced.

V0.3.0 added table-backed Custom Content Types for implementation data that does
not need WordPress singles. CCT records can be queried by the Filter Controller
and exposed through CCT Dynamic Tags. Elementor Loop Grid/Carousel rendering is
available only when the local Elementor Pro / Loop Builder runtime exposes the
required Loop APIs.

The widget is intentionally parasitic: it does not render its own grid. It detects
an existing listing on the page, lets the implementer select that target in the
Elementor editor, and filters the existing cards through AJAX.

## Current Scope

### Blueprint Kernel

- canonical `eit.dev/v1 Blueprint` documents with position-independent checksums;
- four executable lanes and typed node/connection validation;
- 27 semantic field primitives with validation, exposure, storage, indexing,
  entry-component, Elementor-category and query-capability contracts;
- explainable CPT/CCT/adapter recommendation with reasoned override gating;
- deterministic artifacts for definitions, storage, capabilities, fields,
  relations, entries, Collections, filters, presentations, routes and policies;
- stable Field ID bindings with legacy raw-key aliases;
- immutable versions, confirmable change sets, expiring locks, redacted runs,
  reconciliation proofs and non-destructive rollback;
- normalized relation and repeatable-group child storage;
- read-only deterministic shadow import for current CPT, CCT and filter-preset
  options; activation installs infrastructure but never migrates content;
- versioned PHP extension contracts for field primitives, storage adapters,
  Collection providers, form actions and presentation adapters.

The existing CPT/CCT and Filter Controller screens remain compatibility
surfaces during the 1.x migration window. Active compiled Entity artifacts are
projected into the existing registrars without writing back into legacy options.

- Elementor widget category: `Elementor Implementation Toolkit`
- Widget: `Filter Controller`
- Admin menu: `Implementation Toolkit`
- Filter preset manager with Elementor filter-control template handoff
- Lightweight Post Types manager for custom post types, taxonomies, and typed fields
- Custom Content Types stored in dedicated tables
- Optional Toolkit CCT skin for Elementor Pro Loop Grid and Loop Carousel
- CCT Dynamic Tags for text, URL, image, and gallery values
- Server-side CCT filtering and pagination through the Filter Controller
- Provider/runtime configuration summary for the current filtering surface
- Editor listing detection with hover highlight
- Manual CSS selector fallback
- DOM-provider filtering for existing listings
- AJAX filtering, sorting, active chips, result count, reset, and pagination
- Style controls for fields, options, chips, buttons, pagination, and states
- Public request limits: 32 KB body, 20 filters, 24 default items, 48 maximum
- Legacy DOM fallback limited to 200 items without server-side enrichment

## Admin Tools

The admin area is an operational backend surface, not a second page builder.
Elementor remains responsible for layout, placement, preview, and visual styling.
The WordPress backend is used for reusable structures that should survive across
pages and projects:

- reusable Filter Presets consumed by the Elementor widget or a plugin-owned
  Elementor template;
- compact Post Types for custom post types, taxonomies, and typed fields;
- Content Types for structured listings that do not need posts or permalinks;
- provider and diagnostic status for the current filtering runtime.

Local visual assets live in `assets/images/icons/` as transparent, tightly
cropped WebP files. The palette/tokens used by the admin surface are documented
in `assets/design/palette.json`.

### Filter Presets

Filter presets move reusable behavior out of the Elementor widget panel:

- apply mode, URL sync, result count, active chips, empty copy, and pagination;
- filter definitions for search, checkbox, radio, select, chips, toggle, range,
  date, swatches, and rating;
- advanced DOM selector/query metadata when the fallback needs help;
- Elementor filter-control template creation so layout and styling happen in
  Elementor instead of a custom admin builder.

The widget can still use inline controls, but when `Configuration Source` is set
to `Admin filter preset`, the preset supplies the filter definitions and runtime
behavior. The widget remains responsible for placement and visual styling.

### Post Types

The Post Types manager is intentionally compact. It registers stored definitions
with native WordPress APIs:

- custom post type labels, menu icon, and description;
- advanced visibility, REST exposure, archives, rewrite slug, hierarchy, and
  supports;
- taxonomies attached to the managed post type;
- repeatable typed fields rendered in a native meta box.

Supported field types include text, textarea, number, URL, email, date, time,
date/time, checkbox, select, radio, color, image, and gallery. Image and gallery
fields use the WordPress media selector while preserving existing URL-backed
values.

The WordPress editor is not enabled by default. Selecting the `editor` support
is an explicit editorial decision; structured post types otherwise use the
Toolkit fields without accidentally exposing Gutenberg as their content model.
Required fields block publication through both the classic save path and REST,
while valid values such as `0` remain accepted.

Deleting a post type definition unregisters the structure on the next request.
It does not delete posts, terms, or post meta.

### Content Types

Content Types are table-backed records intended for implementation data such as
portfolio projects, directories, catalogs, and comparison entries:

- one dedicated `{prefix}eit_cct_{slug}` table per definition;
- typed fields, searchable/filterable flags, status, and manual order;
- native WordPress CRUD screens without an automatic permalink or single;
- archive/restore lifecycle that retains definitions, columns, and records;
- explicit permanent deletion available only for archived definitions;
- verified columns and indexes before a definition becomes the active runtime;
- public queries restricted to published rows;
- normal Elementor Loop Item templates populated through CCT Dynamic Tags when
  Elementor Pro / Loop Builder support is present.

Removing a field from a definition marks it inactive. Its database column and
stored values remain available for a future restoration or migration.

### Providers / Diagnostics

The Diagnostics area reports current configuration facts. It does not certify
that a page-level Elementor layout has been QA'd.

- bounded DOM provider for existing Elementor, WooCommerce, JetEngine, and
  generic listings when usable item data is already present in the snapshot;
- CCT provider for direct table queries and Loop Item rendering when the local
  Elementor runtime supports it.

Deep adapters remain future work until a real project needs them. The admin no
longer offers a custom adapter mode as a normal setup path.

## Data Contract

The legacy controller works best when each listing item exposes at least one of:

- visible text;
- filterable attributes such as `data-eit-category`, `data-eit-price`, `data-eit-material`, `data-eit-rating`;
- child fields using `data-eit-field="category"` and optional `data-eit-value`.

Opaque third-party listings can still be detected and highlighted, but the
server no longer performs N+1 post enrichment. Advanced filters need usable
snapshot data, the CCT provider, or a future Collection adapter.

## Local Development

The current workstation runs PHPUnit and PHPCS inside the WordPress PHP 8.3
container because its host PHP intentionally lacks the XML extensions required
by those tools. Install the locked dependencies locally with the matching
platform extensions, or on this workstation with the explicit Composer
platform exceptions below:

```bash
composer install --ignore-platform-req=ext-dom --ignore-platform-req=ext-simplexml --ignore-platform-req=ext-xml --ignore-platform-req=ext-xmlwriter
npm ci
```

Run the kernel and trust-baseline suite:

```bash
composer validate --strict --no-check-publish
composer lint
composer phpcs
composer analyse -- --no-progress
composer test
composer test:wp
npm run build
npm test -- --run
npm run check:js
npm run test:e2e
gitleaks git --redact --no-banner --exit-code 1
```

Activate in the local WordPress runtime:

```bash
../../../scripts/wp.sh plugin activate elementor-implementation-toolkit
```

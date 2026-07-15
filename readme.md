# Elementor Implementation Toolkit

V0.3.2 makes the current runtime claims explicit and brings WordPress media
selection to Toolkit-managed CPT image and gallery fields while preserving
existing URL-backed values.

V0.3.0 added table-backed Custom Content Types for implementation data that does
not need WordPress singles. CCT records can be queried by the Filter Controller
and exposed through CCT Dynamic Tags. Elementor Loop Grid/Carousel rendering is
available only when the local Elementor Pro / Loop Builder runtime exposes the
required Loop APIs.

The widget is intentionally parasitic: it does not render its own grid. It detects
an existing listing on the page, lets the implementer select that target in the
Elementor editor, and filters the existing cards through AJAX.

## Current Scope

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
- normal Elementor Loop Item templates populated through CCT Dynamic Tags when
  Elementor Pro / Loop Builder support is present.

Removing a field from a definition marks it inactive. Its database column and
stored values remain available for a future restoration or migration.

### Providers / Diagnostics

The Diagnostics area reports current configuration facts. It does not certify
that a page-level Elementor layout has been QA'd.

- DOM provider for existing Elementor, WooCommerce, JetEngine, and generic
  listings when usable item data is present;
- best-effort WordPress enrichment when listing items expose a local post ID or
  permalink;
- CCT provider for direct table queries and Loop Item rendering when the local
  Elementor runtime supports it.

Deep adapters remain future work until a real project needs them. The admin no
longer offers a custom adapter mode as a normal setup path.

## Data Contract

The controller works best when each listing item exposes at least one of:

- a local permalink;
- a post ID through `data-eit-post-id`, `data-post-id`, `data-id`, or classes such as `post-123`;
- visible text;
- filterable attributes such as `data-eit-category`, `data-eit-price`, `data-eit-material`, `data-eit-rating`;
- child fields using `data-eit-field="category"` and optional `data-eit-value`.

Opaque third-party listings can still be detected and highlighted, but advanced
filters need usable item data or a future adapter.

## Local Development

```bash
composer dump-autoload
docker compose run --rm --entrypoint sh wpcli -lc 'find /var/www/html/wp-content/plugins/elementor-implementation-toolkit -name "*.php" -print0 | xargs -0 -n1 php -l'
node --check assets/js/eit-frontend.js
node --check assets/js/eit-editor.js
node --check assets/js/eit-admin.js
```

Activate in the local WordPress runtime:

```bash
../../../scripts/wp.sh plugin activate elementor-implementation-toolkit
```

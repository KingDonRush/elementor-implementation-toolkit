# Elementor Implementation Toolkit

V0.2.1 starts with a Filter Controller widget for Elementor and a practical
WordPress admin layer for reusable implementation settings.

The widget is intentionally parasitic: it does not render its own grid. It detects
an existing listing on the page, lets the implementer select that target in the
Elementor editor, and filters the existing cards through AJAX.

## Current Scope

- Elementor widget category: `Elementor Implementation Toolkit`
- Widget: `Filter Controller`
- Admin menu: `Implementation Toolkit`
- Filter preset manager with an Elementor template bridge
- Lightweight Post Types manager for custom post types, taxonomies, and typed fields
- Providers / Diagnostics status for the current filtering runtime
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
- provider and diagnostic status for the current filtering runtime.

Local visual assets live in `assets/images/icons/` as transparent, tightly
cropped WebP files. The palette/tokens used by the admin surface are documented
in `assets/design/palette.json`.

### Filter Presets

Filter presets move reusable behavior out of the Elementor widget panel:

- apply mode, URL sync, result count, active chips, empty copy, and pagination;
- filter definitions for search, checkbox, radio, select, chips, toggle, range,
  date, swatches, and rating;
- advanced provider/selector/query metadata when the DOM fallback needs help;
- Elementor template creation so layout and styling happen in Elementor instead
  of a custom admin builder.

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
date/time, checkbox, select, radio, color, image URL, and gallery URLs.

Deleting a post type definition unregisters the structure on the next request.
It does not delete posts, terms, or post meta.

### Providers / Diagnostics

The Diagnostics area is intentionally small in V0.2. It reports the providers
that are real today:

- DOM provider for existing Elementor, WooCommerce, JetEngine, and generic
  listings;
- WordPress enrichment when listing items expose a local post ID or permalink.

Deep adapters remain future work until a real project needs them.

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
docker compose run --rm --entrypoint sh wpcli -lc 'find /var/www/html/wp-content/plugins/elementor-implementation-toolkit -name "*.php" -print0 | xargs -0 -n1 php -l'
node --check assets/js/eit-frontend.js
node --check assets/js/eit-editor.js
node --check assets/js/eit-admin.js
```

Activate in the local WordPress runtime:

```bash
../../../scripts/wp.sh plugin activate elementor-implementation-toolkit
```

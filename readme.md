# Elementor Implementation Toolkit

V0.2 starts with a Filter Controller widget for Elementor and a product-grade
WordPress admin layer for implementation-heavy settings.

The widget is intentionally parasitic: it does not render its own grid. It detects
an existing listing on the page, lets the implementer select that target in the
Elementor editor, and filters the existing cards through AJAX.

## Current Scope

- Elementor widget category: `Elementor Implementation Toolkit`
- Widget: `Filter Controller`
- Admin menu: `Implementation Toolkit`
- Filter preset manager for backend filter minutiae
- Lightweight CPT manager for custom post types, taxonomies, and typed meta fields
- Integrations / Superpowers admin contracts saved in `eit_integration_patterns`
- Editor listing detection with hover highlight
- Manual CSS selector fallback
- DOM-provider filtering for existing listings
- AJAX filtering, sorting, active chips, result count, reset, and pagination
- Style controls for fields, options, chips, buttons, pagination, and states

## Admin Tools

The admin UI now uses the Toolkit's own architecture-builder language inside the
normal WordPress admin chrome. Filter presets, CPT definitions, and Integration
modules are shown as architecture layers, runtime boundaries, and contextual
inspectors so the interface explains what wraps, influences, or connects to each
backend option.

Local visual assets live in `assets/images/icons/` as transparent, tightly
cropped WebP files. The palette/tokens used by the admin surface are documented
in `assets/design/palette.json`.

### Filter Presets

Filter presets move the noisy configuration out of the Elementor widget panel:

- provider mode and target/item selector defaults;
- apply mode, URL sync, result count, active chips, empty copy, and pagination;
- sort labels/options;
- filter definitions for search, checkbox, radio, select, chips, toggle, range,
  date, swatches, and rating;
- backend metadata such as source type, compare mode, data type, query var,
  default value, and future count behavior.
- structured builders for sort rules, filter choices, and select choices. The
  admin UI does not require line-coded textarea input.

The widget can still use inline controls, but when `Configuration Source` is set
to `Admin filter preset`, the preset supplies the filter definitions and runtime
behavior. The widget remains responsible for placement and visual styling.

### CPT Manager

The CPT manager is intentionally compact. It registers stored definitions with
native WordPress APIs:

- custom post type labels, visibility, REST exposure, archives, rewrite slug,
  hierarchy, menu icon, and supports;
- taxonomies attached to the managed post type;
- typed meta fields rendered in a native meta box.

Deleting a CPT definition unregisters the structure on the next request. It does
not delete posts, terms, or post meta.

### Integrations / Superpowers

The Integrations area is an admin contract layer for future Toolkit modules. In
V0.2 these modules save configuration, status, and preview state, but do not
claim full runtime adapters yet:

- Simple Budget Bridge;
- WooCommerce Card Adapter;
- Mobile Filter Panel;
- Listing Target Detector;
- URL State Router;
- Conditional Display Rules;
- Design Token Mapper;
- Editor Handoff Notes;
- QA Scenario Runner;
- Connector Registry.

Each module has a fixed product identity, status (`active`, `draft`, or
`degraded`), scoped configuration fields, a contextual inspector, and a contract
preview modal. Deep integrations stay optional and adapter-based.

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

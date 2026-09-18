# Elementor Implementation Toolkit

A WordPress plugin that turns declarative blueprints into content structures and
Elementor-facing interfaces. A blueprint describes entities, fields, relations,
entry forms, collections and presentation routes; the compiler produces artifacts
through storage and presentation contracts.

## From blueprint to implementation

Define the content model, validate the blueprint, inspect the compiled artifacts
and apply them to a development site. Forms and collections consume those contracts
rather than independently inventing a data shape. Diagnostics and validation expose
incompatible or destructive changes before they become an implementation step.

| Component | Source |
| --- | --- |
| Blueprint compilation | [Compiler](includes/Blueprint/Compiler.php) |
| PHP domain and change contracts | [PHP tests](tests/phpunit) |
| Form and collection behavior | [JavaScript tests](tests/js) |
| Browser workflows | [End-to-end scenarios](tests/e2e) |

The [Portuguese operational manual](docs/manual.pt-BR.md) contains the detailed
setup and builder workflow. The existing manual is retained because it describes
the product's Portuguese administration surface.

## Setup and verification

Requires PHP 8.1+, WordPress 6.7+ and Elementor. Version `1.0.0-rc.1` targets
new implementations; legacy migration remains experimental.

```bash
npm ci
npm test
npm run check:js
npm run build
php scripts/lint-php.php
```

The JavaScript suite has 37 tests. PHP syntax checks do not substitute for the
WordPress contract suite. Existing Composer integration scripts depend on the
Studio WordPress Docker topology; inspect their paths before running them in a
standalone checkout. This pass verified JavaScript behavior/builds and PHP syntax,
not the complete WordPress integration or browser suites.

The license declared in [composer.json](composer.json) is `proprietary`.
Source visibility does not grant an open-source license.

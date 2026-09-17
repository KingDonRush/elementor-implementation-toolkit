# Elementor Implementation Toolkit

A WordPress/PHP plugin for describing content structures and connecting them to
Elementor interfaces. Blueprints define entities, fields, relations, entry forms,
collections and presentation routes; the compiler produces implementation artifacts
through storage and presentation contracts.

**Independent project · 1.0.0-rc.1 · internal validation stage.** It targets new
implementations. Legacy migration remains experimental and needs staged validation.

## Review the system

| Question | Evidence |
| --- | --- |
| How does a blueprint become an implementation? | [Blueprint compiler](includes/Blueprint/Compiler.php) |
| How are invalid or destructive changes constrained? | [PHP contract tests](tests/phpunit) |
| How do forms and collections behave in the browser? | [JavaScript tests](tests/js), [end-to-end scenarios](tests/e2e) |
| How is it configured and operated? | [Complete Portuguese manual](docs/manual.pt-BR.md), [documentation](docs) |

This is custom plugin and implementation tooling beyond page assembly. It is not
an established SaaS product or evidence of broad customer adoption.

## Setup and verification

Requires PHP 8.1+, WordPress 6.7+ and Elementor. Install in a development WordPress
environment and follow the [manual](docs/manual.pt-BR.md) before enabling it.

```bash
npm ci
npm test
php scripts/lint-php.php
```

Review on 2026-09-17: 37 JavaScript tests passed and 359 PHP files passed syntax
checking on revision `f85e126823e19be2ac35d9727e0c37bb1ce42d93`.
This review did not execute WordPress integration or browser tests. The existing
Composer test scripts expect the Studio WordPress Docker topology; inspect those
paths before running them in a standalone checkout. Syntax checks are not PHP unit tests.

## Development method and authorship

This is an independent project, not evidence of an employer or a client engagement.
The source was produced primarily or entirely by AI coding agents under Guilherme
Manoel da Silva's direction. His contribution includes product intent, requirements,
constraints, decomposition, product and architectural decisions through the agent
interface, iteration, validation and documentation. The repository demonstrates
the resulting system and process; it does not imply that he manually wrote every
component or can reproduce it unaided from memory.

## Em português

Plugin de implementação para WordPress e Elementor: estruturas de conteúdo,
formulários, coleções, rotas e contratos de armazenamento a partir de blueprints.
O [manual completo](docs/manual.pt-BR.md) preserva as instruções operacionais.

Licensing remains as declared in [composer.json](composer.json) (`proprietary`).
Public source visibility is not an open-source license grant.

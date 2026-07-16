# Compatibility matrix — 1.0.0-rc.1

This matrix distinguishes target support from evidence observed in a live
runtime. A target row is not certified until its live canary passes.

| Layer | Target | Current evidence | RC gate |
| --- | --- | --- | --- |
| WordPress | 6.7–6.9 | Integration and browser suites pass on 6.9.4 | 6.7 and 6.8 live canaries open |
| PHP | 8.1–8.4 | PHPCompatibility targets 8.1+; suites pass on 8.3.31 | 8.1, 8.2 and 8.4 live canaries open |
| Elementor Free | 3.28–4.x | Editor/frontend canaries pass on 4.0.8 | 3.28 and one prior 4.x release open |
| Pro-compatible Dynamic Tags | current and prior | `ELEMENTOR_PRO_VERSION` 4.0.4.2 is observed through Pro Elements | Official Elementor Pro current/prior private canaries open |
| WooCommerce | current maintained release | Closed Field catalog and CRUD/query contracts pass in isolation | Live WooCommerce runtime absent and open |
| Browsers | current Chromium, Firefox, WebKit | Chromium Playwright coverage is local | Firefox and WebKit canaries open |

The current dogfood runtime has WordPress 6.9.4, PHP 8.3.31, Elementor Free
4.0.8 and a Pro-compatible 4.0.4.2 runtime. WooCommerce is not installed.

The plugin degrades explicitly when WooCommerce or Pro-compatible Dynamic Tags
are unavailable. Their schema remains inspectable, but publication that needs
an unhealthy adapter is blocked.

No row in this document replaces Guilherme's browser approval of map grammar,
inspector density, Elementor authoring, frontend workspaces or responsive
interaction.

This matrix supports internal RC hardening only. It does not close the separate
destructive-migration, parameterized-route or independent-cutover capability
gates documented in the release notes.

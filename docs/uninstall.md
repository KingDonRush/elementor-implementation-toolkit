# Safe uninstall

Uninstall is non-destructive by default. WordPress may remove the plugin files,
but Toolkit options, Blueprint history, diagnostics, normalized values and CCT
tables remain available for reinstall or manual recovery.

To request an irreversible purge, a developer must define the following in
`wp-config.php` before uninstalling:

```php
define( 'EIT_UNINSTALL_REMOVE_DATA', true );
```

Then uninstall the plugin through WordPress. The purge removes Toolkit
infrastructure tables, Toolkit CCT tables, definitions, presets, schema markers,
Collection cache generations and Toolkit transients for the current site.

The purge does not delete WordPress posts created under a Toolkit CPT and does
not delete Elementor documents, WooCommerce objects or external-provider data.
Without their definitions those records may no longer have an active editing
surface, so export or migrate them first.

Remove the constant after the operation. On multisite, destructive cleanup is
site-scoped; each site must be reviewed and handled deliberately.

# Povikvane SMS for WooCommerce

WooCommerce plugin that sends order status SMS (and opt-in marketing) messages
through the Povikvane Public API. Distributed through WordPress.org.

Requires WordPress 7.0, WooCommerce 11.0, PHP 8.3.

The development plan and API contract notes live in the Povikvane application
repository (`docs/woocommerce-plugin-plan.md`,
`docs/public-api-woocommerce-requirements.md`).

## Development

```bash
composer install
composer lint     # WordPress coding standards + PHP 8.3 compatibility
composer test     # PHPUnit unit tests
```

To try the plugin, symlink this folder into a local WordPress site's
`wp-content/plugins/` directory and activate it with WooCommerce.

Never commit API keys. Keep them in `wp-config.php` (`POVIKVANE_API_KEY`) or an
untracked `.env`.

# WooCommerce Variable Manager

Copyright © 2026 Eduardo Yauri. See [LICENSE](LICENSE). Public repository does not grant rights to the proprietary code; WordPress/WooCommerce and other third-party components keep their respective licenses.

## Scope: first development beta (0.1.0)

An independent WordPress plugin providing:
- **Variable Manager > Plantillas**: create a reusable template with sizes, colours, thickness/height and openings.
- **Products > Edit > Product data > Variable Manager**: link a published template to a **Simple** WooCommerce product and enter one price per size.
- **Storefront**: render selectors in the standard classic WooCommerce simple-product form; validate every required option in the server and calculate the chosen size's price from saved WooCommerce product metadata.
- **Cart & order**: retain selections in cart items and order-line metadata, including orders using HPOS.
- **Fail-closed checkout**: reject incomplete matrices and invalid or outdated configurations instead of selling at the minimum price.

Each size has its **own final price**, while optional color/thickness/opening fields have **no surcharge** in this first beta. Stock and SKU belong to the parent simple product, **not** individual measurements.

## Installation

Requires WordPress with WooCommerce active, PHP >= 8.1, and the **classic WooCommerce product editor**.

1. Copy this folder into `wp-content/plugins/woocommerce-variable-manager/`, or package the repository root as a ZIP with that folder name.
2. Activate **WooCommerce Variable Manager**.
3. From **Variable Manager > Add new**, create a template named e.g. "Canapé tapizado"; set measures `90x190, 135x190, 150x190`; optionally set colors, heights and openings; **Publish**.
4. Create a WooCommerce **Simple product**. In the **Variable Manager** product-data tab, choose the template and fill **every** size price. Save.
5. Open its product page using a classic WooCommerce product form and test options, cart and a test order.

The theme must render WooCommerce standard product hooks and normal form fields; Site Architect is not required and this plugin never writes theme settings.

## Architecture

- `woocommerce-variable-manager.php` bootstrap and dependency guard
- `includes/class-wvm-templates.php` reusable templates, classic WordPress editor
- `includes/class-wvm-pricing.php` strict size matrix validation / price lookup
- `includes/class-wvm-products.php` WooCommerce product editor + CRUD product metadata
- `includes/class-wvm-storefront.php` server-validated classic single product purchase and order metadata
- `assets/` vanilla JS and small CSS, no Gutenberg / Elementor / build pipeline
- `tests/pricing-smoke.php` price acceptance/rejection checks

## Limitations and next milestone

This is a development beta; **do not deploy to a production store without full WordPress/WooCommerce integration, payment, currency, tax, cache, theme and checkout tests**. The PHP/JS CI checks are only syntax and pricing-service smoke tests. WooCommerce Cart/Checkout Blocks and Store API integration are not certified; use classic checkout for this beta.

Next: UX improvements to the price matrix, optional surcharges and rule compatibility, import/export, stock by SKU where needed, and integration tests against current WooCommerce. Site Architect's greenfield theme write permissions are separately gated and are **not enabled by this plugin**.

The product editor is intentionally classic (not Gutenberg). It uses WooCommerce CRUD methods and hooks, not direct SQL writes to product/order tables.

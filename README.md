# WooCommerce Variable Manager

**0.5.0-beta · © 2026 Eduardo Yauri**

See [LICENSE](LICENSE) for original-code rights. This public repository is used for CI and engineering; rights to WordPress, WooCommerce and other third-party components remain under their respective licenses.

A lightweight, independent plugin for mattresses, ottoman beds, bases and configurable sleep products. It uses WooCommerce as the commerce engine and implements **one plugin-owned dashboard** for the product workflow, without Gutenberg, Elementor or heavy page builders.

## The only administration menu

**Variable Manager → Resumen:** product/template indicators and quick-start guide.

**Variable Manager → Plantillas:** reusable sizes, colors, heights/thicknesses and opening options. Starter presets for mattresses and canapés. Active templates cannot have their options changed incompatibly while in use.

**Variable Manager → Productos y tarifas:** create/search/edit simple WooCommerce products, including name, SKU, draft/publication status, full/short descriptions, category assignment, main image, general stock and availability. Link a template, edit final base prices for each size, paste semicolon-separated size/price rows from Excel and set nonnegative surcharges for colors, heights and openings. Block incompatible attribute *pairs* in a visual rule builder.

**Variable Manager → Importar / exportar:** download a CSV of existing managed product prices and upload edited prices for **up to 1,000 rows / 80 products** per batch. Upload creates an authenticated **preview without writes**; a second explicit confirmation applies changes. Only the selected size prices change; a full preflight rejects invalid SKUs/sizes, duplicate lines, incomplete configurations and products with WooCommerce sale prices. Concurrent changes between preview and apply cancel the import. Unchanged products are not saved. The importer restores prior prices on unexpected save failures on a best-effort basis, but cannot undo external WordPress hook side effects.

The configurator renders through classic WooCommerce product-form hooks, calculates a live estimate and checks all choices on the server. All final prices are recalculated by the server on cart calculation. Configuration is carried into cart item data and the corresponding order line. Archive cards open the configurator instead of using an invalid direct add-to-cart action.

WooCommerce retains all customers, products, orders, payments, taxes, coupons, shipments and primary stock data. Site Architect is independent; it does not administer product pricing or grant theme write authority.

## Development without a live subdomain

Synthetic fixtures are available at [tests/fixtures/catalog-synthetic.json](tests/fixtures/catalog-synthetic.json).

They contain **8 fictional products**, **112 possible configurations**, **93 valid** and **19 deliberately forbidden** combinations. Expected prices are explicit in EUR. No real inventory, customer details, payments or orders are used.

### Tests (no WordPress required)

```bash
php -l woocommerce-variable-manager.php
php tests/pricing-smoke.php
php tests/rules-smoke.php
php tests/synthetic-catalog.php
php tests/bulk-smoke.php
node --check assets/admin.js
node --check assets/storefront.js
```

### CI integration (disposable WordPress + real WooCommerce)

GitHub Actions also installs WordPress, WooCommerce and a MySQL service **inside an ephemeral job** and runs [tests/woocommerce-integration.php](tests/woocommerce-integration.php) to exercise real CRUD, stored templates, price rules, cart data/order metadata and fail-closed checks. A separate [WooCommerce bulk integration test](tests/woocommerce-bulk-integration.php) checks two-product CSV imports, selective price changes, rule/stock preservation, conflict detection, sale protection and export roundtrip. The seeded demo catalog is audited, seeded twice to check idempotence and then purged. A final check confirms that **demo seeding is refused when WP_ENVIRONMENT_TYPE=production**.

The test environment is destroyed after the run. It does not need `colchones.kairoseth.com` and cannot change a real shop.

### Synthetic catalog inside a future nonproduction WordPress install

The staging ZIP includes the test fixture and guarded WP-CLI commands:

```bash
wp wvm demo preview
wp wvm demo seed --confirm-synthetic=YES
wp wvm demo purge --confirm-synthetic=YES
```

The write commands require `WP_ENVIRONMENT_TYPE` to be `local`, `development` or `staging` **and** explicit `--confirm-synthetic=YES`. Seeds are created **only as non-sellable, hidden drafts**, marked with `_wvm_demo_synthetic`. The purge operation removes only records carrying that marker; never use it on a product you have repurposed from a demo.

No demo products are ever created on plugin activation, by the admin dashboard or without explicit CLI confirmation.

## Installing for staging

Requires PHP 8.1+, WordPress and active WooCommerce. Download the `woocommerce-variable-manager-0.5.0-beta-staging` artifact from the successful GitHub Actions workflow. Extract the plugin ZIP and upload it from WordPress → Plugins.

Open **Variable Manager** in the WP admin sidebar. The shop's theme must render classic WooCommerce product hooks; checkout blocks / Store API are **not yet certified**. For `colchones.kairoseth.com` setup and acceptance checks, see [docs/STAGING.md](docs/STAGING.md).

## Code structure

- `woocommerce-variable-manager.php` — bootstrapping and HPOS declaration.
- `includes/class-wvm-dashboard.php` — plugin-owned admin forms and product flow.
- `includes/class-wvm-templates.php` — internal reusable-option templates.
- `includes/class-wvm-products.php` — validated product configuration using WooCommerce CRUD.
- `includes/class-wvm-pricing.php` — strict per-size price matrix.
- `includes/class-wvm-rules.php` — server-side surcharges and incompatible option pairs.
- `includes/class-wvm-storefront.php` — classic product page, cart, order metadata.
- `includes/class-wvm-demo-cli.php` — guarded nonproduction demo preview/seed/purge.
- `includes/class-wvm-bulk.php` — CSV parsing, full preflight, price update and export using WooCommerce CRUD.
- `includes/class-wvm-bulk-admin.php` — nonce-protected bulk import preview, confirmation and export page.
- `assets/` — lightweight CSS and vanilla JavaScript.
- `tests/` — synthetic fixture, pricing tests and ephemeral WooCommerce integration.

## Known limitations

**Beta, not production certified.** Real staging still needs visual/accessibility checks, tax handling, coupons, multiple gateway types, shipping, refund behavior, HPOS, the custom theme and a complete order/checkout lifecycle.

The manager handles **simple products** that use a single parent SKU/stock pool. CSV imports only update existing price matrices; they do **not** import new products, tax settings, stock, photos or supplier catalogs. Stock per size or separate SKU, full product gallery, advanced multi-attribute logical expressions, customer-facing variation URLs, full supplier CSV updates and Store API/Checkout Blocks remain future milestones, added only where needed. The attribute price model supports **nonnegative fixed supplements**, not complex conditional discount stacks.

**Do not install this draft PR in production before staging acceptance.**

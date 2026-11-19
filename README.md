# WooCommerce Variable Manager

**0.9.0-beta · © 2026 Eduardo Yauri**

See [LICENSE](LICENSE) for original-code rights. This public repository is used for CI and engineering; rights to WordPress, WooCommerce and other third-party components remain under their respective licenses.

A lightweight, independent plugin for mattresses, ottoman beds, bases and configurable sleep products. It uses WooCommerce as the commerce engine and implements **one plugin-owned dashboard** for the product workflow, without Gutenberg, Elementor or heavy page builders.

## The only administration menu

**Variable Manager → Resumen:** product/template indicators and quick-start guide.

**Variable Manager → Plantillas:** reusable sizes, colors, heights/thicknesses and opening options. Starter presets for mattresses and canapés. Active templates cannot have their options changed incompatibly while in use.

**Variable Manager → Productos y tarifas:** search/filter simple WooCommerce products by name or exact SKU, publication status and managed-only, create/edit and and duplicate existing configured products as safe private drafts (no inherited SKU, stock quantity or sale price), including name, SKU, draft/publication status, full/short descriptions, category assignment, main image, general stock and availability. Link a template, edit final base prices for each size, paste semicolon-separated size/price rows from Excel and set nonnegative surcharges for colors, heights and openings. Block incompatible attribute *pairs* in a visual rule builder.

**Variable Manager → Edición rápida:** find managed products by name or exact SKU, filter by publication status and adjust the base price of several measurements across up to 10 products on one page. The mobile layout becomes grouped product cards; the navigation tabs scroll horizontally. A sticky save bar shows a live changed-price count, supports undo, disables redundant/invalid saves and warns before abandoning unsaved edits. Products with active WooCommerce sale prices are read-only. Save revalidates all sizes and prices, checks the product's original fingerprint and applies only changed rows through the same CRUD bulk service. Supports both comma and dot decimal formats, including products without SKUs.

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
node --check assets/quick-edit.js
node tests/quick-edit-ui.cjs
node --check assets/admin.js
node --check assets/storefront.js
```

### CI integration (disposable WordPress + real WooCommerce)

GitHub Actions also installs WordPress, WooCommerce and a MySQL service **inside an ephemeral job** and runs [tests/woocommerce-integration.php](tests/woocommerce-integration.php) to exercise real CRUD, stored templates, price rules, cart data/order metadata and fail-closed checks. A separate [WooCommerce bulk integration test](tests/woocommerce-bulk-integration.php) checks two-product CSV imports, selective price changes, rule/stock preservation, conflict detection, sale protection and export roundtrip. [Catalog integration tests](tests/woocommerce-catalog-integration.php) cover exact SKU search and safe draft duplication of managed products. [Quick-editor integration tests](tests/woocommerce-quick-integration.php) verify multi-product edits (including products with no SKU), preservation of surcharges and stock, stale form rejection, invalid prices and sale protection. The seeded demo catalog is audited, seeded twice to check idempotence and then purged. A final check confirms that **demo seeding is refused when WP_ENVIRONMENT_TYPE=production**.

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

Requires PHP 8.1+, WordPress and active WooCommerce. Download the `woocommerce-variable-manager-0.9.0-beta-staging` artifact from the successful GitHub Actions workflow. Extract the plugin ZIP and upload it from WordPress → Plugins.

Open **Variable Manager** in the WP admin sidebar. The shop's theme must render classic WooCommerce product hooks; checkout blocks / Store API are **not yet certified**. For `colchones.kairoseth.com` setup and acceptance checks, see [docs/STAGING.md](docs/STAGING.md).

## Code structure

- `woocommerce-variable-manager.php` — bootstrapping and HPOS declaration.
- `includes/class-wvm-dashboard.php` — plugin-owned admin forms and product flow.
- `includes/class-wvm-catalog.php` — WooCommerce-backed name/SKU search and draft-only duplication.
- `includes/class-wvm-quick-edit.php` — managed-only query and authenticated inline price grid, with optimistic concurrency.
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

## 0.9.0-beta — faster catalog management

A product can be located by **exact SKU**, even if its title contains no such text. Normal product-title searches remain available. The **Duplicar borrador** action on a managed product creates a second *draft, hidden, out-of-stock* product with a blank SKU; pricing matrices, compatible option rules, text, category/image references, basic dimensions and tax/shipping classification are retained. Unmanaged products and products whose configurations are stale cannot be duplicated. No order or stock is copied. The original stays untouched. Test before use on staging.

## 0.9.0-beta — inline catalog price grid

Use **Variable Manager → Edición rápida** for everyday tariff corrections, without editing product fichas, exporting/importing CSV, or generating WooCommerce variations. Up to 10 managed simple products appear per page, with price inputs per size. The interface shows how many prices have changed, supports Spanish decimals, leaves sales discounted products in read-only mode, and validates all modified product matrices on the server. A concurrent change to the same product cancels the save rather than overwriting it. No pricing rules, inventory, SEO or customer orders are altered by the quick editor. **Save runs for a whole edited page; failed preflight rejects the batch.**

## 0.9.0-beta — catalog filters and mobile-first price administration

- **Products & tariffs:** search by name or exact SKU, select published/draft/pending/private status, optionally show only products managed by Variable Manager. Filters are server-side WC product queries and persist across pagination.
- **Quick edit:** the same status filter, with a manageable 10 products per page and a responsive **card layout on mobile** instead of requiring horizontal table scrolling.
- **Safe editing:** highlight changed prices, count pending updates, `Deshacer cambios` resets the page, invalid amounts disable saving, and the browser warns when leaving a dirty editor. Users can still submit with JavaScript disabled; PHP validates all inputs.
- **Verification:** WooCommerce integration covers managed-only searches, published/draft filtering, SKU filtering and existing price protections. A zero-dependency Node test covers change-count, decimal comma handling, undo, invalid prices and unsaved navigation warning.

The filtering code does not write to WooCommerce; it affects catalog queries only. Site Architect, theme and production installations remain unchanged.

## 0.9.0-beta — synthetic checkout acceptance gate

The disposable GitHub CI environment now additionally creates a temporary **real WooCommerce pending order** via the classic checkout's line-item creation method, based on two separately configured cart lines of a fictional canopy bed. The test adds a real WooCommerce fixed-cart coupon (20 EUR), enables temporary Spanish base taxation (ES, 21%), verifies subtotal, VAT, coupon and total consistency, then persists the order. It verifies that every order line still carries the selected measure, color, thickness and opening, and that allocated line totals reflect the discount. Product, coupon, order, tax rate, templates and changed settings are removed in a cleanup block.

**This is not a browser checkout or payment test.** It deliberately skips UI submissions, shipping method choice, gateway authorization/capture, fiscal edge cases and full HPOS production deployment. Those remain explicitly gated on staging acceptance. GitHub Actions never connects to a live store.

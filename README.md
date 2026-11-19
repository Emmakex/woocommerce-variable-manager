# WooCommerce Variable Manager

Copyright © 2026 Eduardo Yauri. See [LICENSE](LICENSE). Public visibility is for development/CI; it does not grant a license to proprietary materials. WordPress, WooCommerce and other third-party components remain under their respective licenses.

## 0.2.0-beta — one unified dashboard

A lightweight independent plugin for selling mattresses, canapés and other configurable products without manually generating every combination.

**Everything essential to the Variable Manager workflow now lives under one WordPress sidebar menu:**

1. **Variable Manager → Resumen:** catalog indicators and a three-step getting-started guide.
2. **Variable Manager → Plantillas:** create/edit reusable sets of measurements, colours, thickness/height and opening options. Starter presets for mattress and ottoman bed. No Gutenberg or hidden WP editor.
3. **Variable Manager → Productos y tarifas:** search/paginate simple products; create new products; edit product name, SKU, publication status, short description, featured image, linked template and size-price matrix. Paste size/price rows from a spreadsheet (`90x190;299,99`) to populate the matrix in one click.

WooCommerce still owns the product data, catalog, cart, checkout, orders, payments, stock and taxes. This plugin edits its managed products using WooCommerce CRUD objects; it does not replace WooCommerce.

For now each size has a full final price. Color, thickness/height and opening are selectable **without extra surcharges**. Parent SKU and stock only: no inventory per size or SKU per option in this beta.

## Developer / staging installation

Requires WordPress, active WooCommerce, PHP 8.1+ and a theme rendering classic WooCommerce product forms.

- Download the staging ZIP from GitHub Actions for this pull request; install via **Plugins → Add New → Upload Plugin** on a **staging** site.
- Open **Variable Manager → Plantillas**. Choose a mattress/canapé starter preset or start from scratch, review the attributes, then save.
- Open **Variable Manager → Productos y tarifas**. Create or search a simple product, select the template, set all size prices or paste rows from Excel/CSV, choose a photo and publish.
- Visit the product: selections must be validated when adding to the cart. Price is always recomputed on the server, and selected options are saved in the order line.
- The optional color, thickness and opening selectors do not alter the price yet.

### Data and safety guarantees

- Templates: internal `wvm_template` post type, no Gutenberg editor and no extra top-level WordPress menu.
- Product configuration: WooCommerce CRUD metadata `_wvm_template_id` and `_wvm_price_matrix`.
- Templates already in use cannot have their list of measurements changed in place; duplicate a template instead.
- Server validates the complete matrix, exact measure, selectable options and currency rounding before sale.
- Published products with missing/outdated matrices are not purchasable. Existing cart configurations are revalidated at checkout.
- PHP admin handlers check WooCommerce management permissions and WordPress nonces.
- Site Architect is a separate product; it never owns these commercial controls.

### Repository

`woocommerce-variable-manager.php`: bootstrap and HPOS declaration.

`includes/class-wvm-dashboard.php`: full plugin-owned dashboard, menu, admin forms and save handlers.

`includes/class-wvm-templates.php`: reusable option templates, internal CPT storage.

`includes/class-wvm-products.php`: strict price preparation, WooCommerce product metadata.

`includes/class-wvm-pricing.php`: size/price validation.

`includes/class-wvm-storefront.php`: theme hooks, selection validation, cart reprice, order line data.

`assets/admin.js` and `assets/admin.css`: accessible, responsive dashboard with price matrix and media picker, no extra JS framework.

`tests/pricing-smoke.php`: isolated validation smoke tests.

### Limits of this beta

This build is **not production-certified**. The CI runs PHP/JS syntax checks and isolated pricing tests; it does not replace WordPress/WooCommerce staging tests for save/edit, cart, taxes, discounts, gateways or checkout.

No checkout-block/Store-API compatibility is claimed. Use the classic WooCommerce checkout for beta testing. Image gallery, categories, stock per measure/SKU, attribute surcharges, rule incompatibilities and full CSV import/export are later milestones.

Site Architect's greenfield theme-write permissions are separately gated, unchanged by Variable Manager.

## Roadmap priority

1. Validate 0.2.0-beta end to end against staging WooCommerce and the custom theme.
2. Add reusable price supplements and option compatibility rules.
3. Add bulk updates/import/export, and stock per SKU where genuinely needed.

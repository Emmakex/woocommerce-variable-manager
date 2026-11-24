# Staging plan — colchones.emmake.com

This document is an installation and acceptance checklist, **not a deployment record**.
DNS/subdomain creation and WordPress installation are performed on the staging host
by the infrastructure owner. Do not deploy this beta to production.

## Recommended environment

- Host: `colchones.emmake.com` (the owner is creating the subdomain).
- Dedicated staging WordPress install + its **own database**, not the production database.
- PHP >= 8.1 (prefer an actively supported 8.2+ branch), HTTPS and a supported WooCommerce release.
- Protect the staging site behind basic authentication or equivalent access restriction;
  **do not rely only on robots.txt**. Also enable "Discourage search engines" in WP.
- Configure WooCommerce for EUR, Europe/Madrid timezone, Spanish locale.
- Disable all real payment charges and notifications; use offline gateway or sandbox only.
- Restrict admin users to named authorized accounts; staging secrets must NOT be committed.
- Use a WooCommerce-compatible classic product form; the storefront Cart/Checkout Blocks
  and Store API are not yet certified by this module.

## Install/test from GitHub

1. Download the `woocommerce-variable-manager-0.10.1-beta-staging` artifact from the
   latest successful PR workflow and extract its inner ZIP.
2. In staging WP: Plugins > Add Plugin > Upload the plugin ZIP.
3. Enable WooCommerce, then Variable Manager. Leave the commercial theme and
   Site Architect independent; they are not a prerequisite for plugin tests.
4. Under Variable Manager > Plantillas, choose Canapé and save a template.
5. Create a Simple product from Variable Manager > Productos y tarifas.
6. Test one product with a base-price matrix, color/height/opening surcharges
   and a forbidden size+opening pair.
7. Open **Variable Manager → Edición rápida**. Edit multiple size prices (including one typed with a decimal comma), save, and verify that only selected amounts changed. Also test a stale page from another admin and a protected sale product.
8. Open **Variable Manager → Importar / exportar**. Download the CSV of configured tariffs, modify one or more rows in a spreadsheet, upload and verify the preview. Confirm and verify that only the intended measure prices changed.
9. Optional: run `wp wvm demo preview`, then `wp wvm demo seed --confirm-synthetic=YES` to insert eight **draft/hidden** synthetic products. After testing use `wp wvm demo purge --confirm-synthetic=YES` on that nonproduction site.
10. No DNS/subdomain is needed to execute the CI synthetic suite. The temporary WordPress/MySQL site in GitHub Actions already tests the real WooCommerce CRUD and test fixture lifecycle.

## Acceptance cases (manual WooCommerce end-to-end)

- [ ] Create/edit template from dashboard without Gutenberg or another editor.
- [ ] Create new product with name, full/short description, image, categories, general stock, SKU and published/draft status.
- [ ] Search by exact SKU and by product name, including a paginated catalog.
- [ ] Filter catalog to published/draft status and managed-only products. Verify filters persist when switching pages.
- [ ] On 390px mobile viewport: navigation tabs scroll, product tariffs appear as readable cards, no horizontal page overflow.
- [ ] Change a price, navigate away: unsaved-change warning appears. Use «Deshacer cambios»: all values reset, save disabled and warning cleared.
- [ ] Invalid, zero and negative prices cannot be submitted with JavaScript; server rejects direct crafted POST.
- [ ] Edit several product-size prices on a single page, including comma decimals, without affecting stock or compatibility rules.
- [ ] Confirm stale editor view is rejected and products with WooCommerce sale prices remain read-only.
- [ ] Duplicate an existing configured product: clone is a hidden, out-of-stock draft with empty SKU, full rules/prices, original remains unchanged.
- [ ] Paste prices by size; verify correct amount is saved for each measure.
- [ ] Export and re-import semicolon CSV using `sku;medida;precio`, preview changes before confirming.
- [ ] CSV with one unknown SKU or measure rejects the **whole** import without partial writes.
- [ ] CSV with duplicate SKU+measure rows or zero/negative prices is rejected.
- [ ] Product modified after preview cancels the import on confirmation; a WooCommerce sale product is skipped/protected.
- [ ] CSV import preserves option surcharges, product stock, images and order history.
- [ ] Configure Beige/Gris and opening surcharges; compare online selection vs cart totals.
- [ ] Invalid size + opening blocked by JS and PHP; direct crafted POST rejected.
- [ ] Switching template in admin retains unsaved input during that page session.
- [ ] Editing a template in use cannot silently invalidate option combinations.
- [ ] A stale configuration already in the cart is removed before calculation.
- [ ] Two valid configurations of the same product remain distinct cart lines.
- [ ] Checkout test: order contains selected size, color, thickness and opening.
- [ ] Confirm WooCommerce taxes, currency decimals, coupons, shipping and refunds.
- [ ] Review synthetic checkout CI: two configured product lines, EUR coupon of 20, 21% ES VAT, persisted order line metadata, no gateway charges.
- [ ] Separately run a real staging storefront checkout in browser (including shipping, payment sandbox, tax address and HPOS); synthetic order tests are insufficient.
- [ ] Test managed stock versus availability-only status and product-category assignment.
- [ ] Confirm behavior without JavaScript and on mobile.
- [ ] Confirm WooCommerce HPOS on a separate staging order test.
- [ ] Confirm configurable product cards in archive link to product pages rather than adding without choices.
- [ ] Confirm accessibility: keyboard selection, labels, contrast and live notices.
- [ ] Verify noindex/access restrictions and that no emails or live payments were sent.

## Boundaries

WooCommerce manages customers, orders, gateways, tax, shipment and payments.
Variable Manager owns reusable templates, per-size prices, per-option surcharges,
compatibility rules, and the configuration interface.
Site Architect owns site structure/navigation/content only, and no WooCommerce
product/checkout writes.

Do not copy live customer databases into this staging install.
A future deployment workflow should use approved staging secrets and explicit
owner verification; never store credentials in GitHub source files.

## Current known limits

- Parent stock and SKU only, not stock differentiated per option/measurement.
- No multi-condition/quantity rules beyond pairwise excluded options.
- Category creation, full gallery, promotions, **full supplier product** CSV import and product-type
  conversions are not part of the current dashboard beta.
- Store API/Cart & Checkout Blocks are intentionally out of scope pending dedicated tests.
- Staging acceptance must precede any production release.

### Import a synthetic catalog of 30 new products

1. Update the installed plugin to `woocommerce-variable-manager-0.10.1-beta.zip` (Plugins → Add Plugin → Upload, then Replace Current). Keep WooCommerce active.
2. From the existing dashboard open **Variable Manager → Importar / exportar**. Scroll to **Crear productos nuevos desde CSV (pruebas)**. Do **not** use the other CSV tariff-update form; it only works on existing SKUs.
3. Upload `catalogo_30_productos_variable_manager.csv` (separate CSV supplied for testing), click **Previsualizar 30 productos sin crear**.
4. Review all SKU/name/size counts. Then press **Crear los productos como borradores** once. On success verify 30 newly created products in **Variable Manager → Productos y tarifas**.
5. All products must be `draft`, `hidden`, `outofstock`; no one can purchase them. Check a mattress's height supplements, a canapé's color/opening supplements and the 90x190+lateral incompatibility.
6. Uploading the CSV again should refuse existing SKUs and never overwrite existing products. Tests do not create live stock or publish products. Do not change their status to published until a separate visual/checkout review.

### Preview configurations before publishing (0.10.1)

- [ ] While a created CSV product is a hidden draft and `outofstock`, open the admin's product preview. Verify **Medida, Color, Espesor / altura, Apertura** are shown whenever that template defines them.
- [ ] Choose compatible values and observe the price preview update; choose a forbidden size/opening pair and see the incompatibility warning.
- [ ] Verify there is no add-to-cart button for an unavailable draft. Never publish a synthetic product merely to see its options.
- [ ] On a disposable in-stock, published product, the configurator appears only once **inside** the WooCommerce add-to-cart form and submitted selections still reach cart and order.
- [ ] If using a theme based solely on WooCommerce Product Blocks, verify hook support separately.

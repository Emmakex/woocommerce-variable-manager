# Staging plan — colchones.kairoseth.com

This document is an installation and acceptance checklist, **not a deployment record**.
DNS/subdomain creation and WordPress installation are performed on the staging host
by the infrastructure owner. Do not deploy this beta to production.

## Recommended environment

- Host: `colchones.kairoseth.com` (the owner is creating the subdomain).
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

1. Download the `woocommerce-variable-manager-0.4.0-beta-staging` artifact from the
   latest successful PR workflow and extract its inner ZIP.
2. In staging WP: Plugins > Add Plugin > Upload the plugin ZIP.
3. Enable WooCommerce, then Variable Manager. Leave the commercial theme and
   Site Architect independent; they are not a prerequisite for plugin tests.
4. Under Variable Manager > Plantillas, choose Canapé and save a template.
5. Create a Simple product from Variable Manager > Productos y tarifas.
6. Test one product with a base-price matrix, color/height/opening surcharges
   and a forbidden size+opening pair.
7. Optional: run `wp wvm demo preview`, then `wp wvm demo seed --confirm-synthetic=YES` to insert eight **draft/hidden** synthetic products. After testing use `wp wvm demo purge --confirm-synthetic=YES` on that nonproduction site.
8. No DNS/subdomain is needed to execute the CI synthetic suite. The temporary WordPress/MySQL site in GitHub Actions already tests the real WooCommerce CRUD and test fixture lifecycle.

## Acceptance cases (manual WooCommerce end-to-end)

- [ ] Create/edit template from dashboard without Gutenberg or another editor.
- [ ] Create new product with name, full/short description, image, categories, general stock, SKU and published/draft status.
- [ ] Paste prices by size; verify correct amount is saved for each measure.
- [ ] Configure Beige/Gris and opening surcharges; compare online selection vs cart totals.
- [ ] Invalid size + opening blocked by JS and PHP; direct crafted POST rejected.
- [ ] Switching template in admin retains unsaved input during that page session.
- [ ] Editing a template in use cannot silently invalidate option combinations.
- [ ] A stale configuration already in the cart is removed before calculation.
- [ ] Two valid configurations of the same product remain distinct cart lines.
- [ ] Checkout test: order contains selected size, color, thickness and opening.
- [ ] Confirm WooCommerce taxes, currency decimals, coupons, shipping and refunds.
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
- Category creation, full gallery, promotions, full supplier CSV import and product-type
  conversions are not part of the current dashboard beta.
- Store API/Cart & Checkout Blocks are intentionally out of scope pending dedicated tests.
- Staging acceptance must precede any production release.

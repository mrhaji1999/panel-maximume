# User Cards Plugin

Custom post type and management tools for configuring sellable “cards” that can be bridged to destination WooCommerce stores.

## Pricing Metabox

Each `uc_card` post exposes a **Pricing (Normal + Upsells)** metabox. Every row now captures:

- **Label** – short description shown in the admin UI.
- **Amount** – base sale price.
- **Wallet Amount** – value that should be credited in the destination wallet plugin.
- **Code Type** – either `wallet` (wallet credit) or `coupon` (fixed-cart coupon).

Rows are stored in the `_uc_pricings` post meta field and exposed through the REST API for Gutenberg and remote integrations.

## Store Link

Cards also include a **Store Link** field that stores the destination WooCommerce shop URL in `_uc_store_link`. The user-cards-bridge plugin reads this value when forwarding codes.

## Security

Metabox saves are protected with nonces and WordPress capability checks. All submitted values are sanitized before persisting.

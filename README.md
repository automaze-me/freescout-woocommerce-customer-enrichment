# WooCommerce Customer Enrichment

A companion module for the official [WooCommerce](https://freescout.net/module/woocommerce/) module for [FreeScout](https://freescout.net). It solves two recurring problems: customers give you their phone number when placing an order, but that number never shows up in FreeScout when they later email support from the same address; and customers sometimes write in from a different email than the one they ordered with, so the official module's order sidebar finds nothing for them. This module watches incoming customer messages in the background, looks up the customer's WooCommerce orders — by known email addresses and by order numbers detected in the message subject/body — and fills gaps in the FreeScout customer profile (phone, alternate email, name, company/address) from the billing data it finds. Once an order number match adds the billing email to the customer, the official module's "Recent Orders" sidebar starts working for that customer too.

## Requirements

- FreeScout 1.8.215 or later.
- The official **WooCommerce** module (1.0.16 or later) installed, **active**, and configured with API credentials (either globally or per mailbox). Without it active, this module registers nothing at all.
- Nothing else. Enrichment runs through FreeScout's standard background processing, which every working FreeScout installation already has (it is the same mechanism that sends your email).

## Installation

1. Download `WooCommerceCustomerEnrichment.zip` from the [latest release](https://github.com/automaze-me/freescout-woocommerce-customer-enrichment/releases/latest) and extract it into FreeScout's `Modules/` directory (resulting in `Modules/WooCommerceCustomerEnrichment/`).
2. Activate **WooCommerce Customer Enrichment** in *Manage → Modules*.

## Settings

Configured under *Manage → Settings → Customer Enrichment*.

**Order number pattern.** A regular expression (without delimiters, case-insensitive), with one capture group for the order number, applied to the subject and body of incoming messages. Left empty, the built-in default is used: it looks for a `#` or an order-related keyword (`Bestellung`, `Bestellnummer`, `Auftrag`, `Auftragsnummer`, `order`) immediately next to a run of 3–10 digits, so it matches things like `#12345` or `Bestellung 12345` without also matching bare numbers, dates, ZIP codes or phone numbers that happen to appear in a message. If your shop uses a different order numbering scheme — for example a plugin that prefixes order numbers with `WC-` — replace it with a custom pattern with one capture group around the part that should be looked up, e.g. `WC-(\d{4,8})`. The captured digits are used directly as the WooCommerce order **ID** (`GET /orders/<id>`), so a custom pattern must capture the real order ID — shops using plugins that show customers a sequential order number different from the underlying order ID are not supported.

**Profile Enrichment toggles.** Four independent switches, all **on by default**:

- **Phone numbers** — add billing phone numbers from matched orders.
- **Alternate emails** — add the order's billing email as an additional customer email when it differs from the ones already on file.
- **Name** — fill in first/last name when the customer has neither.
- **Company & address** — fill in company, address, city, state, ZIP and country when empty.

## How matching works

Enrichment runs automatically whenever a customer message arrives or an agent creates a conversation for a customer. Two matching paths run every time, and their results are combined:

- **By customer email** — every email address already on the customer's profile is looked up against WooCommerce orders, exactly the way the official module's order sidebar does (same credentials, same cache).
- **By order number** — the message subject and body are scanned with the configured pattern, and each order number found (up to three) is looked up directly by ID. This is what catches a customer writing from an email address WooCommerce doesn't recognize yet.

Profile fields (name, company, address) are **fill-gaps-only**: a value an agent already entered is never overwritten. Phone numbers and email addresses are **lists** — a newly found billing phone or email is appended, deduplicated against what's already on the customer, and existing entries are never removed or replaced.

Every change is recorded as a line item on the conversation ("Customer profile enriched from WooCommerce order #…: …"), naming exactly what was added and from which order — so if an order-number match turns out to be wrong, an agent can see it immediately and correct the profile by hand.

As a safeguard, if an order's billing email already belongs to a *different* existing FreeScout customer, that email is not attached — FreeScout treats emails as unique per customer, and merging identities is not something this module decides on its own. The line item notes the match and which email was skipped, so an agent can decide whether the two customer records should be merged manually.

## Manual command

Enrichment normally runs automatically, but a single conversation can be (re-)processed by hand:

```bash
php artisan wcce:enrich <conversation_id>
```

This is useful for testing settings changes or catching up a conversation that arrived before the module was installed. Running it again on an already-enriched conversation is safe — matching data already on the profile is not duplicated, and no new line item is added when nothing changed.

## License

MIT.

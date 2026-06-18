[Back to parent section](README.md)

# Price

The **Price** section (header: *Price*) allows you to attach a price to a booking option. Prices are handled through the **Shopping Cart** integration (requires the [local_shopping_cart](https://github.com/Wunderbyte-GmbH/moodle-local_shopping_cart) plugin).

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Enable pricing](#1-enable-pricing)
2. [Price categories](#2-price-categories)
3. [Price formula](#3-price-formula)
4. [Booking with credits](#4-booking-with-credits)
5. [Shopping cart instalment payments](#5-shopping-cart-instalment-payments)

---

## 1. Enable pricing

| Field | Description |
|-------|-------------|
| **Use price** (`useprice`) | Toggle to enable or disable pricing for this option. When disabled, the option is free and no checkout is required. |

> **Admin setting:** If **Price is always on** is enabled in the plugin settings, the `useprice` flag is forced to `1` for all options and the toggle is hidden.

---

## 2. Price categories

Price categories are defined globally by an admin in **Booking plugin settings → Price categories**. Each category has an identifier (e.g. `default`, `student`, `staff`) and a default value.

Once categories exist, the price form shows one price field per category:

| Field | Description |
|-------|-------------|
| **Price for category X** | The amount to charge for users in that category. Uses a period (`.`) as the decimal separator. Leave at `0.00` for a free category. |

### How the correct price is applied to a user

When a user books an option, the system checks the user's **price category** (assigned via a user profile field or cohort rule, configured by an admin) and applies the matching price.

### Prices in CSV import

Set `useprice=1` in the CSV and add columns named after the price category identifiers:

```
text,identifier,useprice,default,student
Python Basics,PY-01,1,59.90,29.90
```

See [CSV Import — Pricing](../CSV_IMPORT_USER_GUIDE.md#10-pricing) for details.

---

## 3. Price formula

If the **price formula** feature is enabled (plugin setting), two additional fields appear:

| Field | Description |
|-------|-------------|
| **Price formula multiply** (`priceformulamultiply`) | A multiplier applied to the base price calculated by the formula. |
| **Price formula add** (`priceformulaadd`) | A fixed amount added on top of the formula result. |
| **Turn off price formula** (`priceformulaoff`) | Check this to use a manually entered price instead of the formula for this option. |

The price formula is configured globally and uses factors like the number of hours or custom fields. Per-option overrides allow fine-grained control.

---

## 4. Booking with credits

If the **Book with credits** feature is enabled (plugin setting `bookwithcreditsactive`), users who have sufficient credits in their profile can book directly without going through the shopping cart:

| Field | Description |
|-------|-------------|
| **Credits** (`credits`) | The number of credits this option costs. Set to `0` to disable credit-based booking for this option. |

Credits are stored in a user profile field configured by an admin (`bookwithcreditsprofilefield`).

---

## 5. Shopping cart instalment payments

If the shopping cart plugin supports instalments, an additional checkbox appears:

| Field | Description |
|-------|-------------|
| **Allow instalment payment** (`sch_allowinstallment`) | Check to let users pay in instalments for this option. Requires the shopping cart instalment feature to be configured. |

---

## Related pages

- [General settings](01-general.md) — Option title and capacity
- [Availability conditions](04-availability.md) — Conditions that may interact with price categories
- [CSV Import — Pricing](../CSV_IMPORT_USER_GUIDE.md#10-pricing)

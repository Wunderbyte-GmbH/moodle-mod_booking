[Back to parent section](../../../README.md)

# Campaigns — Overview

> **Primary page** for: temporary price changes, temporary capacity/place changes, and time-boxed booking behavior changes (for example "only for two days").

**Booking campaigns** let you modify how a booking option behaves during a defined time window, based on a booking custom field value and an optional user profile condition. You can use campaigns to:

- **Block** booking access for a subset of options during a specific period
- **Discount** the price of options during a promotional window
- **Increase capacity** temporarily for a set of options

Campaigns are a **PRO feature** of mod_booking.

## Click-by-click setup

1. Open campaign management: [/mod/booking/edit_campaigns.php](/mod/booking/edit_campaigns.php).
2. Click Add campaign.
3. Choose campaign type (Block-booking or Custom field).
4. Set campaign time window.
5. Set option field filter and value.
6. Configure effect values (block percentage, price factor, limit factor).
7. Save campaign and test one matching option.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [What is a campaign?](#1-what-is-a-campaign)
2. [Where to manage campaigns](#2-where-to-manage-campaigns)
3. [Campaign types](#3-campaign-types)
4. [How campaigns are evaluated](#4-how-campaigns-are-evaluated)
5. [Further reading](#5-further-reading)

---

## 1. What is a campaign?

A campaign is a rule that says:

> *"During the period from **[starttime]** to **[endtime]**, for all booking options where custom field **[bofieldname]** [operator] **[fieldvalue]**, apply [effect]."*

An optional second condition (the **price category / user profile condition**) further limits which *users* are affected.

Campaigns are evaluated every time a user views a booking option's availability status. The effect takes place automatically — no action is required from participants.

---

## 2. Where to manage campaigns

Campaigns are managed globally at:

*Site administration → Plugins → Activity modules → Booking → Manage campaigns*

or directly via:

`/mod/booking/edit_campaigns.php`

You can create, activate/deactivate, edit, and delete campaigns from this page.

---

## 3. Campaign types

Two campaign types are available:

| Type | Class | What it does |
|------|-------|-------------|
| [Block-booking campaign](campaign_blockbooking.md) | `campaign_blockbooking` | Blocks booking access (or limits available places to a percentage) for options matching a custom field value, optionally overridable by a user price category |
| [Custom field campaign](campaign_customfield.md) | `campaign_customfield` | Applies a price factor and/or capacity factor to options matching a custom field value, within a defined time window |

---

## 4. How campaigns are evaluated

When a user tries to view or book an option:

1. The system loads all active campaigns.
2. For each campaign it checks whether the current time is within `[starttime, endtime]`.
3. It checks whether the booking option's custom field matches the campaign's `bofieldname` / `fieldvalue` / operator criteria.
4. If the optional user price category condition is set, it checks the user's price category.
5. If all criteria match, the campaign's effect is applied:
   - For `campaign_blockbooking`: the option's available places are reduced to `percentageavailableplaces` % of its total capacity, or access is blocked entirely.
   - For `campaign_customfield`: the displayed price is multiplied by `pricefactor` and the capacity by `limitfactor`.

Campaigns do **not** modify the stored booking option data — they apply their effect in-memory at display/booking time. When the campaign ends, the option returns to its normal state automatically.

> **Caches:** Campaign results are cached. When you add or change a campaign, Moodle's caches are purged automatically via the `purge_campaign_caches` task.

---

## 5. Further reading

- [Campaign type: Block-booking](campaign_blockbooking.md)
- [Campaign type: Custom field campaign](campaign_customfield.md)
- [Booking option — Price settings](../booking-option/05-price.md)
- [Availability conditions](../booking_conditions/README.md)

[Back to parent section](README.md)

# Campaign Type: Custom Field Campaign

**Class:** `mod_booking\booking_campaigns\campaigns\campaign_customfield`
**PRO required:** Yes 🔒

---

## What it does

The **Custom field campaign** applies a **price factor** and/or a **capacity factor** to all booking options whose custom field matches the configured criteria, during a defined time window.

Typical uses:

- **Promotional pricing:** Reduce prices by 20 % for all workshops tagged with a certain custom field during a promotional week.
- **Temporary capacity increase:** Allow more bookings (increase the booking limit) for a subset of options during a peak enrollment period.

## Click-by-click setup

1. Open campaign management: [/mod/booking/edit_campaigns.php](/mod/booking/edit_campaigns.php).
2. Click Add campaign.
3. Choose campaign type Custom field campaign.
4. Set Start time and End time.
5. Enter booking option custom field, operator, and field value.
6. Enter Price factor and Capacity factor.
7. Optionally restrict by price category field and value.
8. Save and verify on one matching option and one non-matching option.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Campaign name** | A descriptive label for the campaign (internal use only). |
| **Start time** | The date/time when the campaign becomes active. |
| **End time** | The date/time when the campaign expires. |
| **Booking option custom field** (`bofieldname`) | The shortname of a booking option custom field that must match for the campaign to apply. |
| **Operator** (`campaignfieldnameoperator`) | Comparison operator for the field check (e.g., "equals", "contains"). |
| **Field value** (`fieldvalue`) | The value the custom field must match. |
| **Price factor** (`pricefactor`) | A multiplier applied to the option's base price. Example: `0.8` = 20 % discount; `1.0` = no change; `1.5` = 50 % surcharge. |
| **Capacity factor** (`limitfactor`) | A multiplier applied to the option's booking limit. Example: `1.5` = allow 50 % more bookings than the configured limit. `1.0` = no change. |
| **Extend limit for overbooked** (`extendlimitforoverbooked`) | If set to `1`, the capacity factor also applies when the option is already overbooked (i.e., the extension applies on top of the current booking count). |
| **Price category field** (`cpfield`) | Optional: shortname of a user price category profile field. Only users whose price category matches are given the modified price. |
| **Price category operator** (`cpoperator`) | Comparison operator for the price category check. |
| **Price category value** (`cpvalue`) | The price category value that triggers the modified price. |

---

## How the price factor works

The displayed price for a user is calculated as:

```
displayed_price = base_price × pricefactor
```

The base price is the price stored on the booking option for the user's price category. The factor is applied in-memory — the stored price is never changed.

If a `cpfield` / `cpvalue` condition is set, only users in that price category see the modified price. All other users see the original price.

---

## How the capacity factor works

The booking limit visible to the system during the campaign window is:

```
effective_limit = original_limit × limitfactor
```

If the factor is greater than `1.0`, more users can book than the option's configured capacity during the campaign period. After the campaign ends, the original limit is restored automatically.

---

## Example: 20 % discount on all summer workshops

**Scenario:** During July, all booking options tagged as `type = summer_workshop` should be 20 % cheaper.

| Setting | Value |
|---------|-------|
| Campaign name | Summer workshop discount |
| Start time | 2026-07-01 00:00 |
| End time | 2026-07-31 23:59 |
| Booking option custom field | `type` |
| Operator | equals |
| Field value | `summer_workshop` |
| Price factor | `0.8` |
| Capacity factor | `1.0` (no change) |

During July, the displayed price for all matching options is reduced to 80 % of the configured base price.

---

## See also

- [Campaign overview](README.md)
- [Campaign type: Block-booking campaign](campaign_blockbooking.md)
- [Booking option — Price settings](../booking-option/05-price.md)

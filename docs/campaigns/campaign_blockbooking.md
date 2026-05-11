# Campaign Type: Block-Booking Campaign

**Class:** `mod_booking\booking_campaigns\campaigns\campaign_blockbooking`  
**PRO required:** Yes 🔒

---

## What it does

The **Block-booking campaign** temporarily reduces the number of available places on matching booking options to a configured percentage. It can also restrict booking access entirely, or exempt users in a specific price category from the block.

Typical uses:

- **Early-bird access:** Reserve 50 % of places for a specific group (e.g., internal staff identified by a price category) during the first week after registration opens.
- **Partial block:** Allow only certain users to book during a campaign window, blocking everyone else.

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
| **Percentage of available places** (`percentageavailableplaces`) | After the campaign is active, only this percentage of the option's total capacity is shown as available. For example, `50` means only half the places appear available. Set to `0` to block all bookings. |
| **Block operator** (`blockoperator`) | Fine-grained control of the block behaviour. |
| **Price category field** (`cpfield`) | Optional: shortname of a user price category profile field. Users in the matching price category bypass the block. |
| **Price category operator** (`cpoperator`) | Comparison operator for the price category check. |
| **Price category value** (`cpvalue`) | The price category value for which the block is lifted. |
| **Blocking label** (`blockinglabel`) | Text displayed to blocked users explaining why they cannot book. Supports Moodle lang string format. |
| **Capability override** (`hascapability`) | If set, users with this Moodle capability bypass the block. |

---

## Example: Early-bird reservation for internal staff

**Scenario:** During the first 7 days after a new booking option opens, only internal staff (identified by the price category `internal`) should be able to book. After that, anyone can book.

| Setting | Value |
|---------|-------|
| Campaign name | Early-bird staff window |
| Start time | (set to option's booking opening time) |
| End time | (set to 7 days after opening time) |
| Booking option custom field | `type` |
| Operator | equals |
| Field value | `training` |
| Percentage of available places | `0` (block everyone) |
| Price category field | `pricecategory` |
| Price category value | `internal` |
| Blocking label | Early-bird period: only internal staff may book at this time. |

During the campaign window, external participants see the blocking label instead of the "Book it" button. Internal staff (whose price category matches `internal`) see the booking button normally.

---

## See also

- [Campaign overview](README.md)
- [Campaign type: Custom field campaign](campaign_customfield.md)
- [Booking option — Price settings](../booking-option/05-price.md)

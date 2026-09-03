[Back to parent section](README.md)

# Sub-booking Type: Additional Item

**Class:** `mod_booking\subbookings\sb_types\subbooking_additionalitem`
**PRO required:** Yes 🔒

---

## What it does

The **Additional item** sub-booking lets participants select or purchase an add-on item as part of their booking. Examples:

- Lunch or catering (with a price)
- Course materials or a printed workbook
- A parking spot
- An optional workshop session within a larger event

The item can optionally be **linked to a custom form field** so that participants who have previously filled in that form field are automatically assigned the item.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Name** | The display name of the sub-booking (shown to participants). |
| **Block parent option** | When enabled, the parent booking option cannot be confirmed until this sub-booking step is completed. |
| **Form link** (`subbookingadditemformlink`) | Optional: link this sub-booking to a custom form field. If the user's custom form answer matches `subbookingadditemformlinkvalue`, the item is automatically selected. |
| **Form link value** (`subbookingadditemformlinkvalue`) | The value in the linked custom form field that triggers auto-selection of this item. |
| **Description** | A rich-text description shown to the participant at the sub-booking step. Can include images and formatted text. |
| **Price** | A price for the additional item (requires `local_shopping_cart`). Leave at `0.00` for a free item. |

---

## How it works

1. When the participant reaches the booking confirmation step for the parent option, the additional item is shown with its name, description, and price.
2. The participant selects whether they want the item (or it is auto-selected if a form field link matches).
3. If the item has a price, it is added to the shopping cart as a separate line item.
4. The sub-booking answer (selected / not selected) is stored in `booking_subbooking_answers`.

---

## Example: Add lunch to a training day

**Scenario:** A full-day training offers an optional catering package for €15.

| Setting | Value |
|---------|-------|
| Name | Lunch catering |
| Block parent option | ✗ (optional extra) |
| Description | Hot lunch buffet included. Please confirm your dietary requirements by selecting this option. |
| Price | `15.00` |

Participants who book the training day see a "Lunch catering — €15.00" option in their booking flow. Selecting it adds €15 to their cart.

---

## See also

- [Sub-bookings overview](README.md)
- [Additional person sub-booking](subbooking_additionalperson.md)
- [Time slot sub-booking](subbooking_timeslot.md)
- [Booking option — Price settings](../booking-option/05-price.md)


## Quick setup path

1. Open option edit page: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
2. Edit target option and open Sub-bookings section.
3. Add or configure the sub-booking type from this page.
4. Save and test booking flow as participant.

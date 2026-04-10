# Sub-booking Type: Time Slot

**Class:** `mod_booking\subbookings\sb_types\subbooking_timeslot`  
**PRO required:** Yes 🔒

---

## What it does

The **Time slot** sub-booking allows participants to choose a **specific time slot** within a booking option. This is useful when:

- A booking option represents a larger event with multiple parallel sessions
- You want to manage attendance across different times of day (e.g., morning or afternoon workshop)
- Each slot has its own capacity and/or price

Each time slot appears as a bookable unit. Participants select exactly one slot per booking.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Name** | The display name of the sub-booking (e.g., "Choose your session time"). |
| **Block parent option** | When enabled, the parent booking option is not bookable until the participant has selected a time slot. |
| **Slot duration** (`subbooking_timeslot_duration`) | Duration of each time slot in minutes. This is used to calculate the end time of slots if entity/room assignments are used. |
| **Price** | An optional price for the slot selection (requires `local_shopping_cart`). Useful if different slots have different fees. |
| **Entity / room** | If the `local_entities` plugin is installed, each slot can be assigned to a specific room or venue using the entity relation handler. |

---

## How time slots are presented to participants

When the booking option has a time slot sub-booking configured, the available slots are shown during the booking flow. Participants see each slot with its time, remaining places, and any assigned room/entity. They select one slot and this selection is stored in `booking_subbooking_answers`.

Slot availability is tracked independently: each slot has its own booking counter. A fully-booked slot is shown as unavailable, but the participant can still select another slot if places remain.

---

## Use case: Morning / afternoon workshop selection

**Scenario:** A workshop is offered in two daily sessions — 09:00–12:00 and 13:00–16:00 — each with a capacity of 15 participants.

| Setting | Value |
|---------|-------|
| Name | Choose your session |
| Block parent option | ✓ (participant must choose a slot before confirming) |
| Slot duration | `180` (minutes) |

Two time slot entries are created:
- **Morning slot:** 09:00–12:00, 15 places
- **Afternoon slot:** 13:00–16:00, 15 places

Participants who book the workshop must select either the morning or afternoon session. The booking is only confirmed once a slot is chosen.

---

## See also

- [Sub-bookings overview](README.md)
- [Additional item sub-booking](subbooking_additionalitem.md)
- [Additional person sub-booking](subbooking_additionalperson.md)
- [Booking option — Dates settings](../booking-option/02-dates.md)

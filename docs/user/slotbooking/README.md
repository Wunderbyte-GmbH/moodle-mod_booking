[Back to documentation index](../../README.md)

# Slot booking

Slot booking turns a booking option into a set of **individually bookable time slots** — for appointments, exam sessions, consultations, equipment rental and similar use cases. Instead of booking the option as a whole, participants pick one or more free slots from a schedule.

> **Availability:** Slot booking is a [Booking PRO](https://wunderbyte.at) feature. It must additionally be switched on site-wide with the plugin setting **`booking/slotbookingactive`** ("Enable slot booking"). When that setting is off, slot booking is unavailable everywhere (option type, booking flow, slot pages and the AI skill), even with a PRO licence.

---

## Enabling slot booking on an option

In the booking option form, set the **option type** to **"Slot booking"**. This adds the **Slot Booking Settings** section to the form. The base price of the option (Price section) becomes the base price of every slot; individual slots can deviate via [slot rules](#slot-rules-closed-slots-and-prices).

> **Changing the type later:** if a slot option already has booking answers, changing the option type shows a warning and requires an explicit confirmation, because existing slot bookings may become invalid.

## Slot types

| Type | How slots are generated |
|------|------------------------|
| **Fixed** | A back-to-back grid: slots of **Slot duration (minutes)** fill the daily time window. |
| **Rolling** | Slots of **Slot duration** whose start times repeat every **Slot interval (minutes)** — starts can be denser than the duration, producing overlapping candidate slots. |
| **From option dates (sessions)** | The slots are exactly the **option dates** defined in the Dates section of the form. This is the only slot type where option dates are allowed; for all other types the form rejects dates. |
| **User-defined** | Participants define their own slot: they pick a start (offered every **Slot start interval**) and a duration between **Minimal** and **Maximal slot length**, in steps of **Slot duration step**. **Max days for one slot** allows multi-day slots. Examiner features are not available for this type. |

## Schedule window (fixed, rolling, user-defined)

Slots are generated inside a validity and time window:

- **Valid from / Valid until** — the date range in which slots exist.
- **Opening time / Closing time (HH:MM)** — the daily window.
- **Weekdays** (Monday–Sunday checkboxes) — the days on which slots are offered.

## Capacity

- **Max participants per slot** — how many people can book the same slot.
- **Max slots per user** — the total number of separate slots one user may hold for this option at once. This is independent of the instance-level "Allow to book again" setting (which only controls re-booking after a booking has ended or after a wait time); the instance setting also offers a slot-specific variant **"Allow after the last booked slot has ended"**.

## Booking interface

- **Slot booking interface** (per option): **List view** or **Calendar view** (not for user-defined slots).
- The plugin setting **`booking/slot_bookings_display_mode`** controls what the option list shows as the booking count: only the slots available to the current user, or booked vs. capacity (legacy).
- Booking runs through a **pre-page**: "Please choose one available slot before continuing with the booking." Overlapping selections, over-limit selections and slots that were taken in the meantime are rejected with clear error messages.

## Buffers (warm-up / cool-down)

- **Warm-up before slot (minutes)** blocks preparation time before each slot; **Cool-down after slot (minutes)** blocks follow-up time (cleaning, debrief). `0` disables the respective buffer.
- **Adjacent buffer handling** decides what happens when one slot's cool-down meets the next slot's warm-up: **"Buffers may overlap"** requires only the longer of the two, **"Buffers are summed"** requires both, one after the other.
- Slots that fall into another booking's buffer, or whose **location (entity) is already occupied** at that time, are shown as not bookable.

## Examiners (teachers per slot)

For fixed, rolling and session slots you can attach examiners:

- **Add examiners to slots** with an **Examiner pool** (autocomplete) and **Examiners required per slot**.
- Participants may have to pick an examiner when booking a slot.
- **Student examiner assignments** (`slotteacherassignments.php`): assign one or more examiners from the option's pool to each enrolled student (capability `mod/booking:manageslotunavailability`, `mod/booking:updatebooking` also grants access).
- **Examiner unavailability**: examiners can be blocked with unavailability blocks, managed through a form reachable from the Bookings Tracker. Marking works in two modes — mark **unavailable** slots (red) or mark **available** slots (green, everything else in scope becomes unavailable) — and in different scopes: a specific slot option, all slot options of a booking instance, or system-wide for that examiner. Capability: `mod/booking:manageslotunavailability`.
- **Slot calendar** (`slotcalendar.php`): a per-day occupancy view showing the slots, booked students and booked examiners of an option.

## Slot rules: closed slots and prices

The **slot rule editor** (link in the option form, or `slotrules.php?optionid=<id>`; the option must be saved once first) manages per-slot deviations:

- **Closed slots** — block slots by weekday and time window, optionally limited to an active date range.
- **Price adjustment** — change the price of matching slots: as an **absolute value**, a **delta**, or a **factor** on the base price, optionally per price category and currency. Rules have a **priority** to resolve overlaps.

## Moving, cancelling and rebooking slots

- **Move/cancel deadline** (relative to each slot's start): site-wide default in the plugin setting `booking/slot_change_deadline_minutes`, overridable per booking instance and per option (choices from "until 24 hours before start" to "until 1 hour after start"; "Use default" inherits). Each booked slot is evaluated individually; locked slots (deadline passed) can neither be cancelled nor moved.
- **Participants**: with **"Allow rebooking"** enabled on the option, participants can move their own future slots to another free slot (capability `mod/booking:moveslotsself`, page `rebookslot.php`). Only slots that have not started can be given up, only future slots can be chosen, and rebooking is limited to slots with the same price. The **"Move/Cancel your slot(s)"** tab lets users update their whole booking in one step — the confirmation dialog lists added, moved and cancelled slots and shows the resulting **additional payment** or **credit refund** before saving.
- **Staff**: users with `mod/booking:moveslots` (or `mod/booking:updatebooking`) can move any participant's slot (`moveslot.php`), with an optional reason; the participant (and the examiner, where applicable) is notified. Price differences are settled through the shopping cart — refunds arrive as credit.

## Notifications and booking rules

Slot activity fires its own events that can trigger [booking rules](../booking_rules/README.md): **Booking slot booked**, **Booking slot moved** and **Booking slot cancelled**. In rule mails the placeholders `{slotsbooked}`, `{slotscancelled}`, `{slotsmovedfrom}` and `{slotsmovedto}` render the slots carried by the triggering event.

## Reports and management notes

- The report of a slot option shows the number of **booked slots**, the **slot price paid** and the **assigned examiners** per participant.
- **Booking other users** (`subscribeusers.php`) is disabled for slot options — bookings for third parties are made via the cashier; unenrolment works through the responses list.

## Related capabilities

| Capability | Purpose |
|------------|---------|
| `mod/booking:moveslots` | Move booked slots of other users |
| `mod/booking:moveslotsself` | Rebook one's own booked slots |
| `mod/booking:manageslotunavailability` | Manage examiner unavailability and student–examiner assignments |

## See also

- [Booking option form](../booking-option/README.md)
- [Booking rules](../booking_rules/README.md)
- [Bookings Tracker](../reports/README.md)

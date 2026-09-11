[Back to slot booking](README.md)

# The booking flow

This chapter describes what a participant sees and does, from the option list to the confirmation.

---

## Where the slot picker appears

The slot picker is a step of the pre-booking wizard. Depending on how the option is presented, it appears in one of three ways:

1. **In the booking modal** — the normal case. The participant presses **Book now** (or **Add to cart** for a paid option) and the calendar opens as a step of the wizard. A **Continue** button at the bottom commits the selection and moves on to the remaining steps.
2. **Inline on the page** — when the option is embedded with the shortcode `[bookingoptionview optionid="…" inlinestartpage="slotbooking"]`, the calendar is rendered directly on the page and only the remaining steps open in a modal.
3. **As a persistent calendar** — after booking, the calendar stays visible so participants can see their own booked slots. In this state there is no Continue button.

Above the picker, participants always read:

> **"Please choose one available slot before continuing with the booking."**

If nothing is bookable at all, the picker is replaced by:

> **"There are currently no open slots available."**

---

## The four picker variants

Which picker a participant gets follows from *Slot booking interface* and *Max slots per user*:

| Configuration | What the participant sees |
|---------------|---------------------------|
| **Calendar view** (fixed, rolling, sessions) | A month/week calendar on the left, the slots of the selected day as a proportional timeline on the right |
| **List view** with *Max slots per user* > 1 | A flat, clickable list of all slots, grouped by day label |
| **List view** with *Max slots per user* = 1 | A simple dropdown, with the days as group headings |
| **User-defined** slot type | Calendar on the left, a *Duration* + *Start* editor with a clickable day timeline on the right |

---

## The calendar

- **Toolbar:** back/forward arrows, the current month or week in the middle, and a **Month** / **Week** switch on the right.
- **Navigation is bounded by data:** the arrows only step to months or weeks that actually contain slots, and are disabled at the ends. Switching between month and week never lands on an empty period.
- **Day cells** are outlined in green when they contain slots and show how many (*"6 slots"*). The selected day is highlighted; a day on which the participant already holds a booking is marked with a **★** and an inset ring.
- **Prices:** when the option is paid, each day cell carries small coloured dots — one per distinct price on that day, from green (cheap or free) to red (expensive) — with a legend above the grid.
- A counter under the calendar shows the current selection, e.g. **"1/2 selected"**.
- Days without bookable slots show *"No open slots on this day."*

After a validation error the calendar stays on the day the participant was looking at instead of jumping back to today.

## The day timeline

Clicking a day draws that day's slots as blocks on a shared, proportional time axis, so a 10:00 slot always sits at the same height. Each block shows its time range, the price if any, and the assigned examiners. Blocks are colour-coded:

| Appearance | Meaning |
|------------|---------|
| Normal | free and selectable |
| Highlighted | currently selected |
| Marked **"Booked"** | the participant's own booking — not clickable |
| Hatched buffer bar | warm-up or cool-down around a booking |

Slots that are **full or otherwise unavailable are not displayed at all** — only the participant's own bookings stay visible, marked as booked. This is deliberate: a schedule should show what can still be taken, not a wall of blocked entries.

---

## Times and time zones

All slot times are displayed in the **time zone of the person looking at them**. A slot configured as 09:00–11:00 in a site running on America/Chicago is shown to a participant in Europe/Vienna as 16:00–18:00. Bookings, buffers and deadlines all refer to the same absolute point in time; only the presentation differs.

> Times inside the graphical day timeline are currently rendered in 24-hour format, while the lists and tables follow the language's own convention (e.g. 4:20 PM). The times are identical, the formatting is not yet unified.

---

## Selecting slots

- Click a free slot to select it, click it again to deselect.
- When *Max slots per user* is **1**, picking another slot silently replaces the previous choice.
- When it is greater than 1, further slots are added until the limit is reached; additional clicks are then ignored.
- Slots that are already booked by the participant cannot be selected.

Every change is checked against the server after a moment, and the result is shown right under the picker: either the first error in red, or — for paid options — the running total in green. Typical messages:

| Message | Reason |
|---------|--------|
| *"The selected slot is no longer available. Please choose another one."* | Someone else took it, it collides with an existing booking, or it lies outside the allowed times |
| *"Please select no more than N slot(s)."* | More slots selected than *Max slots per user* allows |
| *"The selected slots overlap each other. Please choose non-overlapping slots."* | Two selected slots overlap |
| *"Please select a valid slot."* | Nothing selected, or the selection is incomplete |
| *"Please select an examiner."* | Examiners are required but none (or too few) were chosen |
| *"This slot is unavailable because it falls within the preparation or follow-up time of another booking."* | Buffer conflict |
| *"This slot is unavailable because the location is already booked at this time."* | The entity (room, resource) is occupied |
| *"You already have a booking for this option and booking again is not currently allowed."* | The *Allow to book again* rule does not permit a further booking yet |

---

## Choosing examiners

When *Examiners required per slot* is greater than zero, an examiner box appears under the calendar, headed for example **"Examiners per slot: 2"**. For every selected slot there is one dropdown listing the examiners who are actually free at that time. If exactly one examiner is required, the dropdown is a single choice; for more, it is a multiple selection.

If the participant has been assigned specific examiners (see [Reporting](reporting.md)), only those may be chosen, and they are preselected.

---

## Confirming

Pressing **Continue** validates the selection on the server and then proceeds with the remaining steps of the booking flow. For a free option the participant ends on the confirmation:

> **"Thank you! You have successfully booked …"**

For a **paid** option, the slot is not booked immediately. It goes into the shopping cart:

> **"Thank you! You have successfully put … into the shopping cart."**

followed by **Proceed to checkout**. See [Prices and shopping cart](prices_and_cart.md).

---

## After booking

The option row in the booking list changes:

- As long as the participant holds **fewer** slots than *Max slots per user*, the row keeps offering **Book now** (or *Book again (already booked N time)*), and lists the booked slots underneath as **Booked slots** with date and time.
- Once the maximum is reached, the row shows the locked state (**Start**) plus the list of booked slots.
- The number shown on the option row counts the **slots still available to this participant** — it goes down by one with every booking. Administrators can switch this to the legacy "booked / available places" display with the site setting *Slot booking count display in option list*.

If the participant may cancel, the row offers **Undo my booking**; a second click confirms (*"Click again to confirm cancellation"*). Cancelling frees the slot immediately for everybody, including for the same participant.

Participants who are allowed to move their own slots get a second tab inside the picker — **Book another slot** and **Move/Cancel your slot(s)** — described in [Moving, cancelling and rebooking](move_cancel_rebook.md).

---

## Mobile and small screens

There is no separate slot picker for the Moodle app; the web picker is used. On small screens the calendar and the day editor stack vertically, the option sidebar moves above the calendar, and the slot grid falls back from three columns to two and finally one.

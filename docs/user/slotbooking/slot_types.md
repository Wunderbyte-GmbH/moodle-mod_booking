[Back to slot booking](README.md)

# Slot types

The **Slot type** field decides how the bookable slots of an option come into existence. It is the first decision to make, because it determines which of the other fields are shown at all.

| Type (stored value) | Label in the form | Slots are… |
|---------------------|-------------------|------------|
| `fixed` | **Fixed** | generated as a back-to-back grid inside the daily time window |
| `rolling` | **Rolling** | generated with their own repeat interval, so they may overlap each other |
| `session` | **From option dates (sessions)** | exactly the option dates defined in the Dates section |
| `userdefined` | **User-defined** | not pre-generated — the participant picks start and duration |

> **Changing the slot type of an option that already has bookings** is possible, but existing bookings keep their stored slot times while the grid around them changes. Change the type only on options without bookings, or check the existing bookings afterwards in the [slot calendar](reporting.md).

---

## Fixed

The classic appointment grid. Starting at the **Opening time**, slots of **Slot duration (minutes)** follow each other until the **Closing time** is reached, on every selected weekday inside the validity window.

If warm-up or cool-down buffers are configured, they are **built into the rhythm of the grid**: the distance from one slot start to the next is

```
slot duration + required gap
```

where the required gap follows the *Adjacent buffer handling* setting (see [Capacity and availability](capacity_and_availability.md#buffers)). A slot is only generated if it still fits into the day *including* its warm-up and cool-down.

The consequence is important and intentional: the rhythm of a fixed schedule depends only on the configuration, **never on which slots happen to be booked**. Everybody sees the same grid.

*Example:* opening 09:00, closing 11:00, duration 20 minutes, no buffers → 09:00–09:20, 09:20–09:40, 09:40–10:00, 10:00–10:20, 10:20–10:40, 10:40–11:00 (six slots).

*Example with buffers:* duration 30, warm-up 5, cool-down 10, mode "Buffers are summed" → the next slot starts 45 minutes after the previous one.

**The field *Slot interval* is not used** for this type.

---

## Rolling

Rolling slots have their own, independent **Slot interval (minutes)**: a new slot starts every *interval* minutes, and each slot lasts *duration* minutes. When the interval is smaller than the duration, the candidate slots **overlap each other**.

*Example:* opening 16:00, closing 18:00, duration 40, interval 20 → 16:00–16:40, 16:20–17:00, 16:40–17:20, 17:00–17:40, 17:20–18:00 (five overlapping candidates).

This is the type to use when appointments should be able to start "every quarter of an hour" without forcing everyone onto the same rigid grid.

Two behaviours follow from the overlap and often surprise first-time users:

- **Only one of several overlapping candidates can ever be booked.** As soon as one candidate is taken, every candidate that overlaps it becomes unavailable — regardless of how much capacity is left. Capacity only governs how many people share the *identical* slot (see [Capacity and availability](capacity_and_availability.md#time-exclusivity)).
- **Buffers are not baked into the grid.** They are enforced dynamically when booking: a candidate that falls inside the warm-up or cool-down of an existing booking is rejected with *"This slot is unavailable because it falls within the preparation or follow-up time of another booking."* In the calendar, buffer bars are drawn only around actual bookings, not around every candidate.

---

## From option dates (sessions)

The bookable slots are exactly the **option dates** (sessions) that are defined in the *Dates* section of the booking option form. Every date with a valid start and end becomes one slot.

This is the only slot type for which option dates are allowed. For all other types, the Dates section shows the warning *"Dates are not used for this slot type. Option dates are only allowed when slot type is 'From option dates (sessions)'."*

Because the slots come from the dates, the whole schedule configuration is ignored and hidden in the form: opening and closing time, valid from/until and the weekday checkboxes have no effect (they are stored as "the whole day, every weekday, unlimited").

Typical use: a fixed set of exam sessions or consultation appointments that were entered by hand and should now be distributed among participants, each with its own capacity.

*Example:* two dates *tomorrow 09:00–09:45* and *tomorrow 14:00–14:45*, *Max participants per slot* = 1 → the first participant takes one of the two, the second participant can only take the remaining one.

---

## User-defined

There is no grid at all. Participants choose

- a **start time** on a day — offered on the grid defined by **Slot start interval (minutes)**, and
- a **duration** — a dropdown from **Minimal slot length** to **Maximal slot length** in steps of **Slot duration step (minutes)**.

**Max days for one slot** allows slots that span more than one day (the default of one day keeps slots inside a single day).

The participant's chosen slot must lie inside the opening hours **of every day it spans**, on an allowed weekday, inside the validity window, and must not collide with existing bookings or their buffers. The days offered in the calendar run from today up to 90 days ahead, clipped by *Valid from* / *Valid until*; a day is only offered when at least one start of minimum length is actually bookable on it.

Restrictions of this type:

- **Examiner features are not available** (the examiner fields are hidden and forced off).
- The *Slot booking interface* choice does not apply — user-defined options always use the calendar with a free-form editor.
- Multi-option calendars with user-defined options work, but the option chooser then behaves as a **single-select** (see [Multi-option calendar](multi_option_calendar.md)).

*Example:* opening 09:00, closing 17:00, minimal length 45 min, maximal length 45 min, start interval 15 → the participant may start at 09:00, 09:15, 09:30 … and always books 45 minutes.

> **Note on the shipped defaults:** *Maximal slot length* defaults to 30 minutes while *Minimal slot length* defaults to 60 minutes. Always set both fields explicitly for user-defined options instead of relying on the defaults.

---

## Which type for which use case?

| Use case | Recommended type |
|----------|------------------|
| Oral exams, 20 minutes each, one after another | **Fixed** |
| Consultation appointments that may start every 15 minutes | **Rolling** |
| A handful of hand-picked exam dates | **From option dates (sessions)** |
| Room, court or equipment rental with a free start and length | **User-defined** |

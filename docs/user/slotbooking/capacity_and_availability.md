[Back to slot booking](README.md)

# Capacity and availability

This chapter explains why a particular slot is offered, marked as booked, or not shown at all. The rules are applied in the order below — the first one that fails decides.

1. The location (entity) is occupied
2. A buffer (warm-up / cool-down) collides
3. Another booking overlaps in time
4. The slot is full
5. Examiners are missing
6. The participant has an overlapping booking in another option

---

## Per-slot capacity

**Max participants per slot** is the number of participants that may book the **same** slot. Every booking answer counts once, no matter how many slots it contains.

Bookings on the **waiting list**, **reserved** bookings and **notification list** entries occupy capacity as well; only cancelled and deleted bookings free it up. A slot that is being paid for in the shopping cart is held too (see [Prices and shopping cart](prices_and_cart.md)).

When a slot is full, it simply **disappears** from the picker for everybody who has not booked it. Participants who booked it keep seeing it, marked **Booked**.

---

## Time exclusivity

This rule is the one most worth understanding, especially for rolling slots:

> **Capacity only governs how many participants share the exact same slot. Any booking that merely *overlaps* another one in time is impossible, regardless of remaining capacity.**

The reason is that the resource behind a slot — a room, an examiner, a court, a device — exists once and cannot be in two overlapping appointments at the same time. So:

- Two participants can book **09:00–09:30** together as long as *Max participants per slot* is 2 or more.
- Once **09:00–09:30** is booked, **09:15–09:45** is impossible for everyone, even if the first slot still has a free place.

This also applies to a participant's **own other bookings**: two of their own bookings may not overlap either. Re-selecting or re-validating a slot they already hold is of course always allowed.

---

## Buffers

Two fields put protected time around every booking:

- **Warm-up before slot (minutes)** — *"Blocks this many minutes before a slot's start, e.g. for preparation."*
- **Cool-down after slot (minutes)** — *"Blocks this many minutes after a slot's end, e.g. for cleaning or follow-up."*

**Adjacent buffer handling** decides what happens where the cool-down of one booking meets the warm-up of the next:

| Mode | Required gap between two bookings |
|------|-----------------------------------|
| **Buffers are summed** (default) | cool-down **+** warm-up |
| **Buffers may overlap** | the **longer** of the two |

*Example:* warm-up 10 minutes, cool-down 15 minutes. "Buffers may overlap" requires 15 minutes between two bookings, "Buffers are summed" requires 25.

Two important details:

- For the **fixed** slot type the buffers are **built into the grid** — the generated slots already keep the required distance, and the grid looks the same whether or not anything is booked.
- For **rolling**, **sessions** and **user-defined** slots the buffers are enforced **when booking**. A slot that falls inside the buffer of an existing booking is refused with *"This slot is unavailable because it falls within the preparation or follow-up time of another booking."* In the calendar, buffer bars are only drawn around actual bookings.

Both buffers at 0 disable the whole mechanism, with no effect on performance.

---

## Entities and shared capacity

If the option is linked to an **entity** (a room, a resource — from `local_entities`), slots can be blocked by activity in *other* booking options that use the same entity, and vice versa: booked slots also block overlapping dates of other options on that entity.

How strictly this is applied depends on the **allocation mode** of the entity:

| Allocation mode | Effect on slots |
|-----------------|-----------------|
| **none** (default) | No cross-option checking at all. |
| **capacity** | The entity is a pool of a given number of units. Every booked slot consumes one unit, other bookings consume their configured quantity. The slot is only blocked when the pool is exhausted — a mere time overlap is fine while units remain. |
| exclusive / any other | **Any** overlapping reservation of another option blocks the slot. |

Blocked slots produce *"This slot is unavailable because the location is already booked at this time."*

An option never blocks its own slots through its own dates. At the moment a booking is finally written, the occupancy is re-read live so two people cannot take the last unit simultaneously.

> Cross-option entity capacity requires `local_entities` 0.5.0 or newer. On older installations slots have no entity constraint.

---

## Examiners

When an option has an examiner pool, availability additionally depends on the examiners:

- An examiner is unavailable in a slot if they are blocked by an **unavailability** entry (for this option or site-wide), or if they are already booked in an overlapping slot — **including in other booking options**.
- If the participant has **assigned examiners**, those examiners must be free, and only they may be chosen.
- If no examiners are explicitly selected, the slot needs at least as many free examiners as *Examiners required per slot*.

If these conditions are not met, the slot is not bookable (*"The selected slot is no longer available. Please choose another one."*), or the selection is rejected with *"Please select an examiner."*

---

## Overlaps with other booking options

If the option uses the availability condition **"No overlapping"**, a slot that overlaps one of the participant's bookings in *other* options is either

- **blocked**: *"This option cannot be booked as it overlaps with your already booked option(s): …"*, or
- only **warned about**: *"Warning, this option overlaps with your already booked option(s): …"* — the slot stays bookable and is marked with an exclamation mark in the picker.

---

## How many slots one participant may hold

**Max slots per user** limits how many slots a participant holds at the same time. It is checked when the selection is saved, when the form is submitted and again when the booking is written.

It works **independently of** the instance setting *Allow to book again*:

| Setting | Controls |
|---------|----------|
| **Max slots per user** | How many slots may be held **at once** |
| **Allow to book again** | Whether a **new booking round** may be started at all — never, after a fixed waiting time, or after the last booked slot has ended |

Both can be combined. If *Allow to book again* forbids a further booking, the attempt is refused with *"You already have a booking for this option and booking again is not currently allowed."* — even if the participant is below their slot maximum.

---

## What frees a slot again

| Action | Effect |
|--------|--------|
| Participant cancels the whole booking (**Undo my booking**) | All slots of that booking become free |
| Participant releases individual slots in the Move/Cancel tab | Only those slots become free |
| Staff cancel or move a booking | The affected slots become free |
| A paid rebooking in the cart expires or is abandoned | The held target slot becomes free |

A full cancellation is only offered while **every** booked slot is still inside its move/cancel deadline. If one slot is already locked, the participant must release the remaining slots individually. See [Moving, cancelling and rebooking](move_cancel_rebook.md).

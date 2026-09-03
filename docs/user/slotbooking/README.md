[Back to documentation index](../README.md)

# Slot booking

Slot booking turns a booking option into a set of **individually bookable time slots** — for appointments, exam sessions, consultations, office hours, equipment rental, court or room reservations and similar use cases. Instead of booking the option as a whole, participants pick one or more free slots from a schedule.

> **Availability:** Slot booking is a [Booking PRO](https://wunderbyte.at) feature. It must additionally be switched on site-wide with the plugin setting **"Enable slot booking"** (`booking/slotbookingactive`, on by default). When that setting is off, slot booking is unavailable in the option type list, the booking flow and the AI skill, even with a valid PRO licence.

---

## Chapters

| I want to… | Go to… |
|------------|--------|
| Understand the four ways slots can be generated | [Slot types](slot_types.md) |
| Configure a slot option — every field explained | [Option configuration](option_configuration.md) |
| Know what participants see and do when booking | [The booking flow](booking_flow.md) |
| Show the slots of several options in one calendar | [Multi-option calendar](multi_option_calendar.md) |
| Understand who can book which slot, and why a slot disappears | [Capacity and availability](capacity_and_availability.md) |
| Close individual slots or give them their own price | [Slot rules](slot_rules.md) |
| Set up paid slots, the shopping cart and refunds | [Prices and shopping cart](prices_and_cart.md) |
| Let participants move slots, or move them as staff | [Moving, cancelling and rebooking](move_cancel_rebook.md) |
| Send mails when slots are booked, moved or cancelled | [Notifications and events](notifications_and_events.md) |
| See who booked which slot | [Reporting](reporting.md) |
| Look up capabilities, settings, web services, limitations | [Reference](reference.md) |

---

## In a nutshell

1. **Switch the option type to "Slot booking"** in the booking option form. A new section **Slot Booking Settings** appears.
2. **Choose a slot type** — *Fixed*, *Rolling*, *From option dates (sessions)* or *User-defined* — and define the schedule window (valid from/until, opening and closing time, weekdays).
3. **Set the capacity**: how many participants share one slot (*Max participants per slot*) and how many slots one participant may hold (*Max slots per user*).
4. Optionally add **examiners**, **buffers**, **slot rules** (closed slots, individual prices) and a **move/cancel deadline**.
5. Participants press **Book now** (or **Add to cart** for paid options), pick their slot in the calendar or list, and confirm.

The base price in the Price section of the option is the **base price per slot**; slot rules can raise or lower it for individual slots. See [Prices and shopping cart](prices_and_cart.md).

---

## How slot booking differs from other booking features

| Feature | Use it when… |
|---------|--------------|
| **Slot booking** (this chapter) | The option itself *is* a schedule: every participant picks their own individual appointment out of a generated grid. Capacity, prices, buffers and deadlines are managed per slot. |
| [Sub-booking "time slot"](../subbookings/subbooking_timeslot.md) | The option is booked normally and the time slot is an **add-on** to that booking. |
| Normal option with several dates | Everybody who books attends **all** dates of the option (a course, a seminar series). |

A slot option is not booked "as a whole": the option row in the booking list stays bookable until the participant has reached *Max slots per user*, and every booked slot is listed underneath it.

---

## Terminology

| Term | Meaning |
|------|---------|
| **Slot** | One bookable time range, identified internally by its start and end time (`start:end`). |
| **Slot grid** | The set of slots generated from the configuration for a given day. Slots are *virtual* — they are computed on the fly, not stored as records (except for the *sessions* type, where they are the option dates). |
| **Examiner** | A teacher from the option's examiner pool who is assigned to, or chosen for, an individual slot. |
| **Buffer** | Warm-up time before and cool-down time after a slot in which no other booking may take place. |
| **Hold** | A slot temporarily reserved for a participant while a paid rebooking is waiting in the shopping cart. |

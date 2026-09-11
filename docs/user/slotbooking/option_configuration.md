[Back to slot booking](README.md)

# Option configuration

This chapter documents **every field** of the **Slot Booking Settings** section of the booking option form, plus the neighbouring fields in other sections that behave differently for slot options.

---

## Turning an option into a slot option

In the option form, set **Type** to **"Slot booking"**. The entry only appears when the site has a PRO licence and slot booking is switched on — otherwise a hint is shown: *"With Booking PRO you can use the time slot booking feature."*

Two safeguards apply:

- An option that **already is** a slot option keeps "Slot booking" in the list even if the licence or the site setting lapses, so editing the option never silently resets its type.
- If a slot option **already has booking answers** and you change its type, a warning appears — *"This slot option already has booking answers. Changing the option type may invalidate existing slot bookings. Please confirm to continue."* — together with the checkbox *"I understand and confirm changing the option type."*

Switching *to* slot booking without a PRO licence is refused with *"Only available in PRO version"*; with a licence but the site setting off, with *"Please turn this on in the settings."*

---

## Fields of the Slot Booking Settings section

Fields are listed in form order. The column *Types* shows for which slot types the field is visible and effective.

### Schedule and generation

| Field | Label | Type | Default | Types | Effect |
|-------|-------|------|---------|-------|--------|
| `slot_type` | **Slot type** | select | Fixed | all | See [Slot types](slot_types.md). Changing it reloads the form so that the matching fields appear. |
| `slot_booking_view_mode` | **Slot booking interface** | select: *List view* / *Calendar view* | Calendar view | fixed, rolling, session | Which picker participants get. See [The booking flow](booking_flow.md). |
| `slot_duration_minutes` | **Slot duration (minutes)** | number | 30 | fixed, rolling | Length of one generated slot. |
| `slot_interval_minutes` | **Slot interval (minutes)** | number | 15 | rolling | Distance between two consecutive slot **starts**. Smaller than the duration ⇒ overlapping candidates. |
| `slot_custom_max_duration` | **Maximal slot length** | duration | 30 min | userdefined | Upper bound of the duration the participant may choose. |
| `slot_custom_min_duration` | **Minimal slot length** | duration | 1 hour | userdefined | Lower bound of the choosable duration. |
| `slot_custom_max_days` | **Max days for one slot** | duration, min. 1 day | 1 day | userdefined | How many days a single slot may span. |
| `slot_custom_start_interval_minutes` | **Slot start interval (minutes)** | select 1/5/10/15/30/60 | 30 | userdefined | Grid on which a user-defined slot may start. |
| `slot_custom_duration_step_minutes` | **Slot duration step (minutes)** | select 1/5/10/15/30/60 | 15 | userdefined | Granularity of the duration dropdown between minimum and maximum. |
| `slot_opening_time` | **Opening time (HH:MM)** | text `HH:MM` | 08:00 | fixed, rolling, userdefined | Earliest time of day a slot may start. |
| `slot_closing_time` | **Closing time (HH:MM)** | text `HH:MM` | 18:00 | fixed, rolling, userdefined | Latest time of day a slot (including its buffers, for fixed) may end. |
| `slot_valid_from` | **Valid from** | date | semester start, if any | fixed, rolling, userdefined | First day on which slots exist. |
| `slot_valid_until` | **Valid until** | date | semester end, if any | fixed, rolling, userdefined | Last day on which slots exist (the whole day is included). |
| `slot_day_1` … `slot_day_7` | **Monday** … **Sunday** | checkboxes | Mon–Fri ticked | fixed, rolling, userdefined | Weekdays on which slots are generated. If none is ticked, Monday–Friday is stored. |

> For the *sessions* type, opening/closing time, validity and weekdays are hidden and stored as "always" — the dates themselves define the slots.

### Capacity

| Field | Label | Type | Default | Effect |
|-------|-------|------|---------|--------|
| `slot_max_participants_per_slot` | **Max participants per slot** | number | 1 | How many participants may book the **same** slot. |
| `slot_max_slots_per_user` | **Max slots per user** | number | 1 | How many separate slots one participant may hold for this option at the same time. |

The help text of *Max slots per user* spells out the relationship to the instance setting *Allow to book again*:

> "The total number of separate slots a user may hold for this option at once (e.g. buying several slots as individual purchases over time). This is independent of the instance-level 'Allow to book again' setting: that setting only controls whether a user may re-book after their booking has ended or after a wait time, and does not limit how many slots they can hold. Both settings can be combined, but neither one substitutes for the other."

In practice, participants stay able to press **Book now** until they hold *Max slots per user* slots; from then on the option row shows the locked state. See [Capacity and availability](capacity_and_availability.md).

### Examiners

| Field | Label | Type | Default | Effect |
|-------|-------|------|---------|--------|
| `slot_add_examiners` | **Add examiners to slots** | select No/Yes | No | Master switch of the examiner block. Not available for user-defined slots. |
| `slot_teacher_pool` | **Examiner pool** | user autocomplete (multiple) | empty | The users who can act as examiners for slots of this option. Choices are the users enrolled in the course. |
| `slot_teachers_required` | **Examiners required per slot** | number | 0 | How many examiners a participant must select per slot. `0` = none. |

When *Add examiners to slots* is set back to No, the pool and the required count are cleared. When you reopen the form, the switch is derived from the stored data: it shows *Yes* whenever a pool exists or examiners are required.

Two more examiner pages are reachable from the report of the option — **Student examiner assignments** and **Examiner unavailability**; see [Reporting](reporting.md).

### Rebooking, deadline and buffers

| Field | Label | Type | Default | Effect |
|-------|-------|------|---------|--------|
| `slot_allow_self_rebooking` | **Allow rebooking** | checkbox | off | Participants may move their own booked slots. |
| `slot_change_deadline_minutes` | **Move/cancel deadline (relative to slot start)** | select | *Use default* | Until when a participant may move or cancel a slot. |
| `slot_buffer_warmup_minutes` | **Warm-up before slot (minutes)** | number | 0 | Blocked time before a slot, e.g. for preparation. |
| `slot_buffer_cooldown_minutes` | **Cool-down after slot (minutes)** | number | 0 | Blocked time after a slot, e.g. for cleaning or follow-up. |
| `slot_buffer_combination_mode` | **Adjacent buffer handling** | select | *Buffers are summed* | Whether the cool-down of one slot and the warm-up of the next add up or may overlap. |

*Allow rebooking* help text: *"If enabled, participants can move their own booked slots to another free slot themselves. Only slots that have not yet started can be given up, and only future slots can be chosen as a target. In this first version, rebooking is limited to slots with the same price."*

The deadline dropdown offers: *Use default*, *Until 24 hours before slot start*, *Until 12 hours before slot start*, *Until 2 hours before slot start*, *Until 1 hour before slot start*, *Until 30 minutes before slot start*, *Until slot start*, *Until 30 minutes after slot start*, *Until 1 hour after slot start*. *Use default* inherits the booking instance setting, which in turn inherits the site setting. The deadline is evaluated **per individual slot**. Details in [Moving, cancelling and rebooking](move_cancel_rebook.md).

Buffer behaviour is explained in [Capacity and availability](capacity_and_availability.md#buffers).

### Slot rules link

At the end of the section, saved options show the link **"Open slot rule editor"**; unsaved ones show *"Save this booking option first to manage slot rules."*

> **Note:** in the Slot Booking Settings section this link is only rendered when *Add examiners to slots* is set to Yes. Because slot rules also control **closed slots and prices**, use the identical link in the **Price** section of the option form, which is always shown — or open the editor directly at `/mod/booking/slotrules.php?id=<cmid>&optionid=<optionid>`.

---

## Validation messages

The form rejects invalid configurations with these messages:

| Situation | Message |
|-----------|---------|
| Duration, interval, start interval, duration step, participants per slot or slots per user is 0 or negative | *"Value must be greater than 0."* |
| Warm-up, cool-down or required examiners is negative | *"Value must be 0 or greater."* |
| Opening or closing time is not `HH:MM` | *"Please use HH:MM format."* |
| *Valid until* lies before *Valid from* | *"Valid until must be after valid from."* |
| Max days for one slot is below one day | *"Value must be greater than 0."* |

If the closing time is at or before the opening time, no error is raised, but **no slots are generated** — participants see *"There are currently no open slots available."*

---

## Fields in other sections that behave differently

| Section | Behaviour for slot options |
|---------|---------------------------|
| **Price** | The price entered here is the **base price of one slot**, not of the whole option. A hint says so, and the *Open slot rule editor* link is offered right below. See [Prices and shopping cart](prices_and_cart.md). |
| **Dates** | Only allowed for the *sessions* slot type. All other types show *"Dates are not used for this slot type…"*. |
| **Booking instance settings** | *Move/cancel deadline (relative to slot start)* can be preset per booking instance and is inherited by options that use *Use default*. The *Allow to book again* setting offers the slot-specific mode **"Allow after the last booked slot has ended"**. |
| **Responses page** | Participants cannot be subscribed manually: *"Because slot booking is enabled for this option, users cannot be booked here."* Bookings for others run through the cashier; unenrolment is done in the list on the responses page. |

---

## Creating slot options with the AI assistant

If the booking AI assistant (`bookingextension_agent`) is installed, slot options can be created from a natural-language request — for example office hours, consultation slots, or hourly court reservations. The assistant fills in the same fields documented above and always sets an explicit *Max participants per slot*; weekday phrases are translated into the weekday checkboxes.

The skill requires a PRO licence (otherwise it reports *"Only available in PRO version"*) and the capability `mod/booking:skill_mod_booking_create_slotbooking_option`. It is deliberately **not** used for numbered lecture series or single dated events — those are ordinary booking options.

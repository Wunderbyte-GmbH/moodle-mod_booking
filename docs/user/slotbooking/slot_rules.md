[Back to slot booking](README.md)

# Slot rules

Slot rules modify the generated schedule of a single option. They can

- **close slots** — remove them from the schedule (lunch breaks, blocked afternoons, holidays), and
- **adjust prices** — make certain slots more or less expensive (early morning discount, prime-time surcharge).

---

## Opening the editor

The **Slot rule editor** is reached from the booking option form:

- **Price section** → **"Open slot rule editor"** (always available on a saved option), or
- **Slot Booking Settings** → *Slot rules* → the same link (shown when *Add examiners to slots* is set to Yes), or
- directly at `/mod/booking/slotrules.php?id=<cmid>&optionid=<optionid>`.

The page requires the capability `mod/booking:updatebooking` or `mod/booking:manageslotunavailability`.

---

## Creating a rule

| Field | Meaning |
|-------|---------|
| **Rule type** | **Closed slots** — matching slots vanish from the schedule. **Price adjustment** — matching slots get a modified price. |
| **Priority** | Rules run from the highest priority downwards. For price rules this matters: later rules build on the result of earlier ones. Default 100. |
| **Limit by active date range** | When ticked, *Active from* / *Active until* restrict the rule to a period. Unticked, the rule always applies. |
| **Active from / Active until** | The period in which the rule is in force. *Active until* includes the whole selected day. |
| **Monday … Sunday** | Restricts the rule to the weekday on which the slot **starts**. No weekday ticked = all weekdays. |
| **Start time / End time (HH:MM)** | The daily time window the rule covers. A rule applies to every slot that **overlaps** that window. Leave both empty for the whole day. |

### Additional fields for price rules

| Field | Meaning |
|-------|---------|
| **Price category identifier** | Which price category the change applies to. `default` applies to everyone; several identifiers can be given, separated by commas. |
| **Price mode** | **Absolute value** — replaces the price. **Delta** — adds to it (negative values discount). **Factor** — multiplies it. |
| **Price value** | The number used by the mode above. |
| **Currency (optional)** | Overrides the currency of that price entry. |

Prices can never become negative; the result is floored at zero.

---

## The rules list

Existing rules are listed under **Existing slot rules** with ID, type, priority, active range, time window, weekdays and the price effect. Each row offers **Edit** and **Delete**; single price entries of a rule can be deleted separately. Deleting always asks for confirmation.

If no rule exists yet, the page shows *"No slot rules exist for this option yet."*

---

## Examples

**Close the lunch break**
Rule type *Closed slots*, weekdays Monday–Friday, start time `12:00`, end time `13:00`. Every slot that overlaps midday to one o'clock disappears from the schedule.

**Block a holiday week**
Rule type *Closed slots*, *Limit by active date range* ticked, *Active from* and *Active until* covering the week, no weekday and no time restriction.

**Charge more for prime time**
Rule type *Price adjustment*, weekdays Monday–Friday, start `17:00`, end `21:00`, price category `default`, mode *Delta*, value `10` — evening slots cost ten units more than the base price.

**Halve the price on Saturdays**
Rule type *Price adjustment*, only Saturday ticked, mode *Factor*, value `0.5`.

**A discount that must win over other rules**
Give the rule a higher **Priority** than the others, or check the resulting prices in the calendar: the price dots on the day cells and the price on each slot always show the final, computed price.

---

## Interaction with the rest of the configuration

- Closed slots are removed **after** the grid has been generated. They therefore do not shift the remaining slots — the schedule keeps its rhythm and simply has gaps.
- Price rules are evaluated **per slot and per participant**, because the participant's price category is part of the match.
- The price stored with a booking is the price computed at the moment of booking. Later rule changes do not retroactively change existing bookings; when a booking is **moved**, the price is recomputed for the new slots.
- Rules apply to the option they were created for. In a [multi-option calendar](multi_option_calendar.md), each option keeps its own rules.

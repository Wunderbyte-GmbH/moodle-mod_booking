[Back to slot booking](README.md)

# Notifications and events

---

## Built-in notifications

Two situations send mail on their own, without any configuration:

| Situation | Who is informed | Subject / body |
|-----------|-----------------|----------------|
| A participant moves their own slot | the participant | *"Your appointment has been moved"* — *"You have moved your appointment. New time: …"* |
| | the examiners of that slot | *"A participant has rebooked an appointment"* — *"Participant … has rebooked from … to …"* |
| Staff move a participant's slot | the participant and the examiners | *"Your booking slot has been moved"* — *"Your booking slot has been moved. New time: … Reason: …"* |

Everything else — booking confirmations, reminders, cancellation notices — is configured as usual with [booking rules](../booking_rules/README.md).

---

## Events

Slot booking fires three events that booking rules can react on:

| Event | Fired when |
|-------|-----------|
| **Booking slot booked** | A slot booking is created |
| **Booking slot moved** | At least one slot of a booking is moved to a new time |
| **Booking slot cancelled** | Slots are released, or the whole booking is cancelled |

An update that both removes and adds slots fires **both** the moved and the cancelled event.

---

## Placeholders for slot information

Inside a rule mail, these placeholders insert the slots carried by the triggering event:

| Placeholder | Contains |
|-------------|----------|
| `{slotsbooked}` | The booked slots of a *Booking slot booked* event |
| `{slotscancelled}` | The cancelled slots of a *Booking slot cancelled* event |
| `{slotsmovedfrom}` | The original slots of a *Booking slot moved* event |
| `{slotsmovedto}` | The new slots of a *Booking slot moved* event |
| `{bookedslotsfromevent}` | The slots of whichever of the three events triggered the rule |

Several slots are listed one after another, separated by semicolons, and formatted like option dates (e.g. *"Wed, 24 June 2026, 10:00 AM - 11:00 AM"*). If the event carries no slots, the placeholder resolves to nothing.

All other [placeholders](../placeholders/README.md) — participant name, option title, links and so on — work as usual.

---

## Recipes

**Confirm a booked slot**

- Rule: *React on event*
- Event: **Booking slot booked**
- Body: `Hello {firstname}, your appointment is confirmed: {slotsbooked}`

**Inform about a move**

- Event: **Booking slot moved**
- Body: `Your appointment was moved from {slotsmovedfrom} to {slotsmovedto}.`

> Remember that participants and examiners already receive a built-in mail for moves. Add a rule only when you want different wording or additional recipients.

**Confirm a cancellation**

- Event: **Booking slot cancelled**
- Body: `The following appointment was cancelled: {slotscancelled}`

**Remind participants the day before**

Use a normal time-based booking rule on the option. Slot times are stored on the booking, so the usual date placeholders resolve to the participant's own slot.

---

## Logs

All three events are written to the Moodle log with the acting user, the affected booking, the number of slots and — for moves — the old and new times plus the reason. The log entry links to the responses page of the option.

[Back to slot booking](README.md)

# Moving, cancelling and rebooking

Slot bookings can be changed in two ways: **participants** may move or release their own slots when the option allows it, and **staff** may move any participant's slots.

---

## The move/cancel deadline

Every slot has its own deadline, expressed **relative to that slot's start**:

| Setting | Meaning |
|---------|---------|
| *Until 24 / 12 / 2 / 1 hour(s) before slot start*, *Until 30 minutes before slot start* | The slot can be changed up to that moment |
| *Until slot start* | Changes are possible until the slot begins |
| *Until 30 minutes / 1 hour after slot start* | A short grace period after the start |

The value is looked up in this order: **option** → **booking instance** → **site setting** (*Move/cancel deadline (relative to slot start)*). Every level can be set to *Use default* to inherit from the next one.

The deadline is evaluated **per individual slot**, not for the whole booking. In a booking with several slots, some may already be locked while others can still be changed. Locked slots are shown as **"Locked (deadline passed)"** and simply stay as they are.

---

## What participants can do

Self-service is only available when the option has **Allow rebooking** switched on and the participant still has at least one slot inside its deadline.

Those participants get a second tab in the booking dialog:

- **Book another slot** — the normal picker, for additional slots (subject to *Max slots per user* and *Allow to book again*)
- **Move/Cancel your slot(s)** — the update editor

### The update editor

The editor opens with the participant's **current slots preselected**. From there:

- **Deselecting** a slot cancels it.
- **Selecting a different slot instead** moves the booking to that slot.
- The booking can **never grow** here — that is what the other tab is for. Attempting it shows *"You cannot add slots here – use "Book another slot" for that. This tab only edits your current slots."*
- Slots past their deadline are pinned and cannot be given up: *"A locked slot (past its deadline) cannot be cancelled or moved."*
- If the option is paid, the price difference is shown live as **"Price change: +10.00 EUR"**.

Pressing **Update my booking** opens a summary dialog, **Confirm booking update**, itemising what will happen — *Cancelled*, *Moved to*, *Added*, and, where money is involved, *"Additional payment due: …"*, *"You will be refunded … as credit."* or *"Your whole booking will be cancelled."* Only after confirming is the change carried out.

Depending on the price difference, the change is applied immediately, refunded as credit, or sent to the shopping cart. See [Prices and shopping cart](prices_and_cart.md).

### Full cancellation

Cancelling the entire booking via **Undo my booking** is offered only while **all** booked slots are still within their deadline. If any slot is already locked, the participant releases the remaining slots individually in the update editor instead.

### Messages participants may see

| Message | Meaning |
|---------|---------|
| *"You are not allowed to rebook this slot."* | Self-rebooking is off, or the booking does not belong to the participant |
| *"A slot that has already started cannot be given up."* | The slot has started |
| *"The rebooking deadline has passed."* | The deadline for that slot is over |
| *"A selected slot is no longer available."* | Somebody else took the target slot in the meantime |
| *"Slot successfully rebooked."* / *"Slot successfully moved."* | The change went through |

---

## What staff can do

Users with **Move booked slots** (`mod/booking:moveslots`) or **Update booking** (`mod/booking:updatebooking`) can move any participant's slots.

The entry points are:

- the column **Move slot** in the responses list / bookings tracker of the option,
- the **Move slot** link in the detail panel of the [slot calendar](reporting.md),
- directly at `/mod/booking/moveslot.php?id=<cmid>&optionid=<optionid>&baid=<answerid>`.

Staff moves use the same editor, but with two differences:

- **Deadlines and locked slots do not apply** — staff can move a slot that a participant could no longer touch.
- The move is **price-neutral**: no shopping cart, no refund, whatever the price difference is. Removing all slots cancels the booking.

A free-text **Reason (optional)** can be entered and is included in the notification and in the log.

---

## Who is informed

Notifications are sent automatically, independently of booking rules:

| Situation | Recipient | Message |
|-----------|-----------|---------|
| Participant moves their own slot | the participant | *"Your appointment has been moved"* — *"You have moved your appointment. New time: …"* |
| Participant moves their own slot | assigned examiners | *"A participant has rebooked an appointment"* — *"Participant … has rebooked from … to …"* |
| Staff move a slot | the participant (and examiners) | *"Your booking slot has been moved"* — *"Your booking slot has been moved. New time: … Reason: …"* |

In addition, the events *Booking slot moved* and *Booking slot cancelled* are fired, which can trigger your own booking rules — see [Notifications and events](notifications_and_events.md). Note that a rule on those events produces a **second** message on top of the built-in one.

---

## What is kept

- Every move is recorded on the booking, so the history of a repeatedly rebooked appointment stays traceable.
- Examiner assignments follow the slots to their new times.
- The stored price is recalculated for the new slots (slot rules are time-dependent, so a moved slot may legitimately cost something else).

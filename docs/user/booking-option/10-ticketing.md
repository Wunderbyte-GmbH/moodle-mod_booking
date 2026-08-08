[Back to booking option index](README.md)

# Ticketing

The **Ticketing** section of the booking option form controls whether participants get a personalised
PDF entry ticket with a QR code when they book this option, what it looks like, and how strictly it
is checked at the door.

The section is only shown when **Enable entry tickets** is switched on in the booking site settings.

For the complete feature — site settings, ticket designs, delivery, scanner, verification page — see
[Entry tickets (SofaTicket)](../ticketing/README.md).

---

## Quick setup path

1. Open the booking option form and scroll to **Ticketing**.
2. Pick a **Ticket design**. This alone switches ticketing on for the option.
3. Decide whether the ticket is **personalised** (default) or freely transferable.
4. For exams, switch on **Require identity confirmation**.
5. Optionally add **Additional ticket information**.
6. Save, then create a [booking rule](../booking_rules/actions.md#6-send_ticket--send-the-entry-ticket)
   that sends the ticket.

---

## Fields

### Ticket design

The certificate template used as the layout of the ticket.

**Leave this empty and no tickets are created for this option** — this is the on/off switch for a
single booking option. The remaining fields stay hidden until a design is selected.

Any certificate template on your site can be used. To get a ready-made one, click **Create example
ticket template** in the booking site settings.

### Personalised ticket

*On by default.*

A personalised ticket is only valid for the person it was created for and may not be passed on or
resold. The [public verification page](../ticketing/README.md#8-the-public-verification-page) states
this clearly, so someone who is offered the ticket can see that it cannot legally change hands.

Switch it off for tickets that may be given away — a general admission voucher, for example.

> This flag is a statement about the ticket, shown to whoever checks it. It does not by itself stop
> a scan; for a hard check at the door use *Require identity confirmation*.

### Require identity confirmation

*Off by default.*

When off, scanning a ticket checks the participant in immediately — fast queues for concerts and
conferences.

When on, a scan first shows the holder's **name and profile picture** and waits for the staff member
to press **Confirm entry** or **Reject**. Only a confirmation records the check-in.

Use this for **exams** and any event where the ticket must belong to the person using it. It works
best when profile pictures are maintained by staff or a student management system rather than by the
users themselves.

The rule is enforced on the server, so it cannot be bypassed by a manipulated client.

### Additional ticket information

Free text printed on the ticket — entry rules, directions, what to bring, a support phone number.

> The text is only visible if the ticket design contains a **Field** element showing *Additional
> ticket information*. The shipped example template already has one.

---

## What happens after saving

Tickets are created **automatically** when someone books the option. They are **not** sent
automatically — sending is a [booking rule](../booking_rules/README.md) with the action **Send
ticket**, so you control the timing and the wording.

Participants find their tickets under *Profile → My tickets* and in *My bookings*.

---

## Related pages

- [Entry tickets (SofaTicket)](../ticketing/README.md) — the complete feature guide
- [Booking rules — Actions](../booking_rules/actions.md) — the *Send ticket* action
- [07 — Advanced options](07-advanced.md) — cancellation settings that also invalidate tickets

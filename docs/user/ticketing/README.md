[Back to parent section](../../../README.md)

# Entry Tickets (SofaTicket)

Entry tickets are personalised PDF tickets with a QR code. A ticket is created automatically when
someone books a booking option, it is delivered by a [booking rule](../booking_rules/README.md), and
it is scanned at the door to check the participant in.

Typical uses: concerts and festivals, conferences, and **exams**, where the person at the door has to
verify that the ticket holder is really the person standing in front of them.

> **Tickets are not certificates.** A certificate documents an achievement *after* the fact; a ticket
> grants entry *before* it. They are separate features with separate storage, separate pages and
> separate permissions. Tickets never appear in the certificate report, and issuing a ticket never
> triggers certificate events.

---

## Quick setup path

### Use the AI agent to configure this for you

If you are using the booking AI assistant, you can ask directly in plain language, for example:

- "Bitte aktiviere Eintrittstickets fuer das Sommerfest mit dem Design 'Buchungsticket'."
- "Please switch on entry tickets for the exam and require an identity check at the door."
- "Mach die Tickets fuer diesen Workshop uebertragbar."

Name the **ticket design by its name** — the agent does not work with numeric ids. If several designs
match, it will ask you which one you mean. As with every write action, it asks for confirmation
before saving.

Two limits worth knowing:

- Ticketing is switched on **after** an option exists. Ask the agent to create the option first, then
  to add tickets to it.
- The agent will not create a ticket design for you. If none exists, it tells you to use the
  **Create example ticket template** button in the site settings first.

### Set up ticketing from scratch

1. **Enable the feature.** Site administration → Plugins → Activity modules → Booking:
   tick **Enable entry tickets** (`bookingticketon`).
2. **Create a ticket design.** On the same settings page, click **Create example ticket template**.
   This adds a ready-made ticket to the certificate templates. Use it as it is, or duplicate it in
   *Site administration → Certificates → Manage certificate templates* and adapt the layout.
3. **Switch ticketing on for a booking option.** Open the option form → **Ticketing** section →
   choose your design under **Ticket design**.
4. **Deliver the tickets.** Open **Booking Rules** (`/mod/booking/edit_rules.php?contextid=1`) →
   *Add rule* → **React on event** → event `ticket_created` → condition *Select user from event*
   (related user) → action **Send ticket**. Write subject and message.
5. **Test it.** Book a test user, check that the mail arrives with the PDF attached, and open
   *Profile → My tickets* as that user.
6. **At the door**, open the scanner from the booking activity navigation (**Ticket scanner**) and
   scan the QR code on a ticket.

---

## Table of Contents

1. [Site settings](#1-site-settings)
2. [Ticket design](#2-ticket-design)
3. [Booking option settings](#3-booking-option-settings)
4. [Creating and delivering tickets](#4-creating-and-delivering-tickets)
5. [Where participants find their tickets](#5-where-participants-find-their-tickets)
6. [Checking tickets at the door](#6-checking-tickets-at-the-door)
7. [Identity confirmation (exams)](#7-identity-confirmation-exams)
8. [The public verification page](#8-the-public-verification-page)
9. [Cancellation and validity](#9-cancellation-and-validity)
10. [Capabilities](#10-capabilities)
11. [How it works internally](#11-how-it-works-internally)
12. [Configuring ticketing with the AI agent](#12-configuring-ticketing-with-the-ai-agent)

---

## 1. Site settings

*Site administration → Plugins → Activity modules → Booking → **Entry tickets (SofaTicket)***

The site configuration only switches the feature on and sets site-wide behaviour for the door
scanner. Everything that describes a concrete ticket belongs to the booking option.

| Setting | Description |
|---------|-------------|
| **Enable entry tickets** (`bookingticketon`) | Master switch. When off, no tickets are created and the *Ticketing* section is hidden from the option form. |
| **Example ticket template** | A button that creates the shipped ticket design once. Shown only while `tool_certificate` is installed. |
| **Presence status set on check-in** (`bookingticketcheckinstatus`) | The presence status a successful scan writes. Defaults to *Checked in*. Keep it different from the status that issues completion certificates, so a check-in never triggers a certificate. |
| **Serial scan mode** (`bookingticketserialscan`) | Keep the scanner running after each result, so a queue can be scanned without touching the screen. |
| **Duplicate-scan warning window** (`bookingticketduplicatewindow`) | Scanning the same code again within this many seconds is reported as "already checked" instead of being re-processed. Default: 5 seconds. |

> **Requirement:** the `tool_certificate` plugin must be installed. It is used only to *draw* the
> ticket — no certificate is ever issued.

---

## 2. Ticket design

A ticket design is an ordinary **certificate template**. That means you get the full drag-and-drop
template designer for free, and you can have as many designs as you like — a festival ticket, an exam
admission slip, a plain black-and-white voucher.

Click **Create example ticket template** in the site settings to get a ready-made 210 × 99 mm ticket
containing:

| Element | Shows |
|---------|-------|
| Text | The word "TICKET" |
| Field: *Booking option name* | The name of the booked option |
| Field: *Sessions* | The dates of the option |
| Field: *Location* | Where it takes place |
| User field: *Full name* | The ticket holder |
| Field: *Additional ticket information* | Your free text from the option form |
| Date | The issue date |
| Code (QR) | The QR code that leads to the verification page |

### Adding your own fields

In the template designer, add an element of type **Field** and pick one of the values mod_booking
provides — `Booking option name`, `Booking option description`, `Teachers`, `Sessions`, `Duration`,
`Location`, `Institution`, `Additional ticket information`, and every text custom field of your
booking options.

> **The QR code element is special.** On a ticket it always encodes mod_booking's own verification
> URL (`/mod/booking/verifyticket.php?code=…`), not the certificate verification URL. You do not have
> to configure anything — just place a *Code* element set to *QR code* on the template.

---

## 3. Booking option settings

*Booking option form → **Ticketing** section*

The section only appears when entry tickets are enabled site-wide.

| Field | Description |
|-------|-------------|
| **Ticket design** | The certificate template used as the ticket layout. **Leave empty to create no tickets for this option.** This is the switch that turns ticketing on for a single option. |
| **Personalised ticket** | On by default. A personalised ticket is only valid for the person it was created for and may not be passed on or resold. The public verification page states this, so someone offered the ticket can see that it cannot legally change hands. Switch it off for freely transferable tickets. |
| **Require identity confirmation** | Off by default. When on, a scan does not check the participant in immediately — see [Identity confirmation](#7-identity-confirmation-exams). |
| **Additional ticket information** | Free text printed on the ticket, for example entry rules or directions. It is only visible if the ticket design contains a *Field* element showing **Additional ticket information**. |

The last three fields are hidden until a ticket design is chosen.

---

## 4. Creating and delivering tickets

**Creation is automatic.** As soon as a user books an option that has a ticket design, mod_booking
creates the ticket, renders the PDF, and fires the event `ticket_created`.

**Delivery is a booking rule.** Nothing is sent until you configure one. This is deliberate — it lets
you decide *when* and *with which wording* tickets go out.

### Send the ticket immediately after booking

1. Open **Booking Rules**: `/mod/booking/edit_rules.php?contextid=1`
2. *Add rule* → **Rule type**: *React on event* → **Event**: `ticket_created`
3. **Condition**: *Select user from event* → *related user*
4. **Action**: **Send ticket** — write subject and message
5. Save.

### Send the ticket a few days before the event

Use the rule type *Trigger n days before* with the date field `coursestarttime`, condition *Select
users of a booking option* (status *Booked*), and the same **Send ticket** action. The action looks
the ticket up at the moment it runs, so it works on any rule type — not only on `ticket_created`.

This is also how you build a **re-send**: any rule whose action is *Send ticket* will attach the
participant's current ticket.

> The **Send ticket** action attaches the ticket PDF to the mail. If a user has no valid ticket
> (because the option has no design, or the booking was cancelled), the action silently sends
> nothing rather than mailing an empty message.

### Useful placeholders

| Placeholder | Replaced with |
|-------------|--------------|
| `{ticketcode}` | The verification code of the ticket |
| `{ticketurl}` | Direct download link for the ticket PDF |
| `{ticketverifyurl}` | Link to the public verification page |

See [Placeholders](../placeholders/README.md) for the full list.

---

## 5. Where participants find their tickets

Tickets are kept separate from certificates everywhere in the interface.

| Where | What |
|-------|------|
| **Profile → My tickets** (`/mod/booking/mytickets.php`) | A list of all tickets a user holds: option, date, code, status, issue date and a PDF download. |
| **My bookings** (`/mod/booking/mybookings.php`) | A link to *My tickets*, plus a ticket download link on each booked option that has one. |
| **Email** | The PDF attached by the *Send ticket* rule action. |
| **Mobile / external apps** | The web service `mod_booking_get_my_tickets` returns all tickets of a user, including the PDF and verification URLs. |

A user with the `mod/booking:viewticketreport` capability at system level can open another user's
ticket list via `mytickets.php?userid=<id>`.

---

## 6. Checking tickets at the door

Open the booking activity and choose **Ticket scanner** from the activity navigation, or go to
`/mod/booking/scan.php?id=<cmid>` directly. It requires the `mod/booking:scanticket` capability.

1. Press **Start scanner** and allow camera access.
2. Point the camera at the QR code on a ticket.
3. The result panel turns green (valid — admitted), amber (already checked in) or red (cancelled or
   unknown), and the counter at the top shows *admitted / booked*.

A successful scan writes the configured presence status (default *Checked in*) to the participant's
booking answer and logs a `ticket_scanned` event, so check-ins are fully traceable in the Moodle log
and can themselves trigger booking rules.

> **The camera needs HTTPS.** Browsers only grant camera access over a secure connection
> (`localhost` excepted). The scanner shows a warning if the site is served over plain HTTP.
>
> **Browser support:** QR decoding uses the standard `BarcodeDetector` API, available in Chrome and
> Edge on Android and desktop. Safari (and therefore every browser on iOS) and Firefox do not
> support it yet; the scanner then shows a clear message instead of failing silently. On those
> devices, open the QR code with the normal camera app — it leads to the
> [verification page](#8-the-public-verification-page), where staff can check the participant in.

---

## 7. Identity confirmation (exams)

Switch **Require identity confirmation** on in the option's *Ticketing* section when the ticket must
belong to the person using it — most importantly for **exams**.

With the setting on, a scan does **not** check anybody in. Instead the scanner shows:

- the holder's **name**,
- the holder's **profile picture**,
- the booked option,

and waits for the staff member to press **Confirm entry** or **Reject**. Only *Confirm entry* records
the check-in. The scanner ignores further QR codes until a decision has been made.

This makes the profile picture part of the entry control: if your institution keeps profile pictures
under administrative control (updated only by staff or by the student management system), the door
staff can compare the picture with the person in front of them.

> **What the server can and cannot enforce.** The web service refuses to write a check-in for an
> option that requires confirmation unless the request carries the staff confirmation flag — a
> stock scanner can therefore never skip the two-step flow. The server records that a
> scan-permitted staff member *sent* the confirmation; whether that person really compared the
> picture with the person at the door is a human step no server can verify. Trust in the identity
> check is trust in your entry staff (only accounts with the scan capability can confirm at all).

---

## 8. The public verification page

The QR code on every ticket leads to `/mod/booking/verifyticket.php?code=<code>`. **This page is
public — no login required.** Its purpose is to protect against forged tickets and illegal resale.

| Who is looking | What they see |
|----------------|--------------|
| Anyone (not logged in) | Valid / cancelled / unknown, the option name, the date and the location, plus the binding notice: *"This is a personalised ticket. It is only valid for the person it was created for and cannot be passed on or resold."* — or, for transferable tickets, that it may be passed on. **No participant name, no picture.** |
| The ticket holder | Additionally their own name and issue date. |
| Entry staff (`mod/booking:scanticket`) | Additionally the holder's name, **profile picture**, issue date, and a link to the scanner. |

So a person who is offered a ticket for resale can check two things without seeing anybody's personal
data: whether the ticket is genuine and still valid, and whether it may legally change hands at all.

> Unknown codes return the same generic "no valid ticket" message as codes for deleted options, so
> the page cannot be used to find out which codes exist.

---

## 9. Cancellation and validity

| Event | Effect on the ticket |
|-------|---------------------|
| The participant cancels, or is cancelled by staff | Every valid ticket for that user and option is set to **cancelled** with a timestamp. |
| A cancelled ticket is scanned | The scanner reports *cancelled* in red and **never** writes a presence status. |
| A cancelled ticket is checked on the public page | Reported as cancelled and no longer valid. |
| The user books the same option again | A new ticket with a new code is created. |

The record and its PDF are kept after cancellation so an old ticket stays verifiable — someone
presenting a cancelled ticket gets a clear "cancelled" answer rather than "unknown".

Ticket creation is **idempotent**: a user can never end up with two valid tickets for the same
booking option.

---

## 10. Capabilities

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:scanticket` | Open the entry scanner, verify tickets and check participants in | editingteacher, manager |
| `mod/booking:viewticketreport` | See the tickets of other users | editingteacher, manager |

Participants need no capability at all to see and download their own tickets.

See [Capabilities](../capabilities/README.md) for the full list.

---

## 11. How it works internally

For administrators who need to know where the data lives, and for developers.

- A ticket is a record in the **`booking_tickets`** table (option, user, template, unique code,
  status, personalised flag, timestamps, plus a snapshot of the option data at creation time).
- The PDF is stored in the Moodle file area `mod_booking/tickets`, in the module context of the
  booking instance, under the file name `<code>.pdf`. Access is checked per file: only the holder,
  entry staff and ticket reporters can download it.
- **`tool_certificate` is used only as a layout engine.** mod_booking renders the PDF itself
  (`classes/local/ticket/ticket_pdf.php`) from the chosen template. **No certificate issue is
  created**, no `certificate_issued` event fires, and the certificate plugin sends no mail of its
  own. This is why tickets never show up in the certificate report.
- The data snapshot on the ticket is what fills the *Field* elements of the design, so a ticket keeps
  showing the values it was created with even if the booking option is edited afterwards.
- Check-in state is stored as the participant's **presence status** on their booking answer, not in a
  separate table, so it appears in the normal booking reports.
- Events: `ticket_created` (a ticket was created) and `ticket_scanned` (a participant was admitted).
  Both can be used in booking rules.
- Web services: `mod_booking_verify_ticket` (verify and check in) and `mod_booking_get_my_tickets`
  (list a user's tickets). Both are exposed to the Moodle mobile app service.

---

## 12. Configuring ticketing with the AI agent

The booking AI assistant can switch entry tickets on and off and change every ticketing setting of a
booking option. It runs with **your** permissions and asks for confirmation before it writes.

| Agent input key | Meaning |
|-----------------|---------|
| `ticketdesign` | The **name** of the ticket design (a numeric template id is accepted too). Setting it switches tickets on; an empty value or `none` switches them off. |
| `ticketpersonalized` | `true` (default) for a ticket bound to its holder, `false` for a transferable one. |
| `ticketconfirmidentity` | `true` to make the door scanner ask for an identity confirmation. |
| `ticketextrainfo` | The extra text printed on the ticket. |

These keys belong to the `update_option` and `bulk_update_options` skills. Option **creation** does
not take them — create the option first, then switch ticketing on, which is also what the agent is
told to do.

Behaviour worth knowing:

- **Names, not ids.** If the name matches several designs, the agent gets the candidates back and
  asks you which one you meant. If nothing matches, it tells you no design exists and points at the
  *Create example ticket template* button.
- **Partial changes are safe.** Asking only for "require an identity check" keeps the design and the
  other settings as they are.
- **The agent never creates a ticket design**, so it cannot leave objects behind in the certificate
  templates.
- **Creating and sending are different things.** The agent is instructed never to claim a ticket was
  sent; after switching tickets on it offers to create the booking rule that delivers them.

---

## Related documentation

- [Booking option — Ticketing](../booking-option/10-ticketing.md) — the option form section
- [Booking rules — Actions](../booking_rules/actions.md) — the *Send ticket* action
- [Booking rules — Rule types](../booking_rules/rule-types.md) — the `ticket_created` and `ticket_scanned` events
- [Placeholders](../placeholders/README.md) — `{ticketcode}`, `{ticketurl}`, `{ticketverifyurl}`
- [Capabilities](../capabilities/README.md) — `scanticket`, `viewticketreport`

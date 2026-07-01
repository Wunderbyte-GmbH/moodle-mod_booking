[Back to parent section](README.md)

# Booking Rules — Actions

An **action** defines *what happens* when a rule fires and its condition is satisfied.
Every rule must have exactly one action.

---
## Quick setup path

### Use the AI agent to configure this for you

If you are using the booking AI assistant, you can ask directly in plain language, for example:

- "Kannst du eine automatische Buchungsbestaetigung einrichten?"
- "Please create a booking confirmation rule when someone books."

The agent can guide you in read-only mode (what is already configured) and can also configure a booking rule for you after confirmation.

### Send a confirmation email when a user books

1. Open **Booking Rules**: `/mod/booking/edit_rules.php?contextid=1`
2. Click **Add rule** → **Rule type**: *React on event* → **Event**: `bookingoption_booked`.
3. **Condition**: *Select user from event*.
4. **Action**: *Send email* (`send_mail`).
5. Fill in **Subject** (e.g. `Booking confirmed: {coursename}`) and **Message** (use `{bookingdetails}` for all details).
6. **Save**. The email is sent immediately after the booking.

### Send a reminder email N days before a course

1. Open **Booking Rules**: `/mod/booking/edit_rules.php?contextid=1`
2. Click **Add rule** → **Rule type**: *Trigger n days…* → **Days**: `3`, **Date field**: `coursestarttime`.
3. **Condition**: *Select users of a booking option* → status **Booked**.
4. **Action**: *Send email* (`send_mail`) — write Subject and Message.
5. **Save**.

---

## Table of Contents

1. [send_mail — Send an email](#1-send_mail--send-an-email)
2. [send_mail_interval — Send repeated emails with a time delay](#2-send_mail_interval--send-repeated-emails-with-a-time-delay)
3. [send_copy_of_mail — Forward an email copy to additional recipients](#3-send_copy_of_mail--forward-an-email-copy-to-additional-recipients)
4. [confirm_bookinganswer — Automatically confirm a booking answer](#4-confirm_bookinganswer--automatically-confirm-a-booking-answer)
5. [delete_conditions_from_bookinganswer — Remove availability conditions](#5-delete_conditions_from_bookinganswer--remove-availability-conditions)
6. [Placeholders available in email templates](#6-placeholders-available-in-email-templates)

---

## 1. `send_mail` — Send an email

**Display name:** *Send email*

The most commonly used action. Schedules an ad-hoc task that sends a custom email to each user identified by the condition. The email is sent at the time determined by the rule type (immediately for event-based rules, or at the scheduled time for date-based rules).

### Configuration

| Field | Description |
|-------|-------------|
| **Subject** | The email subject line. Supports [placeholders](#6-placeholders-available-in-email-templates). |
| **Message** | The email body (HTML editor). Supports [placeholders](#6-placeholders-available-in-email-templates). |
| **Send iCal attachment** | Toggle on to include an iCal (`.ics`) calendar event in the email. |
| **Create or cancel iCal** | When *Send iCal* is enabled: choose `Create` to add the event to the recipient's calendar, or `Cancel` to remove it. |

### How it works internally

Each user/option combination is queued as a Moodle ad-hoc task (`send_mail_by_rule_adhoc`). Before the task executes, the system checks whether the rule still applies (e.g., the booking option was not cancelled, the user is still booked). If the rule no longer applies, the email is silently skipped.

### Use cases

- Reminder emails before a course starts.
- Booking confirmation to the participant.
- Change notifications to teachers.
- Any custom notification triggered by a booking event.

---

## 2. `send_mail_interval` — Send repeated emails with a time delay

**Display name:** *Send a message to multiple users with a time delay*

Similar to `send_mail`, but sends the same message to a **batch of users** with a configurable **delay between each email**. This is useful to avoid flooding users when many people are in scope at once, or to stagger notifications deliberately.

### Configuration

| Field | Description |
|-------|-------------|
| **Subject** | The email subject line. Supports [placeholders](#6-placeholders-available-in-email-templates). |
| **Message** | The email body. Supports [placeholders](#6-placeholders-available-in-email-templates). |
| **Interval** | Time delay in minutes between each email in the batch. |

### Use cases

- Send notifications to a large group of users without overwhelming the mail server, staggering delivery by a few minutes per user.
- Gradually notify participants on a waiting list as spots become available.

---

## 3. `send_copy_of_mail` — Forward an email copy to additional recipients

**Display name:** *Send an email copy*

Forwards a **copy** of an email that was sent as part of a booking event. The email subject and body are taken directly from the event data (`other['subject']`, `other['message']`), with optional prefixes.

> ⚠️ This action can only be used with the following events:
> - `custom_message_sent`
> - `custom_bulk_message_sent`
>
> Other events do not carry email subject/body data and are not compatible.

### Configuration

| Field | Description |
|-------|-------------|
| **Subject prefix** | Text prepended to the original subject (e.g., `[COPY] `). |
| **Message prefix** | Text prepended to the original email body (e.g., `This is a copy of the message sent to …`). |

### Use cases

- Automatically BCC a coordinator whenever a custom bulk message is sent from a booking option.
- Archive copies of custom messages by routing them to a dedicated email address.

---

## 4. `confirm_bookinganswer` — Automatically confirm a booking answer

**Display name:** *Confirm booking answer when user notification is enabled.*

Creates an ad-hoc task that **automatically confirms** a booking answer (moves a user from "waiting for confirmation" to "confirmed"), provided that the booking option has `confirmationonnotification` enabled.

Special behaviour when `confirmationonnotification = 2`: confirms only **one user at a time** from the waiting list (sequential confirmation).

No additional configuration fields are required for this action.

### When to use

Pair this action with the `rule_react_on_event` rule listening to `bookinganswer_waitingforconfirmation` and the condition `select_student_in_bo` (status = "waiting for confirmation"). The rule will then auto-confirm users as soon as they reach that status.

### Use cases

- Implement a fully automatic booking approval workflow where no manual confirmation is needed.
- Auto-confirm only one user at a time to control capacity step by step.

---

## 5. `delete_conditions_from_bookinganswer` — Remove availability conditions

**Display name:** *Delete userdata from booking form*

Deletes the **availability condition data** (custom form data collected during booking) from a user's booking answer. This is useful for GDPR compliance or data hygiene workflows — for example, removing form inputs after a certain period.

No additional configuration fields are required for this action beyond the standard rule/condition setup.

### Use cases

- Automatically remove personal form data X days after a course ends.
- Clear intake form responses when a booking is cancelled.

---

## 6. Placeholders available in email templates

The `send_mail` and `send_mail_interval` actions support a rich set of placeholders that are substituted with real values when the email is sent.

Commonly used placeholders:

| Placeholder | Replaced with |
|-------------|--------------|
| `{firstname}` | Recipient's first name |
| `{lastname}` | Recipient's last name |
| `{email}` | Recipient's email address |
| `{title}` | Booking option title |
| `{description}` | Booking option description |
| `{location}` | Booking option location |
| `{institution}` | Booking option institution |
| `{bookingdetails}` | Full formatted block of booking details (dates, location, teachers, …) |
| `{bookinglink}` | URL to the booking activity |
| `{courselink}` | URL to the linked Moodle course |
| `{pollurl}` | Poll URL configured on the booking option |
| `{pollurlteachers}` | Teacher-specific poll URL |
| `{bookingconfirmationlink}` | Booking confirmation URL |
| `{changes}` | Summary of changes (for `bookingoption_updated` events) |
| `{participant}` | Full name of the participant |
| `{price}` | Price of the booking |
| `{optiondatefromdate}` | If the event is related to a specific session date, that date is shown here |

The full list of available placeholders is shown dynamically inside the rule editor form, just above the subject field. Custom user profile fields and custom booking option fields can also be used by their shortname (e.g., `{profile_field_department}`).

> **Tip:** Click the *"Show placeholders"* link in the rule editor to expand the complete list for your site.

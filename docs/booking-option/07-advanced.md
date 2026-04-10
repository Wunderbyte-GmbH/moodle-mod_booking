# Advanced Options

The **Advanced options** section (header: *Advanced options*) groups settings that are less commonly needed but provide fine-grained control over cancellations, custom texts, poll URLs, attachments, and more.

---

## Table of Contents

1. [Cancellation settings](#1-cancellation-settings)
2. [Status-dependent texts](#2-status-dependent-texts)
3. [Notification message](#3-notification-message)
4. [Poll URL](#4-poll-url)
5. [Attachments](#5-attachments)
6. [Remove activity completion after N minutes](#6-remove-activity-completion-after-n-minutes)
7. [Multiple bookings](#7-multiple-bookings)
8. [Return URL](#8-return-url)
9. [How many users can be booked by one person](#9-how-many-users-can-be-booked-by-one-person)

---

## 1. Cancellation settings

### Disable cancellation

| Field | Description |
|-------|-------------|
| **Disable cancellation** (`disablecancel`) | When checked, participants can no longer cancel their own booking. A manager or teacher must cancel on their behalf. |

> This complements the instance-wide *Disable cancellation* setting: if the booking activity already disables all cancellations, this per-option flag has no additional effect.

### Cancel-until date

| Field | Description |
|-------|-------------|
| **Cancelling is only possible until certain date** (`canceluntilcheckbox`) | Enable a hard deadline for cancellations. |
| **Cancel until** (`canceluntil`) | The exact date and time until which cancellation is allowed. After this point, the cancel button is hidden from participants. |

> `canceluntil` is disabled (greyed out) when `disablecancel` is checked.

---

## 2. Status-dependent texts

These three rich-text (HTML) fields let you show custom messages to participants depending on the booking status of the option. They appear on the option detail page.

| Field | When it is shown |
|-------|-----------------|
| **Before booked** (`beforebookedtext`) | Shown to a user *before* they have booked the option. Useful for prerequisites or instructions to read before signing up. |
| **After booked** (`beforecompletedtext`) | Shown to a user *after* they have booked, but *before* the option is marked as completed. |
| **After completed** (`aftercompletedtext`) | Shown *after* the option is marked as completed for the user. Ideal for follow-up links, certificate information, or feedback forms. |

All three fields support HTML and [placeholders](#) (e.g. `{bookingdetails}`, `{courselink}`).

---

## 3. Notification message

| Field | Description |
|-------|-------------|
| **Notification message** (`notificationtext`) | A custom HTML message that is included in the booking confirmation e-mail sent to the participant. If left empty, only the global notification template is used. |

---

## 4. Poll URL

| Field | Description |
|-------|-------------|
| **Poll URL** (`pollurl`) | A URL to a survey or feedback form for participants. Can be inserted into e-mails using the `{pollurl}` placeholder. |
| **Teachers poll URL** (`pollurlteachers`) | A separate poll URL sent only to teachers (via the `{pollurlteachers}` placeholder). |

---

## 5. Attachments

| Field | Description |
|-------|-------------|
| **Attachments** (`attachment`) | Upload one or more files to attach to this booking option. Attachments can be included in e-mails and displayed on the option detail page. |

---

## 6. Remove activity completion after N minutes

| Field | Description |
|-------|-------------|
| **Remove activity completion after N minutes** (`removeafterminutes`) | If set to a positive integer, the Moodle activity completion for the linked course is removed automatically N minutes after the session starts. Useful for attendance-based completion. Set to `0` to disable. |

---

## 7. Multiple bookings

| Field | Description |
|-------|-------------|
| **Allow to book again** (`multiplebookings`) | When enabled, a user who has already booked (and possibly completed) this option can book it again. |
| **Allow to book again after (seconds)** (`allowtobookagainafter`) | Minimum waiting time before re-booking is allowed. Enter the number of seconds (e.g. `86400` = 24 hours). Only relevant when *Allow to book again* is active. |

---

## 8. Return URL

| Field | Description |
|-------|-------------|
| **URL to return to** (`returnurl`) | After a successful booking, redirect the user to this URL instead of the standard booking confirmation page. Useful for embedding the booking flow in custom pages or external applications. |

---

## 9. How many users can be booked by one person

| Field | Description |
|-------|-------------|
| **How many other users can a user book** (`howmanyusers`) | Defines how many *other* users a single booker is allowed to sign up in addition to themselves. For example, a value of `2` means the booker can book themselves plus 2 other participants in one go. `0` means this feature is disabled (only self-booking). |

---

## Related pages

- [General settings](01-general.md) — Capacity and waiting list
- [Availability conditions](04-availability.md) — Booking window (opening and closing time)
- [Demand confirmation](08-confirmation.md) — Manual confirmation workflow
- [CSV Import — Cancellation settings](../CSV_IMPORT_USER_GUIDE.md#7-cancellation-settings)

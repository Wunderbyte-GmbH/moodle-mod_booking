[Back to parent section](README.md)

# Demand Confirmation

The **Demand confirmation** section (header: *Demand confirmation*) allows you to require manual approval before a booking is confirmed. This is useful for options with limited places, prerequisite checks, or any scenario where you want a human to review each registration.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Confirmation modes](#1-confirmation-modes)
2. [Confirmation on notification](#2-confirmation-on-notification)
3. [Workflow overview](#3-workflow-overview)
4. [Waiting list and confirmation](#4-waiting-list-and-confirmation)

---

## 1. Confirmation modes

The **Wait for confirmation** select field (`waitforconfirmation`) has three options:

| Value | Label | Description |
|-------|-------|-------------|
| `0` | No restriction | Bookings are confirmed immediately. No manual approval required. This is the default. |
| `1` | Wait for confirmation | Every new booking is placed in a *pending* state. A manager or teacher must manually confirm or reject it before the user receives a confirmation. |
| `2` | Wait for confirmation (waiting list) | Only users on the **waiting list** need manual confirmation. Users who book within the main capacity are confirmed immediately. |

> This field may appear in the **Advanced options** section instead of a dedicated section, depending on the admin setting *Use confirmation workflow header*.

---

## 2. Confirmation on notification

When confirmation mode `1` or `2` is active, the **Confirmation on notification** select field (`confirmationonnotification`) controls *when* the manager is notified and *how* they can confirm:

| Value | Label | Description |
|-------|-------|-------------|
| `0` | Do not open booking for confirmation | The manager is not automatically notified. They must check the booking list manually and confirm from there. |
| `1` | Open for confirmation for all pending bookings | All pending bookings appear in a notification for the manager. All can be confirmed at once. |
| `2` | Open for confirmation one at a time | Each pending booking generates a separate notification. The manager confirms them one by one. |

> A warning message is shown when this setting is active to remind managers that booking rules (if any) may also interact with the confirmation workflow.

---

## 3. Workflow overview

When **Wait for confirmation = 1**:

1. User clicks **Book now**.
2. Booking is saved with status *pending* (not yet confirmed).
3. The user sees a "pending confirmation" message instead of a confirmation.
4. The manager/teacher receives a notification (depending on the *Confirmation on notification* setting).
5. The manager opens the booking list, reviews the pending booking, and clicks **Confirm** or **Reject**.
6. On confirmation: the user receives a standard booking confirmation e-mail and their status changes to *booked*.
7. On rejection: the user is notified and the booking is removed.

---

## 4. Waiting list and confirmation

When **Wait for confirmation = 2**, the system splits behaviour:

- Users who book within the main capacity (`maxanswers`) are confirmed **immediately**.
- Users who book beyond the capacity are placed on the **waiting list** and require manual confirmation.
- When a confirmed user cancels, a manager can promote the next waiting-list user. Depending on the *Confirmation on notification* setting, this can happen automatically or require manual action.

This mode is useful when you want to auto-confirm early registrations but manually review overflow bookings.

---

## Related pages

- [General settings](01-general.md) — Capacity (`maxanswers`, `maxoverbooking`)
- [Advanced options](07-advanced.md) — Cancel settings (`disablecancel`, `canceluntil`)
- [Availability conditions](04-availability.md) — Booking window

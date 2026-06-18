[Back to parent section](../README.md)

# Actions After Booking — Overview

**Actions after booking** (also called *booking actions* or *bo_actions*) are automated actions that are triggered **immediately** when a specific booking event occurs for a user. They are distinct from [booking rules](../booking_rules/README.md), which are schedule-based or event-triggered notifications.

> Important: If your question is about sending messages, reminders, or notification emails, use [Booking rules](../booking_rules/README.md) documentation, not Actions after booking.

The key difference:

| Feature | Booking rules | Actions after booking |
|---------|--------------|----------------------|
| When triggered | On a schedule (days before/after a date) or when a Moodle event fires | Immediately when a booking answer is created, confirmed, or deleted |
| What they do | Send emails, confirm booking answers | Cancel bookings, book other options, call REST scripts, set profile fields |
| Configuration | Booking Rules administration page | Per booking option (option form → Actions section) |

Actions after booking are a **PRO feature** of mod_booking.

---

## Quick setup path

1. Open option edit page: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
2. Edit target option and open Booking actions section.
3. Add or edit the action type documented here.
4. Save and test with one booking event.

---

## Table of Contents

1. [Where to configure actions](#1-where-to-configure-actions)
2. [Available action types](#2-available-action-types)
3. [Execution order](#3-execution-order)
4. [Action abort behaviour](#4-action-abort-behaviour)

---

## 1. Where to configure actions

Actions are configured per booking option:

1. Open the Booking activity and open (or create) a booking option.
2. Scroll to the **Booking actions** section of the option form.
   > This section is only visible when **Show booking actions** is enabled in the booking plugin settings (`showboactions`) **and** a PRO licence is active.
3. Click **Add action** and select an action type.
4. Configure the action and save. Multiple actions can be added; they are executed in the order listed.

Actions must be saved on an existing option (they cannot be added to a new, unsaved option).

---

## 2. Available action types

| Type | Class | What it does |
|------|-------|-------------|
| [Cancel booking](cancelbooking.md) | `cancelbooking` | Cancels the user's booking answer for the parent option after a trigger. |
| [Book other options](bookotheroptions.md) | `bookotheroptions` | Automatically books one or more other booking options for the user. |
| [Execute REST script](executerestscript.md) | `executerestscript` | Calls an external REST API endpoint and optionally records the response. |
| [Set user profile field](userprofilefield.md) | `userprofilefield` | Sets or modifies a custom user profile field when the booking is confirmed. |

---

## 3. Execution order

When multiple actions are attached to a booking option, they are executed in the order they were added. You can reorder them in the form.

---

## 4. Action abort behaviour

Each action type returns a status code after execution:

- **Status 0:** Continue — execute the next action in the list.
- **Status 1:** Abort — stop executing further actions after this one.

Most action types (cancel, book others, execute REST) return status `1` (abort) to prevent unintended cascading effects. Check the individual action type pages for details.

---

## See also

- [Action type: Cancel booking](cancelbooking.md)
- [Action type: Book other options](bookotheroptions.md)
- [Action type: Execute REST script](executerestscript.md)
- [Action type: Set user profile field](userprofilefield.md)
- [Booking rules](../booking_rules/README.md) — For schedule-based and event-based email notifications

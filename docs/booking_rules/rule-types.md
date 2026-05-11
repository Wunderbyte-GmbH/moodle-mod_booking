# Booking Rules — Rule Types (Triggers)

A **rule type** defines *when* a booking rule is triggered. Currently three rule types are available.

---

## Table of Contents

1. [rule_daysbefore — Trigger N days in relation to a date](#1-rule_daysbefore--trigger-n-days-in-relation-to-a-date)
2. [rule_specifictime — Trigger at a precise time relative to a date](#2-rule_specifictime--trigger-at-a-precise-time-relative-to-a-date)
3. [rule_react_on_event — React immediately when a Moodle event fires](#3-rule_react_on_event--react-immediately-when-a-moodle-event-fires)
4. [Compatibility with conditions](#4-compatibility-with-conditions)

---

## 1. `rule_daysbefore` — Trigger N days in relation to a date

**Display name:** *Trigger n days in relation to a certain date*

This rule is scheduled by Moodle's cron/ad-hoc task system. It looks for booking options whose chosen date field is exactly N days in the future (or in the past, using negative values) and runs the action for each matching option/user pair it finds.

### Configuration

| Field | Description |
|-------|-------------|
| **Days** | An integer between −30 and +30. Positive values = "before" the date; negative values = "after" the date. For example, `3` means "3 days before". |
| **Date field** | Which date field of the booking option to measure against. See the table below. |

### Available date fields

| Field identifier | Description |
|-----------------|-------------|
| `coursestarttime` | Start of the booking option |
| `courseendtime` | End of the booking option |
| `optiondatestarttime` | Start of each individual session (option date). When this is selected the rule fires once per session, not once per option. Each session can individually override the number of days via its *daystonotify* column. |
| `bookingopeningtime` | When booking opens |
| `bookingclosingtime` | Booking deadline |
| `selflearningcourseenddate` | End of self-learning course subscription (stored in the booking-answer JSON) |
| `installmentpayment` *(requires local_shopping_cart)* | Due date of a shopping-cart instalment payment |

> **Note:** For self-learning courses the date fields `coursestarttime`, `courseendtime`, and `optiondatestarttime` are ignored by this rule because they are used only for sorting and do not represent real session dates.

### Execution detail

The rule is evaluated every time the Moodle cron runs. It schedules an **ad-hoc task** (`send_mail_by_rule_adhoc`) for each user/option pair it finds. A one-hour tolerance window is applied to avoid missed executions caused by cron delays.

---

## 2. `rule_specifictime` — Trigger at a precise time relative to a date

**Display name:** *Trigger at certain time in relation to a certain date*

This rule works exactly like `rule_daysbefore` but uses a **duration** (hours and minutes) instead of whole days, giving you fine-grained control over exactly when the action fires.

### Configuration

| Field | Description |
|-------|-------------|
| **Timespan** | A Moodle *duration* field (days / hours / minutes / seconds). Stored internally as a number of seconds. |
| **Before or after?** | `Before` (positive offset) or `After` (negative offset) the chosen date field. |
| **Date field** | Same set of date fields as in `rule_daysbefore`. |

> **Example:** Set *Timespan* to `30 minutes` and *Before or after* to `Before` to send a reminder exactly 30 minutes before each session starts.

### Differences vs. `rule_daysbefore`

- Uses seconds internally instead of whole days, so sub-day precision is possible (e.g., 2 hours 30 minutes before start).
- The `optiondatestarttime` variant reads the `daystonotify` field of each option date and converts it to seconds (`daystonotify × 86400`).

---

## 3. `rule_react_on_event` — React immediately when a Moodle event fires

**Display name:** *React on event*

This rule listens for a Moodle booking event and executes the action synchronously (via an ad-hoc task) as soon as the event is observed. No cron scheduling is involved — the reaction happens at the time the event occurs.

### Configuration

| Field | Description |
|-------|-------------|
| **Event** | The booking event to listen for. A drop-down of all supported events is provided (see list below). |
| **Booking option state** | Optional filter. Only run the action when the booking option is in a given state at the time of the event. See state options below. |
| **Days after end** | Optional. Stop reacting once the booking option's end time is more than N days in the past. Leave empty (or 0) to always react. Negative values suspend the rule before the specified end time. |

### Supported booking events

| Event key | When it fires |
|-----------|--------------|
| `bookingoption_freetobookagain` | A fully booked option becomes available again |
| `bookinganswer_cancelled` | A participant cancels their booking |
| `bookingoption_booked` | A user books a booking option |
| `bookingoptionwaitinglist_booked` | A user joins the waiting list |
| `bookinganswer_movedupfromwaitinglist` | A user is moved up from the waiting list to confirmed |
| `bookingoption_completed` | A booking option is marked as completed |
| `bookingoption_uncompleted` | Completion status is reversed |
| `bookinganswer_confirmed` | A booking answer receives manual confirmation |
| `bookinganswer_denied` | A booking answer is denied |
| `bookinganswer_waitingforconfirmation` | A booking answer is placed in "waiting for confirmation" status |
| `bookingoption_updated` | A booking option's data is updated |
| `bookingoption_cancelled` | A booking option is cancelled entirely |
| `custom_message_sent` | A custom message was sent manually to a user |
| `custom_bulk_message_sent` | A custom bulk message was sent |
| `optiondates_teacher_added` | A teacher is added to a session (option date) |
| `optiondates_teacher_deleted` | A teacher is removed from a session |
| `rest_script_success` | A REST script executed successfully |
| `enrollink_triggered` | An enrol-link was triggered |
| `bookingoption_bookedviaautoenrol` | A booking was made via auto-enrolment |
| `certificate_issued` | A certificate was issued for a booking |

> Additional events can be registered by **booking extensions** (`bookingextension_*` plugins) by implementing `get_allowedruleeventkeys()` in their main class.

### Booking option state filter

| Value | Meaning |
|-------|---------|
| Always (0) | Run regardless of the booking option's current state |
| Fully booked (1) | Only run if the option is fully booked |
| Not fully booked (2) | Only run if the option is not fully booked |
| Waiting list is full (3) | Only run if the waiting list is full |
| Waiting list is not full (4) | Only run if the waiting list is not full |

---

## 4. Compatibility with conditions

Not all conditions can be used with every rule type. The following matrix shows which combinations are supported:

| Condition | `rule_daysbefore` | `rule_specifictime` | `rule_react_on_event` |
|-----------|:-----------------:|:-------------------:|:---------------------:|
| select_student_in_bo | ✓ | ✓ | ✓ |
| select_teacher_in_bo | ✓ | ✓ | ✓ |
| select_users | ✓ | ✓ | ✓ |
| select_responsible_contact_in_bo | ✓ | ✓ | ✓ |
| select_booking_manager | ✓ | ✓ | ✓ |
| match_userprofilefield | ✓ | ✓ | ✓ |
| enter_userprofilefield | ✓ | ✓ | ✓ |
| select_user_from_event | ✗ | ✗ | ✓ |
| select_user_shopping_cart | ✗ | ✗ | ✓ |
| select_users_from_userfield_of_eventuser | ✗ | ✗ | ✓ |
| select_deputy_of_supervisor | ✗ | ✗ | ✓ |

The event-specific conditions (`select_user_from_event`, `select_user_shopping_cart`, `select_users_from_userfield_of_eventuser`, `select_deputy_of_supervisor`) require event data that is not available for time-based triggers, so they can only be combined with `rule_react_on_event`.

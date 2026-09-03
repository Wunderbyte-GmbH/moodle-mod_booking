[Back to parent section](README.md)

# Booking Rules — Conditions

A **condition** defines *who* is affected when a rule fires.
Every rule must have exactly one condition. The condition narrows down the set of users that will receive the action.

---

## Quick setup path

1. Open booking rules: [/mod/booking/edit_rules.php?contextid=1](/mod/booking/edit_rules.php?contextid=1).
2. Click Add rule or edit an existing rule.
3. Apply the configuration from this page.
4. Save, activate, and test with one booking event.

---

## Table of Contents

1. [select_student_in_bo — Students of a booking option](#1-select_student_in_bo--students-of-a-booking-option)
2. [select_teacher_in_bo — Teachers of a booking option](#2-select_teacher_in_bo--teachers-of-a-booking-option)
3. [select_users — Specific named users](#3-select_users--specific-named-users)
4. [select_responsible_contact_in_bo — Responsible contact](#4-select_responsible_contact_in_bo--responsible-contact)
5. [select_booking_manager — Booking manager](#5-select_booking_manager--booking-manager)
6. [match_userprofilefield — Match booking-option field with user profile field](#6-match_userprofilefield--match-booking-option-field-with-user-profile-field)
7. [enter_userprofilefield — Filter by a fixed value in a user profile field](#7-enter_userprofilefield--filter-by-a-fixed-value-in-a-user-profile-field)
8. [select_user_from_event — User who triggered or was affected by an event](#8-select_user_from_event--user-who-triggered-or-was-affected-by-an-event)
9. [select_user_shopping_cart — User with payment obligation](#9-select_user_shopping_cart--user-with-payment-obligation)
10. [select_users_from_userfield_of_eventuser — Users from a profile field of the event user](#10-select_users_from_userfield_of_eventuser--users-from-a-profile-field-of-the-event-user)
11. [select_deputy_of_supervisor — Deputy of a supervisor](#11-select_deputy_of_supervisor--deputy-of-a-supervisor)

---

## 1. `select_student_in_bo` — Students of a booking option

**Display name:** *Select users of a booking option*

Selects users who have a booking answer in the booking option and whose answer has a specific status.

### Configuration

| Field | Options |
|-------|---------|
| **Role / status** | Booked; Waiting list; Notification list; Deleted; Booked + waiting list (both) |

### Use cases

- Send a reminder email to all currently **booked** participants.
- Notify everyone on the **waiting list** when a spot opens up.
- Send a follow-up to users whose booking was **deleted** (e.g., manual off-boarding).

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 2. `select_teacher_in_bo` — Teachers of a booking option

**Display name:** *Select teachers of a booking option*

Selects all users who are assigned as teachers (trainers) in the booking option via the `booking_teachers` table. No additional configuration is required.

### Use cases

- Send the teacher a reminder before a session starts.
- Notify the teacher when a participant books or cancels.
- Alert the teacher when the waiting list is full.

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 3. `select_users` — Specific named users

**Display name:** *Select specific user(s)*

Lets you manually select one or more Moodle users by name. The selected users will receive the action regardless of their relationship to the booking option.

### Configuration

| Field | Description |
|-------|-------------|
| **Users** | Autocomplete search field. Type a name or email to search; multiple users can be added. |

### Use cases

- Always send a copy to a specific administrator.
- Notify a group of coordinators who are not formally linked to the option.

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 4. `select_responsible_contact_in_bo` — Responsible contact

**Display name:** *Select contact(s) of a booking option*

Selects the user(s) set as **responsible contact** in the booking option settings. A booking option can have exactly one responsible contact.

### Use cases

- Route cancellation notifications to the responsible contact rather than the teacher.
- Send a management summary to the contact person at the end of a course.

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 5. `select_booking_manager` — Booking manager

**Display name:** *Select booking manager*

Selects the user who is configured as the **booking manager** in the booking instance settings (the module-level manager, not a per-option contact). Only one manager per instance is supported.

### Use cases

- Route system-level notifications (e.g., the option reached maximum capacity) to the booking manager.
- Send periodic summaries to the person responsible for managing the whole instance.

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 6. `match_userprofilefield` — Match booking-option field with user profile field

**Display name:** *Select users by matching field in booking option and user profile field.*

Selects users whose **custom user profile field** value matches a **field of the booking option**. Both the user profile field and the booking option field are selected from drop-down lists.

This is powerful for targeted notifications: for example, notify only users whose *department* profile field matches the booking option's *institution* field.

### Configuration

| Field | Description |
|-------|-------------|
| **Custom profile field** | The Moodle custom user profile field (shortname) to compare |
| **Operator** | How to compare: equals, contains, starts with, ends with, is empty, is not empty |
| **Booking option field** | The field of the booking option whose value is used for comparison |

### Use cases

- Send a targeted email to all booked users whose city matches the event's location.
- Notify users whose department matches the booking option's institution.

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 7. `enter_userprofilefield` — Filter by a fixed value in a user profile field

**Display name:** *Select users by entering a value for custom user profile field. Attention! This targets all the users on the platform.*

Similar to `match_userprofilefield`, but instead of comparing against a booking option field, you manually **enter a fixed value** to match against. This condition searches across **all users on the platform**, not just those registered to the option.

> ⚠️ **Warning:** Because this condition queries all platform users, it can result in very large numbers of recipients. Use with care and test on a small scale first.

### Configuration

| Field | Description |
|-------|-------------|
| **Custom profile field** | The Moodle custom user profile field (shortname) to compare |
| **Operator** | Comparison operator (equals, contains, etc.) |
| **Value** | The text value to match against |

### Use cases

- Send a reminder to all users whose profile field `department` equals `IT`.
- Notify all premium members (profile field `membership` = `premium`) about a new option.

### Compatible rule types

`rule_daysbefore`, `rule_specifictime`, `rule_react_on_event`

---

## 8. `select_user_from_event` — User who triggered or was affected by an event

**Display name:** *Select user from event*

Selects a user directly from the event data. This can be either the user who *triggered* the event (`userid`) or the user who was *affected by* it (`relateduserid`).

### Configuration

| Field | Options |
|-------|---------|
| **Role** | User who triggered the event; User who was affected by the event |

### Use cases

- When a booking is made (`bookingoption_booked`), send a confirmation to the user who booked.
- When a teacher is added to a session (`optiondates_teacher_added`), notify that teacher.

### Compatible rule types

`rule_react_on_event` only

---

## 9. `select_user_shopping_cart` — User with payment obligation

**Display name:** *Choose user who has to pay installments*

Selects the user who is associated with a payment obligation in the shopping cart (e.g., an instalment that is due). The user is taken from the event data.

This condition is specifically designed for instalment payment notifications in combination with the `local_shopping_cart` plugin.

### Use cases

- Send a reminder when a payment instalment is coming due.
- Notify the payer after a successful payment.

### Compatible rule types

`rule_react_on_event` only

---

## 10. `select_users_from_userfield_of_eventuser` — Users from a profile field of the event user

**Display name:** *Select user(s) from profilefield of user from event*

Looks up a **custom user profile field** of the user who triggered (or was affected by) the event, and reads the value of that field as one or more Moodle user IDs. The identified users then receive the action.

This allows indirect notification chains: *"When user A books, notify the users whose IDs are stored in field X of user A's profile"* — for example, a supervisor or coordinator.

### Configuration

| Field | Description |
|-------|-------------|
| **Profile field of event user** | The profile field whose value contains the target user ID(s) |
| **User from event type** | Whether to read the field from the triggering user (`userid`) or the affected user (`relateduserid`) |

### Use cases

- Notify a manager (whose ID is stored in the employee's profile) whenever the employee books an option.
- Alert a coordinator when a user on their team cancels.

### Compatible rule types

`rule_react_on_event` only

---

## 11. `select_deputy_of_supervisor` — Deputy of a supervisor

**Display name:** *Select deputy of supervisor*

A two-hop lookup: reads the supervisor's ID from a profile field of the event user, then reads the deputy's ID from a profile field of *that* supervisor. This enables hierarchical notification chains.

### Configuration

| Field | Description |
|-------|-------------|
| **Profile field containing supervisor ID** | Which field of the event user holds the supervisor's Moodle ID |
| **User from event type** | Whether to read from `userid` or `relateduserid` |
| **Profile field of supervisor containing deputy ID** | Which field of the supervisor holds the deputy's Moodle ID |

### Use cases

- When a booking is cancelled, notify the deputy of the responsible supervisor.
- Escalate a payment notification to a substitute when the primary contact is unavailable.

### Compatible rule types

`rule_react_on_event` only

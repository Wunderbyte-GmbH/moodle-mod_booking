[Back to parent section](README.md)

# Booking Rules — Built-in Templates

The rule editor provides a set of **pre-configured rule templates** that cover the most common notification scenarios. Loading a template pre-fills the rule type, condition, action, email subject, and email body. You can then adjust any field before saving.

To load a template: click **Load a template rule** at the top of the rule editor and select the desired template from the list.

---

## Quick setup path

If you use the booking AI assistant, you can ask it to set up one of these templates directly, for example: "Set up a booking confirmation email rule." The agent can first check existing rules and then apply a template-based setup after your confirmation.

1. Open booking rules: [/mod/booking/edit_rules.php?contextid=1](/mod/booking/edit_rules.php?contextid=1).
2. Click Add rule or edit an existing rule.
3. Apply the configuration from this page.
4. Save, activate, and test with one booking event.

---

## Table of Contents

1. [Template — Notification N days before start](#1-template--notification-n-days-before-start)
2. [Template — Reminder before each session (date)](#2-template--reminder-before-each-session-date)
3. [Template — Updates](#3-template--updates)
4. [Template — Confirm booking](#4-template--confirm-booking)
5. [Template — Confirm waiting list](#5-template--confirm-waiting-list)
6. [Template — Payment for booking is confirmed](#6-template--payment-for-booking-is-confirmed)
7. [Template — Booking option completed with poll](#7-template--booking-option-completed-with-poll)
8. [Template — Booking option completion undone](#8-template--booking-option-completion-undone)
9. [Template — Booking option cancellation — Mail to teachers](#9-template--booking-option-cancellation--mail-to-teachers)

---

## 1. Template — Notification N days before start

**String ID:** `ruletemplatedaysbefore`

Sends an email reminder to all booked participants a configurable number of days before the booking option's start time.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_daysbefore` |
| **Date field** | `coursestarttime` |
| **Days** | (set to your desired value, e.g., `3`) |
| **Condition** | `select_student_in_bo` — status: Booked |
| **Action** | `send_mail` |
| **Subject** | Your booking starts in a few days |
| **Body** | Your booking starts in a few days: {bookingdetails} \<br\> Name: {participant} \<br\> To get an overview of all bookings, click on the following link: {bookinglink}\<br\> Here is the link to the course: {courselink} |

**After loading:** Change the *Days* field to your desired notification window (e.g., 7 for one week before, 1 for the day before).

---

## 2. Template — Reminder before each session (date)

**String ID:** `ruletemplatesessionreminders`

Sends a per-session reminder to all booked participants shortly before each individual session starts. Uses the `optiondatestarttime` date field, so it fires for every session (option date), not just once per option.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_daysbefore` |
| **Date field** | `optiondatestarttime` |
| **Days** | (set to your desired value, e.g., `1`) |
| **Condition** | `select_student_in_bo` — status: Booked |
| **Action** | `send_mail` |
| **Subject** | A new session of {Title} will start soon |
| **Body** | Good day {firstname} {lastname},\<br\>the next session of "{title}" will start soon:\<br\>\<br\>{bookingdetails} |

**After loading:** Adjust the *Days* value and optionally customise the email text.

> **Tip:** Individual sessions (option dates) can override the notification day via the *daystonotify* field on the option date. If *daystonotify* is set to a value greater than 0 for a specific session, that value takes precedence over the rule's *Days* setting.

---

## 3. Template — Updates

**String ID:** `ruletemplatecourseupdate`

Notifies all booked participants whenever a booking option is modified. Uses the `{changes}` placeholder to describe what changed.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_updated` |
| **Condition** | `select_student_in_bo` — status: Booked |
| **Action** | `send_mail` |
| **Subject** | Your booking "{title}" has changed |
| **Body** | This is new: \<br\> {changes} \<br\> Click the following link to view the change(s) and an overview of all bookings: {bookinglink} |

---

## 4. Template — Confirm booking

**String ID:** `ruletemplateconfirmbooking`

Sends a booking confirmation email to a participant when their booking is confirmed.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookinganswer_confirmed` |
| **Condition** | `select_user_from_event` — affected user |
| **Action** | `send_mail` |
| **Subject** | You have successfully booked |
| **Body** | Dear {firstname} {lastname},\<br\>Thank you very much for your booking\<br\>{bookingdetails}\<br\>All the best! |

---

## 5. Template — Confirm waiting list

**String ID:** `ruletemplateconfirmwaitinglist`

Notifies a participant that they have been placed on the waiting list.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoptionwaitinglist_booked` |
| **Condition** | `select_user_from_event` — affected user |
| **Action** | `send_mail` |
| **Subject** | You are on the waiting list |
| **Body** | Dear {firstname} {lastname},\<br\>You are on the waiting list\<br\>{bookingdetails}\<br\>All the best! |

---

## 6. Template — Payment for booking is confirmed

**String ID:** `ruletemplatepaymentconfirmation`

Sends a payment confirmation email after a user's payment for a booking is processed via the shopping cart.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_booked` (or a shopping-cart payment event) |
| **Condition** | `select_user_from_event` — affected user |
| **Action** | `send_mail` |
| **Subject** | Payment for {Title} confirmed |
| **Body** | Thank you for your booking!\<br\>Your booking {Title} with the price: {price} has been successfully made.\<br\>Here is the confirmation link:\<br\>{bookingconfirmationlink}\<br\>Here is the course link:\<br\>{courselink}\<br\>Best regards |

---

## 7. Template — Booking option completed with poll

**String ID:** `ruletemplatebookingoptioncompleted`

Notifies participants when a booking option is marked as completed, and includes the poll URL so they can give feedback.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_completed` |
| **Condition** | `select_student_in_bo` — status: Booked |
| **Action** | `send_mail` |
| **Subject** | Bookingoption completed |
| **Body** | You have completed the following booking option:\<br\>{bookingdetails}\<br\> Please participate in the Poll. Poll link: {pollurl} \<br\>To the course: {courselink}\<br\>View all booking options: {bookinglink} |

---

## 8. Template — Booking option completion undone

**String ID:** `ruletemplatebookingoptionuncompleted`

Notifies participants when the completion status of a booking option is reversed.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_uncompleted` |
| **Condition** | `select_student_in_bo` — status: Booked |
| **Action** | `send_mail` |
| **Subject** | Completion undone |
| **Body** | The completion of the following booking option has been undone:\<br\>{bookingdetails} |

---

## 9. Template — Booking option cancellation — Mail to teachers

**String ID:** `ruletemplatetrainercancellation`

Alerts the teachers of a booking option when the option is cancelled.

| Setting | Pre-filled value |
|---------|-----------------|
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_cancelled` |
| **Condition** | `select_teacher_in_bo` |
| **Action** | `send_mail` |
| **Subject** | Cancellation of {Title} |
| **Body** | Good day {firstname} {lastname},\<br\>unfortunately, the following event had to be cancelled:\<br\>{bookingdetails} |

---

## Notes on templates

- Loaded templates are fully editable before saving. They are a starting point, not a fixed configuration.
- You can save your own rules as **custom templates** by enabling the *Use as template* checkbox when creating or editing a rule. Custom templates appear alongside the built-in ones in the template loader.
- Templates are stored with `useastemplate = 1` in the `booking_rules` database table.

# Booking Rules — Practical Examples

This page shows complete, real-world booking rule configurations. Each example lists the exact settings to use in the rule editor.

---

## Table of Contents

1. [Reminder 3 days before course start (all booked participants)](#1-reminder-3-days-before-course-start-all-booked-participants)
2. [Session reminder 1 day before each individual session](#2-session-reminder-1-day-before-each-individual-session)
3. [Instant booking confirmation to the participant](#3-instant-booking-confirmation-to-the-participant)
4. [Waiting list notification to the participant](#4-waiting-list-notification-to-the-participant)
5. [Notify teacher when a participant cancels](#5-notify-teacher-when-a-participant-cancels)
6. [Notify responsible contact when option changes](#6-notify-responsible-contact-when-option-changes)
7. [Auto-confirm booking answers automatically](#7-auto-confirm-booking-answers-automatically)
8. [BCC coordinator on every custom bulk message](#8-bcc-coordinator-on-every-custom-bulk-message)
9. [Notify manager when employee books (via profile field)](#9-notify-manager-when-employee-books-via-profile-field)
10. [Instalment payment reminder 2 days before due date](#10-instalment-payment-reminder-2-days-before-due-date)
11. [Delete form data 30 days after course end (GDPR)](#11-delete-form-data-30-days-after-course-end-gdpr)

---

## 1. Reminder 3 days before course start (all booked participants)

**Goal:** Send a reminder email to every confirmed participant 3 days before the course starts.

| Setting | Value |
|---------|-------|
| **Rule name** | Reminder 3 days before start |
| **Rule type** | `rule_daysbefore` |
| **Days** | `3` |
| **Date field** | `coursestarttime` |
| **Condition** | `select_student_in_bo` |
| **Role** | Booked |
| **Action** | `send_mail` |
| **Subject** | Your booking starts in a few days |
| **Body** | Hi {firstname},\<br\>your booking "{title}" starts on {bookingdetails}.\<br\>See you soon! |

**How it works:** Every time cron runs, the system finds all booking options whose `coursestarttime` is exactly 3 days in the future (with a 1-hour tolerance). For each such option it emails all booked participants.

---

## 2. Session reminder 1 day before each individual session

**Goal:** For multi-session courses, send a reminder 1 day before each individual session.

| Setting | Value |
|---------|-------|
| **Rule name** | Session reminder – 1 day before |
| **Rule type** | `rule_daysbefore` |
| **Days** | `1` |
| **Date field** | `optiondatestarttime` |
| **Condition** | `select_student_in_bo` |
| **Role** | Booked |
| **Action** | `send_mail` |
| **Subject** | A session of {title} starts tomorrow |
| **Body** | Hi {firstname},\<br\>a session of "{title}" starts tomorrow:\<br\>{bookingdetails} |

**Tip:** You can override the reminder lead-time per session by setting the *daystonotify* field on individual option dates. If *daystonotify* is greater than 0 for a session, that value overrides the rule's *Days* setting for that session only.

---

## 3. Instant booking confirmation to the participant

**Goal:** Send a confirmation email the moment a booking is made.

| Setting | Value |
|---------|-------|
| **Rule name** | Booking confirmation |
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_booked` |
| **Condition** | `select_user_from_event` |
| **User type** | User affected by the event (the person who booked) |
| **Action** | `send_mail` |
| **Subject** | You have successfully booked: {title} |
| **Body** | Dear {firstname} {lastname},\<br\>thank you for booking "{title}".\<br\>{bookingdetails}\<br\>Best regards |

---

## 4. Waiting list notification to the participant

**Goal:** Notify a participant immediately when they are placed on the waiting list.

| Setting | Value |
|---------|-------|
| **Rule name** | Waiting list confirmation |
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoptionwaitinglist_booked` |
| **Condition** | `select_user_from_event` |
| **User type** | User affected by the event |
| **Action** | `send_mail` |
| **Subject** | You are on the waiting list for: {title} |
| **Body** | Dear {firstname} {lastname},\<br\>you have been placed on the waiting list for "{title}".\<br\>We will notify you if a spot becomes available. |

---

## 5. Notify teacher when a participant cancels

**Goal:** Alert the teacher(s) of a booking option whenever a participant cancels.

| Setting | Value |
|---------|-------|
| **Rule name** | Cancellation alert to teachers |
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookinganswer_cancelled` |
| **Condition** | `select_teacher_in_bo` |
| **Action** | `send_mail` |
| **Subject** | A participant cancelled: {title} |
| **Body** | Hi {firstname},\<br\>a participant has cancelled their booking for "{title}".\<br\>{bookingdetails} |

---

## 6. Notify responsible contact when option changes

**Goal:** Whenever any field of a booking option is updated, send a summary of changes to the responsible contact.

| Setting | Value |
|---------|-------|
| **Rule name** | Change notification to responsible contact |
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_updated` |
| **Condition** | `select_responsible_contact_in_bo` |
| **Action** | `send_mail` |
| **Subject** | Changes in booking option: {title} |
| **Body** | Hi {firstname},\<br\>the following booking option has changed:\<br\>{changes}\<br\>View the option: {bookinglink} |

---

## 7. Auto-confirm booking answers automatically

**Goal:** Automatically confirm every booking answer that enters "waiting for confirmation" status, without manual intervention.

| Setting | Value |
|---------|-------|
| **Rule name** | Auto-confirm on notification |
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookinganswer_waitingforconfirmation` |
| **Condition** | `select_student_in_bo` |
| **Role** | Booked (or "Booked + waiting list") |
| **Action** | `confirm_bookinganswer` |

**Prerequisite:** The booking option must have *Confirmation on notification* enabled. If the value is `2`, only one user is confirmed per rule execution (sequential flow for waiting-list management).

---

## 8. BCC coordinator on every custom bulk message

**Goal:** Whenever a booking manager sends a custom bulk message from within the plugin, automatically send a copy to a specific coordinator.

| Setting | Value |
|---------|-------|
| **Rule name** | BCC coordinator on bulk messages |
| **Rule type** | `rule_react_on_event` |
| **Event** | `custom_bulk_message_sent` |
| **Condition** | `select_users` |
| **Users** | *(select the coordinator user)* |
| **Action** | `send_copy_of_mail` |
| **Subject prefix** | `[COPY] ` |
| **Message prefix** | `This is a copy of a message sent to participants of: {title}\n\n---\n\n` |

---

## 9. Notify manager when employee books (via profile field)

**Goal:** When a user books an option, read their *manager_id* custom profile field and notify that manager.

**Prerequisite:** A custom user profile field named `manager_id` exists on your site, and it stores the Moodle user ID of each user's manager.

| Setting | Value |
|---------|-------|
| **Rule name** | Notify manager on booking |
| **Rule type** | `rule_react_on_event` |
| **Event** | `bookingoption_booked` |
| **Condition** | `select_users_from_userfield_of_eventuser` |
| **Profile field** | `manager_id` |
| **User from event type** | User affected by the event (the person who booked) |
| **Action** | `send_mail` |
| **Subject** | Your employee booked: {title} |
| **Body** | Hi {firstname},\<br\>one of your team members has booked the following option:\<br\>{bookingdetails} |

---

## 10. Instalment payment reminder 2 days before due date

**Goal:** Remind users about an upcoming shopping-cart instalment payment.

**Prerequisites:** `local_shopping_cart` plugin is installed; instalment payments are configured.

| Setting | Value |
|---------|-------|
| **Rule name** | Instalment payment reminder |
| **Rule type** | `rule_daysbefore` |
| **Days** | `2` |
| **Date field** | `installmentpayment` |
| **Condition** | `select_user_shopping_cart` |
| **Action** | `send_mail` |
| **Subject** | Your payment for {title} is due in 2 days |
| **Body** | Hi {firstname},\<br\>a payment instalment for "{title}" is due on {duedate}.\<br\>Please ensure your payment is completed on time.\<br\>Price: {price} |

---

## 11. Delete form data 30 days after course end (GDPR)

**Goal:** Remove the custom availability-condition form data from all booking answers 30 days after the booking option ends, for GDPR compliance.

| Setting | Value |
|---------|-------|
| **Rule name** | Delete form data 30 days after end |
| **Rule type** | `rule_daysbefore` |
| **Days** | `-30` (negative = 30 days **after** the date) |
| **Date field** | `courseendtime` |
| **Condition** | `select_student_in_bo` |
| **Role** | Booked |
| **Action** | `delete_conditions_from_bookinganswer` |

**Note:** Using a negative *Days* value means the rule fires 30 days **after** the `courseendtime`. No email is sent — the action only removes stored condition data from the `booking_answers` table.

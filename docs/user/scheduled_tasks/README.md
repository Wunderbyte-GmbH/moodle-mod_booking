[Back to parent section](../../../README.md)

# Scheduled Tasks — Reference

mod_booking registers 5 Moodle scheduled tasks in `db/tasks.php`. These run automatically via Moodle's cron system.

---

## Quick setup path

1. Open scheduled tasks in Site administration.
2. Search for booking-related tasks from this page.
3. Run one task manually for testing if needed.
4. Confirm next run times and logs.

---

## Table of Contents

1. [remove_activity_completion](#1-remove_activity_completion)
2. [enrol_bookedusers_tocourse](#2-enrol_bookedusers_tocourse)
3. [send_reminder_mails](#3-send_reminder_mails)
4. [send_notification_mails](#4-send_notification_mails)
5. [clean_booking_db](#5-clean_booking_db)
6. [How to manage scheduled tasks](#6-how-to-manage-scheduled-tasks)

---

## 1. `remove_activity_completion`

| Setting | Value |
|---------|-------|
| **Class** | `mod_booking\task\remove_activity_completion` |
| **Default schedule** | Every minute (`* * * * *`) |
| **Blocking** | No |

### Purpose

Removes stale activity completion tracking entries. When a booking option is deleted or a user's booking is cancelled, any associated Moodle activity completion record is cleaned up. This keeps the completion subsystem consistent.

### Performance considerations

This task runs every minute by default. Because it only processes a small delta of changed records, the runtime is typically very short (milliseconds). If your site has a very high booking volume, you can reduce the frequency to every 5 minutes without significant impact.

---

## 2. `enrol_bookedusers_tocourse`

| Setting | Value |
|---------|-------|
| **Class** | `mod_booking\task\enrol_bookedusers_tocourse` |
| **Default schedule** | Every minute (`* * * * *`) |
| **Blocking** | No |

### Purpose

Automatically enrols confirmed participants into the Moodle course linked to a booking option, once the course's start time has been reached. This implements the "link to Moodle course" feature documented in [Booking option — Linked Moodle course](../booking-option/06-moodle-course.md).

### When enrolment occurs

Enrolment is triggered when:
- A booking option has a linked Moodle course (`courseid` field set)
- The booking option's `coursestarttime` is in the past (i.e., the course has started)
- The user has a confirmed booking answer (`status = booked`)
- The enrolment has not yet been processed for this user/option combination

### Performance considerations

Like `remove_activity_completion`, this runs every minute. Each run only processes users who haven't been enrolled yet. On large sites with many simultaneous bookings, consider increasing the frequency if enrolment delays are observed, or reducing it if cron performance is a concern.

---

## 3. `send_reminder_mails`

| Setting | Value |
|---------|-------|
| **Class** | `mod_booking\task\send_reminder_mails` |
| **Default schedule** | At minute 7 of every hour (`7 * * * *`) |
| **Blocking** | No |

### Purpose

Sends the **legacy reminder emails** configured on the booking *activity* level (not booking rules). Specifically, it processes the `daystonotify` and `daystonotify2` fields on the booking instance, which define how many days before `coursestarttime` reminders are sent.

> **Note:** This task only runs when **Use legacy mail templates** is enabled in the booking plugin settings (`uselegacymailtemplates`). If you are using the modern [booking rules](../booking_rules/README.md) system for email automation, this task is skipped.

### What it does

1. Queries all booking options whose `coursestarttime` is in the future and whose `sent`/`sent2` flags are not yet set.
2. For options within the configured notification window (`daystonotify` days before start), queues reminder emails to all booked participants.
3. Sets `sent = 1` or `sent2 = 1` after the mail is queued to prevent duplicate sends.

---

## 4. `send_notification_mails`

| Setting | Value |
|---------|-------|
| **Class** | `mod_booking\task\send_notification_mails` |
| **Default schedule** | Daily at 07:30 (`30 7 * * *`) |
| **Blocking** | No |

### Purpose

Sends **notification list emails** to users who have signed up for the notification list (users who asked to be notified when a spot becomes available). This is distinct from the waiting list; notification list users are not automatically booked when a spot opens, but they receive an email notification.

### What it does

1. Queries users who are on the notification list (`status = MOD_BOOKING_STATUSPARAM_NOTIFYMELIST`) for options with open capacity.
2. Sends a notification email to each user informing them that a place is available.
3. Marks the notification as sent to prevent duplicate emails.

### Why daily at 07:30?

Notification emails are batched and sent once per day rather than in real time, to avoid flooding users with repeated notifications if multiple cancellations occur throughout the day.

---

## 5. `clean_booking_db`

| Setting | Value |
|---------|-------|
| **Class** | `mod_booking\task\clean_booking_db` |
| **Default schedule** | Weekly on Sunday at 03:42 (`42 3 * * 0`) |
| **Blocking** | No |

### Purpose

Performs periodic housekeeping on the booking database tables. Removes stale and orphaned records that accumulate over time.

### What it cleans up

- **`booking_optiondates_teachers`** records whose `optiondateid` no longer exists in `booking_optiondates` (orphaned teacher-to-session links).
- **`booking_teachers`** records whose parent booking option no longer exists.
- Other stale artifacts that can accumulate when options are deleted without full cascade cleanup.

### Caches

After cleaning, the task purges relevant Moodle caches (`setbackcachedteachersjournal`) to ensure the UI reflects the cleaned data.

---

## 6. How to manage scheduled tasks

All scheduled tasks can be viewed and their schedules adjusted at:

*Site administration → Server → Scheduled tasks*

Direct URL: `/admin/tool/task/scheduledtasks.php`

To change a task's schedule:

1. Find the task by its class name (e.g., `mod_booking\task\clean_booking_db`).
2. Click the **Edit** button next to it.
3. Adjust the cron expression fields (minute, hour, day, month, day-of-week).
4. Save. The custom schedule overrides the default.

To manually trigger a task (for testing or after a data issue):

```
php admin/cli/scheduled_task.php --execute=\\mod_booking\\task\\clean_booking_db
```

---

## See also

- [Booking rules — Rule types (rule_daysbefore, rule_specifictime)](../booking_rules/rule-types.md) — Ad-hoc tasks used by the booking rules system
- [Booking option — Linked Moodle course](../booking-option/06-moodle-course.md) — `enrol_bookedusers_tocourse` context

# Check Answers — "Unenrol users without access"

This guide explains the **check-answers** subsystem: the feature that automatically removes a
user's booking once that user can no longer access the booking activity or its course. It is both
an administrator guide (how to switch it on, how it is triggered) and a developer guide (how to add
your own checks and actions).

> **PRO feature.** The admin settings that drive this subsystem are only available with a
> Booking PRO licence.

---

## Table of Contents

1. [What it does](#1-what-it-does)
2. [Turning it on (admin)](#2-turning-it-on-admin)
3. [What triggers a check](#3-what-triggers-a-check)
4. [Execution flow](#4-execution-flow)
5. [The built-in checks and action](#5-the-built-in-checks-and-action)
6. [Adding a custom check or action (developer)](#6-adding-a-custom-check-or-action-developer)
7. [Troubleshooting](#7-troubleshooting)
8. [File reference](#8-file-reference)

---

## 1. What it does

When a user loses access to a booking activity, their existing booking (booking answer) becomes
"orphaned" — they are still booked, but can no longer see or reach the activity. This subsystem
detects that situation and **deletes the affected booking answer** so that seats are freed and
reporting stays accurate.

A user can lose access in two ways, each handled by its own check:

| Trigger | Check | Meaning |
|---------|-------|---------|
| User unenrolled from the course | `CHECK_COURSE_ENROLLMENT` | User is no longer enrolled in the course that hosts the booking activity. |
| User no longer sees the activity | `CHECK_CM_VISIBILITY` | The booking activity's course module is shown in the course but is no longer *user-visible* (e.g. an access restriction now hides it from this user). |

Deletion never happens synchronously. Every check is queued as an **ad-hoc task that runs ~15
minutes later**, giving an administrator a window to undo a mistaken group/enrolment change before
bookings are removed.

---

## 2. Turning it on (admin)

The feature is gated by **two** settings under
*Site administration → Plugins → Activity modules → Booking*
(defined in [`settings.php`](../../settings.php), section "Unenrol users without access").

It is deliberately a **two-step** opt-in:

1. **`unenroluserswithoutaccessareyousure`** — *"Do you really want to activate…"*
   A safety confirmation checkbox. **The real toggle is hidden until this one is saved and the
   settings page is reloaded.**
2. **`unenroluserswithoutaccess`** — *"Delete booking answers of users without access"*
   The actual feature switch. Only appears once step 1 is saved.

> ⚠️ **Both must be enabled.** If either is off, nothing happens — no ad-hoc task is even queued.
> The most common setup mistake is enabling only the confirmation checkbox (step 1) and assuming
> the feature is active.

When you tick `unenroluserswithoutaccess` **in the settings form**, its `set_updatedcallback`
immediately queues a **system-wide `CHECK_ALL` sweep** (also delayed ~15 min) so that bookings that
are *already* orphaned get cleaned up once, not just future ones.

> Setting the value any other way (CLI `admin/cli/cfg.php`, direct DB write) does **not** fire the
> callback, so the initial system-wide sweep will not run — only event-triggered checks will.

---

## 3. What triggers a check

Checks are created from event observers registered in [`db/events.php`](../../db/events.php) and
handled in [`classes/observer.php`](../../classes/observer.php):

| Event | Observer | Check queued | Scope |
|-------|----------|--------------|-------|
| `\core\event\group_member_added` | `group_membership_changed()` | `CHECK_CM_VISIBILITY` | The affected user, course context |
| `\core\event\group_member_removed` | `group_membership_changed()` | `CHECK_CM_VISIBILITY` | The affected user, course context |
| `\core\event\user_enrolment_deleted` | `user_enrolment_deleted()` | `CHECK_COURSE_ENROLLMENT` | The affected user, course context |
| Enabling the `unenroluserswithoutaccess` setting | `set_updatedcallback` | `CHECK_ALL` | All users, system context |

The check type is chosen by the triggering event — there is **no per-check on/off setting**. The
group events run only the visibility check; the unenrolment event runs only the course-enrolment
check.

> The `group_member_*` observer also feeds the separate *group → booking enrolment sync* feature.
> The legacy per-instance "Remove user on unenrolment" (`removeuseronunenrol`) behaviour also lives
> in `user_enrolment_deleted()` and is independent of this subsystem.

---

## 4. Execution flow

```
event (group / unenrol) ─┐
setting enabled ─────────┴─▶ checkanswers::create_bookinganswers_check_tasks()
                                │  • returns early unless BOTH settings are on
                                │  • finds every booking option under the given context
                                │    (optionally filtered to one user, waitinglist < 5)
                                ▼
                         queue ad-hoc task  \mod_booking\task\check_answers
                                │  • next_run_time = now + 15 minutes
                                ▼  (cron, after the delay)
                         check_answers::execute()
                                │  • re-checks BOTH settings
                                ▼
                         checkanswers::process_booking_option()
                                │  • for each booking answer (optionally one user)
                                ▼
                         check_answer()  ── discovers all classes in
                                │           local\checkanswers\checks, sorts by $id,
                                │           runs the requested check (or all for CHECK_ALL),
                                │           stops on the first blocking result
                                ▼  (a check returned false = "no access")
                         perform_action()  ── runs the matching class in
                                            local\checkanswers\actions (ACTION_DELETE)
```

Key points:

- The 15-minute delay is set in
  [`checkanswers.php`](../../classes/local/checkanswers/checkanswers.php) via
  `strtotime('+ 15 minutes', time())` (under PHPUnit it runs immediately).
- `check_answers::execute()` re-validates **both** settings, so disabling the feature after a task
  is queued cancels its effect.
- Checks and actions are **auto-discovered** with
  `core_component::get_component_classes_in_namespace()` — there is no registry to edit.

---

## 5. The built-in checks and action

Constants live in
[`checkanswers.php`](../../classes/local/checkanswers/checkanswers.php):

```php
const CHECK_ALL            = 1;  // run every check
const CHECK_COURSE_ENROLLMENT = 2;
const CHECK_CM_VISIBILITY  = 3;
const ACTION_DELETE        = 1;
```

### Checks (`classes/local/checkanswers/checks/`)

A check's `check_answer($answer)` returns **`true` = user still has access (keep the booking)** and
**`false` = user lost access (run the action)**.

- **`enrolledincourse`** (`CHECK_COURSE_ENROLLMENT`) — returns
  `is_enrolled(course_context, $answer->userid)`. Booking is removed when the user is no longer
  enrolled in the course.
- **`cmvisibility`** (`CHECK_CM_VISIBILITY`) — loads the activity for the *answer's* user via
  `get_fast_modinfo($course, $answer->userid)` and returns
  `!($cm->visible == "1" && !$cm->get_user_visible())`. In words: the booking is removed when the
  activity is *shown* in the course (`visible = 1`) but is **not user-visible** to this user — for
  example because an access restriction (group, grouping, profile field, etc.) now excludes them.
  If the course module cannot be loaded at all, it also returns `false` (remove).

### Action (`classes/local/checkanswers/actions/`)

- **`deleteanswer`** (`ACTION_DELETE`) — calls
  `booking_option::user_delete_response()` to remove the booking answer.

---

## 6. Adding a custom check or action (developer)

The subsystem is extensible without touching the orchestrator — drop a class into the right
namespace and it is picked up automatically.

### A custom check

Create `classes/local/checkanswers/checks/mycheck.php`:

```php
namespace mod_booking\local\checkanswers\checks;

use stdClass;

class mycheck {
    /** @var int Unique id — reuse an existing CHECK_* constant or define a new unique integer. */
    public static int $id = 4;

    public static function get_id() {
        return self::$id;
    }

    /**
     * @param stdClass $answer A booking answer record.
     * @return bool true = user keeps access, false = run the action (e.g. delete).
     */
    public static function check_answer(stdClass $answer) {
        // ... your logic ...
        return true;
    }
}
```

Notes:

- Checks are sorted by `$id` (ascending) and, by default, evaluation **stops on the first blocking
  check** (`breakonfirst = true`).
- A new check only runs automatically when something queues it. To wire it to a Moodle event, add
  an observer in [`db/events.php`](../../db/events.php) that calls
  `checkanswers::create_bookinganswers_check_tasks($contextid, mycheck::$id, ...)`, or rely on
  `CHECK_ALL` (the system-wide sweep / any caller using `CHECK_ALL`).

### A custom action

Create `classes/local/checkanswers/actions/myaction.php` with the same shape, implementing
`get_id()` and `perform_action(stdClass $answer): bool`. Pass its id as the `$action` argument when
queuing tasks.

---

## 7. Troubleshooting

**No ad-hoc task is created when I change a group / unenrol a user.**
Both settings must be on. Check the *actual stored values* — not just what the form looks like:

```bash
php admin/cli/cfg.php --component=booking | grep -i unenrol
```

You should see **both** `unenroluserswithoutaccessareyousure` **and** `unenroluserswithoutaccess`
set to `1`. If only the first appears, the real toggle was never saved (it is hidden until the
confirmation checkbox is saved and the page reloaded — see [section 2](#2-turning-it-on-admin)).

**A task exists but never runs.**
It is scheduled ~15 minutes in the future. Inspect the queue (adjust the table prefix to your site):

```sql
SELECT id, classname, nextruntime, faildelay, customdata
FROM {task_adhoc}
WHERE classname = '\mod_booking\task\check_answers';
```

To run it sooner, set `nextruntime` to a past/near timestamp (and `faildelay = 0` if non-zero), then
run cron. Remember `nextruntime` is a Unix timestamp.

**The task runs but the booking is kept.**
- *Visibility check:* the user must be genuinely *not user-visible*. A user with
  `moodle/course:ignoreavailabilityrestrictions` or `moodle/course:viewhiddenactivities` (teachers,
  managers, admins) still counts as having access, so their booking is kept. Test with a plain
  student.
- *Enrolment check:* the user must actually be unenrolled from the **course**; merely losing the
  booking activity is the visibility check's job, not this one.

---

## 8. File reference

| File | Role |
|------|------|
| [`classes/local/checkanswers/checkanswers.php`](../../classes/local/checkanswers/checkanswers.php) | Orchestrator: constants, task creation, option processing, check/action discovery |
| [`classes/local/checkanswers/checks/cmvisibility.php`](../../classes/local/checkanswers/checks/cmvisibility.php) | `CHECK_CM_VISIBILITY` — activity user-visibility check |
| [`classes/local/checkanswers/checks/enrolledincourse.php`](../../classes/local/checkanswers/checks/enrolledincourse.php) | `CHECK_COURSE_ENROLLMENT` — course enrolment check |
| [`classes/local/checkanswers/actions/deleteanswer.php`](../../classes/local/checkanswers/actions/deleteanswer.php) | `ACTION_DELETE` — deletes the booking answer |
| [`classes/task/check_answers.php`](../../classes/task/check_answers.php) | Ad-hoc task that runs the checks after the delay |
| [`classes/observer.php`](../../classes/observer.php) | Event observers that queue the checks |
| [`db/events.php`](../../db/events.php) | Event → observer registration |
| [`settings.php`](../../settings.php) | The two admin settings (PRO) |
| [`tests/checkanswers/`](../../tests/checkanswers/) | PHPUnit coverage for the subsystem |

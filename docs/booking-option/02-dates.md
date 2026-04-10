# Dates

The **Dates** section lets you define when a booking option takes place. Options can have a **single session**, **multiple sessions** (added one by one), or a **recurring weekly series** derived from a semester.

---

## Table of Contents

1. [Single and multiple sessions](#1-single-and-multiple-sessions)
2. [Recurring dates from a semester](#2-recurring-dates-from-a-semester)
3. [Add to calendar](#3-add-to-calendar)
4. [How dates affect the option display](#4-how-dates-affect-the-option-display)

---

## 1. Single and multiple sessions

When you open the dates section you see a date picker component for the first session:

| Field | Description |
|-------|-------------|
| **Start date / time** (`coursestarttime`) | When the session begins. |
| **End date / time** (`courseendtime`) | When the session ends. End must be after start. |

### Adding more sessions

Click **Add date** inside the dates section to add additional sessions to the same booking option. Each session gets its own start/end time. All sessions are linked to one booking option — participants book the whole option, not individual sessions.

> The first session's start time is used as the option's overall `coursestarttime`; the last session's end time is used as the overall `courseendtime`. These values appear in list views and are used for sorting.

### Location and entity per session

If the [local_entities](https://github.com/Wunderbyte-GmbH/moodle-local_entities) plugin is installed, each session can be assigned its own entity (venue/room) independently.

---

## 2. Recurring dates from a semester

Instead of entering dates manually, you can generate an entire series of sessions from a weekly pattern and a semester:

| Field | Description |
|-------|-------------|
| **Day and time pattern** (`dayofweektime`) | A string like `Monday 09:00-11:00` or `Wed 14:00-15:30`. You can enter multiple patterns separated by commas for options that meet on more than one day per week. |
| **Semester** (`semesterid`) | Select the semester that defines the start and end of the series. The system generates one session for each matching weekday within the semester's date range. |

> **Note:** Semesters must be configured by an admin in the booking plugin settings before they can be used here.

### Supported day name formats

Day names are flexible: `Monday`, `Mon`, `Montag`, `Mo` — the system recognises all common forms in multiple languages.

### Combining recurring and manual dates

After generating a series, you can still add one-off sessions manually using **Add date**. Both manual and generated dates co-exist on the same option.

---

## 3. Add to calendar

| Field | Value | Description |
|-------|-------|-------------|
| **Add to course calendar** (`addtocalendar`) | `0` — Do not add | Sessions are not added to the Moodle course calendar. |
| | `1` — Add as course event | Each session is added as an event to the course calendar. The event is visible to **all** enrolled course members, not just bookers. |

> **Important:** Course calendar events are visible to everyone enrolled in the connected Moodle course. If you only want events to appear in a user's personal calendar after booking, leave this set to *Do not add* and rely on the iCal/e-mail notification system instead.

When **Add to calendar** is set to 1 and you later remove or change a date, the corresponding calendar event is deleted or updated automatically.

This setting can be **locked** by an admin for the whole installation so that it cannot be changed per option.

---

## 4. How dates affect the option display

- Options **without any date** are displayed as "no date" and sorted to the bottom of the list.
- The option's booking opening and closing times (set under [Availability conditions](04-availability.md)) are separate from the session dates.
- The `{coursestarttime}` and `{courseendtime}` placeholders in e-mail templates use the earliest/latest session timestamps.
- Individual session details are available via the `{dates}` and `{option_times}` placeholders.

---

## Related pages

- [General settings](01-general.md) — Title, capacity
- [Availability conditions](04-availability.md) — Booking window (open/close times)
- [CSV Import — Dates](../CSV_IMPORT_USER_GUIDE.md#5-dates-and-scheduling) — How to set dates via CSV

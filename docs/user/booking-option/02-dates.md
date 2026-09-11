[Back to parent section](README.md)

# Dates

The **Dates** section lets you define when a booking option takes place. Options can have a **single session**, **multiple sessions** (added one by one), or a **recurring weekly series** derived from a semester.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

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
| **Add to Moodle calendar** (`addtocalendar`) | `0` — Do not add to calendar | Sessions are not added to the Moodle calendar as instance-wide events. (Personal calendar events for booked users and teachers are created regardless.) |
| | `1` — Add to calendar (visible only to participants of moodle course) | Each session is added as a **course event** to the calendar of the course the booking instance is in. The event is visible to **all** enrolled course members, not just bookers. |
| | `2` — Add as site event (visible to all users of the site) | Each session is added as a **site event**. Site events are visible to **every user of the site** — an enrolment in the course of the booking instance is not needed. Requires the capability `mod/booking:createcalendarsiteevents` (default: manager; site administrators always have it). |

> **Important:** Course calendar events are visible to everyone enrolled in the connected Moodle course, site events to everyone on the site. If you only want events to appear in a user's personal calendar after booking, leave this set to *Do not add to calendar* and rely on the iCal/e-mail notification system instead.

When **Add to Moodle calendar** is set to 1 or 2 and you later remove or change a date, the corresponding calendar event is deleted or updated automatically. Switching between course event and site event converts the existing events in place. Like course events, site events are hidden while the booking option is invisible or the booking activity is hidden.

**Site events and permissions:** the option *Add as site event* is only offered to users holding `mod/booking:createcalendarsiteevents`. For other users the dropdown shows only *Do not add* and *course event*; if the option already is a site event (set by a privileged user), the dropdown is shown read-only so the setting is preserved. The check is enforced on the server for every save path (option form, bulk editing, web service, CSV import) — a value of `2` submitted without the capability is rejected with a permission error.

**Defaults (site administration):** `booking/addtocalendardefault` preselects the dropdown for **new** booking options (*Do not add*, *course event* or *site event*; *site event* falls back to *course event* for users without the capability). `booking/addtocalendar_locked` freezes the dropdown at that default for everyone, so it cannot be changed per option. Neither setting affects existing options, the CSV import or the web service.

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

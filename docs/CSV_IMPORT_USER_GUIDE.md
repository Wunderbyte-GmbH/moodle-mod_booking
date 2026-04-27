# Booking Options — CSV Import User Guide

This guide explains how to import or update booking options in bulk using a CSV file.

---

## Table of Contents

1. [How to import](#1-how-to-import)
2. [File format rules](#2-file-format-rules)
3. [Required columns](#3-required-columns)
4. [Core option fields](#4-core-option-fields)
5. [Dates and scheduling](#5-dates-and-scheduling)
6. [Capacity and booking window](#6-capacity-and-booking-window)
7. [Cancellation settings](#7-cancellation-settings)
8. [Teachers](#8-teachers)
9. [Directly booking users](#9-directly-booking-users)
10. [Pricing](#10-pricing)
11. [Linked Moodle course](#11-linked-moodle-course)
12. [Availability restrictions](#12-availability-restrictions)
13. [Notifications and texts](#13-notifications-and-texts)
14. [Advanced columns](#14-advanced-columns)
15. [Date formats](#15-date-formats)
16. [Tips and common mistakes](#16-tips-and-common-mistakes)
17. [Example files](#17-example-files)

---

## 1. How to Import

1. Open the booking activity in which you want to create or update options.
2. Go to **Settings → Import options from CSV**.
3. Upload your CSV file and confirm.
4. The system shows a preview and a result log. Rows with errors are skipped; valid rows are saved.

---

## 2. File format rules

- Encoding: **UTF-8** (important for special characters)
- Delimiter: **comma (`,`)** or **semicolon (`;`)** — the importer auto-detects it
- First row: **column headers** (see sections below)
- Subsequent rows: one booking option per row
- Empty cells are treated as "not set" — leave a cell blank to keep the existing value (for updates)
- Text values containing commas must be enclosed in double quotes: `"Room 3, Building A"`

---

## 3. Required columns

Every CSV file needs at least these columns:

| Column | Purpose | Example |
|--------|---------|---------|
| `text` | Title of the booking option | `Introduction to Python` |
| `identifier` | A unique ID you assign (used to update existing options without needing the internal ID) | `PYTHON-2026-01` |

> **Tip:** If a row has `identifier` but no `id`, the importer will search for an existing option with that identifier. If found, it updates it; if not found, it creates a new one.
> If you supply the internal Moodle `id`, the importer updates that specific option directly.

Alternative for `text`: you can also use the column name `name` — both work.

---

## 4. Core option fields

| Column | What it does | Example |
|--------|-------------|---------|
| `text` | Option title | `Introduction to Python` |
| `titleprefix` | Short prefix shown before the title | `2026-01` |
| `description` | Longer description (HTML allowed) | `Learn the basics of Python programming.` |
| `location` | Location name or room | `Room 3B` |
| `address` | Address of the location | `Main Street 1, 1010 Vienna` |
| `institution` | Organising institution | `IT Department` |
| `annotation` | Internal notes (not shown to participants) | `Only for premium members` |
| `pollurl` | URL to a poll or feedback form | `https://forms.example.com/poll` |
| `invisible` | Hide the option from participants (`1` = hidden, `0` = visible) | `1` |
| `identifier` | Your custom unique identifier for this option | `PYTHON-2026-01` |

---

## 5. Dates and scheduling

### Single date (one session)

| Column | What it does | Example |
|--------|-------------|---------|
| `coursestarttime` | Start date and time of the session | `2026-04-15 09:00:00` |
| `courseendtime` | End date and time of the session | `2026-04-15 11:00:00` |

> For multiple sessions in one option, see the `optiondateid_0`, `optiondateid_1`, … columns in the [Advanced columns](#14-advanced-columns) section, or use the Booking UI to add extra sessions after import.

### Recurring dates (weekly series from a semester)

| Column | What it does | Example |
|--------|-------------|---------|
| `dayofweektime` | Day and time pattern for the series | `Monday 09:00-11:00` |
| `semesterid` | ID of the semester that defines the date range | `5` |

> When `dayofweektime` is set, the importer generates all occurrence dates for the given semester automatically. `semesterid` is **required** in this case.

### Custom date format

If your dates are in a non-standard format (e.g. `30.04.2026 09:00`), add the column:

| Column | What it does | Example |
|--------|-------------|---------|
| `dateparseformat` | PHP date format string for the dates in this row | `d.m.Y H:i` |

See [Date formats](#15-date-formats) for details.

---

## 6. Capacity and booking window

| Column | What it does | Example |
|--------|-------------|---------|
| `maxanswers` | Maximum number of bookings (0 = unlimited) | `20` |
| `minanswers` | Minimum number of bookings required | `5` |
| `maxoverbooking` | Number of places on the waiting list | `3` |
| `bookingopeningtime` | Date/time from which booking is possible | `2026-03-01 00:00:00` |
| `bookingclosingtime` | Booking deadline (last date to book) | `2026-04-14 23:59:00` |
| `waitforconfirmation` | Require manual confirmation of each booking (`1` = yes, `0` = no) | `1` |
| `confirmationonnotification` | Send confirmation notification when manually confirmed (`1`/`0`) | `1` |
| `disablebookingusers` | Prevent participants from booking themselves (`1` = disabled, `0` = enabled) | `0` |

---

## 7. Cancellation settings

| Column | What it does | Example |
|--------|-------------|---------|
| `disablecancel` | Prevent cancellations by participants (`1` = disabled) | `0` |
| `canceluntil` | Latest date until which cancellation is possible | `2026-04-10 23:59:00` |
| `canceluntilcheckbox` | Enable the cancel-until restriction (`1` = enabled); set automatically if `canceluntil` is not empty | `1` |

---

## 8. Teachers

| Column | What it does | Example |
|--------|-------------|---------|
| `teacheremail` | Email address of the teacher/trainer to assign. Separate multiple teachers with a pipe `\|` | `trainer@example.com` or `a@example.com\|b@example.com` |

> The teacher must already exist as a Moodle user. An error is shown if the email is not found.
> By default, assigning teachers via import **adds** them to existing teachers. Set the companion column `mergeparam` to `0` to **replace** all existing teachers.

---

## 9. Directly booking users

Use these columns to pre-book participants into a booking option during import.

| Column | What it does | Example |
|--------|-------------|---------|
| `useremail` | Email address of the user to book | `student@example.com` |
| `username` | Username of the user to book (use either `useremail` or `username`, not both) | `jdoe` |
| `timebooked` | Date/time when the booking was made (optional) | `2026-03-20 10:00:00` |
| `completed` | Mark the user's booking as completed (`1` = completed) | `1` |

> Note: To book multiple users into the same option, you need one row per user. Use the same `identifier` to link all rows to the same option.

---

## 10. Pricing

| Column | What it does | Example |
|--------|-------------|---------|
| `useprice` | Enable pricing for this option (`1` = yes) | `1` |
| *(price category identifier)* | Set the price for a specific price category. The column name is the short identifier of the price category configured in the booking settings. | `default` → `59.90` |

### Example

If your Moodle booking instance has the price categories `default` and `student`, your CSV can include:

```
text,identifier,useprice,default,student
Python Basics,PY-01,42,1,59.90,29.90
```

> Price values use a period (`.`) as decimal separator, e.g. `59.90`.

---

## 11. Linked Moodle course

| Column | What it does | Example |
|--------|-------------|---------|
| `enroltocourseshortname` | Short name of the Moodle course to link and auto-enrol participants | `python-2026` |
| `courseid` | Internal numeric ID of the Moodle course | `87` |
| `coursenumber` | Alias for `courseid` (same as above) | `87` |

> Only one of these columns is needed. If the course is not found, the import row will fail with an error.

---

## 12. Availability restrictions

These columns configure who is allowed to book the option.

### Restricted to enrolled course participants

| Column | What it does | Example |
|--------|-------------|---------|
| `boavenrolledincourse` | Short name(s) of courses the user must be enrolled in (comma-separated) | `python-basics,python-adv` |
| `boavenrolledincourseoperator` | Logical operator: `AND` (must be in all) or `OR` (must be in at least one) | `OR` |

### Restricted to cohort members

| Column | What it does | Example |
|--------|-------------|---------|
| `boavenrolledincohorts` | ID number(s) of cohorts the user must belong to (comma-separated) | `STAFF,PREMIUM` |
| `boavenrolledincohortsoperator` | Logical operator: `AND` or `OR` | `AND` |

### Skip booking rules

| Column | What it does | Example |
|--------|-------------|---------|
| `skipbookingrules` | Comma-separated IDs of booking rules to skip | `3,7` |
| `skipbookingrulesmode` | `0` = opt the option OUT of the listed rules; `1` = ONLY apply the listed rules | `0` |

---

## 13. Notifications and texts

| Column | What it does | Example |
|--------|-------------|---------|
| `notificationtext` | Custom notification text sent on booking confirmation | `Thank you for booking!` |
| `beforebookedtext` | Text shown to users before they book | `Please read the prerequisites.` |
| `aftercompletedtext` | Text shown after the option is completed | `Well done! Certificate attached.` |
| `beforecompletedtext` | Text shown before completion | `Please submit your assignment.` |

---

## 14. Advanced columns

| Column | What it does | Example |
|--------|-------------|---------|
| `addtocalendar` | Add the option to the Moodle calendar (`1` = yes) | `1` |
| `duration` | Duration of the option in seconds | `7200` |
| `credits` | Number of credits awarded | `3` |
| `removeafterminutes` | Remove the option from view X minutes after it starts | `60` |
| `enrolmentstatus` | Enrolment status value | `0` |
| `multiplebookings` | Allow booking multiple times (`1` = yes) | `1` |
| `allowtobookagainafter` | Seconds until re-booking is allowed (requires `multiplebookings=1`) | `86400` |
| `responsiblecontact` | Email of the responsible contact person (must be existing Moodle user) | `manager@example.com` |
| `returnurl` | URL the user is sent to after booking | `https://example.com/thanks` |
| `optiondateid_0` | Internal ID of the first existing date slot (for updating specific option dates) | `12` |
| `optiondateid_1` | Internal ID of the second existing date slot | `13` |
| `addastemplate` | Save this option as a template (`1` = yes) | `0` |
| `optiontype` | Option type ID | `1` |
| `repeatthisbooking` | Repeat booking option N times | `4` |
| `certificate` | Certificate ID to award on completion | `2` |
| `competency` | Competency ID(s) to link (comma-separated) | `5,8` |
| `slot_enabled` | Enable slot booking (`1` = yes) | `1` |
| `sch_allowinstallment` | Allow payment by instalment in shopping cart (`1` = yes) | `1` |

---

## 15. Date formats

All date and time columns (`coursestarttime`, `courseendtime`, `bookingopeningtime`, `bookingclosingtime`, `canceluntil`, `timebooked`) accept:

1. **ISO 8601 date string** *(recommended)*: `2026-04-15 09:00:00`
2. **Unix timestamp** *(seconds since 1970-01-01)*: `1744700400`
3. **Any format parseable by PHP's `strtotime()`**: `15 April 2026 09:00`, `2026-04-15T09:00:00`

### Custom format with `dateparseformat`

If your dates are in a different format (e.g. European `DD.MM.YYYY HH:MM`), add the column `dateparseformat` to your CSV and specify the PHP date format string:

| Your date looks like | `dateparseformat` value |
|----------------------|------------------------|
| `30.04.2026 09:00` | `d.m.Y H:i` |
| `04/30/2026 09:00 AM` | `m/d/Y h:i A` |
| `2026-04-30` | `Y-m-d` |

The same format applies to all date cells in that row.

---

## 16. Tips and common mistakes

| Problem | Solution |
|---------|---------|
| Option is created but has no date | Check that `coursestarttime` and `courseendtime` are both present and in a valid format |
| Import skips a row with no error message | Make sure `text` (or `name`) and `cmid` are filled |
| Teacher is not assigned | Verify the email in `teacheremail` matches an existing Moodle user account exactly |
| Price is ignored | Make sure `useprice` is set to `1` and the price category column name exactly matches the identifier in booking settings |
| Recurring dates not created | When using `dayofweektime`, `semesterid` is required and must be a valid semester ID |
| Special characters look wrong | Save your CSV as UTF-8 (in Excel: *Save As → CSV UTF-8 (comma-delimited)*) |
| Columns with commas break the file | Wrap values that contain commas in double quotes: `"Room 3, Building A"` |
| Updating an existing option overwrites my dates | Leave date columns empty on update rows if you only want to change other fields |

---

## 17. Example files

Ready-to-use example CSV files are located in the `examples/` subfolder:

| File | Contents |
|------|---------|
| [examples/import_minimal.csv](examples/import_minimal.csv) | Minimal import: title, identifier, and one date |
| [examples/import_with_dates_and_prices.csv](examples/import_with_dates_and_prices.csv) | Full example with dates, capacity, teacher, and prices |
| [examples/import_users_and_teachers.csv](examples/import_users_and_teachers.csv) | Assigning teachers and pre-booking users |

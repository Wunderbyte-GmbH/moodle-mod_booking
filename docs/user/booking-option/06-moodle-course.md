[Back to parent section](README.md)

# Linked Moodle Course

The **Moodle course** section (header: *Moodle course*) lets you connect a booking option to a Moodle course. When a user books the option, they are automatically enrolled in the linked course.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Course connection modes](#1-course-connection-modes)
2. [Connect to an existing course](#2-connect-to-an-existing-course)
3. [Create a new course on save](#3-create-a-new-course-on-save)
4. [Create a course from a template](#4-create-a-course-from-a-template)
5. [Enrolment status](#5-enrolment-status)
6. [Auto-duplicate course on option copy](#6-auto-duplicate-course-on-option-copy)
7. [Roles in the connected course](#7-roles-in-the-connected-course)

---

## 1. Course connection modes

The **Connected Moodle course** select field (`chooseorcreatecourse`) controls how the course link is established:

| Value | Label | Description |
|-------|-------|-------------|
| `0` | No Moodle course connection | The booking option is standalone. No course is linked and no enrolment happens. |
| `1` | Connected Moodle course | Link to an **existing** Moodle course chosen from a search box. |
| `2` | Create new Moodle course | A brand-new course is created automatically when the option is saved. |
| `3` | Create new course from template | A copy of a selected template course is created when the option is saved. |

---

## 2. Connect to an existing course

When mode `1` is selected, an autocomplete search field appears:

| Field | Description |
|-------|-------------|
| **Moodle course** (`courseid`) | Search for and select the course by name or short name. |

Once linked, every user who books this option is immediately enrolled in the selected course using the standard Moodle enrolment mechanism.

### CSV import

Use `enroltocourseshortname` (preferred) or `courseid` (internal numeric ID) in the CSV:

```
enroltocourseshortname
python-2026
```

See [CSV Import — Linked Moodle course](../CSV_IMPORT_USER_GUIDE.md#11-linked-moodle-course).

---

## 3. Create a new course on save

When mode `2` is selected, a new empty Moodle course is created the moment you save the booking option. The new course is placed in the same category as the current course and named after the booking option title.

---

## 4. Create a course from a template

When mode `3` is selected:

| Field | Description |
|-------|-------------|
| **Template course** (`coursetemplateid`) | Search for the Moodle course to use as a template. A full copy (backup + restore) of this course is created as an asynchronous background task. |
| **Copy with enrolled users** (`createnewmoodlecoursefromtemplatewithusers`) | When checked, the enrolled users of the template course are also copied into the new course. |

> **Note:** Course duplication runs as an async task. The new course may not be immediately available — a short delay after saving is normal.

---

## 5. Enrolment status

| Field | Description |
|-------|-------------|
| **Enrolment status** (`enrolmentstatus`) | Controls the enrolment status of users in the connected course. `0` = active (default), `1` = suspended. |

---

## 6. Auto-duplicate course on option copy

If the admin has enabled the **Duplicate Moodle courses** setting, copying a booking option also duplicates the linked Moodle course automatically. The new option is then linked to the new course copy.

---

## 7. Roles in the connected course

- **Teachers** assigned to the booking option can be given a role in the connected course automatically (configured globally by an admin).
- **Responsible contacts** can also be assigned a configurable role in the connected course.
- **Booked users** are enrolled with the standard student role (or a role configured in the booking instance settings).

---

## Related pages

- [Teachers and responsible contact](03-teachers-and-contact.md) — Assigning roles in the course
- [General settings](01-general.md) — Option title used when creating a new course
- [CSV Import](../CSV_IMPORT_USER_GUIDE.md#11-linked-moodle-course)

# Teachers and Responsible Contact

The booking option form has two separate sections for people associated with an option:

- **Teachers** — trainers, presenters, or instructors who deliver the option.
- **Responsible contact** — a contact person (e.g. coordinator or manager) who is reachable for organisational questions.

---

## Table of Contents

1. [Teachers](#1-teachers)
2. [Responsible contact](#2-responsible-contact)

---

## 1. Teachers

The **Teachers** section (header: *Booking option teachers*) lets you assign one or more Moodle users as teachers for this option.

### What teachers can do

- Teachers appear on the booking option detail page and in e-mails via the `{teachers}` placeholder.
- A teacher can be assigned their own **personal calendar entries** for each session date, so sessions appear in their Moodle calendar.
- If a teacher is removed, their calendar entries are deleted automatically.
- Depending on plugin configuration, teachers may receive notification e-mails when users book or cancel.

### How to add teachers

Use the **teacher search field** to find users by name or e-mail. You can assign multiple teachers to one option.

### Teachers and the connected Moodle course

If the option is linked to a Moodle course (see [Linked Moodle course](06-moodle-course.md)), teachers can optionally be given a role in that course automatically.

### Teachers in CSV import

When importing via CSV, use the `teacheremail` column. Multiple teachers are separated by a pipe `|`:

```
teacheremail
trainer@example.com
trainer-a@example.com|trainer-b@example.com
```

> By default, importing adds teachers to any already-assigned ones. Set `mergeparam` to `0` to **replace** all existing teachers instead.

See the [CSV Import Guide — Teachers](../CSV_IMPORT_USER_GUIDE.md#8-teachers) for full details.

---

## 2. Responsible contact

The **Responsible contact** section (header: *Responsible contact*) assigns one or more Moodle users as contact persons for this option.

### Difference from teachers

| | Teachers | Responsible contact |
|---|---------|---------------------|
| Shown on detail page | Yes | Configurable |
| Delivers the session | Yes | No |
| Calendar entries | Yes (per session) | No |
| Role in connected course | Optional | Optional (configurable) |
| E-mail placeholder | `{teachers}` | `{contact}` |

### Role in the connected course

An admin can configure a specific Moodle role (e.g. *Non-editing teacher* or a custom role) that is automatically assigned to the responsible contact in the connected Moodle course when the option is saved. This is configured at the plugin level under **Settings → Define role for responsible contact person**.

### How to add a responsible contact

Use the autocomplete search field to find users by name or e-mail. Multiple users can be selected.

---

## Related pages

- [Dates](02-dates.md) — Session dates that generate teacher calendar entries
- [Linked Moodle course](06-moodle-course.md) — Teacher and contact roles in the course
- [CSV Import](../CSV_IMPORT_USER_GUIDE.md#8-teachers) — Bulk-assigning teachers

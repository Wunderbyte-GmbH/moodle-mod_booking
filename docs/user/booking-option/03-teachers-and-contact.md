[Back to parent section](README.md)

# Teachers and Responsible Contact

The booking option form has two separate sections for people associated with an option:

- **Teachers** — trainers, presenters, or instructors who deliver the option.
- **Responsible contact** — a contact person (e.g. coordinator or manager) who is reachable for organisational questions.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Teachers](#1-teachers)
2. [Responsible contact](#2-responsible-contact)

---

## 1. Teachers

The **Teachers** section (header: *Booking option teachers*) lets you assign one or more Moodle users as teachers for this option.

### What teachers can do

- Teachers appear on the booking option detail page and in e-mails via the `{teachers}` placeholder. They are sorted by name, or shown in the [order you set](#order-of-teachers-pro) (PRO).
- A teacher can be assigned their own **personal calendar entries** for each session date, so sessions appear in their Moodle calendar.
- If a teacher is removed, their calendar entries are deleted automatically.
- Depending on plugin configuration, teachers may receive notification e-mails when users book or cancel.

### How to add teachers

Use the **teacher search field** to find users by name or e-mail. You can assign multiple teachers to one option.

### Order of teachers (PRO)

By default, the teachers of a booking option are shown sorted by name (last name, then first name). With **Booking PRO**, you can set the order yourself, for example to list the main trainer first.

**Where:** booking option form, section **Teachers**, field **Order of teachers** directly below **Assign teachers**.

**How it works**

1. Assign the teachers with **Assign teachers** and **save** the booking option.
2. Open the booking option form again. **Order of teachers** now lists the saved teachers.
3. Move a teacher with the arrow buttons (up / down) or drag the entry by its handle. A click on the handle opens a dialogue to choose the new position, which also works with the keyboard.
4. Save the booking option.

**Good to know**

- The field only puts teachers in an order. Teachers are added and removed with **Assign teachers**, not here.
- Only teachers that are **already saved** in the booking option are listed. As long as fewer than two teachers are saved (for example in a new booking option), the form shows a hint instead of the list. A teacher you have just selected in **Assign teachers** appears in the list after saving.
- A teacher that is added later is placed **at the end** of your order. Without an order of your own, new teachers are sorted in by name as before.
- To go back to the automatic sorting, put the teachers in the order by name. The booking option then has no order of its own any more.
- Duplicating a booking option and saving it as a template keep the order.
- Without Booking PRO the field is not shown and a submitted order is not saved. An order that was saved while Booking PRO was active stays in effect.

**Where the order is used**

The order applies everywhere the teachers of the booking option are displayed:

- the list of booking options of a booking activity (`view.php`) and the page of a single booking option (`optionview.php`),
- shortcodes of mod_booking such as `[courselist]`, and shortcodes of other Wunderbyte plugins that show the teachers of booking options (for example `[allekurseliste]` and `[allekursekarten]` of local_musi, the course shortcodes of local_urise),
- the placeholders `{teachers}` (all teachers) and `{teacher}` (the first teacher) in e-mails and texts, and `{bookingdetails}`,
- certificates, the download of the booking options and the Moodle app,
- the column for the full-text search by teacher (`teachernames`).

If no responsible contact is set and the site setting to show the first teacher as contact is active, the **first teacher of your order** is shown as contact.

> **Not affected:** lists of people with a sorting of their own keep it, for example the sign-in sheet (sorted by the field chosen for the download), the teachers of a single date in the teaching journal and the pages that list all teachers of the site.

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

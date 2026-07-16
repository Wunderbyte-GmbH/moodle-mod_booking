[Back to parent section](../../../README.md)

# Booking Option — Settings Reference

This section documents every setting available in the **booking option form** — the form you see when you create or edit a single bookable event, course, or time slot inside a Booking activity.

---

## What is a booking option?

A **booking option** is one concrete item that users can book. A single Booking activity can contain many booking options. Examples:

- A single workshop session on a specific date
- A recurring weekly class over a semester
- A product or service that requires a sign-up

Each booking option has its own dates, capacity, price, availability rules, and customisable texts.

---

## How to open the booking option form

1. Open the Booking activity.
2. Click **Add new booking option** (or click the edit icon next to an existing option).
3. The form opens — it may show all sections at once or only those enabled via **Form configuration** (see note below).

> **Form configuration:** Admins and managers can control which form sections are visible through the **Option form configuration** setting in the booking activity settings. This means you may not see every section described here in your installation.

---

## Documentation pages

| Page | What it covers |
|------|---------------|
| [01 — General settings](01-general.md) | Title, description, location, capacity, visibility |
| [02 — Dates](02-dates.md) | Session dates, recurring dates, calendar integration |
| [03 — Teachers & responsible contact](03-teachers-and-contact.md) | Assigning teachers and a responsible contact person |
| [04 — Availability conditions](04-availability.md) | Who can book: cohort, course enrolment, booking window, custom conditions |
| [05 — Price](05-price.md) | Pricing, price categories, price formula |
| [06 — Linked Moodle course](06-moodle-course.md) | Auto-enrol participants into a Moodle course |
| [07 — Advanced options](07-advanced.md) | Cancel settings, notification texts, poll URL, attachments, and more |
| [08 — Demand confirmation](08-confirmation.md) | Manual confirmation workflow and waiting list confirmation |

---

## Related documentation

- [CSV Import User Guide](../CSV_IMPORT_USER_GUIDE.md) — How to create or update booking options in bulk via CSV


## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

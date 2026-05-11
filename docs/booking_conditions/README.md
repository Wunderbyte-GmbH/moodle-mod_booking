# Booking Conditions

Booking conditions control **who can book** a booking option and **when**. Each booking option can have one or more conditions configured. If a condition is not met, the user sees an alert and the "Book it" button is blocked (or replaced by a warning).

---

## Where to find booking conditions

Conditions are configured per booking option:

1. Open the **Booking** activity.
2. Click on a booking option to open its detail view, then click **Edit** (or create a new option).
3. Scroll to the **Availability / Booking conditions** section of the option form.
4. Each condition has its own toggle checkbox. Enable it to reveal its settings.

![Booking conditions section in the option edit form](pix/conditions_overview_placeholder.png)

> **Note:** Most conditions require a **PRO licence** from Wunderbyte. Conditions that require PRO are marked with 🔒 below.

---

## Available conditions

| Condition | File | PRO required | Description |
|-----------|------|:---:|-------------|
| [Booking time](booking_time.md) | `booking_time.php` | — | Restricts booking to a specific time window (opening / closing date). |
| [Enrolled in course](enrolled_in_course.md) | `enrolledincourse.php` | 🔒 | Only users enrolled in one or more selected Moodle courses can book. |
| [Enrolled in cohort](enrolled_in_cohort.md) | `enrolledincohorts.php` | 🔒 | Only members of one or more selected cohorts can book. |
| [Has competency](has_competency.md) | `hascompetency.php` | 🔒 | Only users who have (been rated for) one or more Moodle competencies can book. |
| [Previously booked](previously_booked.md) | `previouslybooked.php` | 🔒 | Requires the user to have already booked (and optionally completed) another booking option. |
| [Select users](select_users.md) | `selectusers.php` | 🔒 | Restricts booking to an explicit list of selected users. |
| [User profile field (standard)](user_profile_field.md) | `userprofilefield_1_default.php` | 🔒 | Checks a standard Moodle user profile field against a value using a configurable operator. |
| [User profile field (custom)](user_profile_field_custom.md) | `userprofilefield_2_custom.php` | 🔒 | Same as above but for custom user profile fields. |
| [No overlapping bookings](no_overlapping.md) | `nooverlapping.php` | — | Blocks or warns when a user tries to book an option whose dates overlap with another booking they already have. |
| [Allowed to book in instance](allowed_to_book_in_instance.md) | `allowedtobookininstance.php` | 🔒 | Restricts booking to users who hold a specific Moodle capability in the booking instance. |
| [Custom form](custom_form.md) | `customform.php` | 🔒 | Forces the user to fill in a custom form (checkboxes, text fields, dropdowns, …) before booking is finalised. |

---

## How conditions work together

- Multiple conditions can be active at the same time. All active conditions must be satisfied for a user to be able to book.
- Most conditions expose an **Override** option: a second (privileged) condition can override the first one. This lets you say, for example, "users must be enrolled in course A, **unless** they are also enrolled in course B".
- Users with the Moodle capability `mod/booking:overrideboconditions` bypass all blocking conditions.

---

## Condition types

Conditions fall into two technical categories:

| Type | Stored how | Example |
|------|-----------|---------|
| **Hardcoded** | In the booking option's own DB columns | Booking time (uses `bookingopeningtime`, `bookingclosingtime`) |
| **JSON-based** | In the `availability` JSON column of `booking_options` | All PRO conditions |

This distinction is only relevant if you work with the database directly or the CSV import. For day-to-day use, all conditions are edited in the same booking option form.

---

## See also

- [CSV Import User Guide](../CSV_IMPORT_USER_GUIDE.md) — availability restrictions can also be set via CSV import (columns `boavenrolledincourse`, `boavenrolledincohorts`, …).

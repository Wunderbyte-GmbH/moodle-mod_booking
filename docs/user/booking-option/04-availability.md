[Back to parent section](README.md)

# Availability Conditions

> **Primary page** for: temporal booking restrictions (opening time, closing time, booking window), access restrictions. For automated emails and reminders, see [Booking Rules](../booking_rules/README.md).

The **Availability conditions** section (header: *Availability conditions*) controls **who** can book the option and **when** bookings are accepted. It is one of the most powerful sections in the booking option form.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Booking window — opening and closing time](#1-booking-window--opening-and-closing-time)
2. [User-based restrictions](#2-user-based-restrictions)
3. [Course enrolment restriction](#3-course-enrolment-restriction)
4. [Cohort restriction](#4-cohort-restriction)
5. [Previously booked restriction](#5-previously-booked-restriction)
6. [Custom form condition](#6-custom-form-condition)
7. [Booking rules and skip rules](#7-booking-rules-and-skip-rules)
8. [How conditions combine](#8-how-conditions-combine)

---

## 1. Booking window — opening and closing time

These two fields define the time range during which users are allowed to book:

| Field | Description |
|-------|-------------|
| **Bookable from** (`bookingopeningtime`) | The date and time from which users can start booking. Before this time, the option shows a "not yet bookable" message. |
| **Bookable until** (`bookingclosingtime`) | The deadline for booking. After this time, users can no longer book the option. |

Both fields are optional. If not set, the option is bookable at any time (subject to capacity and other conditions).

> **Tip:** Use these in combination with session dates: set `bookingopeningtime` several weeks before the session and `bookingclosingtime` one day before it starts.

---

## 2. User-based restrictions

The availability system supports a rich set of conditions that can be combined:

| Condition | Description |
|-----------|-------------|
| **Enrolled in course** | User must be enrolled in one or more specific Moodle courses. |
| **Member of cohort** | User must be a member of one or more system cohorts. |
| **Previously booked** | User must have already booked (or completed) another specific booking option. |
| **Select users** | Manually select specific users who are allowed to book (whitelist). |
| **User profile field** | User's profile field must match a given value (e.g. department, job role). |
| **Custom form** | User must fill in and submit a custom form before booking is confirmed. |
| **Max. bookings per user** | Limit how many options a user can book in this booking activity. |
| **Booking credits** | User must have sufficient credits in their profile. |

Each condition can be **enabled or disabled** individually via its checkbox, and multiple conditions can be active at the same time.

---

## 3. Course enrolment restriction

When the **Enrolled in course** condition is enabled:

| Field | Description |
|-------|-------------|
| **Course(s)** | One or more Moodle courses the user must be enrolled in. |
| **Operator** | `AND` — must be enrolled in all listed courses. `OR` — must be enrolled in at least one. |

In **CSV import**, use the `boavenrolledincourse` column with course short names (comma-separated) and `boavenrolledincourseoperator` for the logical operator.

---

## 4. Cohort restriction

When the **Cohort** condition is enabled:

| Field | Description |
|-------|-------------|
| **Cohort(s)** | One or more system cohorts the user must belong to. |
| **Operator** | `AND` — must be in all cohorts. `OR` — must be in at least one. |

In **CSV import**, use the `boavenrolledincohorts` column with cohort ID numbers (comma-separated) and `boavenrolledincohortsoperator`.

---

## 5. Previously booked restriction

When the **Previously booked** condition is enabled:

| Field | Description |
|-------|-------------|
| **Required booking option** | The user must have already booked (or completed) this specific other booking option. |

This is useful for prerequisites: e.g. a user must have attended "Introduction" before they can book "Advanced".

---

## 6. Custom form condition

When the **Custom form** condition is enabled, a form with custom fields is shown to the user before or during the booking process. The user must submit it successfully to complete their booking.

This can be used to:
- Collect additional registration information
- Let users confirm they have read terms and conditions
- Present a questionnaire as part of sign-up

---

## 7. Booking rules and skip rules

**Booking rules** are global or instance-level rules configured separately (outside the option form). They can block or allow bookings based on complex logic.

Within a booking option you can override which rules apply:

| Field | Description |
|-------|-------------|
| **Skip booking rules** (`skipbookingrules`) | Comma-separated IDs of booking rules to skip or exclusively apply. |
| **Skip booking rules mode** (`skipbookingrulesmode`) | `0` — opt this option *out* of the listed rules. `1` — apply *only* the listed rules to this option. |

> In CSV import these are the `skipbookingrules` and `skipbookingrulesmode` columns.

---

## 8. How conditions combine

- All **enabled** conditions must be satisfied simultaneously (logical AND between condition types).
- Within a single condition type (e.g. multiple cohorts), the `AND` / `OR` operator controls the logic.
- When a condition is not met, the user sees an explanation message instead of the booking button.

---

## Related pages

- [General settings](01-general.md) — Capacity and waiting list
- [Advanced options](07-advanced.md) — Cancellation settings
- [Demand confirmation](08-confirmation.md) — Manual approval workflow
- [CSV Import — Availability restrictions](../CSV_IMPORT_USER_GUIDE.md#12-availability-restrictions)

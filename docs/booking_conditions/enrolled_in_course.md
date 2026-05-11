# Enrolled in Course

**Class:** `mod_booking\bo_availability\conditions\enrolledincourse`  
**PRO required:** Yes 🔒

---

## What it does

The **Enrolled in course** condition restricts a booking option so that only users who are enrolled in one or more specified Moodle courses can book it.

You select which courses are required and whether the user must be enrolled in **all** of them (AND) or **at least one** of them (OR).

---

## How to configure it

Open the booking option edit form and scroll to the **Availability / Booking conditions** section.

> This condition is only available with an active Wunderbyte PRO licence. Without it, a static notice is shown instead of the settings.

### Step 1 — Enable the condition

Check **Restrict to enrolled users in course(s)** to activate the condition and reveal its settings.

### Step 2 — Select courses

An autocomplete search field lists all courses in the Moodle site. Select one or more courses that the user must be enrolled in.

![Enrolled in course condition in the option form](pix/enrolled_in_course_form_placeholder.png)

### Step 3 — Choose the operator

Select how multiple courses are combined:

| Operator | Meaning |
|----------|---------|
| **AND** *(default)* | The user must be enrolled in **all** selected courses. |
| **OR** | The user must be enrolled in **at least one** of the selected courses. |

### Step 4 — SQL filter (optional)

Check **Hide option if condition is not met** to also filter the booking option out of the list view for users who do not fulfil the course enrolment requirement. Without this checkbox, the option is still visible but the "Book it" button is replaced by an alert.

> The SQL filter requires a database that supports JSON functions (PostgreSQL, or MySQL/MariaDB 10.6+). On older databases, the checkbox has no effect.

### Step 5 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for specific users. Choose the override operator (OR / AND) and select which other conditions may override this one.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User is enrolled (condition met) | Normal "Book it" button |
| User is not enrolled (condition not met) | Alert: "You must be enrolled in \<course(s)\> to book this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of course enrolment.

---

## CSV import

This condition can also be set via CSV import using the columns `boavenrolledincourse` and `boavenrolledincourseoperator`.  
See the [CSV Import User Guide](../CSV_IMPORT_USER_GUIDE.md#12-availability-restrictions) for details.

---

## Back to overview

[← All booking conditions](README.md)

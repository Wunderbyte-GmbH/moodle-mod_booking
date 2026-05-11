# Enrolled in Cohort

**Class:** `mod_booking\bo_availability\conditions\enrolledincohorts`  
**PRO required:** Yes 🔒

---

## What it does

The **Enrolled in cohort** condition restricts a booking option so that only users who are members of one or more specified Moodle cohorts (site-wide groups) can book it.

You select which cohorts are required and whether the user must be in **all** of them (AND) or **at least one** of them (OR).

---

## How to configure it

Open the booking option edit form and scroll to the **Availability / Booking conditions** section.

> This condition is only available with an active Wunderbyte PRO licence. Without it, a static notice is shown instead of the settings.

### Step 1 — Enable the condition

Check **Restrict to cohort members** to activate the condition and reveal its settings.

### Step 2 — Select cohorts

An autocomplete search field lists all cohorts defined in the Moodle site (up to 500 are shown; a warning is displayed if there are more). Select one or more cohorts that the user must belong to.

![Enrolled in cohort condition in the option form](pix/enrolled_in_cohort_form_placeholder.png)

### Step 3 — Choose the operator

Select how multiple cohorts are combined:

| Operator | Meaning |
|----------|---------|
| **OR** *(default)* | The user must be a member of **at least one** of the selected cohorts. |
| **AND** | The user must be a member of **all** selected cohorts. |

### Step 4 — SQL filter (optional)

Check **Hide option if condition is not met** to also filter the booking option out of the list view for users who are not in any of the required cohorts. Without this checkbox, the option is still visible but the "Book it" button is replaced by an alert.

> The SQL filter requires a database that supports JSON functions (PostgreSQL, or MySQL/MariaDB 10.6+). On older databases, the checkbox has no effect.

### Step 5 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for specific users. Choose the override operator (OR / AND) and select which other conditions may override this one.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User is in the required cohort(s) | Normal "Book it" button |
| User is not in any required cohort | Alert: "You must be a member of \<cohort(s)\> to book this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of cohort membership.

---

## CSV import

This condition can also be set via CSV import using the columns `boavenrolledincohorts` and `boavenrolledincohortsoperator`.  
See the [CSV Import User Guide](../CSV_IMPORT_USER_GUIDE.md#12-availability-restrictions) for details.

---

## Back to overview

[← All booking conditions](README.md)

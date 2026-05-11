# User Profile Field (Custom)

**Class:** `mod_booking\bo_availability\conditions\userprofilefield_2_custom`  
**PRO required:** Yes 🔒

---

## What it does

The **User profile field (custom)** condition works exactly like the [standard profile field condition](user_profile_field.md) but targets **custom** user profile fields — fields created by an administrator under *Site administration → Users → User profile fields*.

This allows you to restrict booking based on any custom data stored about users, for example: membership level, organisation unit, certification status, dietary preference, or any other attribute you have added to your Moodle user profiles.

---

## How to configure it

Open the booking option edit form and scroll to the **Availability / Booking conditions** section.

> This condition is only available with an active Wunderbyte PRO licence.

### Step 1 — Enable the condition

Check **Restrict by custom user profile field** to activate the condition and reveal its settings.

### Step 2 — Select the custom profile field

A dropdown lists all custom profile fields defined on the site (e.g. `profile_field_membership`, `profile_field_department`). Select the field you want to check.

### Step 3 — Select the operator

The same operators as the standard field condition are available:

| Operator | Symbol | Meaning |
|----------|--------|---------|
| Equals | `=` | Field value matches exactly |
| Not equals | `!=` | Field value does not match |
| Lower than | `<` | Field value is lower (numeric comparison) |
| Greater than | `>` | Field value is greater (numeric comparison) |
| Contains | `~` | Field value contains the given string |
| Does not contain | `!~` | Field value does not contain the given string |
| In list | `[]` | Field value is one of a comma-separated list of values |
| Not in list | `[!]` | Field value is not in the comma-separated list |
| Contains value from list | `[~]` | Field value contains at least one value from the list |
| Does not contain any from list | `[!~]` | Field value does not contain any value from the list |
| Is empty | `()` | Field has no value |
| Is not empty | `(!)` | Field has a value |

### Step 4 — Enter the comparison value

Enter the value to compare against. For list operators, separate values with commas: `Gold,Silver,Platinum`.

![User profile field (custom) condition in the option form](pix/user_profile_field_custom_form_placeholder.png)

### Step 5 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for users who would otherwise be blocked.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| Custom profile field matches condition | Normal "Book it" button |
| Custom profile field does not match | Alert: "You do not meet the required profile condition to book this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of their custom profile field value.

---

## Notes

- If the user is not logged in, or is a guest, the condition evaluates to **false** (blocking).
- Custom profile fields must be created in *Site administration → Users → User profile fields* before they appear in the dropdown.
- The comparison is **case-sensitive** for most operators.
- If the field is not filled out for a user, it evaluates to false for all operators except `()` (is empty).

---

## See also

- [User profile field (standard)](user_profile_field.md) — for built-in Moodle user fields (city, country, department, etc.).

---

## Back to overview

[← All booking conditions](README.md)

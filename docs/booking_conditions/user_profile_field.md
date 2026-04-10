# User Profile Field (Standard)

**Class:** `mod_booking\bo_availability\conditions\userprofilefield_1_default`  
**PRO required:** Yes 🔒

---

## What it does

The **User profile field (standard)** condition restricts booking based on the value of one of the **standard** (built-in) Moodle user profile fields — such as `city`, `country`, `department`, `institution`, `idnumber`, `phone1`, and others.

It compares the field value in the user's profile against a value you specify, using a configurable comparison operator. Booking is only allowed when the comparison evaluates to true.

For **custom** user profile fields, see [User profile field (custom)](user_profile_field_custom.md) instead.

---

## How to configure it

Open the booking option edit form and scroll to the **Availability / Booking conditions** section.

> This condition is only available with an active Wunderbyte PRO licence.

### Step 1 — Enable the condition

Check **Restrict by standard user profile field** to activate the condition and reveal its settings.

### Step 2 — Select the profile field

A dropdown lists all standard columns of the Moodle `user` database table. Select the field you want to check.

### Step 3 — Select the operator

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
| Contains value from list | `[~]` | Field value contains at least one value from the comma-separated list |
| Does not contain any value from list | `[!~]` | Field value does not contain any value from the comma-separated list |
| Is empty | `()` | Field has no value |
| Is not empty | `(!)` | Field has a value |

### Step 4 — Enter the comparison value

Enter the value to compare against. For list operators (`[]`, `[!]`, `[~]`, `[!~]`), separate values with a comma: `Vienna,Berlin,Zurich`.

![User profile field (standard) condition in the option form](pix/user_profile_field_form_placeholder.png)

### Step 5 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for users who would otherwise be blocked.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| Profile field matches condition | Normal "Book it" button |
| Profile field does not match | Alert: "You do not meet the required profile condition to book this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of their profile field value.

---

## Notes

- If the user is not logged in, or is a guest, the condition evaluates to **false** (blocking).
- The comparison is **case-sensitive** for most operators.
- The field must exist and have a value in the user's profile; an unset field evaluates to false for operators other than `()` (is empty).

---

## See also

- [User profile field (custom)](user_profile_field_custom.md) — for custom user profile fields created under *Site administration → Users → User profile fields*.

---

## Back to overview

[← All booking conditions](README.md)

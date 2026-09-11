[Back to parent section](README.md)

# Allowed to Book in Instance

**Class:** `mod_booking\bo_availability\conditions\allowedtobookininstance`  
**PRO required:** Yes 🔒

---

## What it does

The **Allowed to book in instance** condition restricts a booking option so that only users who have a specific Moodle **capability** granted within the booking activity (instance) context can book it.

In practice this means: a user must have been given a role (or a direct capability override) inside the booking activity that grants the `mod/booking:choose` capability (or another capability configured for this instance). Users without the capability see a blocking alert.

An optional sub-setting allows users to book even without the capability, effectively making the capability check a soft restriction that can be bypassed by the "Allow booking without capability" flag.

---

## How to configure it

For non-technical users, use this click path:

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open the options list: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click **Edit** on the target option (or create one first).
4. In the option form, scroll to **Availability / Booking conditions**.
5. Enable this condition and save the option.

> This condition is only available with an active Wunderbyte PRO licence.

### Step 1 — Enable the condition

Check **Restrict to users allowed to book in this instance** to activate the condition.

### Step 2 — Allow booking without capability (optional)

An additional checkbox **Allow booking even without the required capability** appears. When checked (it is checked by default), users who do not hold the required capability are still allowed to book. This effectively makes the condition inactive for regular users while keeping it as a framework for overrides.

Uncheck this option to enforce the capability restriction strictly.

![Allowed to book in instance condition in the option form](pix/allowed_to_book_in_instance_form_placeholder.png)

### Step 3 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one. Choose the override operator (OR / AND) and select which other conditions may override this one.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User has the required capability (or "allow anyway" is checked) | Normal "Book it" button |
| User does not have the capability and "allow anyway" is unchecked | Alert: "You are not allowed to book in this booking instance." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` bypass this condition entirely.

---

## Use cases

- **Role-gated workshops**: only users who have been granted the "participant" role inside the booking activity may book.
- **Staged availability**: combine with override conditions so that, for example, PRO members can book before the general public.

---

## Related capability

The relevant Moodle capability is `mod/booking:choose`. It can be assigned via roles at the activity level (*Booking → Participants → Enrolled users → Roles*) or via role overrides.

---

## Back to overview

[← All booking conditions](README.md)

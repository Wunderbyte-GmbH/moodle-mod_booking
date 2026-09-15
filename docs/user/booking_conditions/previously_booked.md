[Back to parent section](README.md)

# Previously Booked

**Class:** `mod_booking\bo_availability\conditions\previouslybooked`  
**PRO required:** Yes 🔒

---

## What it does

The **Previously booked** condition restricts a booking option so that only users who have already booked (and optionally _completed_) another specific booking option can book this one.

This is useful for sequential or prerequisite booking flows — for example, a user must have booked and attended "Module 1" before they are allowed to book "Module 2".

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

Check **Restrict: Previously booked** to activate the condition and reveal its settings.

### Step 2 — Select the prerequisite booking option

An autocomplete search field lets you search across all booking options in the site. Select the option that the user must have previously booked.

![Previously booked condition in the option form](pix/previously_booked_form_placeholder.png)

### Step 3 — Require completion (optional)

Check **Require completion of prerequisite option** to make the condition stricter: the user must not only have booked the prerequisite option, but also have it marked as _completed_ (i.e., the activity completion for that booking option must be achieved).

| Setting | Behaviour |
|---------|-----------|
| Checkbox unchecked | User must have an active booking in the prerequisite option. |
| Checkbox checked | User must have an active booking **and** the booking must be marked completed. |

### Step 4 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for specific users.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User has previously booked the required option (and completed it if required) | Normal "Book it" button |
| User has not booked (or not completed) the required option | Alert: "You must first book \<option name\> before booking this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of whether they have completed the prerequisite.

---

## Notes

- If the referenced prerequisite booking option is deleted, the condition evaluates to **false** (blocking) for all users.
- Completion tracking for booking options must be enabled in the booking activity settings for the "require completion" feature to work.

---

## Back to overview

[← All booking conditions](README.md)

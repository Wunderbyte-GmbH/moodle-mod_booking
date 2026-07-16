[Back to parent section](README.md)

# Select Users

**Class:** `mod_booking\bo_availability\conditions\selectusers`  
**PRO required:** Yes 🔒

---

## What it does

The **Select users** condition restricts a booking option to an explicit, hand-picked list of users. Only the users named in the list are allowed to book; everyone else sees a blocking alert.

This is the most direct form of restriction: instead of a rule-based check (enrolment, cohort, profile field, …) you literally choose which individuals may book.

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

Check **Restrict to selected users** to activate the condition and reveal its settings.

### Step 2 — Select the users

An autocomplete search field (powered by AJAX) lets you search users by name or username. Select one or more users who should be allowed to book this option.

![Select users condition in the option form](pix/select_users_form_placeholder.png)

> **Tip:** When you start typing in the search field, matching users are shown with their name and email address. You can select multiple users.

### Step 3 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for additional users.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User is in the allowed list | Normal "Book it" button |
| User is not in the allowed list | Alert: "You are not allowed to book this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of whether they are in the selected list.

---

## Use cases

- **Invitation-only events** — create the booking option, then add only the invited people to the list.
- **Waitlist bypass** — allow specific users to skip other conditions and book directly.
- **Testing** — allow only testers to book an option before it is opened to everyone.

---

## Back to overview

[← All booking conditions](README.md)

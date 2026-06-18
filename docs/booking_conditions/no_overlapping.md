[Back to parent section](README.md)

# No Overlapping Bookings

**Class:** `mod_booking\bo_availability\conditions\nooverlapping`  
**PRO required:** No

---

## What it does

The **No overlapping bookings** condition checks whether a user already has another booking whose date(s) overlap with the option they are trying to book. If an overlap is detected, the system can either:

- **Block** the booking entirely (hard block), or
- **Warn** the user with a dismissible warning that lets them continue and book anyway.

---

## How to configure it

For non-technical users, use this click path:

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open the options list: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click **Edit** on the target option (or create one first).
4. In the option form, scroll to **Availability / Booking conditions**.
5. Enable this condition and save the option.

### Step 1 — Enable the condition

Check **Do not allow overlapping bookings** to activate the condition.

### Step 2 — Choose the handling mode

A dropdown appears with two options:

| Mode | Effect |
|------|--------|
| **Blocking** | The user cannot book if an overlap exists. The "Book it" button is replaced by a red alert. |
| **Warning** | The user sees an orange warning about the overlap but can still proceed and book (the warning includes a button to continue). |

![No overlapping bookings condition in the option form](pix/no_overlapping_form_placeholder.png)

---

## Effect on the user

### Blocking mode

| Situation | What the user sees |
|-----------|-------------------|
| No overlap | Normal "Book it" button |
| Overlap detected | Red alert: "You already have a booking that overlaps with this option." — no way to proceed. |

### Warning mode

| Situation | What the user sees |
|-----------|-------------------|
| No overlap | Normal "Book it" button |
| Overlap detected | Orange warning: "You already have a booking that overlaps with this option." — with a "Continue anyway" / play button. |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` are not subject to the blocking mode. In warning mode, all users can continue regardless.

---

## Notes

- The overlap check compares the **option dates** (sessions/slots) of the current option against all other active bookings of the user across the entire Moodle site.
- A user who is already booked _into the same option_ (e.g. on the waiting list) is not blocked by this condition.
- The condition does not affect the option's visibility in list views — it only affects the booking step.

---

## Back to overview

[← All booking conditions](README.md)

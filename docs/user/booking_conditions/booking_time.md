[Back to parent section](README.md)

# Booking Time

**Class:** `mod_booking\bo_availability\conditions\booking_time`  
**PRO required:** No

---

## What it does

The **Booking time** condition restricts the window during which users are allowed to book a booking option. You can set:

- a **booking opening time** — booking is only possible _from_ this point in time
- a **booking closing time** — booking is only possible _until_ this point in time

Both dates are optional. If neither is set, the option is bookable at any time (as far as this condition is concerned).

When a user tries to book outside the configured window, they see a warning alert showing the exact opening or closing date instead of the "Book it" button.

---

## How to configure it

For non-technical users, use this click path:

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open the options list: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click **Edit** on the target option (or create one first).
4. In the option form, scroll to **Availability / Booking conditions**.
5. Enable this condition and save the option.

### Step 1 — Enable the opening restriction (optional)

Check **Restrict booking opening time**. A date/time picker appears.  
Set the date and time from which booking should be possible.

### Step 2 — Enable the closing restriction (optional)

Check **Restrict booking closing time**. A date/time picker appears.  
Set the date and time after which booking is no longer possible.

![Booking time condition in the option form](pix/booking_time_form_placeholder.png)

### Step 3 — SQL filter (optional)

An additional checkbox controls whether options whose booking window has already passed are hidden from the list view:

- **Hide options whose booking closing time has passed** — when enabled, options that are past their `bookingclosingtime` are no longer shown to users in the booking list.

This setting is influenced by the global admin setting **SQL filter for booking time** (`sqlfilterbookingtimeonlypast`), which can be found under *Site administration → Plugins → Activity modules → Booking → Advanced settings*.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| Before the opening time | Alert: "Booking opens on \<date\>" |
| Within the booking window | Normal "Book it" button |
| After the closing time | Alert: "Booking closed on \<date\>" |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of the configured time window.

---

## CSV import

The booking time window can also be set via CSV import using the columns `bookingopeningtime` and `bookingclosingtime`.  
See the [CSV Import User Guide](../CSV_IMPORT_USER_GUIDE.md#6-capacity-and-booking-window) for details.

---

## Back to overview

[← All booking conditions](README.md)

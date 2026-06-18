[Back to parent section](../README.md)

# Sub-bookings — Overview

**Sub-bookings** are optional add-ons or customisation steps that can be attached to a booking option. When a user books the parent option, they are presented with the sub-booking as an additional step in the booking flow.

Sub-bookings allow you to:

- Offer **add-on items** (e.g., lunch, materials) that participants can select or purchase alongside the main booking
- Allow participants to bring **additional persons** (e.g., a guest, a family member)
- Let participants choose a **specific time slot** within a booking option that has multiple available slots

Sub-bookings are a **PRO feature** of mod_booking.

## Click-by-click setup

1. Open the booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option management: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click Edit on the target option.
4. In the option form, open Sub-bookings.
5. Click Add sub-booking and choose the type.
6. Configure fields and save.
7. Test booking as participant to verify the extra step appears.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [How sub-bookings work](#1-how-sub-bookings-work)
2. [Where to configure sub-bookings](#2-where-to-configure-sub-bookings)
3. [Sub-booking types](#3-sub-booking-types)
4. [Blocking behaviour](#4-blocking-behaviour)
5. [Capacity and availability](#5-capacity-and-availability)

---

## 1. How sub-bookings work

When a user books a booking option that has sub-bookings configured:

1. The normal booking flow proceeds to the booking confirmation page.
2. Before confirming, the system presents the user with the sub-booking step (e.g., "Do you want to add lunch? Select your time slot").
3. The user makes their choice and the sub-booking answer is stored alongside the main booking answer.
4. If a sub-booking has `block = 1`, the parent booking option is not bookable until the user has completed the sub-booking.

Sub-booking answers are stored in `booking_subbooking_answers` and linked to the parent `booking_answers` record.

---

## 2. Where to configure sub-bookings

Sub-bookings are configured per booking option:

1. Open the Booking activity and open a booking option for editing.
2. Scroll to the **Sub-bookings** section of the option form.
3. Click **Add sub-booking** and select a type.
4. Configure the sub-booking settings and save.

> You can add multiple sub-bookings to a single booking option. They are presented to the user in the order they are configured.

---

## 3. Sub-booking types

| Type | Class | Description |
|------|-------|-------------|
| [Additional item](subbooking_additionalitem.md) | `subbooking_additionalitem` | A selectable add-on (e.g., lunch, equipment). Can be linked to a custom form field and can carry a price. |
| [Additional person](subbooking_additionalperson.md) | `subbooking_additionalperson` | Allows the booker to register one or more additional persons alongside themselves. |
| [Time slot](subbooking_timeslot.md) | `subbooking_timeslot` | Offers a set of bookable time slots within the option. Participants choose their preferred slot. |

---

## 4. Blocking behaviour

Each sub-booking has a **block** flag. When `block = 1`:

- The parent booking option is not accessible to the participant until they have completed the sub-booking.
- The sub-booking is treated as a prerequisite step, not an optional extra.

When `block = 0` (the default), the sub-booking is presented as an optional add-on and the parent booking can proceed regardless.

---

## 5. Capacity and availability

- **Additional item / Additional person:** The `available` field on the sub-booking sets how many units are available. `0` means unlimited.
- **Time slot:** Each slot has its own available places, defined by the slot's configuration.
- Sub-bookings participate in the standard availability check pipeline. A fully-booked sub-booking (when it is required/blocking) will prevent the parent booking from being completed.

---

## See also

- [Sub-booking type: Additional item](subbooking_additionalitem.md)
- [Sub-booking type: Additional person](subbooking_additionalperson.md)
- [Sub-booking type: Time slot](subbooking_timeslot.md)
- [Booking option form](../booking-option/README.md)

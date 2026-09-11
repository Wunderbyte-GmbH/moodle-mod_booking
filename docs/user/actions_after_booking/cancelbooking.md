[Back to parent section](README.md)

# Action After Booking: Cancel Booking

**Class:** `mod_booking\bo_actions\action_types\cancelbooking`
**PRO required:** Yes 🔒

---

## What it does

The **Cancel booking** action immediately **cancels** the user's booking answer for the parent booking option. The booking is removed as if the user had cancelled manually.

This is useful for:

- Creating a "conditional booking" flow where a booking is automatically cancelled if a prerequisite is not met within a defined time.
- Implementing a "book-and-then-cancel" pattern in combination with another action that books a different option.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Action name** (`boactionname`) | An internal label for this action (shown in the action list in the option form). |

No additional configuration is required. The action uses the booking option ID and user ID from the booking event context.

---

## Behaviour

When this action is executed:

1. The system calls `booking_option::user_delete_response($userid)` on the parent option.
2. The user's booking answer is deleted (the booking is cancelled).
3. The action returns **status 1** (abort): no further actions in the list are executed after this one.

---

## Use case: Auto-cancel if terms not accepted

This action is typically combined with the [Custom form](../booking_conditions/custom_form.md) availability condition. If a user books but does not submit the required form within a time window, an admin can manually trigger a cancellation, or it can be paired with a booking rule that calls this action.

---

## See also

- [Actions after booking overview](README.md)
- [Book other options action](bookotheroptions.md)
- [Custom form condition](../booking_conditions/custom_form.md)


## Quick setup path

1. Open option edit page: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
2. Edit target option and open Booking actions section.
3. Add or edit the action type documented here.
4. Save and test with one booking event.

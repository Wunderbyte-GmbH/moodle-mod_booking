[Back to parent section](README.md)

# Action After Booking: Set User Profile Field

**Class:** `mod_booking\bo_actions\action_types\userprofilefield`
**PRO required:** Yes 🔒

---

## What it does

The **Set user profile field** action modifies a custom Moodle user profile field for the participant when a booking is confirmed. You can use this to:

- Assign a user to a specific group or category by setting a profile field (e.g., `enrolled_in = training_2026`)
- Increment or decrement a numeric profile field (e.g., track credits or attendance counts)
- Add days to a date-type profile field (e.g., extend a subscription end date)

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Action name** (`boactionname`) | An internal label for this action (shown in the action list). |
| **User profile field** (`boactionselectuserprofilefield`) | The custom Moodle user profile field to modify. Only custom profile fields are listed (not standard Moodle fields). |
| **Operator** (`boactionuserprofileoperator`) | How to modify the field value — see the table below. |
| **Value** (`boactionuserprofilefieldvalue`) | The value to use with the operator. |

### Operators

| Operator | Effect |
|----------|--------|
| `set` | Sets the profile field to the exact value specified. Replaces any existing value. |
| `add` | Adds the specified numeric value to the current field value. Useful for incrementing counters. |
| `subtract` | Subtracts the specified numeric value from the current field value. |
| `adddate` | Adds the specified number of days to the current date-type field value. Useful for extending subscription periods. |

---

## How it works

When the booking is confirmed:

1. The system loads the user's full profile (including custom fields).
2. It locates the configured custom field by its shortname.
3. It applies the operator (set, add, subtract, or adddate) with the configured value.
4. The updated profile is saved.

---

## Example: Mark a user as enrolled in a training programme

**Scenario:** When a user books the "2026 Leadership Programme", set their `training_programme` custom profile field to `leadership_2026`.

| Setting | Value |
|---------|-------|
| Action name | Set training programme field |
| User profile field | `training_programme` |
| Operator | `set` |
| Value | `leadership_2026` |

After booking, the user's profile field `training_programme` is set to `leadership_2026`. This can then be used in availability conditions (e.g., `userprofilefield_2_custom`) to restrict other booking options to users in this programme.

---

## Example: Extend a subscription end date by 365 days

**Scenario:** When a user purchases an annual membership option, extend their `membership_expiry` date field by 365 days.

| Setting | Value |
|---------|-------|
| Action name | Extend membership |
| User profile field | `membership_expiry` |
| Operator | `adddate` |
| Value | `365` |

---

## See also

- [Actions after booking overview](README.md)
- [Execute REST script action](executerestscript.md)
- [User profile field condition](../booking_conditions/user_profile_field.md)
- [User profile field (custom) condition](../booking_conditions/user_profile_field_custom.md)


## Quick setup path

1. Open option edit page: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
2. Edit target option and open Booking actions section.
3. Add or edit the action type documented here.
4. Save and test with one booking event.

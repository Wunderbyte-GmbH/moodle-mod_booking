[Back to parent section](README.md)

# Action After Booking: Book Other Options

**Class:** `mod_booking\bo_actions\action_types\bookotheroptions`
**PRO required:** Yes 🔒

---

## What it does

The **Book other options** action **automatically books** one or more additional booking options for the user when the parent option is booked. This lets you create bundled booking flows where confirming one option also enrols the participant in related options.

Examples:

- Booking a multi-day conference automatically books the participant into each individual day's session.
- Enrolling in a course package automatically registers the participant in all component modules.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Action name** (`boactionname`) | An internal label for this action (shown in the action list). |
| **Booking options to book** (`bookotheroptionsselect`) | A multi-select list of other booking options (within the same booking activity). All selected options will be booked for the user. |
| **Force booking** (`bookotheroptionsforce`) | When enabled, the system ignores availability conditions on the target options and books the user regardless. When disabled, standard conditions apply. |

---

## Behaviour

When this action is executed:

1. For each selected option, the system calls `booking_option::user_submit_response()` on the user's behalf with `MOD_BOOKING_VERIFIED` status.
2. The booking is recorded as if the user had booked each option individually.
3. The action returns **status 1** (abort): no further actions in the list are executed after this one.

---

## Important notes

- The target options must be in the **same booking activity** (same `cmid`) as the parent option.
- If **Force booking** is disabled and a target option has blocking availability conditions (e.g., cohort restriction), the booking will be blocked by those conditions.
- If **Force booking** is enabled, the system bypasses all availability conditions on the target options.

---

## Use case: Conference package with automatic session booking

**Scenario:** A 3-day conference has a main "Conference Package" booking option, plus three individual day sessions. When a participant books the package, they should automatically be booked into all three day sessions.

| Setting | Value |
|---------|-------|
| Action name | Book conference days |
| Options to book | Day 1 session, Day 2 session, Day 3 session |
| Force booking | ✓ (bypass capacity checks on individual sessions) |

---

## See also

- [Actions after booking overview](README.md)
- [Cancel booking action](cancelbooking.md)
- [Execute REST script action](executerestscript.md)


## Quick setup path

1. Open option edit page: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
2. Edit target option and open Booking actions section.
3. Add or edit the action type documented here.
4. Save and test with one booking event.

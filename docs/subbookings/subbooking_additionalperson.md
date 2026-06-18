[Back to parent section](README.md)

# Sub-booking Type: Additional Person

**Class:** `mod_booking\subbookings\sb_types\subbooking_additionalperson`
**PRO required:** Yes 🔒

---

## What it does

The **Additional person** sub-booking lets the primary booker register **one or more additional persons** alongside themselves. Examples:

- A parent registering a child for a course
- An employee bringing a guest to a company event
- A participant registering a colleague who does not have a Moodle account

Each additional person is captured with their name and any other details you configure. They are stored as part of the booking answer but do not require a Moodle account.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Name** | The display name of the sub-booking (shown to participants, e.g., "Additional guest"). |
| **Block parent option** | When enabled, the parent booking option cannot be confirmed until this sub-booking step is completed. |
| **Available** | The maximum number of additional persons allowed per primary booking. `0` means unlimited. Default is `1`. |
| **Description** | A rich-text description shown at the sub-booking step (e.g., instructions for entering the guest's details). |

---

## How it works

1. When the participant reaches the booking confirmation step, a form is shown asking for the additional person's details.
2. The number of times the form can be filled in is limited by the **Available** count.
3. The entered person data is stored as a JSON payload in `booking_subbooking_answers`, linked to the primary booking answer.
4. The additional persons appear in the participant list and can be exported in the booking responses export.

---

## Example: Bring a guest to a corporate event

**Scenario:** Employees can bring one guest to the company's annual training day.

| Setting | Value |
|---------|-------|
| Name | Bring a guest |
| Block parent option | ✗ (the primary employee can still attend even if they don't bring a guest) |
| Available | `1` (only one guest per booking) |
| Description | You may bring one guest. Please enter their first name, last name, and email address below. |

---

## See also

- [Sub-bookings overview](README.md)
- [Additional item sub-booking](subbooking_additionalitem.md)
- [Time slot sub-booking](subbooking_timeslot.md)


## Quick setup path

1. Open option edit page: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
2. Edit target option and open Sub-bookings section.
3. Add or configure the sub-booking type from this page.
4. Save and test booking flow as participant.

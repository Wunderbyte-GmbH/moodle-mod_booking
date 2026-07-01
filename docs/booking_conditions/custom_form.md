[Back to parent section](README.md)

# Custom Form

**Class:** `mod_booking\bo_availability\conditions\customform`
**PRO required:** Yes 🔒

---

## What it does

The **Custom form** condition injects a custom form that the user must complete **before** the booking is finalised. The form is shown as an extra step in the booking process (a "pre-booking page"). Booking is only confirmed once the user has submitted the form.

The form can contain any combination of:

- **Checkboxes** (e.g. "I accept the terms and conditions")
- **Short text fields** (e.g. dietary requirements, T-shirt size)
- **Dropdowns / select menus** (e.g. choose a session time, select a group)
- **Display text** (static information shown to the user, not an input)
- **URL fields** (validated URL input)
- **Email fields** (validated email input)
- **Delete personal info checkbox** (GDPR: lets the user request deletion of their booking data)
- **Enrol users action** (lets a manager enrol additional users into a course as part of the booking flow)

Up to **50 form elements** can be added to a single custom form.

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

Check **Enable custom booking form** to activate the condition and reveal the form builder.

### Step 2 — Build the form

For each element you want to add:

1. In the **Form element type** dropdown, select the type of element (checkbox, text field, dropdown, …).
2. In the **Label** field, enter the label shown to the user for that element.
3. For **select** (dropdown) elements, enter the available options in the **Value** field, one option per line.
4. For **static** (display text) elements, enter the text to show in the **Value** field. HTML is allowed.
5. For **URL** and **email** fields, leave the Value field empty — these elements validate the format automatically.

Repeat for each element. Elements with no type selected (type = 0) are ignored.

![Custom form condition in the option form](pix/custom_form_placeholder.png)

#### Special element: Enrol users action

When **Enrol users action** is selected, a checkbox **Enrol to waiting list** appears. If checked, the users added through this element are placed on the waiting list rather than directly booked.

> **Note:** The "Enrol users action" element is available from Moodle 4.3 (version 2023100900) and later.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User has not yet submitted the form | A custom form page is shown before the booking confirmation step. |
| User has already submitted the form (re-visiting the option) | Form is not shown again; the booking step proceeds normally. |

The custom form appears as an intermediate page in the booking wizard, **before** the "Book it" button or price step.

### Optional prefill via optionview link

If a logged-in user opens the booking option detail page via `optionview.php`, custom form fields can be prefilled from the URL.

Use query parameters with the prefix `prefill_...`.

- The suffix may match the internal field name, for example `prefill_customform_url_1=...`
- Or it may match the field label in normalized form, for example `prefill_website_url=...`

Example:

```text
/mod/booking/optionview.php?optionid=945&cmid=4&prefill_website_url=https%3A%2F%2Fmywebsite.example&prefill_company=Acme
```

Supported field types for prefilling:

- Short text
- URL
- Email
- Select
- Checkbox
- Delete personal info checkbox
- Enrol users action

Static text elements are ignored.

Invalid values are ignored silently. For example, an unknown select option or an invalid URL is not written to the cache.

---

## Where the form data is stored

Before booking is completed, submitted or prefilled custom form data is stored in the existing booking customform cache, keyed by user and booking option.

Once the booking is completed, the cached values are copied into the booking answer JSON and become visible in the booking option's participant list and related reports.

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can bypass the custom form step.

---

## Use cases

- **Acceptance of terms**: a single mandatory checkbox.
- **Participant information**: collect dietary requirements, accommodation preferences, emergency contacts.
- **Group selection**: let participants self-select into sub-groups via a dropdown.
- **GDPR data deletion**: include the "Delete personal info" checkbox to let users opt out of data retention.
- **Manager-initiated group enrolment**: use "Enrol users action" so a manager can book multiple colleagues at once.

---

## Back to overview

[← All booking conditions](README.md)

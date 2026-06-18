[Back to parent section](../README.md)

# Placeholders — Reference

Placeholders are tokens in the form `{tokenname}` that mod_booking replaces with live values when it renders texts. They are available in:

- **Booking rule email templates** (subject and body of `send_mail` and `send_mail_interval` actions)
- **Booking confirmation and notification texts** configured in booking option settings (Advanced section)
- **iCal event descriptions** attached to rule emails
- **Poll URL fields** on booking options (only placeholders that have `for_pollurl() = true`)

Each placeholder maps to a PHP class under `classes/placeholders/placeholders/`. The token name is the class name surrounded by braces, e.g., class `firstname` → `{firstname}`.

---

## Quick setup path

1. Open booking rules editor: [/mod/booking/edit_rules.php?contextid=1](/mod/booking/edit_rules.php?contextid=1).
2. Edit the mail action template text.
3. Insert placeholders from this page.
4. Send a test message and verify placeholder output.

---

## Table of Contents

1. [User fields](#1-user-fields)
2. [Booking option fields](#2-booking-option-fields)
3. [Dates and times](#3-dates-and-times)
4. [Links and URLs](#4-links-and-urls)
5. [Teachers and responsible contact](#5-teachers-and-responsible-contact)
6. [Pricing and shopping cart](#6-pricing-and-shopping-cart)
7. [Booking status and capacity](#7-booking-status-and-capacity)
8. [Certificates and QR codes](#8-certificates-and-qr-codes)
9. [Calendar URLs](#9-calendar-urls)
10. [Miscellaneous](#10-miscellaneous)
11. [Custom fields and custom form data](#11-custom-fields-and-custom-form-data)
12. [Using placeholders in practice](#12-using-placeholders-in-practice)

---

## 1. User fields

These placeholders resolve to properties of the **recipient** user (the person who booked).

| Placeholder | Replaced with |
|-------------|--------------|
| `{firstname}` | Recipient's first name |
| `{lastname}` | Recipient's last name |
| `{email}` | Recipient's email address |
| `{username}` | Recipient's Moodle username |
| `{department}` | Recipient's department (Moodle profile field) |
| `{institution}` | Recipient's institution (Moodle profile field) |
| `{profilepicture}` | Recipient's profile picture (rendered as an `<img>` tag) |

---

## 2. Booking option fields

These placeholders resolve to properties of the **booking option** itself.

| Placeholder | Replaced with |
|-------------|--------------|
| `{title}` | The booking option title (same as the `text` field) |
| `{bookingoptionname}` | Alias for the booking option title |
| `{description}` | The booking option description (HTML) |
| `{location}` | The location of the booking option |
| `{address}` | The address of the booking option |
| `{institution}` | The institution set on the booking option |
| `{duration}` | Duration of the option (formatted, e.g., "2 hours") |
| `{optionid}` | Numeric ID of the booking option |
| `{instancename}` | Name of the booking activity instance this option belongs to |
| `{semester}` | Semester name associated with the booking option (if configured) |
| `{type}` | Booking option type (custom field value used for categorisation) |
| `{eventtype}` | Event type label |
| `{eventdescription}` | Description text from the event context |
| `{selflearningcourse}` | Information about the self-learning course subscription period (if applicable) |

---

## 3. Dates and times

| Placeholder | Replaced with |
|-------------|--------------|
| `{startdate}` | Start date of the booking option (formatted date only) |
| `{starttime}` | Start time of the booking option (formatted time only) |
| `{enddate}` | End date of the booking option (formatted date only) |
| `{endtime}` | End time of the booking option (formatted time only) |
| `{dates}` | All session dates as a formatted list (multi-session options show all dates) |
| `{datesandentities}` | All session dates with their associated entities/venues (requires `local_entities`) |
| `{bookingdetails}` | Full formatted block: all dates, location, teachers, and option details in one output |
| `{optiondatefromevent}` | The specific session date that triggered the event (for `rule_daysbefore` rules using `optiondatestarttime`) |
| `{pollstartdate}` | Formatted start date used in poll URLs |

---

## 4. Links and URLs

| Placeholder | Replaced with |
|-------------|--------------|
| `{bookinglink}` | URL to the booking activity list page |
| `{bookingoptiondetaillink}` | URL to the detail page of this booking option |
| `{bookingconfirmationlink}` | URL the recipient can click to confirm their booking (used in confirmation workflow) |
| `{courselink}` | URL to the linked Moodle course (only if a course is linked) |
| `{enrollink}` | URL to directly enrol the user into the linked course |
| `{gotobookingoption}` | A "Go to booking option" button/link (HTML) |
| `{bookingreportlink}` | URL to the booking report page for this option (staff only) |
| `{pollurl}` | Poll URL configured on the booking option (participant-facing) |
| `{pollurlteachers}` | Poll URL configured on the booking option (teacher-facing) |

---

## 5. Teachers and responsible contact

| Placeholder | Replaced with |
|-------------|--------------|
| `{teacher}` | Full name of the first teacher assigned to the option |
| `{teachers}` | Comma-separated list of all teachers assigned to the option |
| `{firstnamerelated}` | First name of the responsible contact person |
| `{lastnamerelated}` | Last name of the responsible contact person |
| `{emailrelated}` | Email address of the responsible contact person |

> **Tip:** "Responsible contact" is a separate person configured in the **Teachers & responsible contact** section of the booking option form. It does not have to be the same as the teacher.

---

## 6. Pricing and shopping cart

These placeholders require the `local_shopping_cart` plugin.

| Placeholder | Replaced with |
|-------------|--------------|
| `{price}` | The price of the booking option for the current user's price category |
| `{installmentprice}` | The amount of a single instalment payment |
| `{numberofinstallment}` | The instalment number (e.g., "2" for the second payment) |
| `{duedate}` | Due date of an instalment payment (formatted date) |
| `{shoppingcartplaceholder}` | A shopping-cart-specific block (used in shopping cart email templates) |

---

## 7. Booking status and capacity

| Placeholder | Replaced with |
|-------------|--------------|
| `{status}` | Current booking answer status of the recipient (e.g., "Booked", "Waiting list") |
| `{bookedplaces}` | Number of confirmed bookings on this option |
| `{numberparticipants}` | Total number of participants (confirmed + waiting list) |
| `{numberwaitinglist}` | Number of users on the waiting list |
| `{changes}` | A human-readable summary of what changed on the booking option (used with `bookingoption_updated` events) |
| `{participant}` | Full name of the participant (same person as the recipient in most contexts) |
| `{restresponse}` | Response body returned by a REST script (used with `executerestscript` actions after booking) |

---

## 8. Certificates and QR codes

These placeholders are only meaningful when a certificate plugin is integrated.

| Placeholder | Replaced with |
|-------------|--------------|
| `{certificateurl}` | URL to the participant's certificate for this booking option |
| `{qrid}` | QR code identifier for the participant |
| `{qrenrollink}` | URL encoded in a QR code that auto-enrols the participant |
| `{qrusername}` | Username embedded in the QR enrolment link |

---

## 9. Calendar URLs

| Placeholder | Replaced with |
|-------------|--------------|
| `{coursecalendarurl}` | URL to the Moodle course calendar, filtered to this course |
| `{usercalendarurl}` | URL to the current user's personal Moodle calendar |

---

## 10. Miscellaneous

| Placeholder | Replaced with |
|-------------|--------------|
| `{courseid}` | Numeric Moodle course ID of the linked course |
| `{coursename}` | Full name of the linked Moodle course |
| `{journal}` | Journal/log data associated with the booking answer (advanced use) |

---

## 11. Custom fields and custom form data

| Placeholder | Replaced with |
|-------------|--------------|
| `{customfields}` | Rendered block of all booking custom fields for this option |
| `{customform}` | Data submitted via the custom-form booking condition |
| `{profile_field_<shortname>}` | Value of a custom user profile field. Replace `<shortname>` with the actual field shortname, e.g., `{profile_field_department}`. |

> **Note:** Custom booking option fields and custom user profile fields are listed dynamically inside the rule editor. Click **Show placeholders** above the subject field to see the full list for your specific site.

---

## 12. Using placeholders in practice

### In booking rule email templates

Placeholders can be used in both the **Subject** and **Message** body of `send_mail` and `send_mail_interval` rule actions. Example:

```
Subject: Your booking "{title}" starts on {startdate}

Body:
Hi {firstname},

your booking for "{title}" starts on {startdate} at {starttime} in {location}.

Details: {bookingdetails}

Log in to view your booking: {bookinglink}

Best regards,
The Booking Team
```

### In confirmation texts (option form)

The same tokens work in the **Confirmation text** field of the booking option's **Advanced** section.

### In poll URLs

Only placeholders where `for_pollurl()` returns `true` (such as `{firstname}`, `{lastname}`, `{email}`) are substituted inside poll URL fields. All others are left unchanged.

### Cross-references

- [Booking rules — Actions: Placeholders](../booking_rules/actions.md#6-placeholders-available-in-email-templates) — Short reference table used within rule emails
- [Booking rules — Overview](../booking_rules/README.md)

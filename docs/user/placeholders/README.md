[Back to parent section](../../../README.md)

# Placeholders — Reference

Placeholders are tokens in the form `{tokenname}` that mod_booking replaces with live values when it renders texts. They are available in:

- **Booking rule email templates** (subject and body of `send_mail` and `send_mail_interval` actions)
- **Booking confirmation and notification texts** configured in booking option settings (Advanced section)
- **iCal event descriptions** attached to rule emails — placeholders are resolved there too, and `{mlang}` multi-language filters are supported, so calendar entries arrive in the recipient's language. HTML and links in iCal descriptions are cleaned up so they also display correctly in Outlook.
- **Poll URL fields** on booking options (only placeholders that have `for_pollurl() = true`, plus custom booking option fields)
- **Sign-in sheet HTML template** (setting `signinsheethtml`, outside of the `[[users]]` section) — only placeholders that have `for_signinsheet() = true`, written as `[[tokenname]]` instead of `{tokenname}`

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
5. [Teachers and related user](#5-teachers-and-related-user)
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
| `{datescompact}` | All session dates in compact form: one line per day (separated by `<br>`), several dates on the same day combined into one line, e.g. "20 August 2026, 10:00-12:00, 13:00-15:00 and 17:00-18:00" |
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

## 5. Teachers and related user

| Placeholder | Replaced with |
|-------------|--------------|
| `{teacher}` | Full name of the first teacher assigned to the option |
| `{teachers}` | Comma-separated list of all teachers assigned to the option |
| `{firstnamerelated}` | First name of the related user of the triggering event (e.g., the user a booking was made for) |
| `{lastnamerelated}` | Last name of the related user of the triggering event |
| `{emailrelated}` | Email address of the related user of the triggering event |

> **Tip:** The `…related` placeholders resolve the **related user** of the event that triggered a booking rule — typically the user a booking was made for, which can differ from the recipient of the email (e.g., when a cashier, manager or supervisor books for someone else). Because they need the triggering event, they only produce a value in booking rule templates. Custom user profile fields of the related user are available as well — see [Custom fields](#11-custom-fields-and-custom-form-data).

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

Custom fields are referenced directly by their **shortname** — there is no prefix. Two kinds of fields are supported by the same token syntax:

| Placeholder | Replaced with |
|-------------|--------------|
| `{<shortname>}` | Value of the **custom booking option field** with this shortname (configured under *Booking custom fields*). If no booking option field with this shortname exists, the value of the recipient's **custom user profile field** with this shortname is used instead. Example: a field with shortname `sportsclub` → `{sportsclub}`. |
| `{<shortname>-related}` | Value of the **custom user profile field** of the **related user** of the event that triggered the booking rule (e.g., the user a booking was made for), instead of the recipient. Append `-related` to the profile field shortname, e.g., `{sportsclub-related}`. |
| `{customform}` | Data submitted via the custom-form booking condition |

### How `{<shortname>}` is resolved

1. **Built-in placeholders win**: if the shortname collides with one of the placeholder names on this page (e.g., a profile field with shortname `firstname`), the built-in placeholder is used. Choose distinct shortnames for fields you want to reference.
2. **Custom booking option fields** are checked next — they resolve in any context that knows the booking option (rule emails, confirmation texts, etc.). Multi-value fields (e.g., multi-selects) are rendered as a comma-separated list.
3. **Custom user profile fields** of the recipient are used last.

### The `-related` suffix

`{<shortname>-related}` reads the custom **user profile** field from the **related user** of the triggering event — the same person the `{firstnamerelated}`, `{lastnamerelated}` and `{emailrelated}` placeholders refer to (see [Teachers and related user](#5-teachers-and-related-user)). This is useful in booking rules when the recipient of the email is not the person the booking is about, e.g.:

- a supervisor or responsible contact receives a mail about a booking and the mail should show data of the **booked user**,
- a cashier or manager books **for** someone else and the confirmation goes to a third party.

Because the related user comes from the triggering event, `{<shortname>-related}` only works in **booking rule** templates (`send_mail` / `send_mail_interval`) for rules that react to an event carrying a related user. In contexts without such an event, the related user cannot be determined and the recipient's own field value is used as a fallback.

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

### Conditional text: `{#name}...{/name}`

Text that only makes sense together with a placeholder can be wrapped in a **section**: `{#name}` opens it, `{/name}` closes it, and `{name}` is the placeholder the section depends on. When the placeholder renders a value, the two markers are removed and the text in between stays; when the placeholder is **empty**, the whole section - markers, text and the placeholder itself - is removed.

```
{#location}Venue: {location}{/location}
{#dates}
Dates:
{dates}
{/dates}
```

With a location set, the first line becomes `Venue: Vienna`; without one, nothing of it remains - no dangling "Venue:" label. The same applies to the dates block, e.g. for self-learning courses, where `{dates}`, `{datescompact}`, `{startdate}`, `{starttime}`, `{enddate}` and `{endtime}` are always empty (see [Dates and times](#3-dates-and-times)).

Sections work wherever placeholders are rendered - rule emails (subject and body), confirmation texts, option descriptions - and:

- may span several lines and HTML paragraphs (`<p>{#location}</p><p>Venue: {location}</p><p>{/location}</p>` is removed as a whole; an empty `<p></p>` can remain, which is invisible in the mail),
- may be nested: `{#title}{title} {#location}in {location}{/location}{/title}` keeps the outer section and drops only the inner one when the location is empty,
- may be used several times with the same name - every `{#name}...{/name}` block in the text is handled.

**Limitations**

- Whether a section is kept or removed is decided by the placeholder `{name}` itself, so `{name}` has to occur somewhere in the text (usually inside the section). A section without it, e.g. `{#location}Venue{/location}`, is left untouched - including the markers.
- Sections must refer to an existing placeholder. `{#foo}...{/foo}` for an unknown name stays in the text as it is.
- A missing closing marker disables the removal: `{#location}Venue: {location}` renders as `{#location}Venue: ` when the location is empty.
- "Empty" means empty in the PHP sense: a value of `0` counts as empty as well, so a section depending on a numeric placeholder disappears when the number is zero.
- Only the placeholder named in the markers is checked. Other placeholders inside the section are rendered normally, even if they are empty.

### In confirmation texts (option form)

The same tokens work in the **Confirmation text** field of the booking option's **Advanced** section.

### In poll URLs

Only placeholders where `for_pollurl()` returns `true` (such as `{firstname}`, `{lastname}`, `{email}`) are substituted inside poll URL fields. Custom booking option fields are substituted as well, referenced by their shortname (`{shortname}`). All other placeholders are left unchanged (they end up URL encoded, e.g. `%7Bdates%7D`).

### In sign-in sheet templates

All `[[...]]` placeholders of the sign-in sheet template are case-insensitive (`[[FullName]]`, `[[EXTRANAME]]`). Outside of the `[[users]]` section, the HTML template of the sign-in sheet (setting `signinsheethtml`) accepts the placeholders where `for_signinsheet()` returns `true` (values of the option, instance and course) — written with double square brackets instead of braces, e.g. `[[bookingoptionname]]`, `[[startdate]]`, `[[numberparticipants]]` or a custom booking option field `[[shortname]]`. Custom user profile fields (`[[shortname]]`) are available inside `[[users]]` only, where they are rendered per booked user. The template's own placeholders (`[[location]]`, `[[dayofweektime]]`, `[[teachers]]`, `[[dates]]`, `[[logourl]]`, `[[tablename]]`) keep their sign-in sheet specific values; other user related placeholders such as `[[firstname]]` and event related ones are not available outside of `[[users]]` and stay unresolved.

### Cross-references

- [Booking rules — Actions: Placeholders](../booking_rules/actions.md#6-placeholders-available-in-email-templates) — Short reference table used within rule emails
- [Booking rules — Overview](../booking_rules/README.md)

[Back to parent section](README.md)

# General Settings

The **General** section is always the first section in the booking option form. It covers the identity of the option (title, description, visibility) and its basic capacity settings.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Title and prefix](#1-title-and-prefix)
2. [Description](#2-description)
3. [Location and address](#3-location-and-address)
4. [Institution](#4-institution)
5. [Internal annotation](#5-internal-annotation)
6. [Identifier](#6-identifier)
7. [Visibility](#7-visibility)
8. [Header image](#8-header-image)
9. [Capacity: participants and waiting list](#9-capacity-participants-and-waiting-list)
10. [Disable booking by participants](#10-disable-booking-by-participants)

---

## 1. Title and prefix

| Field | Description |
|-------|-------------|
| **Booking option name** (`text`) | The main title of the option. This is required and shown everywhere the option appears. Maximum 255 characters. |
| **Title prefix** (`titleprefix`) | A short prefix displayed before the title (e.g. a course number or year tag like `2026-01`). Optional. |

> **Tip:** If you enter a prefix, the option will be displayed as *prefix — title* in list views.

---

## 2. Description

| Field | Description |
|-------|-------------|
| **Description** (`description`) | A rich-text (HTML) description of the booking option. Shown on the detail page and optionally in e-mails via the `{description}` placeholder. |

Admins can limit the maximum description length in the booking plugin settings (`descriptionmaxlength`).

---

## 3. Location and address

| Field | Description |
|-------|-------------|
| **Location** (`location`) | The name of the venue or room. You can type a new value or pick from previously used locations. If the [local_entities](https://github.com/Wunderbyte-GmbH/moodle-local_entities) plugin is installed, this field is replaced by the entity selector. |
| **Address** (`address`) | The full address of the location. Free text. |

> **Note:** When `local_entities` is installed, both the location and address fields are hidden and replaced by an entity/room picker that links to a managed entity record.

---

## 4. Institution

| Field | Description |
|-------|-------------|
| **Institution** (`institution`) | The organising institution or department. Free text. Shown in reports and can be used as an e-mail placeholder `{institution}`. |

---

## 5. Internal annotation

| Field | Description |
|-------|-------------|
| **Internal annotation** (`annotation`) | Notes that are only visible to managers and teachers — never shown to participants. Useful for internal instructions or reminders. |

---

## 6. Identifier

| Field | Description |
|-------|-------------|
| **Identifier** (`identifier`) | A unique human-readable ID you assign to this option (e.g. `PYTHON-2026-01`). Used in CSV import/export to reference options without relying on the internal numeric ID. Must be unique across all options. |

---

## 7. Visibility

| Field | Description |
|-------|-------------|
| **Invisible** (`invisible`) | When checked, the option is hidden from regular participants and students. It remains visible to managers and teachers. Useful for options that are not yet ready to be published. |

![Screenshot placeholder — Invisible checkbox](pix/general-invisible.png)

---

## 8. Header image

| Field | Description |
|-------|-------------|
| **Header image** (`bookingoptionimage`) | Upload an image that is shown as a banner at the top of the booking option detail page. Only one image is accepted. |

---

## 9. Capacity: participants and waiting list

| Field | Description |
|-------|-------------|
| **Max. number of participants** (`maxanswers`) | The maximum number of confirmed bookings. Set to `0` for unlimited. When this limit is reached, new bookings automatically go to the waiting list (if configured). |
| **Min. number of participants** (`minanswers`) | The minimum number of bookings required for the option to take place. Informational — no automatic cancellation is triggered. |
| **Max. number of waiting list places** (`maxoverbooking`) | How many users can be placed on the waiting list beyond the participant maximum. Set to `0` to disable the waiting list. |

> **Example:** `maxanswers = 20`, `maxoverbooking = 5` means 20 confirmed places + 5 waiting list places = 25 total registrations possible.

---

## 10. Disable booking by participants

| Field | Description |
|-------|-------------|
| **Disable booking of users — hide Book now button** (`disablebookingusers`) | Hides the booking button from participants. Only managers or teachers can then book users into this option manually. Useful for invitation-only events. |

---

## Related pages

- [Dates](02-dates.md) — When the option takes place
- [Availability conditions](04-availability.md) — Restrict who can book
- [Price](05-price.md) — Add a price to the option

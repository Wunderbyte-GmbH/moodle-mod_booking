[Back to parent section](../README.md)

# Booking Extensions — Overview

**Booking extensions** are subplugins of type `bookingextension` that extend mod_booking with additional functionality. They follow Moodle's standard subplugin architecture and are installed as separate plugins under `mod/booking/bookingextension/<pluginname>/`.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [What booking extensions can do](#1-what-booking-extensions-can-do)
2. [How to find and install booking extensions](#2-how-to-find-and-install-booking-extensions)
3. [Managing installed extensions](#3-managing-installed-extensions)
4. [For developers: building a booking extension](#4-for-developers-building-a-booking-extension)

---

## 1. What booking extensions can do

A booking extension can:

- **Add new fields to the booking option form** — additional configuration fields that appear in the option editor.
- **Add admin settings** — extension-specific settings pages under the mod_booking settings tree.
- **Add actions to the booking option list column** — extra action buttons or links in the options table.
- **Register new Moodle events** — events that can be used as triggers in [booking rules](../booking_rules/README.md).
- **Implement custom confirmation workflows** — override the standard booking confirmation flow (via `confirmbooking_interface`).
- **Add data to the booking option view page** — extra content shown on the option detail page.

---

## 2. How to find and install booking extensions

### From Wunderbyte

Wunderbyte provides official booking extensions as part of their PRO offering. Contact [info@wunderbyte.at](mailto:info@wunderbyte.at) or visit [wunderbyte.at](https://www.wunderbyte.at) for available extensions.

### Manual installation

1. Download the extension ZIP.
2. Install via *Site administration → Plugins → Install plugins*, or by extracting the ZIP into `<moodleroot>/mod/booking/bookingextension/<pluginname>/`.
3. Visit *Site administration → Notifications* to run the Moodle upgrade and complete installation.

---

## 3. Managing installed extensions

Installed extensions are listed under:

*Site administration → Plugins → Activity modules → Booking → (extension settings)*

Each extension that implements `load_settings()` adds its own settings page or tab to the mod_booking settings tree.

To uninstall an extension:

*Site administration → Plugins → Plugins overview → Booking extensions → \[extension name\] → Uninstall*

---

## 4. For developers: building a booking extension

For full developer documentation, see [Booking extensions developer API](developer-api.md).

---

## See also

- [Booking extensions developer API](developer-api.md)
- [Developer guides](../developer-guides/BOOKING_EXTENSIONS_API.md)
- [Booking rules](../booking_rules/README.md) — Custom events from extensions can be used as rule triggers

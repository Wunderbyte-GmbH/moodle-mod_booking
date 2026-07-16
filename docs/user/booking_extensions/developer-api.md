[Back to parent section](README.md)

# Booking Extensions — Developer API

This page describes how to build a `bookingextension_*` subplugin for mod_booking.

---

## Quick setup path

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option administration: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open the feature-specific page from this document and apply the settings.
4. Save and verify with one test booking.

---

## Table of Contents

1. [Plugin type and directory structure](#1-plugin-type-and-directory-structure)
2. [Required files](#2-required-files)
3. [Implementing `bookingextension_interface`](#3-implementing-bookingextension_interface)
4. [Optional: implementing `confirmbooking_interface`](#4-optional-implementing-confirmbooking_interface)
5. [Registering custom events for booking rules](#5-registering-custom-events-for-booking-rules)
6. [Adding option fields](#6-adding-option-fields)
7. [Adding admin settings](#7-adding-admin-settings)

---

## 1. Plugin type and directory structure

Booking extensions are Moodle subplugins of type `bookingextension`. They are installed under:

```
<moodleroot>/mod/booking/bookingextension/<pluginname>/
```

The full Moodle component name is `bookingextension_<pluginname>`.

---

## 2. Required files

At minimum, a booking extension needs these files:

```
bookingextension/<pluginname>/
├── version.php          # Plugin version metadata
├── lang/
│   └── en/
│       └── bookingextension_<pluginname>.php   # Language strings
└── classes/
    └── <pluginname>.php  # Main plugin class implementing bookingextension_interface
```

**`version.php` example:**

```php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'bookingextension_myplugin';
$plugin->version   = 2026010100;
$plugin->requires  = 2023042400; // Minimum Moodle version
$plugin->release   = '1.0.0';
$plugin->maturity  = MATURITY_STABLE;
```

---

## 3. Implementing `bookingextension_interface`

The main plugin class must implement `mod_booking\plugininfo\bookingextension_interface`.

**Namespace:** `bookingextension_<pluginname>`
**Class file:** `classes/<pluginname>.php`

```php
namespace bookingextension_myplugin;

use mod_booking\plugininfo\bookingextension_interface;

class myplugin implements bookingextension_interface {

    public function get_plugin_name(): string {
        return 'myplugin';
    }

    public function contains_option_fields(): bool {
        return false; // Set to true if you add fields to the booking option form
    }

    public function get_option_fields_info_array(): array {
        return []; // Return field definitions if contains_option_fields() is true
    }

    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
        // Add your plugin settings to $adminroot here, if needed
    }

    public static function load_data_for_settings_singleton(int $optionid): object {
        return new \stdClass(); // Return an object with any data needed for the option view
    }

    public static function set_template_data_for_optionview(object $settings): array {
        return []; // Return key/value/label/description arrays for display on the option view page
    }

    public static function add_options_to_col_actions(object $settings, $context): string {
        return ''; // Return HTML for extra action buttons in the booking options table
    }

    public static function get_allowedruleeventkeys(): array {
        return []; // Return event class names that this extension fires
    }
}
```

### Interface methods summary

| Method | Purpose |
|--------|---------|
| `get_plugin_name()` | Returns the plugin shortname (used internally) |
| `contains_option_fields()` | Returns `true` if the extension adds fields to the booking option form |
| `get_option_fields_info_array()` | Returns field definitions for option form fields (see section 6) |
| `load_settings()` | Adds admin settings pages to the mod_booking settings tree |
| `load_data_for_settings_singleton()` | Returns option-specific data for the singleton service cache |
| `set_template_data_for_optionview()` | Returns data for rendering on the booking option detail page |
| `add_options_to_col_actions()` | Returns HTML for extra action buttons in the options list |
| `get_allowedruleeventkeys()` | Returns event class names that this extension fires and that can be used in booking rules |

---

## 4. Optional: implementing `confirmbooking_interface`

If your extension provides a custom booking confirmation workflow, implement `mod_booking\local\interfaces\bookingextension\confirmbooking_interface` in addition to `bookingextension_interface`.

```php
use mod_booking\local\interfaces\bookingextension\confirmbooking_interface;

class myplugin implements bookingextension_interface, confirmbooking_interface {

    public function get_name(): string {
        return get_string('pluginname', 'bookingextension_myplugin');
    }

    public function get_description(): string {
        return get_string('plugindescription', 'bookingextension_myplugin');
    }

    public static function get_required_confirmation_count(int $optionid): int {
        return 1; // Number of confirmations required (e.g., 1 or 2)
    }
}
```

---

## 5. Registering custom events for booking rules

To allow booking rules to react to events fired by your extension:

1. Define your event class under `classes/event/` following Moodle's standard event architecture.
2. Return your event class names from `get_allowedruleeventkeys()`:

```php
public static function get_allowedruleeventkeys(): array {
    return [
        \bookingextension_myplugin\event\my_custom_event::class,
    ];
}
```

These events will appear as available triggers in the booking rules editor under the `rule_react_on_event` rule type.

---

## 6. Adding option fields

To add new fields to the booking option form:

1. Set `contains_option_fields()` to return `true`.
2. Return field definitions from `get_option_fields_info_array()`. Each definition should include at minimum: `classname`, `area`, `name`, `type`.
3. Create a field class under `classes/option/fields/` (optionally extending `mod_booking\option\field_base`).

---

## 7. Adding admin settings

Use `load_settings()` to add settings pages or tabs to the mod_booking settings tree:

```php
public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
    global $CFG;

    if ($hassiteconfig) {
        $settings = new \admin_settingpage(
            'bookingextension_myplugin',
            get_string('pluginname', 'bookingextension_myplugin')
        );
        // Add settings elements here
        $adminroot->add($parentnodename, $settings);
    }
}
```

---

## See also

- [Booking extensions overview](README.md)
- [Developer guides — Booking Extensions API](../../developer-guides/BOOKING_EXTENSIONS_API.md)
- [Booking rules — Rule types](../booking_rules/rule-types.md) — How custom events integrate with rules

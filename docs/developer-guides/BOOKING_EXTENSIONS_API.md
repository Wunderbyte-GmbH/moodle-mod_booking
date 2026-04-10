# Booking Extensions — Full Developer API

This is the complete developer reference for building `bookingextension_*` subplugins. For a shorter overview, see [Booking extensions developer API](../booking_extensions/developer-api.md).

---

## Table of Contents

1. [Plugin structure](#1-plugin-structure)
2. [Interface reference: `bookingextension_interface`](#2-interface-reference-bookingextension_interface)
3. [Interface reference: `confirmbooking_interface`](#3-interface-reference-confirmbooking_interface)
4. [Adding option fields](#4-adding-option-fields)
5. [Adding placeholders](#5-adding-placeholders)
6. [Adding custom events for booking rules](#6-adding-custom-events-for-booking-rules)
7. [Admin settings integration](#7-admin-settings-integration)
8. [Option view integration](#8-option-view-integration)
9. [Uninstall cleanup](#9-uninstall-cleanup)

---

## 1. Plugin structure

```
mod/booking/bookingextension/<pluginname>/
├── version.php
├── lang/
│   └── en/
│       └── bookingextension_<pluginname>.php
└── classes/
    ├── <pluginname>.php              # Main class implementing bookingextension_interface
    ├── event/                        # Optional: custom Moodle events
    ├── placeholders/                 # Optional: custom placeholder classes
    └── option/fields/                # Optional: custom option form fields
```

---

## 2. Interface reference: `bookingextension_interface`

Full interface: `mod_booking\plugininfo\bookingextension_interface`

| Method | Return type | Purpose |
|--------|-------------|---------|
| `get_plugin_name()` | `string` | Short name of the plugin (e.g., `myplugin`) |
| `contains_option_fields()` | `bool` | Whether the extension adds fields to the option form |
| `get_option_fields_info_array()` | `array` | Field definitions if `contains_option_fields()` is true |
| `load_settings($adminroot, $parentnodename, $hassiteconfig)` | `void` | Adds settings pages to the Moodle admin tree |
| `load_data_for_settings_singleton(int $optionid)` | `object` | Data injected into the singleton service for this option |
| `set_template_data_for_optionview(object $settings)` | `array[]` | Key/value/label/description arrays for the option view page |
| `add_options_to_col_actions(object $settings, mixed $context)` | `string` | HTML for extra action buttons in the options list |
| `get_allowedruleeventkeys()` | `array` | Event class names that this extension fires (for booking rules) |

---

## 3. Interface reference: `confirmbooking_interface`

Full interface: `mod_booking\local\interfaces\bookingextension\confirmbooking_interface`

Implement this interface if your extension provides a custom booking confirmation workflow.

| Method | Return type | Purpose |
|--------|-------------|---------|
| `get_name()` | `string` | Human-readable workflow name |
| `get_description()` | `string` | Description of what this workflow does |
| `get_required_confirmation_count(int $optionid)` | `int` | Number of confirmations required (e.g., 1 or 2) |

---

## 4. Adding option fields

To add fields to the booking option form:

1. Set `contains_option_fields()` to `true`.
2. Return field definitions from `get_option_fields_info_array()`.

Each field definition should be an associative array:

```php
public function get_option_fields_info_array(): array {
    return [
        [
            'classname' => 'bookingextension_myplugin\option\fields\myfield',
            'area'      => 'bookingextension_myplugin',
            'name'      => 'myfield',
            'type'      => 'text', // or 'checkbox', 'select', etc.
        ],
    ];
}
```

The field class should follow the same pattern as classes in `mod_booking/classes/option/fields/`.

---

## 5. Adding placeholders

Place placeholder classes in `classes/placeholders/`:

```
bookingextension_<pluginname>/classes/placeholders/<tokenname>.php
```

Namespace: `bookingextension_<pluginname>\placeholders\`

The class must have a static `return_value()` method following the same signature as `mod_booking\placeholders\placeholder_base`. See [Placeholders API](PLACEHOLDERS_API.md) for the full signature.

---

## 6. Adding custom events for booking rules

1. Create event classes under `classes/event/` following Moodle's standard event structure.
2. Return the event class names from `get_allowedruleeventkeys()`:

```php
public static function get_allowedruleeventkeys(): array {
    return [
        \bookingextension_myplugin\event\my_event::class,
    ];
}
```

These events will appear as available triggers in the booking rules editor under `rule_react_on_event`.

---

## 7. Admin settings integration

```php
public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
    global $CFG;

    if (!$hassiteconfig) {
        return;
    }

    // Add a settings page
    $settings = new \admin_settingpage(
        'bookingextension_myplugin_settings',
        get_string('pluginname', 'bookingextension_myplugin')
    );

    $settings->add(new \admin_setting_configtext(
        'bookingextension_myplugin/mysetting',
        get_string('mysetting', 'bookingextension_myplugin'),
        get_string('mysetting_desc', 'bookingextension_myplugin'),
        ''
    ));

    $adminroot->add($parentnodename, $settings);
}
```

---

## 8. Option view integration

To render custom content on the booking option detail page, implement `set_template_data_for_optionview()`:

```php
public static function set_template_data_for_optionview(object $settings): array {
    return [
        [
            'key'         => 'my_extension_badge',
            'value'       => 'PRO',
            'label'       => get_string('mybadgelabel', 'bookingextension_myplugin'),
            'description' => get_string('mybadgedescription', 'bookingextension_myplugin'),
        ],
    ];
}
```

---

## 9. Uninstall cleanup

Override `uninstall_cleanup()` in your main class (which extends `mod_booking\plugininfo\bookingextension`) to remove any plugin-specific data:

```php
public function uninstall_cleanup() {
    global $DB, $CFG;
    // Remove plugin-specific DB tables, config, etc.
    $DB->delete_records('config_plugins', ['plugin' => 'bookingextension_myplugin']);
    parent::uninstall_cleanup();
}
```

---

## See also

- [Booking extensions overview](../booking_extensions/README.md)
- [Booking extensions quick-start](../booking_extensions/developer-api.md)
- [Architecture overview](ARCHITECTURE.md)
- [Placeholders API](PLACEHOLDERS_API.md)

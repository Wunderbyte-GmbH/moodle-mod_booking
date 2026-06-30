[Back to parent section](../README.md)

# Availability Conditions — Developer API

This guide explains how to implement a custom availability condition for the mod_booking booking option availability pipeline.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [How conditions are evaluated](#1-how-conditions-are-evaluated)
2. [Implementing `bo_condition`](#2-implementing-bo_condition)
3. [User-configurable vs. system conditions](#3-user-configurable-vs-system-conditions)
4. [Registering your condition](#4-registering-your-condition)
5. [Sort order and priority](#5-sort-order-and-priority)

---

## 1. How conditions are evaluated

When a user views or attempts to book a booking option:

1. `bo_info::get_condition_results($optionid, $userid)` iterates over all available conditions.
2. Each condition's `is_available()` method is called.
3. The first condition that returns `false` (is blocking) determines what the user sees — its `get_description()` output is shown instead of the "Book it" button.
4. Conditions that return `true` are transparent to the user.

Conditions are sorted by their `get_id()` return value. Lower IDs are checked first.

---

## 2. Implementing `bo_condition`

Create a new file in `classes/bo_availability/conditions/`. The class must implement `mod_booking\bo_availability\bo_condition`.

```php
namespace mod_booking\bo_availability\conditions;

use mod_booking\bo_availability\bo_condition;
use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

class mycondition implements bo_condition {

    /** @var int Unique numeric ID for this condition */
    private static int $id = 9999; // Choose a unique unused integer

    public function get_id(): int {
        return self::$id;
    }

    public function get_name(): string {
        return 'mycondition';
    }

    public function is_json_compatible(): bool {
        // Return true if this condition stores its config in the booking option's JSON column
        // Return false if it is always applied (system condition)
        return true;
    }

    public function is_shown_in_mform(): bool {
        // Return true if this condition has configuration fields in the booking option form
        return true;
    }

    public function is_available(
        booking_option_settings $settings,
        int $userid,
        bool $not
    ): bool {
        // Return true if the user CAN book, false to block
        // $not = true means the logic should be inverted (condition is set to "user must NOT match")
        $passes = true; // Your logic here

        return $not ? !$passes : $passes;
    }

    public function hard_block(booking_option_settings $settings, $userid): bool {
        // Return true if this condition should be an absolute hard block
        // (cannot be overridden by capabilities like canoverbook)
        return false;
    }

    public function get_description(
        booking_option_settings $settings,
        $userid,
        $full,
        $not
    ) {
        // Return a human-readable description of why the condition is blocking
        // Used in the availability info display
        return [
            'name'        => get_string('mycondition', 'mod_booking'),
            'description' => get_string('mycondition_desc', 'mod_booking'),
        ];
    }

    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid) {
        // Add your configuration fields to the booking option form
        $mform->addElement('advcheckbox', 'bo_cond_mycondition_enable', get_string('mycondition', 'mod_booking'));
    }

    public function render_page(int $optionid, int $userid = 0) {
        // Optional: render a custom "pre-booking page" (like custom form does)
        return '';
    }

    public function render_button(
        booking_option_settings $settings,
        int $userid = 0,
        bool $not = false,
        bool $fullwidth = true,
        bool $main = false
    ) {
        // Return custom HTML for the booking button area, or null to use the default
        return null;
    }

    public function return_sql(int $userid = 0, &$params = []): array {
        // Return SQL fragments to filter booking options in list views
        // Return ['', '', ''] for no additional filter
        return ['', '', ''];
    }

    public function is_skippable(): bool {
        // Return true if this condition can be skipped under certain circumstances
        return false;
    }
}
```

---

## 3. User-configurable vs. system conditions

There are two types of conditions:

| Type | `is_json_compatible()` | `is_shown_in_mform()` | Description |
|------|------------------------|----------------------|-------------|
| **User-configurable** | `true` | `true` | Configured per booking option in the option form. Settings stored in the option's JSON. Example: `booking_time`, `customform`, `selectusers`. |
| **System conditions** | `false` | `false` | Always evaluated, no configuration. Example: `fullybooked`, `isbookable`, `alreadybooked`. Users never see these in the form. |

If you are building a user-configurable condition, make sure to:
1. Add your config to the option's JSON in `save()` logic (called by `booking_option::update()`).
2. Load your config from the JSON in `set_defaults()` and `is_available()`.

---

## 4. Registering your condition

Conditions are auto-discovered by `bo_info`, which scans the `classes/bo_availability/conditions/` directory. No explicit registration is needed.

To verify your condition is loaded:

```php
$conditions = \mod_booking\bo_availability\bo_info::get_all_conditions();
// Your condition should appear in this array
```

---

## 5. Sort order and priority

The `get_id()` return value determines the evaluation order. Lower IDs are evaluated first. Existing IDs in use:

- 1–99: Core system conditions (booking status, button states)
- 100–199: Core user-configurable conditions (booking time, custom form, etc.)
- 200+: Available for extensions

Choose an ID greater than 200 for custom conditions to avoid conflicts.

---

## See also

- [Architecture overview](ARCHITECTURE.md)
- [Availability conditions user documentation](../user/booking_conditions/README.md)

[Back to parent section](../README.md)

# Booking Rules — Developer API

This guide explains how to add custom rule types (triggers), conditions, and actions to the mod_booking booking rules system.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [Overview of the rules system](#1-overview-of-the-rules-system)
2. [Adding a custom rule type (trigger)](#2-adding-a-custom-rule-type-trigger)
3. [Adding a custom rule condition](#3-adding-a-custom-rule-condition)
4. [Adding a custom rule action](#4-adding-a-custom-rule-action)
5. [Registering your components](#5-registering-your-components)

---

## 1. Overview of the rules system

Each booking rule has three components:

- **Rule type (trigger):** When does the rule fire? (`rules/` directory)
- **Condition (who):** Which users are affected? (`conditions/` directory)
- **Action (what):** What happens? (`actions/` directory)

All three implement interfaces defined in `classes/booking_rules/`:

| Component | Interface | Directory |
|-----------|-----------|-----------|
| Rule type | `booking_rule` | `classes/booking_rules/rules/` |
| Condition | `booking_rule_condition` | `classes/booking_rules/conditions/` |
| Action | `booking_rule_action` | `classes/booking_rules/actions/` |

---

## 2. Adding a custom rule type (trigger)

Create a new class in `classes/booking_rules/rules/` implementing `mod_booking\booking_rules\booking_rule`:

```php
namespace mod_booking\booking_rules\rules;

use mod_booking\booking_rules\booking_rule;
use MoodleQuickForm;
use stdClass;

class rule_mytype implements booking_rule {

    public function get_name_of_rule(bool $localized = true): string {
        return $localized
            ? get_string('rule_mytype', 'mod_booking')
            : 'rule_mytype';
    }

    public function add_rule_to_mform(MoodleQuickForm &$mform, array &$repeateloptions, array $ajaxformdata = []) {
        // Add form elements for configuring this rule type
        $mform->addElement('text', 'rule_mytype_param', get_string('rule_mytype_param', 'mod_booking'));
    }

    public function save_rule(stdClass &$data) {
        // Persist rule-specific data to $data->json
    }

    public function set_defaults(stdClass &$data, stdClass $record) {
        // Load saved values back into $data for editing
    }

    public function set_ruledata(stdClass $record) {
        // Load from a DB record
        $this->set_ruledata_from_json($record->rulejson ?? '{}');
    }

    public function set_ruledata_from_json(string $json) {
        // Load configuration from JSON string
    }

    public function execute(int $optionid = 0, int $userid = 0) {
        // Find matching option/user pairs and queue actions
        // Typically queries DB and calls $action->execute() for each match
    }

    public function check_if_rule_still_applies(
        int $optionid, int $userid, int $nextruntime, int $optiondateid = 0
    ): bool {
        // Called before the ad-hoc task sends a mail
        // Return false to cancel the scheduled email
        return true;
    }
}
```

---

## 3. Adding a custom rule condition

Create a new class in `classes/booking_rules/conditions/` implementing `mod_booking\booking_rules\booking_rule_condition`:

```php
namespace mod_booking\booking_rules\conditions;

use mod_booking\booking_rules\booking_rule_condition;
use MoodleQuickForm;
use stdClass;

class select_myusers implements booking_rule_condition {

    public function get_name_of_condition($localized = true) {
        return $localized
            ? get_string('select_myusers', 'mod_booking')
            : 'select_myusers';
    }

    public function can_be_combined_with_bookingruletype(string $bookingruletype): bool {
        // Return true for rule types this condition is compatible with
        return in_array($bookingruletype, ['rule_daysbefore', 'rule_react_on_event']);
    }

    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        // Add configuration fields
    }

    public function save_condition(stdClass &$data): void {
        // Persist condition data to $data->conditionjson
    }

    public function set_defaults(stdClass &$data, stdClass $record) {}

    public function set_conditiondata(stdClass $record) {
        $this->set_conditiondata_from_json($record->conditionjson ?? '{}');
    }

    public function set_conditiondata_from_json(string $json) {}

    public function execute(stdClass &$sql, array &$params): void {
        // Modify the SQL query to filter to the relevant user set
        // $sql->where and $sql->join are available for extension
        $sql->where .= ' AND ba.status = :status';
        $params['status'] = MOD_BOOKING_STATUSPARAM_BOOKED;
    }
}
```

---

## 4. Adding a custom rule action

Create a new class in `classes/booking_rules/actions/` implementing `mod_booking\booking_rules\booking_rule_action`:

```php
namespace mod_booking\booking_rules\actions;

use mod_booking\booking_rules\booking_rule_action;
use MoodleQuickForm;
use stdClass;

class send_myaction implements booking_rule_action {

    public function get_name_of_action($localized = true) {
        return $localized
            ? get_string('send_myaction', 'mod_booking')
            : 'send_myaction';
    }

    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []) {
        return true;
    }

    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {
        $mform->addElement('text', 'myaction_param', get_string('myaction_param', 'mod_booking'));
    }

    public function save_action(stdClass &$data) {}

    public function set_defaults(stdClass &$data, stdClass $record) {}

    public function set_actiondata(stdClass $record) {
        $this->set_actiondata_from_json($record->actionjson ?? '{}');
    }

    public function set_actiondata_from_json(string $json) {}

    public function execute(stdClass $record) {
        // $record contains optionid, userid, cmid and any saved action data
        // Perform the action here
    }
}
```

---

## 5. Registering your components

Components are auto-discovered by the `rules_info`, `conditions_info`, and `actions_info` classes, which scan their respective directories. No explicit registration is needed — placing your class in the correct directory is sufficient.

To verify your component is discovered:

```php
$rules = \mod_booking\booking_rules\rules_info::get_all_rule_types();
// Your class should appear in this array
```

---

## See also

- [Architecture overview](ARCHITECTURE.md)
- [Booking rules user documentation](../user/booking_rules/README.md)
- [Booking extensions API](BOOKING_EXTENSIONS_API.md) — For registering custom events that rules can react to

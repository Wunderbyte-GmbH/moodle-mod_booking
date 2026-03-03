# Certificate Conditions System

The certificate conditions system allows you to define conditional rules that automatically issue certificates when booking options are completed, based on filter, logic, and action criteria.

## Architecture Overview

The system follows a modular three-part pattern:

1. **Filter** – validates that a user meets specified criteria (e.g., profile field value)
2. **Logic** – verifies event/context conditions (e.g., specific booking option completed)
3. **Action** – performs an action when filter and logic both pass (e.g., issue certificate)

Each component is pluggable via a simple interface-based design.

## Directory Structure

```
certificate_conditions/
├── README.md (this file)
├── certificate_conditions.php     # Main helper class
├── filter_interface.php           # Filter interface
├── logic_interface.php            # Logic interface
├── action_interface.php           # Action interface
├── filters_info.php               # Filter discovery/instantiation
├── logics_info.php                # Logic discovery/instantiation
├── actions_info.php               # Action discovery/instantiation
├── filters/
│   └── userprofilefield.php       # Example: check user profile field value
├── logics/
│   └── bookingoption.php          # Example: verify booking option completed
└── actions/
    └── createcertificate.php      # Example: issue a certificate
```

## Creating Custom Components

### 1. Create a Filter

Filters check whether a user meets specified criteria.

**File:** `filters/myfilter.php`

```php
<?php
namespace mod_booking\certificate_conditions\filters;

use mod_booking\certificate_conditions\filter_interface;
use MoodleQuickForm;
use stdClass;

class myfilter implements filter_interface {
    public $myfield = '';

    public function add_filter_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $mform->addElement('text', 'filter_myfilter_field', 'My Field Label');
        $mform->setType('filter_myfilter_field', PARAM_TEXT);
    }

    public function get_name_of_filter(bool $localized = true): string {
        return $localized ? get_string('filter_myfilter', 'mod_booking') : 'myfilter';
    }

    public function save_filter(stdClass &$data): void {
        $obj = new stdClass();
        $obj->filtername = 'myfilter';
        $obj->myfield = $data->filter_myfilter_field ?? '';
        $data->filterjson = json_encode($obj);
    }

    public function set_defaults(stdClass &$data, stdClass $record) {
        if (!empty($record->filterjson)) {
            $this->set_filterdata_from_json($record->filterjson);
            $obj = json_decode($record->filterjson);
            $data->filter_myfilter_field = $obj->myfield ?? '';
        }
    }

    public function set_filterdata(stdClass $record): void {
        // Load filter data from database record if needed
    }

    public function set_filterdata_from_json(string $json): void {
        $obj = json_decode($json);
        if ($obj) {
            $this->myfield = $obj->myfield ?? '';
        }
    }

    public function execute(stdClass &$sql, array &$params): void {
        // Legacy SQL building method (optional)
    }

    public function evaluate(stdClass $context): bool {
        // Return true if filter condition passes
        return true; // Your logic here
    }
}
```

### 2. Create a Logic

Logics verify event/context conditions.

**File:** `logics/mylogic.php`

```php
<?php
namespace mod_booking\certificate_conditions\logics;

use mod_booking\certificate_conditions\logic_interface;
use MoodleQuickForm;
use stdClass;

class mylogic implements logic_interface {
    public $myvalue = 0;

    public function add_logic_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $mform->addElement('text', 'logic_mylogic_value', 'My Value');
        $mform->setType('logic_mylogic_value', PARAM_INT);
    }

    public function get_name_of_logic(bool $localized = true): string {
        return $localized ? get_string('logic_mylogic', 'mod_booking') : 'mylogic';
    }

    public function save_logic(stdClass &$data): void {
        $obj = new stdClass();
        $obj->logicname = 'mylogic';
        $obj->myvalue = (int)($data->logic_mylogic_value ?? 0);
        $data->logicjson = json_encode($obj);
    }

    public function set_defaults(stdClass &$data, stdClass $record) {
        if (!empty($record->logicjson)) {
            $this->set_logicdata_from_json($record->logicjson);
            $obj = json_decode($record->logicjson);
            $data->logic_mylogic_value = $obj->myvalue ?? 0;
        }
    }

    public function set_logicdata(stdClass $record): void {
        // Load logic data if needed
    }

    public function set_logicdata_from_json(string $json): void {
        $obj = json_decode($json);
        if ($obj) {
            $this->myvalue = (int)($obj->myvalue ?? 0);
        }
    }

    public function execute(stdClass &$sql, array &$params): void {
        // Legacy SQL building method (optional)
    }

    public function evaluate(stdClass $context): bool {
        // Return true if logic condition passes
        return true; // Your logic here
    }
}
```

### 3. Create an Action

Actions perform tasks when filter and logic both pass.

**File:** `actions/myaction.php`

```php
<?php
namespace mod_booking\certificate_conditions\actions;

use mod_booking\certificate_conditions\action_interface;
use MoodleQuickForm;
use stdClass;

class myaction implements action_interface {
    public $myconfig = '';

    public function add_action_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $mform->addElement('text', 'action_myaction_config', 'Config Value');
        $mform->setType('action_myaction_config', PARAM_TEXT);
    }

    public function get_name_of_action(bool $localized = true): string {
        return $localized ? get_string('action_myaction', 'mod_booking') : 'myaction';
    }

    public function save_action(stdClass &$data): void {
        $obj = new stdClass();
        $obj->actionname = 'myaction';
        $obj->myconfig = $data->action_myaction_config ?? '';
        $data->actionjson = json_encode($obj);
    }

    public function set_defaults(stdClass &$data, stdClass $record) {
        if (!empty($record->actionjson)) {
            $this->set_actiondata_from_json($record->actionjson);
            $obj = json_decode($record->actionjson);
            $data->action_myaction_config = $obj->myconfig ?? '';
        }
    }

    public function set_actiondata(stdClass $record): void {
        // Load action data if needed
    }

    public function set_actiondata_from_json(string $json): void {
        $obj = json_decode($json);
        if ($obj) {
            $this->myconfig = $obj->myconfig ?? '';
        }
    }

    public function execute(stdClass &$sql, array &$params): void {
        // Legacy SQL building method (optional)
    }

    public function execute_action(stdClass $context): void {
        // Perform the action
        // $context contains: userid, optionid, event
        $userid = $context->userid ?? 0;
        $optionid = $context->optionid ?? 0;

        if ($userid && $optionid) {
            // Perform your action
        }
    }
}
```

## Language Strings

Add corresponding language strings to `lang/en/booking.php`:

```php
// Filter
$string['filter_myfilter'] = 'My Filter';
$string['filter_myfilter_field'] = 'My Field';

// Logic
$string['logic_mylogic'] = 'My Logic';
$string['logic_mylogic_value'] = 'My Value';

// Action
$string['action_myaction'] = 'My Action';
$string['action_myaction_config'] = 'Config Value';
```

## Integration Points

### Certificate Conditions Helper Class

The main class `certificate_conditions` provides utility methods:

- `get_rendered_list_of_saved_conditions($contextid)` – renders the conditions list
- `get_list_of_saved_conditions($contextid)` – retrieves saved conditions
- `save_certificate_condition($data)` – saves a new/updated condition
- `delete_condition($id)` – deletes a condition
- `evaluate_and_execute_condition($record, $context, $userid, $optionid)` – evaluates and executes a condition

### Observer Integration

Conditions are automatically evaluated when a booking option is marked as completed via the `bookingoption_completed` event. The observer calls:

```php
self::evaluate_certificate_conditions($event, $userid, $optionid);
```

This triggers `certificate_conditions::evaluate_and_execute_condition()` for all active conditions.

## Evaluation Flow

1. User completes a booking option
2. `bookingoption_completed` event fires
3. Observer calls `evaluate_certificate_conditions()`
4. For each active condition:
   - Instantiate filter, logic, action handlers
   - Call `filter->evaluate($context)`
   - Call `logic->evaluate($context)`
   - If both pass, call `action->execute_action($context)`

## Context Object

The context object passed to evaluate/execute methods contains:

```php
$context = new stdClass();
$context->event = $event;         // Core event object
$context->userid = $userid;       // User ID completing option
$context->optionid = $optionid;   // Booking option ID
```

## Best Practices

1. **Validation:** Always validate input in form elements
2. **Error Handling:** Use try-catch in execute_action to prevent broken conditions from blocking workflows
3. **Performance:** Cache lookups in set_*_from_json methods
4. **Localization:** Always use get_string() for user-facing text
5. **Testing:** Create unit tests in `tests/certificate_conditions/` for your components

## Example Workflow

To create a condition that issues a certificate when a specific user completes a specific option:

1. Use filter `userprofilefield` with field=role, value=student
2. Use logic `bookingoption` with optionid=42
3. Use action `createcertificate` with certid=5

Result: When a student completes booking option 42, a certificate (id 5) is automatically issued.

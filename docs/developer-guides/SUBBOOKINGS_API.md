[Back to parent section](../README.md)

# Sub-bookings — Developer API

This guide explains how to implement a custom sub-booking type for mod_booking.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [How sub-bookings are processed](#1-how-sub-bookings-are-processed)
2. [Implementing `booking_subbooking`](#2-implementing-booking_subbooking)
3. [Registering your sub-booking type](#3-registering-your-sub-booking-type)

---

## 1. How sub-bookings are processed

When a user books a parent option that has sub-bookings attached:

1. `subbookings_info::get_all_subbookings_for_option()` loads the sub-bookings for the option.
2. If a sub-booking has `is_blocking()` returning `true`, the user is redirected to the sub-booking selection page before the parent booking is confirmed.
3. The user's selection is passed to `return_answer_json()` and stored in `booking_subbooking_answers`.
4. `after_booking_action()` is called after the booking answer is saved.

---

## 2. Implementing `booking_subbooking`

Create a class in `classes/subbookings/sb_types/` implementing `mod_booking\subbookings\booking_subbooking`:

```php
namespace mod_booking\subbookings\sb_types;

use mod_booking\booking_option_settings;
use mod_booking\subbookings\booking_subbooking;
use MoodleQuickForm;
use stdClass;

class subbooking_mytype implements booking_subbooking {

    public $id = 0;
    public $optionid = 0;
    public $type = 'subbooking_mytype';
    public $typestringid = 'subbookingmytype';
    public $name = '';
    public $block = 0;
    public $json = '';

    public function get_name_of_subbooking($localized = true): string {
        return $localized
            ? get_string($this->typestringid, 'mod_booking')
            : $this->type;
    }

    public function set_subbookingdata(stdClass $record) {
        $this->id = $record->id ?? 0;
        $this->block = $record->block;
        $this->optionid = $record->optionid ?? 0;
        $this->set_subbookingdata_from_json($record->json);
    }

    public function set_subbookingdata_from_json(string $json) {
        $this->json = $json;
        $jsondata = json_decode($json);
        $this->name = $jsondata->name ?? '';
        // Load additional fields from JSON
    }

    public function add_subbooking_to_mform(MoodleQuickForm &$mform, array &$formdata) {
        // Add configuration fields to the booking option form
        $mform->addElement('text', 'subbooking_mytype_param', get_string('mytype_param', 'mod_booking'));
    }

    public function save_subbooking(stdClass &$data) {
        // Persist sub-booking data (stored in booking_subbookings.json)
    }

    public function set_defaults(stdClass &$data, stdClass $record) {}

    public function return_interface(booking_option_settings $settings, int $userid): array {
        // Return ['template' => 'templatename', 'data' => [...]] for Mustache rendering
        // This is what the participant sees in the booking flow
        return [
            'template' => 'mod_booking/subbooking/mytype',
            'data'     => ['name' => $this->name],
        ];
    }

    public function return_price($user): array {
        // Return pricing information for this sub-booking
        return ['price' => 0, 'currency' => ''];
    }

    public function return_subbooking_information(int $itemid = 0, int $userid = 0): array {
        // Return information about a specific sub-booking item (e.g., a specific slot)
        return [];
    }

    public function return_answer_json(int $itemid, ?object $user = null): string {
        // Return a JSON string to store as the booking answer for this sub-booking
        return json_encode(['itemid' => $itemid]);
    }

    public function is_blocking(booking_option_settings $settings, int $userid = 0): bool {
        // Return true if this sub-booking must be completed before the parent can be booked
        return (bool) $this->block;
    }

    public function after_booking_action(
        booking_option_settings $settings, int $userid = 0, int $recordid = 0
    ): bool {
        // Called after a booking answer is saved. Return true if successful.
        return true;
    }

    public function reservation_action(
        booking_option_settings $settings, int $userid = 0, int $recordid = 0
    ): bool {
        // Called when a user reserves (puts in shopping cart) without confirming
        return true;
    }

    public function reservation_deletion_action(
        booking_option_settings $settings, int $userid = 0, int $recordid = 0
    ): bool {
        // Called when a reservation is deleted (cart cleared)
        return true;
    }
}
```

---

## 3. Registering your sub-booking type

Sub-booking types are auto-discovered by `subbookings_info`, which scans `classes/subbookings/sb_types/`. No explicit registration is needed.

---

## See also

- [Architecture overview](ARCHITECTURE.md)
- [Sub-bookings user documentation](../subbookings/README.md)

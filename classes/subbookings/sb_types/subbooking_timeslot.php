<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_booking\subbookings\sb_types;

use context_module;
use local_entities\entitiesrelation_handler;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use mod_booking\output\subbooking_timeslot_output;
use mod_booking\price;
use mod_booking\singleton_service;
use mod_booking\subbookings\booking_subbooking;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * subbooking timeslot with a set duration
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_timeslot implements booking_subbooking {
    /** @var int $id Id of the configured subbooking */
    public $id = 0;

    /** @var int $optionid Id of the booking option which parents this subbooking*/
    public $optionid = 0;

    /** @var string $type type of subbooking as the name of this class */
    public $type = 'subbooking_timeslot';

    /** @var string $typestringid localized string to display type of subbooking at various froms */
    public $typestringid = 'subbookingtimeslot';

    /** @var string $name given name to this configured subbooking*/
    public $name = '';

    /** @var int $block subbookings can block the booking option of their parent option */
    public $block = 1;

    /** @var string $json json which holds all the data of a subbooking*/
    public $json = '';

    /** @var int $duration This is a supplementary field which is not directly in the db but wrapped in the json */
    public $duration = 0;

    /** @var int $duration This is a supplementary field which is not directly in the db but wrapped in the json */
    public $description = '';

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a subbooking record from DB
     */
    public function set_subbookingdata(stdClass $record) {
        $this->id = $record->id ?? 0;
        $this->optionid = $record->optionid ?? 0;
        $this->block = $record->block;
        $this->set_subbookingdata_from_json($record->json);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking subbooking
     */
    public function set_subbookingdata_from_json(string $json) {
        $this->json = $json;
        $jsondata = json_decode($json);
        $this->name = $jsondata->name;
        $this->duration = (int) $jsondata->data->duration;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public function add_subbooking_to_mform(MoodleQuickForm &$mform, &$formdata) {

        $mform->addElement(
            'static',
            'subbooking_timeslot_desc',
            '',
            get_string('subbookingtimeslot_desc', 'mod_booking')
        );

        // Duration of one particular slot.
        $mform->addElement(
            'text',
            'subbooking_timeslot_duration',
            get_string('subbookingduration', 'mod_booking')
        );
        $mform->setType('subbooking_timeslot_duration', PARAM_INT);

        // For price & entities wie need the id of this subbooking.
        $sboid = $formdata['id'] ?? 0;

        // Add price.
        $price = new price('subbooking', $sboid);
        $price->add_price_to_mform($mform, true); // Second param true means no price formula here!

        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
            $erhandler->instance_form_definition($mform, $sboid);
        }
    }

    /**
     * Get the name of the subbooking.
     * @param bool $localized
     * @return string
     */
    public function get_name_of_subbooking($localized = true): string {
        return $localized ? get_string($this->typestringid, 'mod_booking') : $this->type;
    }

    /**
     * Save the JSON for timeslot subbooking defined in form.
     *
     * The role has to determine the handler for condtion and action and get the right json object.
     *
     * @param stdClass $data form data reference
     */
    public function save_subbooking(stdClass &$data) {
        global $DB, $USER;

        $record = new stdClass();
        $now = time();

        if (!isset($data->json)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->json);
        }

        $record->name = $data->subbooking_name;
        $record->type = $this->type;
        $record->optionid = $data->optionid;
        $record->block = $this->block;
        $record->timemodified = $now;
        $record->usermodified = $USER->id;

        // If we have no record yet, we need to save it now.
        if (empty($data->id)) {
            $record->timecreated = $now;
            $id = $DB->insert_record('booking_subbooking_options', $record);
            $data->id = $id;
        }
        $this->id = $data->id;

        $jsonobject->name = $data->subbooking_name;
        $jsonobject->type = $this->type;
        $jsonobject->data = new stdClass();
        $jsonobject->data->duration = $data->subbooking_timeslot_duration ?? 0;

        // We need to set the data here because of the slot calculation below.
        $this->optionid = $data->optionid;
        $this->duration = $data->subbooking_timeslot_duration ?? 0;

        $slots = $this->return_slots();
        $jsonobject->data->slots = json_encode($slots);
        $record->json = json_encode($jsonobject);

        $record->id = $data->id;
        $DB->update_record('booking_subbooking_options', $record);

        // Add price.
        $price = new price('subbooking', $this->id);
        $price->save_from_form($data);

        // This is to save entity relation data.
        // The id key has to be set to option id.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
            $erhandler->instance_form_save($data, $this->id);
        }
    }

    /**
     * Sets the subbooking defaults when loading the form.
     *
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_subbookings
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->subbooking_type = $this->type;

        $jsonobject = json_decode($record->json);
        $jsondata = $jsonobject->data;

        $data->subbooking_name = $record->name;
        $data->subbooking_timeslot_duration = $jsondata->duration;

        // This is to save entity relation data.
        // The id key has to be set to option id.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
            $erhandler->values_for_set_data($data, $record->id);
        }
        // Set price.
        $price = new price('subbooking', $record->id);
        $price->set_data($data);
    }

    /**
     * Return interface for this subbooking type as an array of data & template.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return array
     */
    public function return_interface(booking_option_settings $settings, int $userid): array {

        // The interface of the timeslot booking should merge when there are multiple slot bookings.
        // Therefore, we need to first find out how many of these are present.
        $arrayofmine = array_filter(
            $settings->subbookings,
            function ($x) {
                return $x->type == $this->type;
            }
        );

        // We only want to actually render anything when we are in the last item.
        $lastitem = end($arrayofmine);
        if ($lastitem !== $this) {
            return [];
        }

        // Now that we render the last item, we need to render all of them, plus the container.
        // We need to create the json for rendering.

        $data = new subbooking_timeslot_output($settings, true);
        return [$data, 'mod_booking/subbooking/timeslottable'];
    }

    /**
     * Function to return all relevant information of this subbooking as array.
     * This function can be used to differentiate for different items a single ...
     * ... subbooking option can provide. One example would be a timeslot subbooking...
     * ... where itemids would be slotids.
     * But normally the itemid here is the same as the subboooking it.
     *
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public function return_subbooking_information(int $itemid = 0, int $userid = 0): array {

        // In the case of this subbooking type, the itemid refers to the slots.
        // In other types, the itemid is actually $this->id.
        // But here, we need to return the information of the slot.
        $object = json_decode($this->json);
        $data = json_decode($object->data->slots, true);

        // Identify the right timeslot by itemid.
        foreach ($data['locations']['timeslots'] as $timeslot) {
            if ($timeslot['itemid'] == $itemid) {
                break;
            }
        }

        $returnarray = [
            'itemid' => $itemid,
            'optionid' => $this->optionid,
            'title' => $this->name,
            'price' => $timeslot['price'],
            'currency' => $timeslot['currency'],
            'component' => 'mod_booking',
            'area' => 'subbooking-' . $this->id, // ... $this-id is in this case not the itemid, but packed in the area.
            'description' => $timeslot['slot'] ?? '',
            'imageurl' => '',
            'canceluntil' => strtotime('- 1 hour', $timeslot['slotstarttime']), // Hardcoded for now, one hour before.
            'coursestarttime' => $timeslot['slotstarttime'],
            'courseendtime' => $timeslot['slotendtime'],
        ];

        return $returnarray;
    }

    /**
     * When a subbooking is booked, we might need some supplementary values saved.
     * Evey subbooking type can decide what to store in the answer json.
     *
     * @param int $itemid
     * @param ?object $user
     * @return string
     */
    public function return_answer_json(int $itemid, ?object $user = null): string {

        return '';
    }

    /**
     * Returns all the answers as array for a given subbooking.
     * It is possible to specify an itemid. In most subbooking types...
     * ... this would just be the same as $this->id.
     * @param int $itemid
     * @return array
     */
    public function return_answers($itemid = 0): array {
        global $DB;

        $params['sboptionid'] = $this->id;

        $sql = "SELECT *
                FROM {booking_subbooking_answers}
                WHERE sboptionid=:sboptionid";

        // When the itemid is 0, we might return a number of records.
        if ($itemid != 0) {
            $params['itemid'] = $itemid;
            $sql .= " AND itemid=:itemid";
        }

        if (!$records = $DB->get_records_sql($sql, $params)) {
            return [];
        }

        return $records;
    }

    /**
     * Helper Function to create slots and returns them as array.
     *
     * @return array
     */
    private function return_slots(): array {

        // Make sure we avoid a loop.
        if (empty($this->duration)) {
            return ['error' => 'durationisnull'];
        }

        $slotcounter = 1;
        // This is to save entity relation data.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
            $entitiy = $erhandler->get_instance_data($this->id);
            $location = ['name' => $entitiy->name];
        } else {
            $location = ['name' => ''];
        }

        // We save the subbookingid here.
        $location['sboid'] = $this->id;

        // We need to get start & endtime for every date of this option.

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        foreach ($settings->sessions as $session) {
            $date = dates_handler::prettify_datetime($session->coursestarttime, $session->courseendtime);

            $data['days'][] = [
                "day" => $date->startdate,
            ];

            $slots = dates_handler::create_slots(
                $session->coursestarttime,
                $session->courseendtime,
                $this->duration
            );

            $price = price::get_price('subbooking', $this->id);

            foreach ($slots as $slot) {
                if (!isset($data['slots'])) {
                    $tempslots[] = [
                        "slot" => $slot->datestring,
                    ];
                }

                $timeslot = [
                    "free" => true,
                    "slot" => $slot->datestring,
                    "slotstarttime" => $slot->starttimestamp,
                    "slotendtime" => $slot->endtimestamp,
                    "area" => "subbooking-" . $this->id,
                    "componentname" => "mod_booking",
                    "itemid" => $slotcounter,
                ];

                if (!empty($price)) {
                    $timeslot["price"] = $price['price'] ?? 0;
                    $timeslot["currency"] = $price['currency'] ?? 'EUR';
                }
                $location['timeslots'][] = $timeslot;
                $slotcounter++;
            }

            // We only do this once.
            if (!isset($data['slots'])) {
                $data['slots'] = $tempslots;
            }
            $data['locations'] = $location;
        }

        return $data;
    }

    /**
     * The price might be altered, eg. when more than one item is selected.
     *
     * @param object $user
     * @return array
     */
    public function return_price($user): array {
        return price::get_price('subbooking', $this->id, $user);
    }

    /**
     * The description might be adjusted depending on the choices of the user.
     * How many of the items are booked etc.
     *
     * @param object $user
     * @return string
     */
    public function return_description($user): string {
        return $this->description;
    }

    /**
     * This helper function returns first the array with slots, but it also...
     * Marks the booked arrays and those which are booked by the current user.
     *
     * @param array $slots
     * @param int $userid
     * @return array
     */
    public function add_booking_information_to_slots(array $slots, int $userid = 0) {

        global $USER;

        if ($userid == 0) {
            $userid = $USER->id;
        }

        $returnarray = [];

        // Get array of all bookings for this subbooking option.
        $answers = $this->return_answers();

        foreach ($slots as $slot) {
            foreach ($answers as $answer) {
                // Does the answer concern the right slot?
                if ($answer->itemid != $slot['itemid']) {
                    continue;
                }
                // If the answer relevant for our status.
                switch ($answer->status) {
                    case MOD_BOOKING_STATUSPARAM_BOOKED:
                    case MOD_BOOKING_STATUSPARAM_RESERVED:
                        $slot['free'] = false;
                        if ($answer->userid == $userid) {
                            $slot['tag'] = get_string('booked', 'mod_booking');
                            unset($slot['price']);
                            unset($slot['currency']);
                        }
                        break;
                }
            }
            $returnarray[] = $slot;
        }

        return $returnarray;
    }

    /**
     * Is blocking. This depends on the settings and user.
     *
     * @param int $itemid
     * @param int $userid
     * @return bool
     */
    public function is_blocking(booking_option_settings $settings, int $userid = 0): bool {
        return !empty($this->block);
    }
}

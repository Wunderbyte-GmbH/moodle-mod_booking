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
use context_system;
use html_writer;
use mod_booking\booking_option_settings;
use mod_booking\form\subbooking\additionalperson_form;
use mod_booking\output\subbooking_additionalperson_output;
use mod_booking\price;
use mod_booking\subbookings\booking_subbooking;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * subbooking additionalperson with counter
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_additionalperson implements booking_subbooking {
    /** @var int $id Id of the configured subbooking */
    public $id = 0;

    /** @var int $optionid Id of the booking option which parents this subbooking*/
    public $optionid = 0;

    /** @var string $type type of subbooking as the name of this class */
    public $type = 'subbooking_additionalperson';

    /** @var string $typestringid localized string to display type of subbooking at various froms */
    public $typestringid = 'subbookingadditionalperson';

    /** @var string $name given name to this configured subbooking*/
    public $name = '';

    /** @var int $block subbookings can block the booking option of their parent option */
    public $block = 0;

    /** @var string $json json which holds all the data of a subbooking */
    public $json = '';

    /** @var int $available Nr. of times this item is available. 0 for unlimited. */
    public $available = 1;

    /** @var string $description Extensive description of the additonal item. */
    public $description = '';

    /** @var string $descriptionformat  */
    public $descriptionformat = '';

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a subbooking record from DB
     */
    public function set_subbookingdata(stdClass $record) {
        $this->id = $record->id ?? 0;
        $this->block = $record->block;
        $this->optionid = $record->optionid ?? 0;
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
        $this->description = $jsondata->data->description ?? '';
        $this->descriptionformat = $jsondata->data->descriptionformat ?? '';
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public function add_subbooking_to_mform(MoodleQuickForm &$mform, array &$formdata) {

        $mform->addElement(
            'static',
            'subbooking_additionalperson_desc',
            '',
            get_string('subbookingadditionalperson_desc', 'mod_booking')
        );

        // Add a description with the potential inclusion of files.

        $cmid = $formdata['cmid'];
        $context = context_module::instance($cmid);

        $textfieldoptions = [
            'trusttext' => true,
            'subdirs' => true,
            'maxfiles' => 1,
            'context' => $context,
        ];

        $mform->addElement(
            'editor',
            'subbooking_additionalperson_description_editor',
            get_string('subbookingadditionalpersondescription', 'mod_booking'),
            null,
            $textfieldoptions
        );
        $mform->setType('subbooking_additionalperson_description_editor', PARAM_RAW);

        // For price & entities wie need the id of this subbooking.
        $sboid = $formdata['id'] ?? 0;

        // Add price.
        $price = new price('subbooking', $sboid);
        $price->add_price_to_mform($mform, true); // Second param true means no price formula here!
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

        $jsonobject->name = $data->subbooking_name;
        $jsonobject->type = $this->type;
        $jsonobject->data = new stdClass();
        $jsonobject->data->description = ''; // Updated later.
        $jsonobject->data->descriptionformat = 0; // Updated later.
        $record->name = $data->subbooking_name;
        $record->type = $this->type;
        $record->optionid = $data->optionid;
        $record->json = json_encode($jsonobject);
        $record->timemodified = $now;
        $record->usermodified = $USER->id;

        // If we can update, we add the id here.
        if (!empty($data->id)) {
            $this->id = $data->id;
            $record->id = $data->id;
            $DB->update_record('booking_subbooking_options', $record);
        } else {
            $record->timecreated = $now;
            $id = $DB->insert_record('booking_subbooking_options', $record);
            $this->id = $id;
        }

        $context = context_module::instance($data->cmid);
        $textfieldoptions = [
            'trusttext' => true,
            'subdirs' => true,
            'maxfiles' => 1,
            'context' => $context,
        ];

        $data = file_postupdate_standard_editor(
            $data,
            'subbooking_additionalperson_description',
            $textfieldoptions,
            $context,
            'mod_booking',
            'subbookings',
            $this->id
        );

        $record->id = $this->id;

        // We need to update again to show the correct information.
        $jsonobject->data->description =
            $data->subbooking_additionalperson_description ?? '';
        $jsonobject->data->descriptionformat =
            $data->subbooking_additionalperson_descriptionformat ?? '';
        $record->json = json_encode($jsonobject);

        $DB->update_record('booking_subbooking_options', $record);

        // Add price.
        $price = new price('subbooking', $this->id);
        $price->save_from_form($data);
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

        $data->subbooking_additionalperson_description = $jsondata->description;
        $data->subbooking_additionalperson_descriptionformat = $jsondata->descriptionformat;

        $context = context_module::instance($data->cmid);
        $textfieldoptions = [
            'trusttext' => true,
            'subdirs' => true,
            'maxfiles' => 1,
            'context' => $context,
        ];

        $data = file_prepare_standard_editor(
            $data,
            'subbooking_additionalperson_description',
            $textfieldoptions,
            $context,
            'mod_booking',
            'subbookings',
            $record->id
        );

        // Add price.
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

        // The interfaces should merge when there are multiple "additional person" subbookings.
        // Therefore, we need to first find out how many of these are present.
        $arrayofmine = array_filter($settings->subbookings, function ($x) {
            return $x->type == $this->type;
        });

        // We only want to actually render anything when we are in the last item.
        $lastitem = end($arrayofmine);
        if ($lastitem !== $this) {
            return [];
        }

        $dataobj = new subbooking_additionalperson_output($settings, $userid);
        return [['data' => $dataobj->data], 'mod_booking/subbooking/additionalperson'];
    }

    /**
     * The price might be altered, eg. when more than one item is selected.
     *
     * @param object $user
     * @return array
     */
    public function return_price($user): array {

        // First, we get the price object.
        $price = price::get_price('subbooking', $this->id, $user);

        if (empty($price)) {
            return [];
        }

        // When choosing the elements from the subbookings, we store our current state in the cache.
        $data = additionalperson_form::get_data_from_cache($this->id);

        // If we find the multiplier here, we set it, else it's 1.
        $multiplier = (int)$data->subbooking_addpersons ?? 1;

        if (empty($multiplier)) {
            $multiplier = 1;
        }

        $price['price'] = $multiplier * $price['price'];

        return $price;
    }

    /**
     * The description might be adjusted depending on the choices of the user.
     * How many of the items are booked etc.
     *
     * @param object $user
     * @return string
     */
    public function return_description($user): string {

        global $OUTPUT, $PAGE;

        $PAGE->set_context(context_system::instance());

        // When choosing the elements from the subbookings, we store our current state in the cache.
        if (!$data = additionalperson_form::get_data_from_cache($this->id)) {
            return $this->description;
        }

        $bp = $data->subbooking_addpersons ?? 1;

        $templatedata = [
            'introduction' => get_string('subbookingbookedpersons', 'mod_booking'),
            'personbooked' => [],
        ];

        $counter = 1;
        while ($counter <= $bp) {
            $fnkey = "person_firstname_$counter";
            $lnkey = "person_lastname_$counter";
            $akey = "person_age_$counter";

            $templatedata['personbooked'][] = [
                'firstname' => $data->{$fnkey},
                'lastname' => $data->{$lnkey},
                'age' => $data->{$akey},
            ];
            $counter++;
        }

        $description = $OUTPUT->render_from_template('mod_booking/subbooking/additionalperson_description', $templatedata);

        return $description;
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

        return [];
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

        // When choosing the elements from the subbookings, we store our current state in the cache.
        $data = additionalperson_form::get_data_from_cache($this->id);
        return json_encode($data);
    }

    /**
     * Is blocking. This depends on the settings and user.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     *
     * @return bool
     *
     */
    public function is_blocking(booking_option_settings $settings, int $userid = 0): bool {

        return !empty($this->block);
    }
}

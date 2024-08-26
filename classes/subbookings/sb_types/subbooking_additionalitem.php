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
use mod_booking\bo_availability\conditions\alreadybooked;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\bo_availability\conditions\onwaitinglist;
use mod_booking\booking_option_settings;
use mod_booking\local\mobile\customformstore;
use mod_booking\output\subbooking_additionalitem_output;
use mod_booking\price;
use mod_booking\singleton_service;
use mod_booking\subbookings\booking_subbooking;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * subbooking additionalitem with counter
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_additionalitem implements booking_subbooking {
    /** @var int $id Id of the configured subbooking */
    public $id = 0;

    /** @var int $optionid Id of the booking option which parents this subbooking*/
    public $optionid = 0;

    /** @var string $type type of subbooking as the name of this class */
    public $type = 'subbooking_additionalitem';

    /** @var string $typestringid localized string to display type of subbooking at various froms */
    public $typestringid = 'subbookingadditionalitem';

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

    /** @var string $subbookingadditemformlink  */
    public $subbookingadditemformlink = '';

    /** @var string $subbookingadditemformlinkvalue  */
    public $subbookingadditemformlinkvalue = '';

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
        $this->subbookingadditemformlink = $jsondata->data->subbookingadditemformlink ?? '';
        $this->subbookingadditemformlinkvalue = $jsondata->data->subbookingadditemformlinkvalue ?? '';
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
            'subbooking_additionalitem_desc',
            '',
            get_string('subbookingadditionalitem_desc', 'mod_booking')
        );

        $cmid = $formdata['cmid'];
        $context = context_module::instance($cmid);

        $boid = $formdata['optionid'];
        $settings = singleton_service::get_instance_of_booking_option_settings($boid);
        $formelements = $formelements = customform::return_formelements($settings);

        $formoptions = [0 => get_string('noformlink', 'mod_booking')];
        foreach ($formelements as $key => $value) {
            $formoptions[$key] = $value->label;
        }

        $mform->addElement(
            'select',
            'subbookingadditemformlink',
            get_string('subbookingadditemformlink', 'mod_booking'),
            $formoptions
        );
        $mform->addHelpButton('subbookingadditemformlink', 'subbookingadditemformlink', 'mod_booking');

        $mform->addElement(
            'text',
            'subbookingadditemformlinkvalue',
            get_string('subbookingadditemformlinkvalue', 'mod_booking')
        );
        $mform->setType('subbookingadditemformlinkvalue', PARAM_TEXT);
        $mform->hideIf('subbookingadditemformlinkvalue', 'subbookingadditemformlink', 'eq', 0);

        // Add a description with the potential inclusion of files.
        $textfieldoptions = [
            'trusttext' => true,
            'subdirs' => true,
            'maxfiles' => 1,
            'context' => $context,
        ];

        $mform->addElement(
            'editor',
            'subbooking_additionalitem_description_editor',
            get_string('subbookingadditionalitemdescription', 'mod_booking'),
            null,
            $textfieldoptions
        );
        $mform->setType('subbooking_additionalitem_description', PARAM_RAW);

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
        if ($data->id) {
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
            'subbooking_additionalitem_description',
            $textfieldoptions,
            $context,
            'mod_booking',
            'subbookings',
            $this->id
        );

        $record->id = $this->id;

        // We need to update again to show the correct information.
        $jsonobject->data->description =
            $data->subbooking_additionalitem_description ?? '';
        $jsonobject->data->descriptionformat =
            $data->subbooking_additionalitem_descriptionformat ?? '';
        $jsonobject->data->subbookingadditemformlink =
            $data->subbookingadditemformlink ?? '';
        $jsonobject->data->subbookingadditemformlinkvalue =
            $data->subbookingadditemformlinkvalue ?? '';
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

        $data->subbooking_additionalitem_description = $jsondata->description;
        $data->subbooking_additionalitem_descriptionformat = $jsondata->descriptionformat;
        $data->subbookingadditemformlink = $jsondata->subbookingadditemformlink;
        $data->subbookingadditemformlinkvalue = $jsondata->subbookingadditemformlinkvalue;

        $context = context_module::instance($data->cmid);
        $textfieldoptions = [
            'trusttext' => true,
            'subdirs' => true,
            'maxfiles' => 1,
            'context' => $context,
        ];

        $data = file_prepare_standard_editor(
            $data,
            'subbooking_additionalitem_description',
            $textfieldoptions,
            $context,
            'mod_booking',
            'subbookings',
            $record->id
        );

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

        // The interface of the timeslot booking should merge when there are multiple slot bookings.
        // Therefore, we need to first find out how many of these are present.
        $arrayofmine = array_filter($settings->subbookings, function ($x) {
            return $x->type == $this->type;
        });

        // We only want to actually render anything when we are in the last item.
        $lastitem = end($arrayofmine);
        if ($lastitem !== $this) {
            return [];
        }

        // Now that we render the last item, we need to render all of them, plus the container.
        // We need to create the json for rendering.

        $data = new subbooking_additionalitem_output($settings, $userid);

        return [$data, 'mod_booking/subbooking/additionalitem'];
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
     * @param object $user
     * @return string
     */
    public function return_answer_json(int $itemid, $user = null): string {

        return '';
    }

    /**
     * Is blocking. This depends on the settings and user.
     * @param booking_option_settings $settings
     * @param int $userid
     *
     * @return bool
     *
     */
    public function is_blocking(booking_option_settings $settings, int $userid = 0): bool {

        // We never show the subbooking when we are only in waitforconfirmation.
        if (!empty($settings->waitforconfirmation)) {
            $waitinglist = new onwaitinglist();
            $alreadybooked = new alreadybooked();

            $ba = singleton_service::get_instance_of_booking_answers($settings);

            if (
                $ba->usersonlist[$userid] ?? false
                && $ba->usersonwaitinglist[$userid] ?? false
            ) {
                return false;
            }
        }

        // This subbooking supports dependency on form values chosen by the user.
        // Therefore, we need to have a look in the booking answers object.
        $customformstore = new customformstore($userid, $settings->id);

        // If we have have no link to a formvalue in this subbooking...
        // ... or if we have not yet submitted a form value...
        // ... we return the block of the subbooking.
        if (
            $customformstore->get_customform_data() === false
            || empty($this->subbookingadditemformlink)
        ) {
            return empty($this->block);
        }

        if ($data = $customformstore->return_value_for_label($this->subbookingadditemformlink)) {
            if ($this->subbookingadditemformlinkvalue == $data) {
                return true;
            }
        }
        return false;
    }
}

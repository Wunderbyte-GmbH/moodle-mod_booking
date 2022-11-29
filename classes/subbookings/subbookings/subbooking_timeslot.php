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

namespace mod_booking\subbookings\subbookings;

use local_entities\entitiesrelation_handler;
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
    protected $type = 'subbooking_timeslot';

    /** @var string $name given name to this configured subbooking*/
    public $name = '';

    /** @var int $block subbookings can block the booking option of their parent option */
    public $block = 1;

    /** @var string $json json which holds all the data of a subbooking*/
    public $json = '';

    /** @var int $duration This is a supplementary field which is not directly in the db but wrapped in the json */
    public $duration = 0;

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

        $mform->addElement('static', 'subbooking_timeslot_desc', '',
            get_string('subbooking_timeslot_desc', 'mod_booking'));

        // Duration of one particular slot.
        $mform->addElement('text', 'subbooking_timeslot_duration',
            get_string('subbooking_duration', 'mod_booking'));
        $mform->setType('subbooking_timeslot_duration', PARAM_INT);

        if (class_exists('local_entities\entitiesrelation_handler')) {
            $sboid = $formdata['id'] ?? 0;
            $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
            $erhandler->instance_form_definition($mform, $sboid);
        }

    }

    /**
     * Get the name of the subbooking.
     * @param boolean $localized
     * @return void
     */
    public function get_name_of_subbooking($localized = true) {
        return $localized ? get_string($this->type, 'mod_booking') : $this->type;
    }

    /**
     * Save the JSON for timeslot subbooking defined in form.
     * The role has to determine the handler for condtion and action and get the right json object.
     * @param stdClass &$data form data reference
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
        $jsonobject->data->duration = $data->subbooking_timeslot_duration ?? 0;

        $record->name = $data->subbooking_name;
        $record->type = $this->type;
        $record->optionid = $data->optionid;
        $record->block = $this->block;
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

        // This is to save entity relation data.
        // The id key has to be set to option id.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
            $erhandler->instance_form_save($data, $this->id);
        }
    }

    /**
     * Sets the subbooking defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_subbookings
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->subbooking_type = $this->type;

        $jsonobject = json_decode($record->json);
        $data = $jsonobject->data;

        $data->subbooking_name = $record->name;
        $data->subbooking_timeslot_duration = $data->duration;
    }
}

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

use mod_booking\subbookings\booking_subbooking;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * subbooking do something a specified number of days before a chosen date.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_timeslot implements booking_subbooking {

    /** @var string $subbookingname */
    protected $subbookingname = 'subbooking_timeslot';

    /** @var string $name */
    public $name = null;

    /** @var string $subbookingjson */
    public $subbookingjson = null;

    /** @var int $subbookingid */
    public $subbookingid = null;

    /** @var int $days */
    public $days = null;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a subbooking record from DB
     */
    public function set_subbookingdata(stdClass $record) {
        $this->subbookingid = $record->id ?? 0;
        $this->set_subbookingdata_from_json($record->subbookingjson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking subbooking
     */
    public function set_subbookingdata_from_json(string $json) {
        $this->subbookingjson = $json;
        $subbookingobj = json_decode($json);
        $this->name = $subbookingobj->name;
        $this->days = (int) $subbookingobj->subbookingdata->days;
        $this->datefield = $subbookingobj->subbookingdata->datefield;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_subbooking_to_mform(MoodleQuickForm &$mform) {
        global $DB;

        $mform->addElement('static', 'subbooking_timeslot_desc', '',
            get_string('subbooking_timeslot_desc', 'mod_booking'));

        // Number of days before.
        $mform->addElement('text', 'subbooking_timeslot_duration',
            get_string('subbooking_duration', 'mod_booking'));
        $mform->setType('subbooking_timeslot_duration', PARAM_INT);

    }

    /**
     * Get the name of the subbooking.
     * @param boolean $localized
     * @return void
     */
    public function get_name_of_subbooking($localized = true) {
        return $localized ? get_string($this->subbookingname, 'mod_booking') : $this->subbookingname;
    }

    /**
     * Save the JSON for timeslot subbooking defined in form.
     * The role has to determine the handler for condtion and action and get the right json object.
     * @param stdClass &$data form data reference
     */
    public function save_subbooking(stdClass &$data) {
        global $DB;

        $record = new stdClass();

        if (!isset($data->subbookingjson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->subbookingjson);
        }

        $jsonobject->name = $data->subbooking_name;
        $jsonobject->subbookingname = $this->subbookingname;
        $jsonobject->subbookingdata = new stdClass();
        $jsonobject->subbookingdata->days = $data->subbooking_timeslot_days ?? 0;
        $jsonobject->subbookingdata->datefield = $data->subbooking_timeslot_datefield ?? '';

        $record->subbookingjson = json_encode($jsonobject);
        $record->subbookingname = $this->subbookingname;
        $record->bookingid = $data->bookingid ?? 0;

        // If we can update, we add the id here.
        if ($data->id) {
            $record->id = $data->id;
            $DB->update_record('booking_subbookings', $record);
        } else {
            $subbookingid = $DB->insert_record('booking_subbookings', $record);
            $this->subbookingid = $subbookingid;
        }
    }

    /**
     * Sets the subbooking defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_subbookings
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingsubbookingtype = $this->subbookingname;

        $jsonobject = json_decode($record->subbookingjson);
        $subbookingdata = $jsonobject->subbookingdata;

        $data->subbooking_name = $jsonobject->name;
        $data->subbooking_timeslot_days = $subbookingdata->days;
        $data->subbooking_timeslot_datefield = $subbookingdata->datefield;

    }

    /**
     * Execute the subbooking.
     * @param int $optionid optional
     * @param int $userid optional
     */
    public function execute(int $optionid = 0, int $userid = 0) {
        global $DB;

        $jsonobject = json_decode($this->subbookingjson);

        // We reuse this code when we check for validity, therefore we use a separate function.
        $records = $this->get_records_for_execution($optionid, $userid);

        foreach ($records as $record) {

            // Set the time of when the task should run.
            $nextruntime = (int) $record->datefield - ((int) $this->days * 86400);
            $record->subbookingname = $this->subbookingname;
            $record->nextruntime = $nextruntime;
        }
    }

    /**
     * This function is called on execution of adhoc tasks,
     * so we can see if the subbooking still applies and the adhoc task
     * shall really be executed.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $nextruntime
     * @return bool true if the subbooking still applies, false if not
     */
    public function check_if_subbooking_still_applies(int $optionid, int $userid, int $nextruntime): bool {

        $subbookingstillapplies = false;

        // We retrieve the same sql we also use in the execute function.
        $records = $this->get_records_for_execution($optionid, $userid, true);

        foreach ($records as $record) {
            $oldnextruntime = (int) $record->datefield - ((int) $this->days * 86400);

            if ($oldnextruntime != $nextruntime) {
                $subbookingstillapplies = false;
                break;
            }
        }

        return $subbookingstillapplies;
    }

    /**
     * This helperfunction builds the sql with the help of the condition and returns the records.
     * Testmode means that we don't limit by now timestamp.
     *
     * @param integer $optionid
     * @param integer $userid
     * @param bool $testmode
     * @return array
     */
    public function get_records_for_execution(int $optionid = 0, int $userid = 0, bool $testmode = false) {
        global $DB;

        // Execution of a subbooking is a complex action.
        // Going from subbooking to condition to action...
        // ... we need to go into actions with an array of records...
        // ... which has the keys cmid, optionid & userid.

        $jsonobject = json_decode($this->subbookingjson);
        $subbookingdata = $jsonobject->subbookingdata;

        $andoptionid = "";
        $anduserid = "";

        $params = [
            'numberofdays' => (int) $subbookingdata->days,
            'nowparam' => time()
        ];

        if (!empty($optionid)) {
            $andoptionid = " AND bo.id = :optionid ";
            $params['optionid'] = $optionid;
        }

        if (!empty($userid)) {
            $anduserid = "AND ud.userid = :userid";
            $params['userid'] = $userid;
        }

        $sql = new stdClass();

        $sql->select = "bo.id optionid, cm.id cmid, bo." . $subbookingdata->datefield . " datefield";

        $sql->from = "{booking_options} bo
                    JOIN {course_modules} cm
                    ON cm.instance = bo.bookingid
                    JOIN {modules} m
                    ON m.name = 'booking' AND m.id = cm.module";

        // In testmode we don't check the timestamp.
        $sql->where = " bo." . $subbookingdata->datefield;
        $sql->where .= !$testmode ? " >= ( :nowparam + (86400 * :numberofdays ))" : " IS NOT NULL ";
        $sql->where .= " $andoptionid $anduserid ";

        // Now that we know the ids of the booking options concerend, we will determine the users concerned.
        // The condition execution will add their own code to the sql.

        $sqlstring = "SELECT $sql->select FROM $sql->from WHERE $sql->where";

        $records = $DB->get_records_sql($sqlstring, $params);

        return $records;
    }
}

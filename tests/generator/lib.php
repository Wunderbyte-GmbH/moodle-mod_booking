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

/**
 * Module booking data generator
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

use mod_booking\booking_option;
use mod_booking\price;
use mod_booking\semester;
use mod_booking\customfield\booking_handler;

/**
 * Class to handle module booking data generator
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2023 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_generator extends testing_module_generator {

    /**
     *
     * @var int keep track of how many booking options have been created.
     */
    protected $bookingoptions = 0;

    /**
     * To be called from data reset code only, do not use in tests.
     *
     * @return void
     */
    public function reset() {
        $this->bookingoptions = 0;

        parent::reset();
    }

    /**
     * Create booking instance
     *
     * @param mixed|null $record
     * @param array|null $options
     *
     * @return stdClass
     *
     */
    public function create_instance($record = null, array $options = null) {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $record = (object) (array) $record;

        $defaultsettings = [
            'assessed' => 0,
            'showviews' => 'mybooking,myoptions,showall,showactive,myinstitution',
            'whichview' => 'showall',
            'optionsfields' => 'description,statusdescription,teacher,showdates,dayofweektime,
                                location,institution,minanswers',
            'reportsfields' => 'optionid,booking,institution,location,coursestarttime,
                                city,department,courseendtime,numrec,userid,username,
                                firstname,lastname,email,completed,waitinglist,status,
                                groups,notes,idnumber',
            'responsesfields' => 'completed,status,rating,numrec,fullname,timecreated,
                                institution,waitinglist,city,department,notes',
            'sendmail' => 1,

        ];

        foreach ($defaultsettings as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        return parent::create_instance($record, $options);
    }

    /**
     * Function to create a dummy option.
     *
     * @param array|stdClass $record
     * @return stdClass the booking option object
     */
    public function create_option($record = null) {
        global $DB;

        $record = (array) $record;

        if (!isset($record['bookingid'])) {
            throw new coding_exception(
                    'bookingid must be present in phpunit_util::create_option() $record');
        }

        if (!isset($record['text'])) {
            throw new coding_exception(
                    'text must be present in phpunit_util::create_option() $record');
        }

        if (!isset($record['courseid'])) {
            throw new coding_exception(
                    'courseid must be present in phpunit_util::create_option() $record');
        }

        $cmb1 = get_coursemodule_from_instance('booking', $record['bookingid'], $record['courseid']);
        if (!$context = context_module::instance($cmb1->id)) {
            throw new moodle_exception('badcontext');
        }

        // Increment the forum subscription count.
        $this->bookingoptions++;

        $record = (object) $record;

        // Finalizing object with required properties.
        $record->id = 0;
        $record->cmid = $cmb1->id;
        $record->identifier = booking_option::create_truly_unique_option_identifier();

        // Process option teachers.
        if (!empty($record->teachersforoption)) {
            $teacherarr = explode(',', $record->teachersforoption);
            $record->teachersforoption = [];
            foreach ($teacherarr as $teacher) {
                $record->teachersforoption[] = $this->get_user($teacher);
            }
        }

        if (!empty($record->semesterid)) {
            // Force $bookingsettings->semesterid by given $record->semesterid.
            $DB->set_field('booking', 'semesterid', $record->semesterid, ['id' => $record->bookingid]);
            // It might be necessary to reset cache.
            // phpcs:ignore
            //$semester = new semester($record->semesterid);
        }

        // Prepare pricef for being used in option(s) if exist.
        $pricecategories = $DB->get_records('booking_pricecategories', ['disabled' => 0]);
        if (!empty($pricecategories)) {
            foreach ($pricecategories as $pricecat) {
                $catname = "pricegroup_".$pricecat->identifier;
                // We apply default values only if form does not contain it.
                if (empty($record->{$catname})) {
                    $record->{$catname} = ["bookingprice_".$pricecat->identifier => (float) $pricecat->defaultvalue];
                }
            }
        }

        // Create / save booking option(s).
        if ($record->id = booking_option::update($record, $context)) {
            $record->optionid = $record->id;
            // Save customfield data to option (the id key has to be set to option id).
            $handler = booking_handler::create();
            $handler->instance_form_save($record, $record->optionid == -1);
        }

        return $record;
    }

    /**
     * Function to create a dummy pricecategory option.
     *
     * @param array|stdClass $record
     * @return stdClass the booking pricecategory object
     */
    public function create_pricecategory($record = null) {
        global $DB;

        $record = (object) $record;

        $record->id = $DB->insert_record('booking_pricecategories', $record);

        return $record;
    }

    /**
     * Function to create a dummy campaign option.
     *
     * @param array|stdClass $record
     * @return stdClass the booking campaign object
     */
    public function create_campaign($record = null) {
        global $DB;

        $record = (object) $record;

        $record->id = $DB->insert_record('booking_campaigns', $record);

        return $record;
    }


    /**
     * Function to create a dummy semester option.
     *
     * @param array|stdClass $record
     * @return stdClass the booking semester object
     */
    public function create_semester($record = null) {
        global $DB;

        $record = (object) $record;

        $record->id = $DB->insert_record('booking_semesters', $record);

        return $record;
    }

    /**
     * Function, to get userid
     * @param string $username
     * @return int
     */
    private function get_user(string $username) {
        global $DB;

        if (!$id = $DB->get_field('user', 'id', ['username' => $username])) {
            throw new Exception('The specified user with username "' . $username . '" does not exist');
        }
        return $id;
    }
}

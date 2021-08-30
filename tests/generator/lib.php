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
defined('MOODLE_INTERNAL') || die();

/**
 * mod_booking data generator
 *
 * @package mod_booking
 * @category test
 * @copyright 2017 Andraž Prinčič {@link https://www.princic.net}
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

    public function create_instance($record = null, array $options = null) {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $record = (object) (array) $record;

        if (!isset($record->assessed)) {
            $record->assessed = 0;
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

        // Increment the forum subscription count.
        $this->bookingoptions++;

        $record = (object) $record;

        // Add the subscription.
        $record->id = $DB->insert_record('booking_options', $record);

        return $record;
    }

    /**
     * Function, to add user to option
     * @param array|stdClass $record
     * @return stdClass the booking option object
     */
    public function add_user($record = null) {

    }
}

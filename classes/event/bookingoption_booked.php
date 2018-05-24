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
 * The bookingoption_booked event.
 *
 * @package mod_booking
 * @copyright 2014 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\event;

defined('MOODLE_INTERNAL') || die();


/**
 * The bookingoption_booked event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_booked extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_answers';
    }

    public static function get_name() {
        return get_string('eventbookingoption_booked', 'booking');
    }

    public function get_description() {
        if ($this->userid != $this->data['relateduserid']) {
            return "The user with id {$this->userid} booked the user with id {$this->data['other']['userid']} to the option with id  {$this->objectid}.";
        } else {
            return "The user with id {$this->userid} booked the booking option with id {$this->objectid}.";
        }
    }

    public function get_url() {
        return new \moodle_url('/mod/booking/view.php', array('id' => $this->contextinstanceid));
    }

    public function get_legacy_logdata() {
        // Override if you are migrating an add_to_log() call.
        return array($this->courseid, 'booking', 'book', 'choose',
            'view.php?id=' . $this->contextinstanceid, $this->objectid, $this->contextinstanceid);
    }

    public static function get_legacy_eventname() {
        // Override ONLY if you are migrating events_trigger() call.
        return 'choose';
    }

    protected function get_legacy_eventdata() {
        // Override if you migrating events_trigger() call.
        $data = new \stdClass();
        $data->id = $this->objectid;
        $data->userid = $this->relateduserid;
        return $data;
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
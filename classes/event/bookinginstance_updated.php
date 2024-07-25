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
 * The bookingoption_updated event.
 *
 * @package mod_booking
 * @copyright 2014 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;
use mod_booking\output\bookingoption_changes;
use mod_booking\singleton_service;

/**
 * The bookingoption_updated event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinginstance_updated extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'u'; // Meaning: u = update.
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('bookinginstanceupdated', 'booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {

        global $PAGE;

        $data = $this->get_data();

        $jsonstring = isset($data['other']) ? $data['other'] : '[]';

        if (gettype($jsonstring) == 'string') {
            $changes = (array) json_decode($jsonstring);
        }

        if (!empty($changes) && !empty($data['objectid'])) {
            $data = new bookingoption_changes($changes, $data['objectid']);
            $renderer = $PAGE->get_renderer('mod_booking');
            $html = $renderer->render_bookingoption_changes($data);
        } else {
            $html = '';
        }

        return "User with id '{$this->userid}' updated 'booking instance' with cmid '{$this->objectid}'." . $html;
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
     */
    public function get_url() {
        return new \moodle_url('/course/modedit.php', ['update' => $this->objectid]);
    }
}

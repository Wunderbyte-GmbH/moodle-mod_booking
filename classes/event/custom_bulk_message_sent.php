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
 * The custom_bulk_message_sent event class.
 * This event gets triggered when a custom message has been sent to
 * ALL booked users of a booking option.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The custom_bulk_message_sent event class.
 * This event gets triggered when a custom message has been sent to
 * more than 75% of all booked users (and at least 3 users) of a booking option.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_bulk_message_sent extends \core\event\base {

    /**
     * Init.
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_options';
    }

    /**
     * Get name.
     * @return string
     */
    public static function get_name() {
        return get_string('custombulkmessagesent', 'mod_booking');
    }

    /**
     * Get description.
     * @return string
     */
    public function get_description() {

        return
            "A custom bulk message e-mail with subject '" . $this->other['subject'] .
            "' has been sent to all users of booking option with id: '" . $this->other['optionid'] . "'.";
    }
}

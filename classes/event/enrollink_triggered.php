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
 * The enrollink_triggered event.
 *
 * @package mod_booking
 * @copyright 2024 Magdalena Holczik, info@wunderbyte.at
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

/**
 * The enrollink_triggered event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2024 Magdalena Holczik, info@wunderbyte.at
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrollink_triggered extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_options';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('enrollinktriggered', 'booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {

        return get_string('enrollinktriggered:description', 'mod_booking', $this->data);
    }

}
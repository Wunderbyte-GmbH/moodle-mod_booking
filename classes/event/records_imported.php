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
 * The testiteminscale_added event.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;
use moodle_url;

/**
 * The records_imported event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @copyright 2023 Wunderbyte <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class records_imported extends \core\event\base {

    /**
     * Init parameters.
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Get name.
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('recordsimported', 'mod_booking');
    }

    /**
     * Get description.
     *
     * @return string
     *
     */
    public function get_description() {
        $data = $this->data;
        if (is_string($data['other'])) {
            $otherarray = json_decode($data['other']);
            $itemcount = $otherarray->itemcount ?? 0;
        } else if (is_array($data['other'])) {
            $itemcount = $data['other']['itemcount'];
        } else {
            $itemcount = 0;
        }

        return get_string('recordsimporteddescription', 'mod_booking', $itemcount);
    }

    /**
     * Get url.
     *
     * @return object
     *
     */
    public function get_url() {
        return new moodle_url('');
    }
}

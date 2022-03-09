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
 * This file contains the definition for the renderable classes for bookingoption dates.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This file contains the definition for the renderable classes for bookingoption dates.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_dates implements renderable, templatable {

    /**
     * Constructor
     *
     * @param int $optionid
     */
    public function __construct(int $optionid) {

        $this->dates = $this->get_existing_optiondates($optionid);
    }

    /**
     * Returns an array of optiondates as stdClasses for a specific option id.
     *
     * @param int $optionid
     *
     * @return array
     */
    public function get_existing_optiondates(int $optionid): array {
        global $DB;

        $records = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);

        if (count($records) > 0) {

            foreach ($records as $record) {
                $date = new stdClass();
                $date->dateid = 'dateid-' . $record->id;
                $date->starttimestamp = $record->coursestarttime;
                $date->endtimestamp = $record->courseendtime;
                $date->string = date('Y-m-d i:s', $record->coursestarttime) . '-' . date('i:s', $record->courseendtime);
                $datearray[] = $date;
            }

            return $datearray;
        } else {
            return [];
        }
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return array(
            'dates' => $this->dates
        );
    }
}

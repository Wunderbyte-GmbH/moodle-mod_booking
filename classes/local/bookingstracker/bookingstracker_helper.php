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
 * Helper functions for the Bookings tracker (report2).
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use stdClass;
use moodle_url;
use mod_booking\booking;

/**
 * Helper functions for the Bookings tracker (report2).
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingstracker_helper {
    /**
     * Return option column.
     *
     * @param stdClass $values
     * @return string
     */
    public static function render_col_text(stdClass $values): string {
        global $OUTPUT, $SITE;

        if (empty($values->optionid)) {
            return '';
        }

        $optionlink = new moodle_url(
            '/mod/booking/view.php',
            [
                'id' => $values->cmid,
                'optionid' => $values->optionid,
                'whichview' => 'showonlyone',
            ]
        );

        $report2option = new moodle_url(
            '/mod/booking/report2.php',
            [
                'cmid' => $values->cmid,
                'optionid' => $values->optionid,
            ]
        );

        $report2instance = new moodle_url(
            '/mod/booking/report2.php',
            ['cmid' => $values->cmid]
        );

        $report2course = new moodle_url(
            '/mod/booking/report2.php',
            ['courseid' => $values->courseid]
        );

        $report2system = new moodle_url(
            '/mod/booking/report2.php'
        );

        $data = [
            'id' => $values->optionid,
            'text' => $values->text,
            'optionlink' => $optionlink->out(false),
            'report2option' => $report2option->out(false),
            'report2instance' => $report2instance->out(false),
            'report2course' => $report2course->out(false),
            'report2system' => $report2system->out(false),
            'instancename' => $values->instancename ? booking::shorten_text($values->instancename) : null,
            'coursename' => $values->coursename ? booking::shorten_text($values->coursename) : null,
            'systemname' => $SITE->fullname ? booking::shorten_text($SITE->fullname) : null,
        ];

        $output = $OUTPUT->render_from_template('mod_booking/report/option', $data);
        if (empty($output)) {
            return '';
        }
        return (string) $output;
    }
}

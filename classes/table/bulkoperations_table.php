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
 * Class bulkoperations_table.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;
use mod_booking\singleton_service;
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

use local_wunderbyte_table\wunderbyte_table;

/**
 * Class to handle event log table.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulkoperations_table extends wunderbyte_table {
    /**
     * Overrides the output for this column.
     * @param object $values
     * @return string
     */
    public function col_action($values) {
        global $PAGE, $OUTPUT;

        $returnurl = $PAGE->url->out(false);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($values->bookingid);

        $link = html_writer::link(
            new moodle_url('/mod/booking/editoptions.php', [
                'id' => $bookingsettings->cmid,
                'optionid' => $values->id,
                'returnto' => 'url',
                'returnurl' => $returnurl,
            ]),
            $OUTPUT->pix_icon('i/edit', get_string('editbookingoption', 'mod_booking')),
            [
                'target' => '_self',
                'class' => 'text-primary',
                'aria-label' => get_string('editbookingoption', 'mod_booking'),
            ]
        );

        return $link;
    }
}

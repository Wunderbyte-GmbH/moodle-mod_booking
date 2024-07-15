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
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/tablelib.php');

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

        $cmid = optional_param('id', 0, PARAM_INT);

        $url = new moodle_url('/mod/booking/editoptions.php', [
            'id' => $cmid,
            'optionid' => $values->id,
        ]);

        $urlout = $url->out();
        $string = get_string('editbookingoption', 'mod_booking');
        $link = '<a href="' . $urlout .
                '" ><i class="fa fa-pencil" aria-label="'. $string .'"></i></a>';
        return $link;
    }

}

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
 * Booking instance template settings table
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use moodle_url;
use table_sql;

/**
 * Class to handle booking instance template settings table
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinginstancetemplatessettings_table extends table_sql {

    /**
     * @var int
     */
    public $cmid = 0;

    /**
     * bookinginstancetemplatessettings_table constructor.
     *
     * @param string $uniqueid
     * @param int $cmid
     * @throws \coding_exception
     */
    public function __construct($uniqueid, $cmid) {
        global $DB;
        parent::__construct($uniqueid);
        $this->cmid = $cmid;

        // Define the list of columns to show.
        $columns = ['name', 'action'];
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = [get_string('bookinginstancetemplatename', 'mod_booking'), get_string('action')];
        $this->define_headers($headers);
    }

    /**
     * Display the booking instances where template is used.
     *
     * @param object $values
     * @return string
     */
    public function col_name($values) {
        return $values->name;
    }

    /**
     * Display actions for the templates (delete or edit)
     *
     * @param object $values
     * @return string
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function col_action($values) {
        global $OUTPUT;
        $output = '';
        $delete = get_string('delete');
        $url = new moodle_url('/mod/booking/bookinginstancetemplatessettings.php',
            ['templateid' => $values->id, 'action' => 'delete', 'id' => $this->cmid]);
        $output .= $OUTPUT->single_button($url, $delete, 'get');
        return $output;
    }
}

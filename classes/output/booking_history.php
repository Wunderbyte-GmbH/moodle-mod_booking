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
 * This file contains the definition for the renderable classes of
 * booking_history
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use local_wunderbyte_table\filters\types\standardfilter;
use mod_booking\table\booking_history_table;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes of
 * booking_history
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_history implements renderable, templatable {
    /** @var int $optionid */
    public $optionid = null;

    /**
     * Constructor takes the actions to render and saves them as array.
     *
     * @param int $optionid
     */
    public function __construct(int $optionid) {
        $this->optionid = $optionid;
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output) {
        return [
            'optionid' => $this->optionid,
            'bookinghistorytable' => $this->get_rendered_bookinghistorytable_for_optionid($this->optionid),
        ];
    }

    /**
     * Helper function to get a booking history table for the provided optionid.
     * @param int $optionid
     * @return string|null the rendered booking history table
     */
    private function get_rendered_bookinghistorytable_for_optionid(int $optionid) {
        $table = new booking_history_table("bookinghistorytable_" . $optionid);

        $fields = "s1.*";
        $from = "(
            SELECT
                bh.id,
                bh.userid,
                u.firstname,
                u.lastname,
                u.email,
                bh.status,
                bh.usermodified,
                bh.timecreated,
                bh.json
            FROM {booking_history} bh
            LEFT JOIN {user} u ON u.id = bh.userid
            WHERE bh.optionid = :optionid
            ORDER BY bh.id DESC
        ) s1";
        $where = "1=1";
        $params = ['optionid' => $optionid];

        $table->set_sql($fields, $from, $where, $params);
        $table->define_cache('mod_booking', 'bookinghistorytable');
        $table->use_pages = true;

        $columns = [
            'lastname',
            'firstname',
            'email',
            'status',
            'usermodified',
            'timecreated',
            'json',
        ];
        $table->define_columns($columns);

        $headers = [
            get_string('lastname'),
            get_string('firstname'),
            get_string('email'),
            get_string('status'),
            get_string('usermodified', 'mod_booking'),
            get_string('timecreated'),
            get_string('details', 'mod_booking'), // JSON.
        ];
        $table->define_headers($headers);

        // Add filters.
        $standardfilter = new standardfilter('lastname', get_string('lastname'));
        $table->add_filter($standardfilter);

        $sortablecolumns = [
            'lastname',
            'firstname',
            'email',
            'timecreated',
        ];
        $table->define_sortablecolumns($sortablecolumns);
        $table->tabletemplate = 'local_wunderbyte_table/twtable_list';

        return $table->outhtml(20, false);
    }
}

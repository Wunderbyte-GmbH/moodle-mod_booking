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

use context_system;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_table;
use moodle_exception;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for booked users.
 * It is used to display a slightly configurable list of booked users for a given booking option.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view implements renderable, templatable {

    /** @var string $renderedalloptionstable the rendered all options table */
    private $renderedalloptionstable = '';

    /**
     * Constructor
     *
     * @param int $optionid
     */
    public function __construct(int $cmid) {
        global $CFG;

        if (!$context = context_system::instance()) {
            throw new moodle_exception('badcontext');
        }

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $allbookingoptionstable = new bookingoptions_table('allbookingoptionstable', $booking);

        $allbookingoptionstable->set_sql('*', "{booking_options}", '1=1');
        $tablebaseurl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
        $tablebaseurl->remove_params('page');
        $allbookingoptionstable->define_baseurl($tablebaseurl);

        // Headers.
        $headers = [
            get_string('bookingoption', 'mod_booking'),
            get_string('teachers', 'mod_booking'),
            get_string('dayofweektime', 'mod_booking'),
            get_string('location', 'mod_booking'),
            get_string('bookings', 'mod_booking'),
            get_string('booknow', 'mod_booking'),
        ];
        if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context))) {
            $headers[] = get_string('edit', 'core');
        }
        $allbookingoptionstable->define_headers($headers);

        // Columns.
        $columns = [
            'text',
            'teacher',
            'dayofweektime',
            'location',
            'bookings',
            'booknow',
        ];
        if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context))) {
            $columns[] = 'action';
        }
        $allbookingoptionstable->define_columns($columns);

        /**/

        // Header column.
        $allbookingoptionstable->define_header_column('text');

            ob_start();
            $allbookingoptionstable->out(40, true);
            $this->renderedalloptionstable = ob_get_contents();
            ob_end_clean();
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return [
            'alloptionstable' => $this->renderedalloptionstable
        ];
    }
}

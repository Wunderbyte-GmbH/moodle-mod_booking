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
 * Handle option templates settings table.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

use moodle_url;
use table_sql;
use html_writer;

/**
 * Class to handle option templates settings table.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiontemplatessettings_table extends table_sql {

    /**
     * @var int
     */
    public $cmid = 0;

    /**
     * @var array of booking instances
     */
    public $bookinginstances = [];

    /**
     * optiontemplatessettings_table constructor.
     *
     * @param string $uniqueid
     * @param int $cmid
     * @throws \coding_exception
     */
    public function __construct($uniqueid, $cmid) {
        global $DB;
        parent::__construct($uniqueid);
        $this->cmid = $cmid;
        $this->bookinginstances = $DB->get_records_select('booking', 'templateid > 0', [], '', 'id, name, templateid');

        // Define the list of columns to show.
        $columns = ['name', 'options', 'action'];
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = [get_string('optiontemplatename', 'mod_booking'),
                    get_string('usedinbookinginstances', 'mod_booking'),
                    get_string('action'),
                    ];
        $this->define_headers($headers);
    }

    /**
     * Display the booking instances where template is used.
     *
     * @param object $values
     * @return string
     */
    public function col_options($values) {
        global $DB;
        $output = '';
        if (!empty($this->bookinginstances)) {
            foreach ($this->bookinginstances as $instance) {
                if ($instance->templateid == $values->optionid) {
                    // TODO: Replace DB query with something more performant.
                    if ($DB->record_exists('course_modules', ['id' => $instance->id])) {
                        list($course, $cm) = get_course_and_cm_from_cmid($instance->id);
                        $url = new moodle_url('/mod/booking/view.php', ['id' => $cm->id]);
                        $linktobinstance = html_writer::link($url, $instance->name);
                        $newline = html_writer::empty_tag('br');
                        $output .= $linktobinstance . $newline;
                    }
                }
            }
        }
        return $output;
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
        $url = new moodle_url('/mod/booking/optiontemplatessettings.php',
            ['optionid' => $values->optionid, 'action' => 'delete', 'id' => $this->cmid]);
        $output .= $OUTPUT->single_button($url, $delete, 'get');
        $edit = get_string('edit');
        $url = new moodle_url('/mod/booking/editoptions.php', ['optionid' => $values->optionid, 'id' => $this->cmid, 'addastemplate' => '1']);
        $output .= $OUTPUT->single_button($url, $edit, 'get');
        return $output;
    }
}

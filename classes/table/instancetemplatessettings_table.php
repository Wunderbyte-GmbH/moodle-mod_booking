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
 * Handle instance templates settings table.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

use moodle_url;
use table_sql;

/**
 * Class to handle instance templates settings table.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instancetemplatessettings_table extends table_sql {
    /**
     * $instance templates
     *
     * @var mixed
     */
    private $instancetemplates;

    /**
     * instancetemplatessettings_table constructor.
     *
     * @param string $uniqueid
     * @throws \coding_exception
     */
    public function __construct($uniqueid) {
        global $DB;
        parent::__construct($uniqueid);

        $this->instancetemplates = $DB->get_records('booking_instancetemplate');

        // Define the list of columns to show.
        $columns = ['name', 'action'];
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = [get_string('bookinginstancetemplatename', 'mod_booking'), get_string('action')];
        $this->define_headers($headers);
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

        $deletelabel = get_string('delete');
        $deleteurl = new moodle_url('/mod/booking/instancetemplatessettings.php', ['delete' => $values->id]);
        $output .= $OUTPUT->single_button($deleteurl, $deletelabel, 'get');

        // Todo: Editor for instance templates is not yet implemented.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $edit = get_string('edit');
        $url = new moodle_url('/mod/booking/edit_optiontemplates.php', array('optionid' => $values->optionid, 'id' => $this->cmid));
        $output .= $OUTPUT->single_button($url, $edit, 'get'); */

        return $output;
    }
}

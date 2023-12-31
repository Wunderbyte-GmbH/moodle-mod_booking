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
 * Moodle form for teachers instance report to choose a specific teacher.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/formslib.php');

use moodleform;

/**
 * Moodle form for teachers instance report to choose a specific teacher.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teachers_instance_report_form extends moodleform {

    /**
     * Defines the form fields.
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('hidden', 'cmid', 0);
        $mform->setType('cmid', PARAM_INT);

        $teachersarr = [
            0 => get_string('allteachers', 'mod_booking'),
        ];
        if ($teacherrecords = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
            FROM {booking_teachers} bt
            JOIN {user} u
            ON u.id = bt.userid"
        )) {
            foreach ($teacherrecords as $t) {
                $teachersarr[$t->id] = "$t->firstname $t->lastname ($t->email)";
            }
        }

        $teacheridoptions = [
            'tags' => false,
            'multiple' => false,
        ];
        $mform->addElement('autocomplete', 'teacherid', get_string('teacher', 'mod_booking'), $teachersarr, $teacheridoptions);
        $mform->setType('teacherid', PARAM_INT);

        $this->add_action_buttons(false, get_string('filterbtn', 'booking'));
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {

        $errors = [];
        return $errors;
    }
}

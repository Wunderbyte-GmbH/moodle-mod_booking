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
 * Confirm activity form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_booking\utils\db;

/**
 * Class to handle the confirm activity form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmactivity extends \moodleform {

    /**
     * Definitiion.
     * @return void
     */
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form; // Don't forget the underscore!

        $radioarray = [];
        $radioarray[] = $mform->createElement('radio', 'whichtype', '', get_string('badges'), 1, []);
        $radioarray[] = $mform->createElement('radio', 'whichtype', '', get_string('activity'), 0, []);
        $mform->addGroup($radioarray, 'radioar', get_string('confirmactivtyfrom', 'booking'), [' '], false);

        $dbutill = new db();
        $badges = $dbutill->getbadges($this->_customdata['course']->id);

        $mform->addElement('select', 'certid', get_string('badges'), $badges);
        $mform->disabledIf('certid', 'whichtype', 'eq', 0);

        $activity = [];
        $info = get_fast_modinfo($this->_customdata['course']);
        foreach ($info->get_cms() as $cminfo) {
            if ($cminfo->uservisible == 1 && $cminfo->get_course_module_record()->completion > 0) {
                $activity[$cminfo->id] = $cminfo->get_formatted_name();
            }
        }

        $mform->addElement('select', 'activity', get_string('activity'), $activity);
        $mform->disabledIf('activity', 'whichtype', 'eq', 1);

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('confirmusers', 'booking'));
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
    }

    /**
     * Custom validation should be added here.
     *
     * @param array $data
     * @param array $files
     *
     * @return array
     *
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        return $errors;
    }
}

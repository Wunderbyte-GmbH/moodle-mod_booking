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
 * Moodle form for booking option cohort and group subscription.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/formslib.php');

use moodleform;

/**
 * Moodle form for booking option cohort and group subscription.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher_performed_units_report_form extends moodleform {

    /**
     * Defines the form fields.
     */
    public function definition() {

        $mform = $this->_form;

        $mform->addElement('hidden', 'teacherid', 0);
        $mform->setType('teacherid', PARAM_INT);

        // Cohort subscription header.
        $mform->addElement('date_selector', 'filterstartdate', get_string('filterstartdate', 'mod_booking'));
        $mform->setType('filterstartdate', PARAM_INT);

        $mform->addElement('date_selector', 'filterenddate', get_string('filterenddate', 'mod_booking'));
        $mform->setType('filterenddate', PARAM_INT);

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

        if ($data['filterstartdate'] > $data['filterenddate']) {
            $errors['filterstartdate'] = get_string('erroroptiondatestart', 'mod_booking');
            $errors['filterenddate'] = get_string('erroroptiondateend', 'mod_booking');
        }

        return $errors;
    }
}

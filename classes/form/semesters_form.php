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

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use moodleform;
use stdClass;

/**
 * Add price categories form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class semesters_form extends moodleform {

    // TODO...

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        // Loop through already existing semesters.
        $semesters = $DB->get_records_sql("SELECT * FROM {booking_semesters}");
        $i = 1;
        foreach ($semesters as $semester) {

            // Use 2 digits, so we can have up to 99 semesters.
            $j = sprintf('%02d', $i);

            $mform->addElement('hidden', 'semesterid' . $j, $semester->id);
            $mform->setType('semesterid' . $j, PARAM_INT);

            $mform->addElement('text', 'semesteridentifier' . $j, get_string('semesteridentifier', 'booking') . ' ' . $i);
            $mform->setType('semesteridentifier' . $j, PARAM_TEXT);
            $mform->setDefault('semesteridentifier' . $j, $semester->identifier);
            $mform->addHelpButton('semesteridentifier' . $j, 'semesteridentifier', 'booking');
            $mform->disabledIf('semesteridentifier' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('text', 'semestername' . $j, get_string('semestername', 'booking'));
            $mform->setType('semestername' . $j, PARAM_RAW);
            $mform->setDefault('semestername' . $j, $semester->name);
            $mform->addHelpButton('semestername' . $j, 'semestername', 'booking');
            $mform->disabledIf('semestername' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterstart' . $j, get_string('semesterstart', 'booking'));
            $mform->addHelpButton('semesterstart' . $j, 'semesterstart', 'booking');
            $mform->setDefault('semesterstart' . $j, $semester->start);
            $mform->disabledIf('semesterstart' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterend' . $j, get_string('semesterend', 'booking'));
            $mform->addHelpButton('semesterend' . $j, 'semesterend', 'booking');
            $mform->setDefault('semesterend' . $j, $semester->end);
            $mform->disabledIf('semesterend' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('advcheckbox', 'deletesemester' . $j,
                get_string('deletesemester', 'booking') . ' ' . $j, null, null, [0, 1]);
            $mform->setDefault('deletesemester' . $j, 0);
            $mform->addHelpButton('deletesemester' . $j, 'deletesemester', 'booking');

            $i++;
        }

        // Now, if there are less than the maximum number of semesters allow adding additional ones.
        if (count($semesters) < MAX_SEMESTERS) {
            // Between 1 to 99 semesters are supported.
            $start = count($semesters) + 1;
            $this->add_semesters($mform, $start);
        }

        // Add "Save" and "Cancel" buttons.
        $this->add_action_buttons(true);
    }

    /**
     * Helper function to create form elements for adding semesters.
     *
     * @param int $i if there already are existing semesters start with the succeeding number.
     */
    public function add_semesters($mform, $i = 1) {

        // Use 2 digits, so we can have up to 99 semesters.
        $j = sprintf('%02d', $i);

        // Add checkbox to add first semester.
        $mform->addElement('checkbox', 'addsemester' . $j, get_string('addsemester', 'booking'));

        while ($i <= MAX_SEMESTERS) {

            // Use 2 digits, so we can have up to 99 semesters.
            $j = sprintf('%02d', $i);

            // New elements have a default semesterid of 0.
            $mform->addElement('hidden', 'semesterid' . $j, 0);
            $mform->setType('semesterid' . $j, PARAM_INT);

            $mform->addElement('text', 'semesteridentifier' . $j,
                get_string('semesteridentifier', 'booking') . ' ' . $i);
            $mform->setType('semesteridentifier' . $j, PARAM_TEXT);
            $mform->addHelpButton('semesteridentifier' . $j, 'semesteridentifier', 'booking');
            $mform->hideIf('semesteridentifier' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semesteridentifier' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('text', 'semestername' . $j, get_string('semestername', 'booking'));
            $mform->setType('semestername' . $j, PARAM_RAW);
            $mform->setDefault('semestername' . $j, '');
            $mform->addHelpButton('semestername' . $j, 'semestername', 'booking');
            $mform->hideIf('semestername' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semestername' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterstart' . $j, get_string('semesterstart', 'booking'));
            $mform->addHelpButton('semesterstart' . $j, 'semesterstart', 'booking');
            $mform->hideIf('semesterstart' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semesterstart' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterend' . $j, get_string('semesterend', 'booking'));
            $mform->addHelpButton('semesterend' . $j, 'semesterend', 'booking');
            $mform->hideIf('semesterend' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semesterend' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('advcheckbox', 'deletesemester' . $j,
                get_string('deletesemester', 'booking') . ' ' . $j, null, null, [0, 1]);
            $mform->setDefault('deletesemester' . $j, 0);
            $mform->addHelpButton('deletesemester' . $j, 'deletesemester', 'booking');
            $mform->hideIf('deletesemester' . $j, 'addsemester' . $j, 'notchecked');

            // Show checkbox to add a semester.
            if ($i < MAX_SEMESTERS) {
                $next = sprintf('%02d', $i + 1); // Use two digits.

                $mform->addElement('checkbox', 'addsemester' . $next, get_string('addsemester', 'booking'));
                $mform->hideIf('addsemester' . $next, 'addsemester' . $j, 'notchecked');
            }

            $i++;
        }
    }

    /**
     * Validate price categories.
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {

        global $DB;

        $errors = array();

        // TODO: Continue here!

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*
        // Validate price categories.
        for ($i = 1; $i <= MAX_PRICE_CATEGORIES; $i++) {

            if (isset($data['pricecategoryidentifier' . $i])) {
                $pricecategoryidentifierx = $data['pricecategoryidentifier' . $i];
                $pricecategorynamex = $data['pricecategoryname' . $i];
                $defaultvaluex = $data['defaultvalue' . $i];

                // The price category identifier is not allowed to be empty.
                if (empty($pricecategoryidentifierx)) {
                    $errors['pricecategoryidentifier' . $i] = get_string('erroremptypricecategoryidentifier', 'booking');
                }

                // The price category name is not allowed to be empty.
                if (empty($pricecategorynamex)) {
                    $errors['pricecategoryname' . $i] = get_string('erroremptypricecategoryname', 'booking');
                }

                // Not more than 2 decimals are allowed for the default price.
                if (!empty($defaultvaluex) && is_float($defaultvaluex)) {
                    $numberofdecimals = strlen(substr(strrchr($defaultvaluex, "."), 1));
                    if ($numberofdecimals > 2) {
                        $errors['defaultvalue' . $i] = get_string('errortoomanydecimals', 'booking');
                    }
                }

                // The identifier of a price category needs to be unique.
                $records = $DB->get_records('booking_pricecategories', ['identifier' => $pricecategoryidentifierx]);
                if (count($records) > 1) {
                    $errors['pricecategoryidentifier' . $i] = get_string('errorduplicatepricecategoryidentifier', 'booking');
                }

                // The name of a price category needs to be unique.
                $records = $DB->get_records('booking_pricecategories', ['name' => $pricecategorynamex]);
                if (count($records) > 1) {
                    $errors['pricecategoryname' . $i] = get_string('errorduplicatepricecategoryname', 'booking');
                }
            }
        }
        */
        return $errors;
    }

    /**
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}

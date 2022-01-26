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

/**
 * Add price categories form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pricecategories_form extends moodleform {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        // At first, loop through already existing price categories.
        $pricecategories = $DB->get_records('booking_pricecategories');

        $j = 1;
        foreach ($pricecategories as $pricecategory) {
            $mform->addElement('hidden', 'pricecategoryid' . $j, $pricecategory->id);
            $mform->setType('pricecategoryid' . $j, PARAM_INT);

            $mform->addElement('text', 'pricecategoryname' . $j, get_string('pricecategoryname', 'booking') . ' ' . $j);
            $mform->setType('pricecategoryname' . $j, PARAM_TEXT);
            $mform->setDefault('pricecategoryname' . $j, $pricecategory->pricecategory);
            $mform->addHelpButton('pricecategoryname' . $j, 'pricecategoryname', 'booking');

            $mform->addElement('textarea', 'pricecategorydescription' . $j,
                get_string('pricecategorydescription', 'booking'), 'wrap="virtual" rows="1" cols="65"');
            $mform->setType('pricecategorydescription' . $j, PARAM_RAW);
            $mform->setDefault('pricecategorydescription' . $j, $pricecategory->description);
            $mform->addHelpButton('pricecategorydescription' . $j, 'pricecategorydescription', 'booking');

            $mform->addElement('float', 'defaultvalue' . $j, get_string('defaultvalue', 'booking'), null);
            $mform->setDefault('defaultvalue' . $j, $pricecategory->defaultvalue);
            $mform->addHelpButton('defaultvalue' . $j, 'defaultvalue', 'booking');

            $mform->addElement('advcheckbox', 'disablepricecategory' . $j,
                get_string('disablepricecategory', 'booking') . ' ' . $j, null, null, [0, 1]);
            $mform->setDefault('disablepricecategory' . $j, $pricecategory->disabled);
            $mform->addHelpButton('disablepricecategory' . $j, 'disablepricecategory', 'booking');

            $j++;
        }

        // Now, if there are less than the maximum number of price category fields allow adding additional ones.
        if (count($pricecategories) < MAX_PRICE_CATEGORIES) {
            // Between one to nine price categories are supported.
            $start = count($pricecategories) + 1;
            $this->addpricecategories($mform, $start);
        }

        $mform->addElement('submit', 'submitbutton', get_string('save'));
    }

    /**
     * Helper function to create form elements for adding price categories.
     * @param int $counter if there already are existing price categories start with the succeeding number
     */
    public function addpricecategories($mform, $counter = 1) {
        global $CFG;

        // Add checkbox to add first price category.
        $mform->addElement('checkbox', 'addpricecategory' . $counter, get_string('addpricecategory', 'booking'));

        while ($counter <= MAX_PRICE_CATEGORIES) {
            // New elements have a default pricecategoryid of 0.
            $mform->addElement('hidden', 'pricecategoryid' . $counter, 0);
            $mform->setType('pricecategoryid' . $counter, PARAM_INT);

            $mform->addElement('text', 'pricecategoryname' . $counter, get_string('pricecategoryname', 'booking') . ' ' . $counter);
            $mform->setType('pricecategoryname' . $counter, PARAM_TEXT);
            $mform->addHelpButton('pricecategoryname' . $counter, 'pricecategoryname', 'booking');
            $mform->hideIf('pricecategoryname' . $counter, 'addpricecategory' . $counter, 'notchecked');

            $mform->addElement('textarea', 'pricecategorydescription' . $counter,
                get_string('pricecategorydescription', 'booking'), 'wrap="virtual" rows="1" cols="65"');
            $mform->setType('pricecategorydescription' . $counter, PARAM_RAW);
            $mform->setDefault('pricecategorydescription' . $counter, '');
            $mform->addHelpButton('pricecategorydescription' . $counter, 'pricecategorydescription', 'booking');
            $mform->hideIf('pricecategorydescription' . $counter, 'addpricecategory' . $counter, 'notchecked');

            $mform->addElement('float', 'defaultvalue' . $counter, get_string('defaultvalue', 'booking'), null);
            $mform->setDefault('defaultvalue' . $counter, 0.00);
            $mform->addHelpButton('defaultvalue' . $counter, 'defaultvalue', 'booking');
            $mform->hideIf('defaultvalue' . $counter, 'addpricecategory' . $counter, 'notchecked');

            $mform->addElement('advcheckbox', 'disablepricecategory' . $counter,
                get_string('disablepricecategory', 'booking') . ' ' . $counter, null, null, [0, 1]);
            $mform->setDefault('disablepricecategory' . $counter, 0);
            $mform->addHelpButton('disablepricecategory' . $counter, 'disablepricecategory', 'booking');
            $mform->hideIf('disablepricecategory' . $counter, 'addpricecategory' . $counter, 'notchecked');

            // Show checkbox to add a price category.
            if ($counter < MAX_PRICE_CATEGORIES) {
                $mform->addElement('checkbox', 'addpricecategory' . ($counter + 1), get_string('addpricecategory', 'booking'));
                $mform->hideIf('addpricecategory' . ($counter + 1), 'addpricecategory' . $counter, 'notchecked');
            }
            ++$counter;
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

        // Validate price categories.
        for ($i = 1; $i <= MAX_PRICE_CATEGORIES; $i++) {

            if (isset($data['pricecategoryname' . $i])) {
                $pricecategorynamex = $data['pricecategoryname' . $i];
                $defaultvaluex = $data['defaultvalue' . $i];

                // The price category name is not allowed to be empty.
                if (empty($pricecategorynamex)) {
                    $errors['pricecategoryname' . $i] = get_string('erroremptypricecategory', 'booking');
                }

                // Not more than 2 decimals are allowed for the default price.
                if (!empty($defaultvaluex) && is_float($defaultvaluex)) {
                    $numberofdecimals = strlen(substr(strrchr($defaultvaluex, "."), 1));
                    if ($numberofdecimals > 2) {
                        $errors['defaultvalue' . $i] = get_string('errortoomanydecimals', 'booking');
                    }
                }

                // The name of a price category needs to be unique.
                $records = $DB->get_records('booking_pricecategories', ['pricecategory' => $pricecategorynamex]);
                if (count($records) > 1) {
                    $errors['pricecategoryname' . $i] = get_string('errorduplicatepricecategory', 'booking');
                }
            }
        }
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

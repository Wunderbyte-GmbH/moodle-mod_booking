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
 * Price categories form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


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
class pricecategories_form extends moodleform {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        // Default price always needs to be there, ordernum has to be 1 and identifier has to be 'default'.
        $defaultprice = $DB->get_record_sql("SELECT * FROM {booking_pricecategories} WHERE identifier = 'default'");
        if (empty($defaultprice)) {
            $defaultprice = new stdClass;
            $defaultprice->ordernum = 1;
            $defaultprice->identifier = 'default';
            $defaultprice->name = get_string('price', 'booking');
            $defaultprice->defaultvalue = 0;
            $defaultprice->pricecatsortorder = 0;
            $defaultprice->disabled = 0; // Default price cannot be disabled.

            $defaultprice->id = $DB->insert_record('booking_pricecategories', $defaultprice);
        }

        $mform->addElement('hidden', 'pricecategoryid1', $defaultprice->id);
        $mform->setType('pricecategoryid1', PARAM_INT);

        $mform->addElement('hidden', 'pricecategoryordernum1', 1); // Order number is 1 for the default price.
        $mform->setType('pricecategoryordernum1', PARAM_INT);

        $mform->addElement('hidden', 'disablepricecategory1', 0); // Default price cannot be disabled.
        $mform->setType('disablepricecategory1', PARAM_INT);

        $mform->addElement('hidden', 'pricecategoryidentifier1', 'default'); // Identifier of the default price is 'default'.
        $mform->setType('pricecategoryidentifier1', PARAM_TEXT);

        $mform->addElement('text', 'pricecategoryname1', get_string('defaultpricecategoryname', 'booking'));
        $mform->setType('pricecategoryname1', PARAM_TEXT);
        $mform->setDefault('pricecategoryname1', $defaultprice->name);
        $mform->addHelpButton('pricecategoryname1', 'pricecategoryname', 'booking');

        $mform->addElement('float', 'defaultvalue1', get_string('defaultvalue', 'booking'), null);
        $mform->setDefault('defaultvalue1', $defaultprice->defaultvalue);
        $mform->addHelpButton('defaultvalue1', 'defaultvalue', 'booking');

        $mform->addElement('text', 'pricecatsortorder1', get_string('pricecatsortorder', 'mod_booking'));
        $mform->setType('pricecatsortorder1', PARAM_INT);
        $mform->setDefault('pricecatsortorder1', $defaultprice->pricecatsortorder);
        $mform->addHelpButton('pricecatsortorder1', 'pricecatsortorder', 'mod_booking');

        $mform->addElement('header', 'additionalpricecategories', get_string('additionalpricecategories', 'booking'));

        // Now, loop through already existing price categories.
        $pricecategories = $DB->get_records_sql("SELECT * FROM {booking_pricecategories} WHERE identifier <> 'default'");
        $j = 2;
        foreach ($pricecategories as $pricecategory) {
            $mform->addElement('hidden', 'pricecategoryid' . $j, $pricecategory->id);
            $mform->setType('pricecategoryid' . $j, PARAM_INT);

            $mform->addElement('hidden', 'pricecategoryordernum' . $j, $j);
            $mform->setType('pricecategoryordernum' . $j, PARAM_INT);

            $mform->addElement('text', 'pricecategoryidentifier' . $j, get_string('pricecategoryidentifier', 'booking') . ' ' . $j);
            $mform->setType('pricecategoryidentifier' . $j, PARAM_TEXT);
            $mform->setDefault('pricecategoryidentifier' . $j, $pricecategory->identifier);
            $mform->addHelpButton('pricecategoryidentifier' . $j, 'pricecategoryidentifier', 'booking');
            $mform->disabledIf('pricecategoryidentifier' . $j, 'disablepricecategory' . $j, 'checked');

            $mform->addElement('text', 'pricecategoryname' . $j, get_string('pricecategoryname', 'booking'));
            $mform->setType('pricecategoryname' . $j, PARAM_RAW);
            $mform->setDefault('pricecategoryname' . $j, $pricecategory->name);
            $mform->addHelpButton('pricecategoryname' . $j, 'pricecategoryname', 'booking');
            $mform->disabledIf('pricecategoryname' . $j, 'disablepricecategory' . $j, 'checked');

            $mform->addElement('float', 'defaultvalue' . $j, get_string('defaultvalue', 'booking'), null);
            $mform->setDefault('defaultvalue' . $j, $pricecategory->defaultvalue);
            $mform->addHelpButton('defaultvalue' . $j, 'defaultvalue', 'booking');
            $mform->disabledIf('defaultvalue' . $j, 'disablepricecategory' . $j, 'checked');

            $mform->addElement('text', 'pricecatsortorder' . $j, get_string('pricecatsortorder', 'mod_booking'));
            $mform->setType('pricecatsortorder' . $j, PARAM_INT);
            $mform->setDefault('pricecatsortorder' . $j, $pricecategory->pricecatsortorder);
            $mform->addHelpButton('pricecatsortorder' . $j, 'pricecatsortorder', 'mod_booking');
            $mform->disabledIf('pricecatsortorder' . $j, 'disablepricecategory' . $j, 'checked');

            $mform->addElement('advcheckbox', 'disablepricecategory' . $j,
                get_string('disablepricecategory', 'booking') . ' ' . $j, null, null, [0, 1]);
            $mform->setDefault('disablepricecategory' . $j, $pricecategory->disabled);
            $mform->addHelpButton('disablepricecategory' . $j, 'disablepricecategory', 'booking');

            $j++;
        }

        // Now, if there are less than the maximum number of price category fields allow adding additional ones.
        if (count($pricecategories) < MOD_BOOKING_MAX_PRICE_CATEGORIES) {
            // Between one to nine price categories are supported.
            $start = count($pricecategories) + 2;
            $this->addpricecategories($mform, $start);
        }

        // Add "Save" and "Cancel" buttons.
        $this->add_action_buttons(true);
    }

    /**
     * Helper function to create form elements for adding price categories.
     * Start with 2, because 1 is the default price.
     *
     * @param mixed $mform
     * @param int $counter if there already are existing price categories start with the succeeding number
     *
     * @return void
     *
     */
    public function addpricecategories($mform, $counter = 2) {

        // Add checkbox to add first price category.
        $mform->addElement('checkbox', 'addpricecategory' . $counter, get_string('addpricecategory', 'booking'));

        while ($counter <= MOD_BOOKING_MAX_PRICE_CATEGORIES) {
            // New elements have a default pricecategoryid of 0.
            $mform->addElement('hidden', 'pricecategoryid' . $counter, 0);
            $mform->setType('pricecategoryid' . $counter, PARAM_INT);

            $mform->addElement('hidden', 'pricecategoryordernum' . $counter, $counter);
            $mform->setType('pricecategoryordernum' . $counter, PARAM_INT);

            $mform->addElement('text', 'pricecategoryidentifier' . $counter,
                get_string('pricecategoryidentifier', 'booking') . ' ' . $counter);
            $mform->setType('pricecategoryidentifier' . $counter, PARAM_TEXT);
            $mform->addHelpButton('pricecategoryidentifier' . $counter, 'pricecategoryidentifier', 'booking');
            $mform->hideIf('pricecategoryidentifier' . $counter, 'addpricecategory' . $counter, 'notchecked');
            $mform->disabledIf('pricecategoryidentifier' . $counter, 'disablepricecategory' . $counter, 'checked');

            $mform->addElement('text', 'pricecategoryname' . $counter, get_string('pricecategoryname', 'booking'));
            $mform->setType('pricecategoryname' . $counter, PARAM_RAW);
            $mform->setDefault('pricecategoryname' . $counter, '');
            $mform->addHelpButton('pricecategoryname' . $counter, 'pricecategoryname', 'booking');
            $mform->hideIf('pricecategoryname' . $counter, 'addpricecategory' . $counter, 'notchecked');
            $mform->disabledIf('pricecategoryname' . $counter, 'disablepricecategory' . $counter, 'checked');

            $mform->addElement('float', 'defaultvalue' . $counter, get_string('defaultvalue', 'booking'), null);
            $mform->setDefault('defaultvalue' . $counter, 0.00);
            $mform->addHelpButton('defaultvalue' . $counter, 'defaultvalue', 'booking');
            $mform->hideIf('defaultvalue' . $counter, 'addpricecategory' . $counter, 'notchecked');
            $mform->disabledIf('defaultvalue' . $counter, 'disablepricecategory' . $counter, 'checked');

            $mform->addElement('text', 'pricecatsortorder' . $counter, get_string('pricecatsortorder', 'mod_booking'));
            $mform->setType('pricecatsortorder' . $counter, PARAM_INT);
            $mform->setDefault('pricecatsortorder' . $counter, "0");
            $mform->addHelpButton('pricecatsortorder' . $counter, 'pricecatsortorder', 'mod_booking');
            $mform->hideIf('pricecatsortorder' . $counter, 'addpricecategory' . $counter, 'notchecked');
            $mform->disabledIf('pricecatsortorder' . $counter, 'disablepricecategory' . $counter, 'checked');

            $mform->addElement('advcheckbox', 'disablepricecategory' . $counter,
                get_string('disablepricecategory', 'booking') . ' ' . $counter, null, null, [0, 1]);
            $mform->setDefault('disablepricecategory' . $counter, 0);
            $mform->addHelpButton('disablepricecategory' . $counter, 'disablepricecategory', 'booking');
            $mform->hideIf('disablepricecategory' . $counter, 'addpricecategory' . $counter, 'notchecked');

            // Show checkbox to add a price category.
            if ($counter < MOD_BOOKING_MAX_PRICE_CATEGORIES) {
                $mform->addElement('checkbox', 'addpricecategory' . ($counter + 1), get_string('addpricecategory', 'booking'));
                $mform->hideIf('addpricecategory' . ($counter + 1), 'addpricecategory' . $counter, 'notchecked');
            }
            ++$counter;
        }
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

        global $DB;

        $errors = [];

        // Validate price categories.
        for ($i = 1; $i <= MOD_BOOKING_MAX_PRICE_CATEGORIES; $i++) {

            if (isset($data['pricecategoryidentifier' . $i])) {
                $pricecategoryidentifierx = $data['pricecategoryidentifier' . $i];
                $pricecategorynamex = $data['pricecategoryname' . $i];
                $defaultvaluex = $data['defaultvalue' . $i];
                $pricecatsortorderx = $data['pricecatsortorder' . $i];

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

                if (empty($pricecatsortorderx)) {
                    $errors['pricecatsortorder' . $i] = get_string('error:entervalue', 'mod_booking');
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

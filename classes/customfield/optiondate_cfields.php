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
 * Course handler for custom fields
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\customfield;

use dml_exception;
use stdClass;

/**
 * Handler for booking optiondate custom fields.
 *
 * @package   mod_booking
 * @copyright 2023 Wunderbyte
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate_cfields {

    /**
     * Helper function to create form elements for adding custom fields.
     *
     * @param mixed $mform
     * @param array $elements
     * @param int $counter if there already are existing custom fields start with the succeeding number
     * @param int $index
     * @param array $customfields
     *
     * @return void
     *
     */
    public static function instance_form_definition(
        &$mform,
        array &$elements,
        $counter = 1,
        $index = 0,
        array $customfields = []
    ) {
        global $CFG;

        $identifier = $index . '_' . $counter;

        // Add checkbox to add first customfield.
        $element = $mform->addElement(
            'checkbox',
            'addcustomfield_' . $identifier,
            get_string('addcustomfieldorcomment', 'mod_booking'));
        if (empty($customfields)) {
            $element->setValue(0);
        }
        $elements[] = $element;

        // Add Autocomplete with TeamsMeeting etc.
        $cfnames = [
            'addcomment' => get_string('addcomment', 'mod_booking'),
            'teamsmeeting' => get_string('teamsmeeting', 'mod_booking'),
            'zoommeeting' => get_string('zoommeeting', 'mod_booking'),
            'bigbluebuttonmeeting' => get_string('bigbluebuttonmeeting', 'mod_booking'),
        ];
        $options = [
                'noselectionstring' => get_string('nocfnameselected', 'mod_booking'),
                'tags' => true,
                'multiple' => false,
        ];

        while ($counter <= MOD_BOOKING_MAX_CUSTOM_FIELDS) {

            if (!empty($customfields)) {
                $customfield = array_shift($customfields);
            } else {
                unset($customfield);
            }

            $identifier = $index . '_' . $counter;

            // New elements have a default customfieldid of 0.
            $element = $mform->addElement('hidden', 'customfieldid_' . $identifier);
            $mform->setType('customfieldid_' . $identifier, PARAM_INT);
            $element->setValue($customfield['id'] ?? 0);
            $elements[] = $element;

            $element = $mform->addElement('autocomplete', 'customfieldname_' . $identifier,
                get_string('customfieldname', 'mod_booking'), $cfnames, $options);
            if (!empty($CFG->formatstringstriptags)) {
                $mform->setType('customfieldname_' . $identifier, PARAM_TEXT);
            } else {
                $mform->setType('customfieldname_' . $identifier, PARAM_CLEANHTML);
            }
            $mform->addHelpButton('customfieldname_' . $identifier, 'customfieldname', 'booking');
            $mform->hideIf('customfieldname_' . $identifier, 'addcustomfield_' . $identifier, 'notchecked');
            $element->setValue($customfield['cfgname'] ?? '');
            $elements[] = $element;

            $element = $mform->addElement('textarea', 'customfieldvalue_' . $identifier,
                get_string('customfieldvalue', 'mod_booking'), 'wrap="virtual" rows="1" cols="65"');
            $mform->setType('customfieldvalue_' . $identifier, PARAM_RAW);
            $mform->addHelpButton('customfieldvalue_' . $identifier, 'customfieldvalue', 'booking');
            $mform->hideIf('customfieldvalue_' . $identifier, 'addcustomfield_' . $identifier, 'notchecked');
            $element->setValue($customfield['value'] ?? '');
            $elements[] = $element;

            // Set delete parameter to 0 for newly created fields, so they won't be deleted.
            $elements[] = $mform->addElement('hidden', 'deletecustomfield_' . $identifier, 0);
            $mform->setType('deletecustomfield_' . $identifier, PARAM_INT);

            // Show checkbox to add a custom field.
            if ($counter < MOD_BOOKING_MAX_CUSTOM_FIELDS) {

                $nextidentifier = $index . '_' . ($counter + 1);

                $element = $mform->addElement(
                    'checkbox',
                    'addcustomfield_' . $nextidentifier,
                    get_string('addcustomfieldorcomment', 'mod_booking'));
                $mform->hideIf('addcustomfield_' . $nextidentifier, 'addcustomfield_' . $identifier, 'notchecked');

                if (empty($customfields)) {
                    $element->setValue(0);
                }
                $elements[] = $element;
            }
            ++$counter;
        }
    }

    /**
     * Function to read all the cf fields in a form for a given optiondate.
     * @param array $formdata
     * @param mixed $index
     * @return array
     */
    public static function get_list_of_submitted_cfields(array $formdata, $index) {

        $regexstring = '/^customfieldname_' . $index . '_/';
        if (!$cfnames = preg_grep($regexstring, array_keys($formdata))) {
            // For performance.
            return [];
        }

        $optionid = $formdata['id'];
        $bookingid = $formdata['bookingid'];

        $returncfields = [];
        foreach ($cfnames as $cfname) {
            list($name, $index, $counter) = explode('_', $cfname);

            $cfname = $formdata[$cfname] ?? null;
            $cfvalue = $formdata['customfieldvalue_' . $index . '_' . $counter] ?? null;
            $id = $formdata['customfieldid_' . $index . '_' . $counter] ?? 0;

            // If no values are set, neither for key nor value, we continue.
            if (empty($cfname) || empty($cfname[0])) {
                continue;
            }

            $cffield = [
                'cfgname' => $cfname,
                'value' => $cfvalue,
                'bookingid' => $bookingid,
                'optionid' => $optionid,
                'optiondateid' => $formdata['optiondateid_' . $index] ?? 0,
            ];
            if (!empty($id)) {
                $cffield['id'] = $id;
                $returncfields[$id] = $cffield;
            } else {
                $returncfields[] = $cffield;
            }
        }

        return $returncfields;
    }

    /**
     * Insert or update customfields.
     * @param int $optionid
     * @param int $optiondateid
     * @param array $customfields
     * @return void
     * @throws dml_exception
     */
    public static function save_fields(int $optionid, int $optiondateid, array $customfields) {

        global $DB;

        // See if the optiondate has previous customfields not there now.

        $oldcustomfields = self::return_customfields_for_optiondate($optiondateid);

        foreach ($customfields as $customfield) {

            if (isset($customfield->id) && isset($oldcustomfields[$customfield->id])) {

                if (empty($customfield["cfgname"]) && empty($customfield["value"])) {
                    $DB->delete_records('booking_customfields', ['id' => $customfield->id]);
                } else {
                    $DB->update_record('booking_customfields', $customfield);
                }
                unset($oldcustomfields[$customfield->id]);
            } else {
                $customfield['optiondateid'] = $optiondateid;
                $DB->insert_record('booking_customfields', $customfield);
            }
        }

        // We delete all fields that are not in the new customfields anymore.
        foreach ($oldcustomfields as $customfield) {
            $DB->delete_records('booking_customfields', ['id' => $customfield->id]);
        }
    }

    /**
     * Just get all the customfields for a given optiondate.
     * @param int $optiondateid
     * @return array
     * @throws dml_exception
     */
    public static function return_customfields_for_optiondate(int $optiondateid) {
        global $DB;

        return $DB->get_records('booking_customfields', ['optiondateid' => $optiondateid]);

    }

    /**
     * Set data function to add the right values to the form.
     * @param stdClass $defaultvalues
     * @param int $optiondateid
     * @param int $idx
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$defaultvalues, int $optiondateid, int $idx) {
        $cfields = self::return_customfields_for_optiondate($optiondateid);

        $i = 1;
        foreach ($cfields as $cfield) {

            $key = 'addcustomfield_' . $idx . '_' . $i;
            $defaultvalues->{$key} = 1;

            $key = 'customfieldid_' . $idx . '_' . $i;
            $defaultvalues->{$key} = $cfield->id;

            $key = 'customfieldname_' . $idx . '_' . $i;
            $defaultvalues->{$key} = $cfield->cfgname;

            $key = 'customfieldvalue_' . $idx . '_' . $i;
            $defaultvalues->{$key} = $cfield->value;
            $i++;
        }
    }

    /**
     * Delete all customfields for a given optiondate.
     * @param mixed $optiondateid
     * @return void
     * @throws dml_exception
     */
    public static function delete_cfields_for_optiondate($optiondateid) {
        global $DB;
        // Delete all custom fields which belong to this optiondate.
        $DB->delete_records('booking_customfields', ['optiondateid' => $optiondateid]);
    }

    /**
     * Check if two dates are dissimilar.
     * @param array $olditem
     * @param array $newitem
     * @return bool
     */
    public static function compare_items(array $olditem, array $newitem) {

        $changed = false;

        // If both are empty, we don't need to continue und say that it has not changed.
        if (empty($olditem['customfields']) && empty($newitem["customfields"])) {
            return true;
        }

        foreach ($newitem["customfields"] as $cfield) {

            if (empty($cfield['optiondateid'])) {
                // We have a new field, so it's obviously changed.
                return false;
            }

            if (isset($olditem['customfields'][$cfield['id']])) {

                $oldcfield = $olditem['customfields'][$cfield['id']];

                // If we find the item, we iterate over all the values.
                foreach ($oldcfield as $key => $value) {
                    if ($cfield[$key] != $value) {
                        $changed = true;
                    }
                }
                unset($olditem['customfields'][$cfield['id']]);
            } else {
                // If we don't find the cffield, obviously it's changed.
                $changed = true;
            }
        }
        // If we haven't found all the old customfields in the new array, it also has been changed.
        if (isset($olditem['customfields']) && count($olditem['customfields']) > 0) {
            $changed = true;
        }

        return !$changed;
    }
}

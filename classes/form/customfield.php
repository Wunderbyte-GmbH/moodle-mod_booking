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
require_once($CFG->libdir . '/formslib.php');

class customfield extends \moodleform {

    public function definition() {
        $mform = & $this->_form;
        $i = 0;
        $defaultvalues = array();
        $fieldnames = array();
        $customfields = \mod_booking\booking_option::get_customfield_settings();
        $repeatno = \count($customfields);

        foreach ($customfields as $customfieldname => $value) {
            $defaultvalues[$i] = $value['value'];
            $fieldnames[$i] = $customfieldname;
            $types[$i] = $value['type'];
            $i++;
        }

        $repeatarray = array();
        $repeatarray[] = $mform->createElement('text', 'customfield',
                get_string('customfielddef', 'mod_booking'));
        $repeatarray[] = $mform->createElement('hidden', 'customfieldname', '');
        $options = array('textfield' => get_string('textfield', 'mod_booking'));
        $repeatarray[] = $mform->createElement('select', 'type',
                get_string('customfieldtype', 'mod_booking'), $options);
        $repeatarray[] = $mform->createElement('checkbox', 'deletefield',
                \get_string('delcustfield', 'mod_booking'));

        $repeateloptions = array();
        $repeateloptions['customfieldname']['type'] = PARAM_ALPHANUMEXT;
        $repeateloptions['customfield']['disabledif'] = array('deletefield', 'eq', 1);
        $repeateloptions['type']['disabledif'] = array('deletefield', 'eq', 1);
        $repeateloptions['type']['disabledif'] = array('customfieldname', 'eq', 1);
        $repeateloptions['customfield']['type'] = PARAM_NOTAGS;

        $this->repeat_elements($repeatarray, $repeatno, $repeateloptions, 'option_repeats',
                'option_add_fields', 1, null, true);
        if ($repeatno) {
            for ($i = 0; $i <= $repeatno; $i++) {
                if ($mform->elementExists("customfieldname[$i]")) {
                    if (isset($fieldnames[$i])) {
                        $mform->setDefault("customfieldname[$i]", $fieldnames[$i]);
                        $mform->setDefault("customfield[$i]", $defaultvalues[$i]);
                        $mform->disabledIf("type[$i]", "customfield[$i]", $types[$i]);
                    }
                }
            }
        }

        // Buttons.
        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                get_string('savechangesanddisplay'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
        $mform->closeHeaderBefore('buttonar');
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $errors;
    }

    public function get_data() {
        global $DB;
        $data = parent::get_data();
        $cfgbkg = \get_config('booking');
        // Check if something needs to be deleted
        if (isset($data->deletefield)) {
            $tobedeleted = array_keys($data->deletefield);
            foreach ($tobedeleted as $value) {
                $cfgname = $data->customfieldname[$value];
                if (isset($cfgbkg->$cfgname)) {
                    \unset_config($cfgname, 'booking');
                    \unset_config($cfgname . "type", 'booking');
                    // Update the showcustfields config, because it might reference the cfgname
                    $cfgbkg->showcustfields = \str_replace($cfgname, '', $cfgbkg->showcustfields);
                    trim($cfgbkg->showcustfields, ",");
                    \set_config('showcustfields', $cfgbkg->showcustfields, 'booking');
                    $DB->delete_records('booking_customfields', array('cfgname' => $cfgname));
                }
                // Remove all deleted values in order to exclude them from further data processing
                unset($data->customfieldname[$value]);
                unset($data->type[$value]);
                unset($data->customfield[$value]);
            }
        }

        // Set new and changed config values
        if (isset($data->customfield) && !empty($data->customfield)) {
            foreach ($data->customfield as $key => $value) {
                $cfgname = $data->customfieldname[$key];
                // Get config again because it has changed
                $cfgbkg = \get_config('booking');
                // Not yet configured, config name has to be found not overwriting existing ones
                if (empty($cfgname)) {
                    for ($i = 0; $i < 300; $i++) {
                        $customname = "customfield_" . $i;
                        if (!isset($cfgbkg->$customname)) {
                            $cfgname = $customname;
                            break;
                        }
                    }
                }
                if ((isset($cfgbkg->$cfgname) && $cfgbkg->$cfgname !== $value) ||
                         !isset($cfgbkg->$cfgname)) {
                    \set_config($cfgname, $value, 'booking');
                    if (isset($data->type[$key])) {
                        \set_config($cfgname . "type", $data->type[$key], 'booking');
                    }
                }
            }
        }
        return $data;
    }
}

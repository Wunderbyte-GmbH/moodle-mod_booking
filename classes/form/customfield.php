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
 * Custom field form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use cache_exception;
use dml_exception;
use coding_exception;
use stdClass;
use ddl_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Customfield class.
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customfield extends \moodleform {

    /**
     * Definitiion.
     * @return void
     * @throws dml_exception
     * @throws coding_exception
     */
    public function definition() {
        $mform = & $this->_form;
        $i = 0;
        $defaultvalues = [];
        $fieldnames = [];
        $options = [];
        $customfields = \mod_booking\booking_option::get_customfield_settings();
        $repeatno = \count($customfields);
        foreach ($customfields as $customfieldname => $value) {
            $defaultvalues[$i] = $value['value'];
            $fieldnames[$i] = $customfieldname;
            $types[$i] = $value['type'];
            $options[$i] = $value['options'];
            $i++;
        }

        $repeatarray = [];
        $repeatarray[] = $mform->createElement('text', 'customfield',
                get_string('customfielddef', 'mod_booking'));
        $repeatarray[] = $mform->createElement('hidden', 'customfieldname', '');
        $optionstype = [
                        'textfield' => get_string('textfield', 'mod_booking'),
                        'select' => get_string('selectfield', 'mod_booking'),
                        'multiselect' => get_string('multiselect', 'mod_booking'),
                    ];
        $repeatarray[] = $mform->createElement('select', 'type',
                get_string('customfieldtype', 'mod_booking'), $optionstype);
        $repeatarray[] = $mform->createElement('textarea', 'options',
                get_string('customfieldoptions', 'mod_booking'), 'rows="5" cols="50"');
        $repeatarray[] = $mform->createElement('checkbox', 'deletefield',
                \get_string('delcustfield', 'mod_booking'));

        $repeateloptions = [];
        $repeateloptions['customfieldname']['type'] = PARAM_ALPHANUMEXT;
        $repeateloptions['customfield']['disabledif'] = ['deletefield', 'eq', 1];
        $repeateloptions['type']['disabledif'] = ['deletefield', 'eq', 1];
        $repeateloptions['type']['disabledif'] = ['customfieldname', 'eq', 1];
        $repeateloptions['options']['disabledif'] = ['type', 'eq', 'textfield'];
        $repeateloptions['customfield']['type'] = PARAM_NOTAGS;

        $this->repeat_elements($repeatarray, $repeatno, $repeateloptions, 'option_repeats',
                'option_add_fields', 1, null, true);
        if ($repeatno) {
            for ($i = 0; $i <= $repeatno; $i++) {
                if ($mform->elementExists("customfieldname[$i]")) {
                    if (isset($fieldnames[$i])) {
                        $mform->setDefault("customfieldname[$i]", $fieldnames[$i]);
                        $mform->setDefault("customfield[$i]", $defaultvalues[$i]);
                        $mform->setDefault("type[$i]", $types[$i]);
                        if (isset($options[$i])) {
                            $mform->setDefault("options[$i]", $options[$i]);
                        }
                        $mform->disabledIf("type[$i]", "customfield[$i]", $types[$i]);
                    }
                }
            }
        }

        // Buttons.
        $buttonarray = [];
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                get_string('savechangesanddisplay'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Validation.
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $errors;
    }

    /**
     * Get data.
     * @return stdClass|null
     * @throws coding_exception
     * @throws dml_exception
     * @throws cache_exception
     * @throws ddl_exception
     */
    public function get_data() {
        global $DB;
        $data = parent::get_data();
        $cfgbkg = \get_config('booking');
        // Check if something needs to be deleted.
        if (isset($data->deletefield)) {
            $tobedeleted = array_keys($data->deletefield);
            foreach ($tobedeleted as $value) {
                $cfgname = $data->customfieldname[$value];
                if (isset($cfgbkg->$cfgname)) {
                    \unset_config($cfgname, 'booking');
                    \unset_config($cfgname . "type", 'booking');
                    // Update the showcustfields config, because it might reference the cfgname.
                    $cfgbkg->showcustfields = \str_replace($cfgname, '', $cfgbkg->showcustfields);
                    trim($cfgbkg->showcustfields, ",");
                    \set_config('showcustfields', $cfgbkg->showcustfields, 'booking');
                    $DB->delete_records('booking_customfields', ['cfgname' => $cfgname]);
                }
                // Remove all deleted values in order to exclude them from further data processing.
                unset($data->customfieldname[$value]);
                unset($data->type[$value]);
                unset($data->customfield[$value]);
                unset($data->options[$value]);
            }
        }

        // Set new and changed config values.
        if (isset($data->customfield) && !empty($data->customfield)) {
            foreach ($data->customfield as $key => $value) {
                $cfgname = $data->customfieldname[$key];
                // Get config again because it has changed.
                $cfgbkg = \get_config('booking');
                // Not yet configured, config name has to be found not overwriting existing ones.
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
                    if (isset($data->options[$key])) {
                        \set_config($cfgname . "options", $data->options[$key], 'booking');
                    }
                }
            }

            $event = \mod_booking\event\custom_field_changed::create(['objectid' => 0, 'context' => \context_system::instance()]);
            $event->trigger();
        }
        return $data;
    }
}

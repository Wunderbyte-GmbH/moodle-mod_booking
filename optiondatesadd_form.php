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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

const MAX_CUSTOM_FIELDS = 3;

/**
 * Add option date form
 * @author David Bogner
 *
 */
class optiondatesadd_form extends moodleform {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('hidden', 'optiondateid');
        $mform->setType('optiondateid', PARAM_INT);

        $mform->addElement('hidden', 'bookingid');
        $mform->setType('bookingid', PARAM_INT);

        $mform->addElement('hidden', 'eventid');
        $mform->setType('eventid', PARAM_INT);

        $mform->addElement('date_time_selector', 'coursestarttime', get_string('from'));
        $mform->setType('coursestarttime', PARAM_INT);

        for ($i = 0; $i <= 23; $i++) {
            $hours[$i] = sprintf("%02d", $i);
        }
        for ($i = 0; $i < 60; $i += 5) {
            $minutes[$i] = sprintf("%02d", $i);
        }

        $courseendtime = array();
        $courseendtime[] = & $mform->createElement('select', 'endhour', get_string('hour', 'form'),
                $hours);
        $courseendtime[] = & $mform->createElement('select', 'endminute',
                get_string('minute', 'form'), $minutes);
        $mform->setType('endhour', PARAM_INT);
        $mform->setType('endminute', PARAM_INT);
        $mform->addGroup($courseendtime, 'endtime', get_string('to'), ' ', false);

        $mform->addElement('text', 'daystonotify', get_string('daystonotifysession', 'booking'));
        $mform->setType('daystonotify', PARAM_INT);
        $mform->setDefault('daystonotify', 0);
        $mform->addHelpButton('daystonotify', 'daystonotifysession', 'booking');

        // Only allow creation of custom fields, when creating a new optiondate.
        if (empty($this->_customdata['optiondateid'])) {
            $this->addcustomfields($mform);
            $mform->addElement('submit', 'submitbutton', get_string('save'));
        } else {
            // At first loop through already existing custom field records.
            $customfields = $DB->get_records("booking_customfields", array('optiondateid' => $this->_customdata['optiondateid']));
            $j = 1;
            foreach ($customfields as $customfield) {
                $mform->addElement('hidden', 'customfieldid' . $j, $customfield->id);
                $mform->setType('customfieldid' . $j, PARAM_INT);

                //$mform->addElement('text', 'customfieldname' . $j, get_string('customfieldname', 'booking'));
                //$mform->setType('customfieldname' . $j, PARAM_TEXT);
                //$mform->setDefault('customfieldname' . $j, $customfield->cfgname);
                //$mform->addHelpButton('customfieldname' . $j, 'customfieldname', 'booking');

                $cfnames = [
                    null => '',
                    'TeamsMeeting' => 'TeamsMeeting',
                    'ZoomMeeting' => 'ZoomMeeting',
                    'BigBlueButtonMeeting' => 'BigBlueButtonMeeting'
                ];
                if (!in_array($customfield->cfgname, $cfnames)) {
                    $cfnames[$customfield->cfgname] = $customfield->cfgname;
                }
                $options = array(
                        'noselectionstring' => get_string('nocfnameselected', 'booking'),
                        'tags' => true
                );
                $element = $mform->createElement('autocomplete', 'customfieldname' . $j, get_string('customfieldname', 'booking'), $cfnames, $options);
                $mform->addElement($element);
                if (!empty($CFG->formatstringstriptags)) {
                    $mform->setType('customfieldname' . $j, PARAM_TEXT);
                } else {
                    $mform->setType('customfieldname' . $j, PARAM_CLEANHTML);
                }
                $mform->setDefault('customfieldname' . $j, $customfield->cfgname);
                $mform->addHelpButton('customfieldname' . $j, 'customfieldname', 'booking');

                $mform->addElement('textarea', 'customfieldvalue' . $j, get_string('customfieldvalue', 'booking'), 'wrap="virtual" rows="1" cols="65"');
                $mform->setType('customfieldvalue' . $j, PARAM_RAW);
                $mform->setDefault('customfieldvalue' . $j, $customfield->value);
                $mform->addHelpButton('customfieldvalue' . $j, 'customfieldvalue', 'booking');

                $mform->addElement('checkbox', 'deletecustomfield' . $j, get_string('deletecustomfield', 'booking'));
                $mform->setDefault('deletecustomfield' . $j, 0);
                $mform->addHelpButton('deletecustomfield' . $j, 'deletecustomfield', 'booking');

                $j++;
            }
            // Now, if there are less than the maximum number of custom fields allow adding additional ones.
            if (count($customfields) < MAX_CUSTOM_FIELDS) {
                // Between one to three custom fields are supported.
                $start = count($customfields) + 1;
                $this->addcustomfields($mform, $start);
            }
            $mform->addElement('submit', 'submitbutton', get_string('savechanges'));
        }
    }

    /**
     * Helper function to create form elements for adding custom fields.
     * @param int $counter if there already are existing custom fields start with the succeeding number
     */
    public function addcustomfields($mform, $counter = 1) {
        // Add checkbox to add first customfield
        $mform->addElement('checkbox', 'addcustomfield' . $counter, get_string('addcustomfield', 'booking'));

        while ($counter <= MAX_CUSTOM_FIELDS) {
            // New elements have a default customfieldid of 0.
            $mform->addElement('hidden', 'customfieldid' . $counter, 0);
            $mform->setType('customfieldid' . $counter, PARAM_INT);

            // Add Autocomplete with TeamsMeeting etc.
            $cfnames = [
                null => '',
                'TeamsMeeting' => 'TeamsMeeting',
                'ZoomMeeting' => 'ZoomMeeting',
                'BigBlueButtonMeeting' => 'BigBlueButtonMeeting'
            ];
            $options = array(
                    'noselectionstring' => get_string('nocfnameselected', 'booking'),
                    'tags' => true
            );
            $mform->addElement('autocomplete', 'customfieldname' . $counter, get_string('customfieldname', 'booking'), $cfnames, $options);
            if (!empty($CFG->formatstringstriptags)) {
                $mform->setType('customfieldname' . $counter, PARAM_TEXT);
            } else {
                $mform->setType('customfieldname' . $counter, PARAM_CLEANHTML);
            }
            $mform->setDefault('customfieldname' . $counter, null);
            $mform->addHelpButton('customfieldname' . $counter, 'customfieldname', 'booking');
            $mform->hideIf('customfieldname' . $counter, 'addcustomfield' . $counter, 'notchecked');

            //$mform->addElement('text', 'customfieldname' . $counter, get_string('customfieldname', 'booking'));
            //$mform->setType('customfieldname' . $counter, PARAM_TEXT);
            //$mform->setDefault('customfieldname' . $counter, '');
            //$mform->addHelpButton('customfieldname' . $counter, 'customfieldname', 'booking');
            //$mform->hideIf('customfieldname' . $counter, 'addcustomfield' . $counter, 'notchecked');

            $mform->addElement('textarea', 'customfieldvalue' . $counter, get_string('customfieldvalue', 'booking'), 'wrap="virtual" rows="1" cols="65"');
            $mform->setType('customfieldvalue' . $counter, PARAM_RAW);
            $mform->setDefault('customfieldvalue' . $counter, '');
            $mform->addHelpButton('customfieldvalue' . $counter, 'customfieldvalue', 'booking');
            $mform->hideIf('customfieldvalue' . $counter, 'addcustomfield' . $counter, 'notchecked');

            // Set delete parameter to 0 for newly created fields, so they won't be deleted.
            $mform->addElement('hidden', 'deletecustomfield' . $counter, 0);
            $mform->setType('deletecustomfield' . $counter, PARAM_INT);

            // Show checkbox to add a custom field.
            if ($counter < MAX_CUSTOM_FIELDS) {
                $mform->addElement('checkbox', 'addcustomfield' . ($counter + 1), get_string('addcustomfield', 'booking'));
                $mform->hideIf('addcustomfield' . ($counter + 1), 'addcustomfield' . $counter, 'notchecked');
            }
            ++$counter;
        }
    }

    /**
     * Validate start and end time.
     * Validate custom fields.
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {
        $errors = array();
        // Validate start and end time.
        $starttime = $data['coursestarttime'];
        $date = date("Y-m-d", $data['coursestarttime']);
        $endtime = strtotime($date . " {$data['endhour']}:{$data['endminute']}");
        if ($endtime < $starttime) {
            $errors['endtime'] = "Course end time must be after course start time";
        }

        if ($data['daystonotify'] != 0 && !(int)$data['daystonotify']) {
            $errors['daystonotify'] = "Value must be an integer number";
        }

        // Validate custom fields.
        for ($i = 1; $i <= MAX_CUSTOM_FIELDS; $i++) {
            $customfieldnamex = $data['customfieldname' . $i];
            $customfieldvaluex = $data['customfieldvalue' . $i];
            // The field name is not allowed to be empty if there is a value.
            if (empty($customfieldnamex) && !empty($customfieldvaluex)) {
                $errors['customfieldname' . $i] = get_string('erroremptycustomfieldname', 'booking');
            }
            // The field value is not allowed to be empty if there is a name.
            if (empty($customfieldvaluex) && !empty($customfieldnamex)) {
                $errors['customfieldvalue' . $i] = get_string('erroremptycustomfieldvalue', 'booking');
            }
        }
        return $errors;
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}
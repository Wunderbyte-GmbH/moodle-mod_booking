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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use context_module;
use html_writer;
use mod_booking\bo_actions\actions_info;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\dates;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use mod_booking\subbookings\subbookings_info;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recurringoptions extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_RECURRINGOPTIONS;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_RECURRINGOPTIONS;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [
        "parentid",
        "repeatthisbooking",
    ];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [];

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param ?mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        $newoption->parentid = $formdata->parentid ?? 0;

        return [];
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     * @return void
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {
        global $DB, $USER;

        // Templates and recurring 'events' - only visible when adding new.
        if ($formdata['id']) {
            $mform->addElement(
                'header',
                'recurringheader',
                get_string('recurringheader', 'mod_booking')
            );
            $mform->addElement(
                'hidden',
                'parentid',
            );

            $settings = singleton_service::get_instance_of_booking_option_settings($formdata['id']);

            // Fetch parents and children of this option.
            // Parents / Children header.

            // Either parentid is current optionid or id is current parentid. Case with same parent.
            $sql = 'SELECT * FROM {booking_options}
                    WHERE parentid = :id';
            $params = [
                'id' => $formdata['id'],
                'parentid1' => $settings->parentid,
                'parentid2' => $settings->parentid,
            ];
            if (!empty($settings->parentid)) {
                $sql .= ' AND id = :parentid1 OR parentid = :parentid2';
            }

            $linkedlist = $DB->get_records_sql($sql, $params);

           // Remove current item from the sibling list.
            foreach ($linkedlist as $key => $linked) {
                if ($linked->id == $settings->id) {
                        unset($linkedlist[$key]);
                }
            }

            // Sort the array by keys in ascending order.
            ksort($linkedlist);

            // Display linked options if available.
            if (!empty($linkedlist)) {
                $sameparent = [];
                $isparentofcurrent = [];
                $ischildofcurrent = [];

                // Sort records into categories.
                foreach ($linkedlist as $record) {
                    if ($record->parentid == $settings->id) {
                        $ischildofcurrent[] = $record;
                    } else if ($record->id == $settings->parentid) {
                        $sameparent[] = $record;
                    } else if ($settings->parentid == $record->id) {
                        $isparentofcurrent[] = $record;
                    }
                }

                if (!empty($ischildofcurrent)) {
                    $mform->addElement('hidden', 'has_children', 1);
                }

                // Function to generate links for a given set of records.
                $generatelinks = function ($records) use ($formdata, $USER) {
                    return array_map(function ($value) {
                        $url = new moodle_url('/mod/booking/optionview.php', ['optionid' => $value->id]);
                        return html_writer::link($url->out(false), $value->text);
                    }, $records);
                };

                // Build categorized sections.
                $htmlcontent = '';

                if (!empty($isparentofcurrent)) {
                    $htmlcontent .= '<h7>' . get_string('recurringparentoption', 'mod_booking') . '</h7><br>';
                    $htmlcontent .= implode('<br>', $generatelinks($isparentofcurrent)) . '<br><br>';
                }

                if (!empty($sameparent)) {
                    $htmlcontent .= '<h7>' . get_string('recurringsameparentoptions', 'mod_booking') . '</h7><br>';
                    $htmlcontent .= implode('<br>', $generatelinks($sameparent)) . '<br><br>';
                }

                if (!empty($ischildofcurrent)) {
                    $htmlcontent .= '<h7>' . get_string('recurringchildoptions', 'mod_booking') . '</h7><br>';
                    $htmlcontent .= implode('<br>', $generatelinks($ischildofcurrent)) . '<br><br>';
                }

                // Add the structured HTML to the form.
                if (!empty($htmlcontent)) {
                    $mform->addElement('html', $htmlcontent);
                }
            }

            $mform->addElement(
                'checkbox',
                'repeatthisbooking',
                get_string('repeatthisbooking', 'mod_booking')
            );
            $mform->addElement(
                'text',
                'howmanytimestorepeat',
                get_string('howmanytimestorepeat', 'mod_booking')
            );
            $mform->setType('howmanytimestorepeat', PARAM_INT);
            $mform->setDefault('howmanytimestorepeat', 1);
            $mform->disabledIf('howmanytimestorepeat', 'repeatthisbooking', 'notchecked');
            $howoften = [
                86400 => get_string('day'),
                604800 => get_string('week'),
                2592000 => get_string('month'),
            ];
            $mform->addElement(
                'select',
                'howoftentorepeat',
                get_string(
                    'howoftentorepeat',
                    'mod_booking'
                ),
                $howoften
            );
            $mform->setType('howoftentorepeat', PARAM_INT);
            $mform->setDefault('howoftentorepeat', 86400);
            $mform->disabledIf('howoftentorepeat', 'repeatthisbooking', 'notchecked');

            // Hidden input to track if the form has been validated before.
            $mform->addElement('hidden', 'validated_once', 0);
            $mform->setType('validated_once', PARAM_INT);

            //$mform->addElement('html', '<div class="alert alert-warning">');
            $mform->addElement('advcheckbox', 'apply_to_children', get_string('confirmrecurringoption', 'mod_booking'));
            $mform->setDefault('apply_to_children', 0);
            //$mform->addElement('html', '</div>');
            $mform->hideIf('apply_to_children', 'validated_once', 'eq', 0);
        }
    }

    /**
     * Definition after data callback
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     * @return void
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {
        if (($mform->_flagSubmitted ?? false) && empty($formdata['validated_once'])) {
            $validationelement = $mform->getElement('validated_once');
            $validationelement->setValue(1);
        }
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        if (empty($data->parentid)) {
            $data->parentid = $settings->parentid;
        }
    }

    /**
     * Save data
     * @param stdClass $data
     * @param stdClass $option
     * @return array
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$data, stdClass &$option): array {

        $changes = [];
        if (!empty($data->repeatthisbooking)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
            $context = context_module::instance($settings->cmid);

            $templateoption = (object)[
                'cmid' => $data->cmid,
                'id' => $option->id, // In the context of option_form class, id always refers to optionid.
                'optionid' => $option->id, // Just kept on for legacy reasons.
                'bookingid' => $data->bookingid,
                'copyoptionid' => 0, // Do NOT set it here as we might get stuck in a loop.
                'oldcopyoptionid' => $data->copyoptionid ?? 0,
                'returnurl' => '',
            ];

            fields_info::set_data($templateoption);
            $templateoption->importing = 1;
            $templateoption->parentid = $option->id;
            [$newoptiondates, $highesindex] = dates::get_list_of_submitted_dates((array)$templateoption);

            $title = $templateoption->text;
            for ($i = 1; $i <= $data->howmanytimestorepeat; $i++) {
                unset($templateoption->id, $templateoption->identifier, $templateoption->optionid);
                $templateoption->text = $title . " $i";
                foreach ($newoptiondates as $newoptiondate) {
                    $key = MOD_BOOKING_FORM_OPTIONDATEID . $newoptiondate["index"];
                    $templateoption->{$key} = 0;
                    $delta = $data->howoftentorepeat;
                    $key = MOD_BOOKING_FORM_COURSESTARTTIME . $newoptiondate["index"];
                    $templateoption->{$key} += $delta;
                    $key = MOD_BOOKING_FORM_COURSEENDTIME . $newoptiondate["index"];
                    $templateoption->{$key} += $delta;
                }

                booking_option::update((object) $templateoption, $context);
            }
        }

        return $changes;
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        $errors = [];

        if (empty($data['validated_once']) && !empty($data['has_children'])) {
            $errors['apply_to_children'] = get_string('confirmrecurringoptionerror', 'mod_booking');
        }
        return $errors;
    }

    public static function update_children(int $optionid, array $changes) {
        global $DB;
        $children = $DB->get_records('booking_options', ['parentid' => $optionid]);

        if (!empty($children)) {
            foreach ($children as $child) {
                // Loop through the changes.
                $data = [
                    'id' => $child->id,
                    'importing' => 1,
                ];
                $update = false;
                foreach ($changes as $change) {
                    if (isset($change['changes']['fieldname']) && $change['changes']['fieldname'] == 'customfields') {
                        continue;
                    }

                    if (isset($change['changes']['fieldname']) && $change['changes']['fieldname'] == 'dates') {
                        $oldvalues = $change['changes']['oldvalue'];
                        $newvalues = $change['changes']['newvalue'];

                        $results = [];

                        foreach ($oldvalues as $old) {
                            foreach ($newvalues as $new) {
                                if ($old->id == $new['id']) {
                                    $oldstarttime = $old->coursestarttime;
                                    $oldendtime = $old->courseendtime;
                                    $newstarttime = $new['coursestarttime'];
                                    $newendtime = $new['courseendtime'];

                                    $deltastart = $newstarttime - $oldstarttime;
                                    $deltaend = $newendtime - $oldendtime;

                                    // Store results
                                    $results[] = [
                                        "id" => $old->id,
                                        "delta_start_time_seconds" => $deltastart,
                                        "delta_end_time_seconds" => $deltaend,
                                    ];
                                }
                            }
                        }

                        $key = MOD_BOOKING_FORM_OPTIONDATEID . $change['changes']['newvalue'][0]['index'];
                        $data[$key] = 0;
                        $key = MOD_BOOKING_FORM_COURSESTARTTIME . $change['changes']['newvalue'][0]['index'];
                        $data[$key] = $child->coursestarttime + $results['delta_start_time_seconds'];
                        $key  = MOD_BOOKING_FORM_COURSEENDTIME . $change['changes']['newvalue'][0]['index'];
                        $data[$key] = $child->courseendtime + $results['delta_end_time_seconds'];
                        $update = true;
                    }

                    $fieldname = $change['changes']['fieldname'] ?? '';
                    $newvalue = $change['changes']['newvalue'] ?? '';

                    // If the field exists and the value is different, update it.
                    if (isset($child->$fieldname) && $child->$fieldname !== $newvalue) {
                        $data[$fieldname] = $newvalue;
                        $update = true;
                    }
                }
                // Update the data record after all changes are made.
                if ($update) {
                    //$DB->update_record('booking_options', $child);
                    //self::update($data, $context);
                }
            }
        }
    }
}

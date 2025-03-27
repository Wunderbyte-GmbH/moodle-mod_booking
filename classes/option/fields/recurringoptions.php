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
use mod_booking\utils\wb_payment;
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
        if (
            $formdata['id']
            && wb_payment::pro_version_is_activated()
        ) {
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }
            $mform->addElement(
                'hidden',
                'parentid',
            );
            $mform->setType('parentid', PARAM_INT);

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
                $sql .= ' OR id = :parentid1 OR parentid = :parentid2';
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
                    } else if ($record->parentid == $settings->parentid) {
                        $sameparent[] = $record;
                    } else if ($settings->parentid == $record->id) {
                        $isparentofcurrent[] = $record;
                    }
                }

                if (!empty($ischildofcurrent)) {
                    $mform->addElement('hidden', 'has_children', 1);
                    $mform->setType('has_children', PARAM_INT);
                }

                // Function to generate links for a given set of records.
                $generatelinks = function ($records) use ($formdata, $USER) {

                    return array_map(function ($value) use ($formdata, $USER) {
                        return booking_option::create_link_to_bookingoption(
                            $value->id,
                            $formdata['cmid'],
                            $value->text,
                            $USER->id,
                            ['target' => '_blank']
                        );
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

            if (!empty($isparentofcurrent)) {
                // For children we don't support creating of further recurrings.
                $mform->addElement('html', get_string('recurringnotpossibleinfo', 'mod_booking'));
            } else {
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

                $mform->addElement(
                    'advcheckbox',
                    'requirepreviousoptionstobebooked',
                    get_string('requirepreviousoptionstobebooked', 'mod_booking')
                );
                $mform->setDefault('requirepreviousoptionstobebooked', 0);
                $mform->hideIf('apply_to_children', 'repeatthisbooking', 'eq', 0);

                $mform->addElement('static', 'recurringsaveinfo', '', get_string('recurringsaveinfo', 'mod_booking'));
                $mform->hideIf('recurringsaveinfo', 'repeatthisbooking', 'notchecked');

                // Hidden input to track if the form has been validated before.
                $mform->addElement('hidden', 'validated_once', 0);
                $mform->setDefault('validated_once', 0);
                $mform->setType('validated_once', PARAM_INT);

                $mform->addElement('advcheckbox', 'apply_to_children', get_string('confirmrecurringoption', 'mod_booking'));
                $mform->setDefault('apply_to_children', 0);
                $mform->hideIf('apply_to_children', 'validated_once', 'eq', 0);
                $mform->addElement('static', 'recurringsavedatesinfo', '', get_string('recurringsavedatesinfo', 'mod_booking'));
                $mform->hideIf('recurringsavedatesinfo', 'apply_to_children', 'eq', 0);
            }
        } else if ($formdata['id']) {
            $mform->addElement(
                'header',
                'recurringheader',
                get_string('recurringheader', 'mod_booking')
            );
            $mform->addElement(
                'static',
                'nolicense',
                get_string('licensekeycfg', 'mod_booking'),
                get_string('licensekeycfgdesc', 'mod_booking')
            );
        }
    }

    /**
     * Definition after data callback
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     * @return void
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {
        if (
            isset($formdata['validated_once'])
            && ($mform->_flagSubmitted ?? false)
            && empty($formdata['validated_once'])
        ) {
            $validationelement = $mform->getElement('validated_once');
            $validationelement->setValue(1);
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
            $templateoption->parentid = $option->id;
            $restrictoptionid = $option->id;
            [$newoptiondates, $highesindex] = dates::get_list_of_submitted_dates((array)$templateoption);

            $title = $templateoption->text;
            for ($i = 1; $i <= $data->howmanytimestorepeat; $i++) {
                // Handle dates.
                unset($templateoption->id, $templateoption->identifier, $templateoption->optionid);
                $templateoption->text = $title;
                foreach ($newoptiondates as $newoptiondate) {
                    $key = MOD_BOOKING_FORM_OPTIONDATEID . $newoptiondate["index"];
                    $templateoption->{$key} = 0;
                    $delta = $data->howoftentorepeat;
                    $key = MOD_BOOKING_FORM_COURSESTARTTIME . $newoptiondate["index"];
                    $templateoption->{$key} += $delta;
                    $key = MOD_BOOKING_FORM_COURSEENDTIME . $newoptiondate["index"];
                    $templateoption->{$key} += $delta;
                }
                // Handle setting: condition that previous option needs to be booked.
                if ($data->requirepreviousoptionstobebooked == 1) {
                    $templateoption->bo_cond_previouslybooked_restrict = "1";
                    $templateoption->bo_cond_previouslybooked_optionid = "$restrictoptionid";
                }
                $restrictoptionid = booking_option::update((object) $templateoption, $context);
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

        if (empty($data['validated_once']) && !empty($data['has_children'])) {
            $errors['apply_to_children'] = get_string('confirmrecurringoptionerror', 'mod_booking');
        }
        return $errors;
    }

    /**
     * If there are changes, apply them to the children.
     *
     * @param int $optionid
     * @param array $changes
     * @param object $data
     * @param object $oldoption
     *
     *
     */
    public static function update_children(
        int $optionid,
        array $changes,
        object $data,
        object $oldoption
    ) {
        global $DB;
        $children = $DB->get_records('booking_options', ['parentid' => $optionid]);

        if (!empty($children)) {
            $context = context_module::instance($data->cmid);

            $delta = 0;
            if (isset($changes['mod_booking\option\fields\optiondates'])) {
                // Check for delta, see if its everywhere the same. If not, 0 returned.
                $delta = self::find_constant_delta($oldoption, $children);
                $d = 0;
                [$newparentoptiondates, $highesindex] = dates::get_list_of_submitted_dates((array)$data);
            }

            foreach ($children as $index => $child) {
                // Loop through the changes.
                $childdata = (object)[
                    'id' => $child->id,
                    'cmid' => $data->cmid,
                ];
                fields_info::set_data($childdata);
                $update = false;

                foreach ($changes as $change) {
                    if (empty($change['changes'])) {
                        continue;
                    }
                    if (
                        isset($change['changes']['fieldname'])
                        && $change['changes']['fieldname'] == 'dates'
                    ) {
                        if (empty($delta)) {
                            // No consistent delta, changes in dates not applied.
                            continue;
                        } else {
                            $update = true;
                            [$childoptiondates, $highesindexchild] = dates::get_list_of_submitted_dates((array)$childdata);

                            for ($i = 1; $i <= $highesindexchild; $i++) {
                                $key = MOD_BOOKING_FORM_OPTIONDATEID . $i;
                                if (isset($childdata->$key)) {
                                    unset($childdata->$key);
                                }
                            }

                            $d += $delta;
                            foreach ($newparentoptiondates as $newparentoptiondate) {
                                // Set the timestamp including the corresponding delta.
                                $key = MOD_BOOKING_FORM_OPTIONDATEID . $newparentoptiondate["index"];
                                $childdata->{$key} = 0;
                                $key = MOD_BOOKING_FORM_COURSESTARTTIME . $newparentoptiondate["index"];
                                $coursestarttimekey = rtrim(MOD_BOOKING_FORM_COURSESTARTTIME, "_");
                                $childdata->{$key} = (int) $newparentoptiondate[$coursestarttimekey] + $d;
                                $key = MOD_BOOKING_FORM_COURSEENDTIME . $newparentoptiondate["index"];
                                $courseendtimekey = rtrim(MOD_BOOKING_FORM_COURSEENDTIME, "_");
                                $childdata->{$key} = (int) $newparentoptiondate[$courseendtimekey] + $d;
                                $key = MOD_BOOKING_FORM_DAYSTONOTIFY . $newparentoptiondate["index"];
                                $dayskey = rtrim(MOD_BOOKING_FORM_DAYSTONOTIFY, "_");
                                $childdata->{$key} = $newparentoptiondate[$dayskey];
                            }
                        }
                        booking_option::update($childdata, $context);
                    } else {
                        $fieldname = $change['changes']['formkey'] ?? '';
                        $newvalue = $change['changes']['newvalue'] ?? '';

                        // If the field exists and the value is different, update it.
                        if (isset($childdata->$fieldname) && $childdata->$fieldname !== $newvalue) {
                            $childdata->$fieldname = $newvalue;
                            $update = true;
                        }
                    }
                }
                // Update the data record after all changes are made.
                if ($update) {
                    $childdata->parentid = $data->optionid ?? $child->parentid ?? 0;
                    $childdata->importing = 1;
                    booking_option::update($childdata, $context);
                }
            }
        }
    }

    /**
     * Check if there is a constant delta between the parent and all children records. Otherwise return 0.
     *
     * @param object $parent
     * @param array $children
     *
     * @return int
     *
     */
    private static function find_constant_delta(object $parent, array &$children): int {
        $parent->coursestarttime = (int)$parent->coursestarttime;
        foreach ($children as $child) {
            $child->coursestarttime = (int)$child->coursestarttime;
        }
        // Ensure the parent record has a valid coursestarttime.
        if (!isset($parent->coursestarttime) || empty($parent->coursestarttime)) {
            return 0;
        }

        // Filter out children that don't have a valid coursestarttime.
        $children = array_filter($children, function ($child) {
            return isset($child->coursestarttime) && !empty($child->coursestarttime);
        });

        // If there is less than 1 valid child, we cannot determine a difference.
        if (count($children) < 1) {
            return 0;
        }

        // Sort the array by coursestarttime in ascending order.
        usort($children, function ($a, $b) {
            return $a->coursestarttime <=> $b->coursestarttime;
        });

        // Re-index the array to have sequential numeric keys.
        $children = array_values($children);

        // Calculate the expected interval based on the first two children.
        $interval = $children[0]->coursestarttime - $parent->coursestarttime;

        // Check if the interval remains constant for all subsequent children.
        for ($i = 0; $i < count($children) - 1; $i++) {
            $diff = $children[$i + 1]->coursestarttime - $children[$i]->coursestarttime;
            if ($diff !== $interval) {
                return 0; // If any difference is inconsistent, return false.
            }
        }

        return $interval; // Return the consistent interval.
    }
}

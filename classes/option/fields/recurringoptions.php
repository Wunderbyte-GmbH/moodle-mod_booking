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
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use context_module;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\dates;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_exception;
use moodle_url;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik, Georg Maißer
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
        if (isset($formdata->parentid)) {
            $newoption->parentid = $formdata->parentid;
        }

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
                    $htmlcontent .= '<div title="' . get_string('recurringparentoption', 'mod_booking') . '">';
                    $htmlcontent .= '<h7>' . get_string('recurringparentoption', 'mod_booking') . '</h7><br>';
                    $htmlcontent .= implode('<br>', $generatelinks($isparentofcurrent)) . '<br><br></div>';
                }

                if (!empty($sameparent)) {
                    $htmlcontent .= '<div title="' . get_string('recurringsameparentoptions', 'mod_booking') . '">';
                    $htmlcontent .= '<h7>' . get_string('recurringsameparentoptions', 'mod_booking') . '</h7><br>';
                    $htmlcontent .= implode('<br>', $generatelinks($sameparent)) . '<br><br></div>';
                }

                if (!empty($ischildofcurrent)) {
                    $htmlcontent .= '<div title="' . get_string('recurringchildoptions', 'mod_booking') . '">';
                    $htmlcontent .= '<h7>' . get_string('recurringchildoptions', 'mod_booking') . '</h7><br>';
                    $htmlcontent .= implode('<br>', $generatelinks($ischildofcurrent)) . '<br><br></div>';
                }

                // Add the structured HTML to the form.
                if (!empty($htmlcontent)) {
                    $mform->addElement('html', $htmlcontent);
                }
            }

            if (
                !empty($isparentofcurrent)
                || (!empty(get_config('booking', 'recurringmultiparenting')) && !empty($ischildofcurrent))
            ) { // For children we don't support creating of further recurrings.
                $mform->addElement('html', get_string('recurringnotpossibleinfo', 'mod_booking'));
            } else { // Add possibility to create further recurrings.
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
                    'day' => get_string('day'),
                    'week' => get_string('week'),
                    'month' => get_string('month'),
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
                $mform->setType('howoftentorepeat', PARAM_TEXT);
                $mform->setDefault('howoftentorepeat', 'day');
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
            }
            if (!empty($ischildofcurrent)) { // Mothers contain additional options to unlink or delete children.
                $applyselectoptions = [
                    MOD_BOOKING_RECURRING_DONTUPDATE => get_string('dontapply', 'mod_booking'),
                    MOD_BOOKING_RECURRING_APPLY_TO_CHILDREN => get_string('confirmrecurringoptionapplychanges', 'mod_booking'),
                    MOD_BOOKING_RECURRING_OVERWRITE_CHILDREN => get_string('confirmrecurringoptionoverwrite', 'mod_booking'),
                ];
                $mform->addElement(
                    'select',
                    'apply_to_children',
                    get_string('confirmrecurringoption', 'mod_booking'),
                    $applyselectoptions
                );
                $mform->setDefault('apply_to_children', MOD_BOOKING_RECURRING_DONTUPDATE);
                $mform->hideIf('apply_to_children', 'validated_once', 'eq', 0);

                $mform->addElement(
                    'checkbox',
                    'unlinkallchildren',
                    get_string('unlinkallchildren', 'mod_booking')
                );
                $mform->addElement('static', 'allchildrenactioninfo1', '', get_string('recurringactioninfo', 'mod_booking'));
                $mform->hideIf('allchildrenactioninfo1', 'unlinkallchildren', 'notchecked');

                $mform->addElement(
                    'checkbox',
                    'deleteallchildren',
                    get_string('deleteallchildren', 'mod_booking')
                );
                $mform->addElement('static', 'allchildrenactioninfo2', '', get_string('recurringactioninfo', 'mod_booking'));
                $mform->hideIf('allchildrenactioninfo2', 'deleteallchildren', 'notchecked');
            } else if (!empty($isparentofcurrent)) { // Child can be unlinked.
                $mform->addElement(
                    'checkbox',
                    'unlinkchild',
                    get_string('unlinkchild', 'mod_booking')
                );
                $mform->addElement('static', 'allchildrenactioninfo', '', get_string('recurringactioninfo', 'mod_booking'));
                $mform->hideIf('deleteallchildreninfo', 'unlinkchild', 'notchecked');
            }
            if (
                !empty($sameparent)
            ) {
                $applyselectoptions = [
                    MOD_BOOKING_RECURRING_DONTUPDATE => get_string('dontapply', 'mod_booking'),
                    MOD_BOOKING_RECURRING_APPLY_TO_SIBLINGS => get_string('confirmrecurringoptionapplychanges', 'mod_booking'),
                    MOD_BOOKING_RECURRING_OVERWRITE_SIBLINGS => get_string('confirmrecurringoptionoverwrite', 'mod_booking'),
                ];
                $mform->addElement(
                    'select',
                    'apply_to_siblings',
                    get_string('recurringselectapplysiblings', 'mod_booking'),
                    $applyselectoptions
                );
                $mform->setDefault('apply_to_siblings', MOD_BOOKING_RECURRING_DONTUPDATE);
                $mform->hideIf('apply_to_siblings', 'validated_once', 'eq', 0);
            }
        } else if ($formdata['id']) {
            // In case there is no active PRO License disable the whole section.
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
        // Hidden input to track if the form has been validated before.
        $mform->addElement('hidden', 'validated_once', 0);
        $mform->setDefault('validated_once', 0);
        $mform->setType('validated_once', PARAM_INT);
    }

    /**
     * Definition after data callback
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     * @return void
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {
        $nosubmit = false;
        foreach ($mform->_noSubmitButtons as $key) {
            if (isset($formdata[$key])) {
                $nosubmit = true;
                break;
            }
        }
        if (
            !$nosubmit
            && isset($formdata['validated_once'])
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
            [$newoptiondates, $highestindex] = dates::get_list_of_submitted_dates((array)$templateoption);
            $delta = $data->howoftentorepeat;
            for ($i = 1; $i <= $data->howmanytimestorepeat; $i++) {
                // Handle dates.
                unset($templateoption->id, $templateoption->identifier, $templateoption->optionid);
                foreach ($newoptiondates as $newoptiondate) {
                    $key = MOD_BOOKING_FORM_OPTIONDATEID . $newoptiondate["index"];
                    $templateoption->{$key} = 0;
                    $key = MOD_BOOKING_FORM_COURSESTARTTIME . $newoptiondate["index"];
                    $templateoption->{$key} = strtotime("+ 1 $delta", $templateoption->{$key});
                    $key = MOD_BOOKING_FORM_COURSEENDTIME . $newoptiondate["index"];
                    $templateoption->{$key} = strtotime("+ 1 $delta", $templateoption->{$key});
                }
                // Handle setting: condition that previous option needs to be booked.
                if ($data->requirepreviousoptionstobebooked == 1) {
                    $templateoption->bo_cond_previouslybooked_restrict = "1";
                    $templateoption->bo_cond_previouslybooked_optionid = "$restrictoptionid";
                }

                // Add info about delta and index of option to jsondata.
                $childdata = (object) [
                    'delta' => $delta,
                    'index' => $i,
                ];
                booking_option::add_data_to_json($templateoption, 'recurringchilddata', $childdata);

                // Apply delay in bookingopening- and bookingclosingtime.
                if (isset($data->bookingopeningtime)) {
                    $templateoption->bookingopeningtime = strtotime("+ $i $delta", $data->bookingopeningtime);
                }
                if (isset($data->bookingclosingtime)) {
                    $templateoption->bookingclosingtime = strtotime("+ $i $delta", $data->bookingclosingtime);
                }

                $restrictoptionid = booking_option::update((object) $templateoption, $context);
            }
        }
        if (!empty($data->deleteallchildren)) {
            $childrenids = self::allchildrenaction(
                $data->id,
                MOD_BOOKING_ALL_CHILDRED_DELETE,
                $data->cmid
            );
            if (!empty($childrenids)) {
                $changes = [
                    'changes' => [
                        'fieldname' => "recurringoptions",
                        'oldvalue' => "linkedchildrendeleted: " . implode(", ", $childrenids),
                        'newvalue' => "",
                        'formkey' => "recurringoptions",
                    ],
                ];
            };
        } else if (!empty($data->unlinkallchildren)) {
            $childrenids = self::allchildrenaction(
                $data->id,
                MOD_BOOKING_ALL_CHILDRED_UNLINK,
                $data->cmid
            );
            if (!empty($childrenids)) {
                $changes = [
                    'changes' => [
                        'fieldname' => "recurringoptions",
                        'oldvalue' => "linkedchildren: " . implode(", ", $childrenids),
                        'newvalue' => "",
                        'formkey' => "recurringoptions",
                    ],
                ];
            };
        }
        if (!empty($data->unlinkchild)) {
            self::unlink_child(
                $data->optionid
            );
            $changes = [
                'changes' => [
                    'fieldname' => "recurringoptions",
                    'oldvalue' => "childremoved: " . $data->optionid,
                    'newvalue' => "",
                    'formkey' => "recurringoptions",
                ],
            ];
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

        if (empty($data['validated_once'])) {
            if (isset($data['apply_to_children'])) {
                $errors['apply_to_children'] = get_string('confirmrecurringoptionerror', 'mod_booking');
            }
            if (isset($data['apply_to_siblings'])) {
                $errors['apply_to_siblings'] = get_string('confirmrecurringoptionerror', 'mod_booking');
            }
        }
        return $errors;
    }

    /**
     * Update options either children or following siblings.
     *
     * @param int $optionid
     * @param array $changes
     * @param object $data
     * @param object $oldoption
     * @param int $typeofoptions
     *
     * @return void
     *
     */
    public static function update_options(
        int $optionid,
        array $changes,
        object $data,
        object $oldoption,
        int $typeofoptions
    ) {
        global $DB;
        $overwrite = false;
        switch ($typeofoptions) {
            case MOD_BOOKING_RECURRING_UPDATE_CHILDREN:
                $records = $DB->get_records('booking_options', ['parentid' => $optionid]);
                if ($data->apply_to_children == MOD_BOOKING_RECURRING_OVERWRITE_CHILDREN) {
                    $overwrite = true;
                }
                break;
            case MOD_BOOKING_RECURRING_UPDATE_SIBLINGS:
                $conditions = [
                    'parentid' => $oldoption->parentid,
                ];
                $select = "parentid = :parentid";
                $allsiblings = $DB->get_records_select(
                    'booking_options',
                    $select,
                    $conditions
                );
                $optionjson = json_decode($allsiblings[$oldoption->id]->json);
                $i = $optionjson->recurringchilddata->index ?? 0;

                $records = array_filter(
                    $allsiblings,
                    fn($r) => (($ri = json_decode($r->json)->recurringchilddata->index ?? 0) === 0) || $ri > $i
                );
                if ($data->apply_to_siblings == MOD_BOOKING_RECURRING_OVERWRITE_SIBLINGS) {
                    $overwrite = true;
                }
                break;
        }
        self::update_records($changes, $data, $oldoption, $records, $overwrite);
    }

    /**
     * If there are changes, apply them to the children.
     *
     * @param array $changes
     * @param object $originaldata
     * @param object $oldoption
     * @param array $records
     * @param bool $overwrite
     *
     *
     */
    private static function update_records(
        array $changes,
        object $originaldata,
        object $oldoption,
        array $records,
        bool $overwrite = false
    ) {
        if (!empty($records)) {
            $context = context_module::instance($originaldata->cmid);

            [$newparentoptiondates, $highestindex] = dates::get_list_of_submitted_dates((array)$originaldata);

            foreach ($records as $index => $child) {
                $data = clone $originaldata;
                // Loop through the changes.
                $childdata = (object)[
                    'id' => $child->id,
                    'cmid' => $data->cmid,
                ];
                fields_info::set_data($childdata);
                $update = false;
                if (!$overwrite) {
                    foreach ($changes as $change) {
                        if (empty($change['changes'])) {
                            continue;
                        }
                        switch ($change['changes']['fieldname']) {
                            case "dates":
                                self::update_recurring_date_sessions($childdata, $newparentoptiondates);
                                $update = true;
                                break;
                            case "bookingopeningtime":
                                self::apply_delta_to_field('bookingopeningtime', $childdata, $originaldata);
                                $update = true;
                                break;
                            case "bookingclosingtime":
                                self::apply_delta_to_field('bookingclosingtime', $childdata, $originaldata);
                                $update = true;
                                break;
                            default:
                                $fieldname = $change['changes']['formkey'] ?? '';
                                $newvalue = $change['changes']['newvalue'] ?? '';

                                // If the field exists and the value is different, update it.
                                if (isset($childdata->$fieldname) && $childdata->$fieldname !== $newvalue) {
                                    $childdata->$fieldname = $newvalue;
                                    $update = true;
                                }
                                break;
                        }
                    }
                } else {
                    // This is case overwrite.
                    $childdatastore = clone $childdata;
                    $childdata = $data;
                    $childdata->id = $child->id;

                    // Make sure to unset further recurring options in dependent options.
                    $recurringkeys = [
                        'apply_to_children',
                        'apply_to_siblings',
                        'unlinkchild',
                    ];
                    foreach ($recurringkeys as $key) {
                        unset($childdata->$key);
                    }

                    self::update_recurring_date_sessions($childdata, $newparentoptiondates, $childdatastore);

                    // Make sure to keep specific data in child.
                    $json = json_decode($child->json);
                    booking_option::add_data_to_json($childdata, 'recurringchilddata', $json->recurringchilddata);

                    // Apply delta once json is set correctly.
                    self::apply_delta_to_field('bookingopeningtime', $childdata, $originaldata);
                    self::apply_delta_to_field('bookingclosingtime', $childdata, $originaldata);
                    $update = true;
                }
                // Update the data record after all changes are made.
                if ($update) {
                    // Keep parentid if it's already set. Otherwise we fallback and use the id of the template (parent) option.
                    $childdata->identifier = $child->identifier ?? '';
                    $childdata->parentid = $child->parentid ?? $data->optionid ?? 0;
                    booking_option::update((object) $childdata, $context);
                }
            }
        }
    }

    /**
     * Apply the defined delta of a child to a datefield.
     *
     * @param string $fieldname
     * @param object $datatoupdate
     * @param object $originaldata
     *
     * @return bool
     *
     */
    private static function apply_delta_to_field(string $fieldname, object &$datatoupdate, object $originaldata) {
        if (empty($originaldata->$fieldname)) {
            $datatoupdate->$fieldname = 0;
            return true;
        }
        $data = json_decode($datatoupdate->json);
        if (!$data || !isset($data->recurringchilddata)) {
            return false;
        }
        $d = $data->recurringchilddata->delta;
        $i = $data->recurringchilddata->index;
        $datatoupdate->$fieldname = strtotime("+ $i $d", $originaldata->$fieldname);

        if (
            $fieldname == 'bookingopeningtime'
            && !(empty($datatoupdate->$fieldname))
        ) {
            $datatoupdate->restrictanswerperiodopening = 1;
        }
        if (
            $fieldname == 'bookingclosingtime'
            && !(empty($datatoupdate->$fieldname))
        ) {
            $datatoupdate->restrictanswerperiodclosing = 1;
        }
        return true;
    }

    /**
     * Create recurring date sessions.
     *
     * @param object $childdatatoupdate
     * @param array $newparentoptiondates
     * @param object|null $childdatatoread
     *
     * @return void
     *
     */
    private static function update_recurring_date_sessions(
        object &$childdatatoupdate,
        array $newparentoptiondates,
        $childdatatoread = null
    ) {
        // In case there is change in dates or everything is overwritten, apply all dates with delta.

        $childdatatoread = $childdatatoread ?? $childdatatoupdate;
        $json = json_decode($childdatatoread->json);
        $delta = $json->recurringchilddata->delta ?? '';
        $index = $json->recurringchilddata->index ?? 0;

        [$childoptiondates, $highestindexchild] = dates::get_list_of_submitted_dates((array)$childdatatoupdate);

        // Unset all dates.
        for ($i = 1; $i <= $highestindexchild; $i++) {
            unset($childdatatoupdate->{MOD_BOOKING_FORM_OPTIONDATEID . $i});
            unset($childdatatoupdate->{MOD_BOOKING_FORM_COURSESTARTTIME . $i});
            unset($childdatatoupdate->{MOD_BOOKING_FORM_COURSEENDTIME . $i});
            unset($childdatatoupdate->{MOD_BOOKING_FORM_DAYSTONOTIFY . $i});
        }
        if (empty($delta) || empty($index)) {
            // No delta or index, don't update the dates.
            return;
        }

        $d = "+ $index $delta";
        foreach ($newparentoptiondates as $newparentoptiondate) {
            // Set the timestamp including the corresponding delta.
            $key = MOD_BOOKING_FORM_OPTIONDATEID . $newparentoptiondate["index"];
            $childdatatoupdate->{$key} = 0;
            $key = MOD_BOOKING_FORM_COURSESTARTTIME . $newparentoptiondate["index"];
            $coursestarttimekey = rtrim(MOD_BOOKING_FORM_COURSESTARTTIME, "_");
            $childdatatoupdate->{$key} = strtotime($d, $newparentoptiondate[$coursestarttimekey]);
            $key = MOD_BOOKING_FORM_COURSEENDTIME . $newparentoptiondate["index"];
            $courseendtimekey = rtrim(MOD_BOOKING_FORM_COURSEENDTIME, "_");
            $childdatatoupdate->{$key} = strtotime($d, $newparentoptiondate[$courseendtimekey]);
            $key = MOD_BOOKING_FORM_DAYSTONOTIFY . $newparentoptiondate["index"];
            $dayskey = rtrim(MOD_BOOKING_FORM_DAYSTONOTIFY, "_");
            $childdatatoupdate->{$key} = $newparentoptiondate[$dayskey];
        }
    }

    /**
     * Treat all children with an action, either unlink or delete.
     *
     * @param int $optionid
     * @param int $action
     * @param int $cmid // Optional.
     *
     * @return array
     *
     */
    private static function allchildrenaction(int $optionid, int $action, int $cmid = 0): array {
        global $DB;

        $children = $DB->get_records_select(
            'booking_options',
            'parentid = :parentid',
            ['parentid' => $optionid],
            'id, parentid'
        );
        try {
            foreach ($children as $child) {
                switch ($action) {
                    case MOD_BOOKING_ALL_CHILDRED_UNLINK: // Unlink.
                        self::unlink_child($child->id);
                        break;
                    case MOD_BOOKING_ALL_CHILDRED_DELETE: // Delete.
                        if (empty($cmid)) {
                            $settings = singleton_service::get_instance_of_booking_by_optionid($optionid);
                            $cmid = $settings->cmid;
                        }
                        $bo = new booking_option($cmid, $child->id);
                        $bo->delete_booking_option();
                        break;
                }
            };
        } catch (moodle_exception $e) {
            return [];
        }
        return array_keys($children);
    }

    /**
     * Unlink a child from its parent.
     *
     * @param int $childid
     *
     * @return void
     *
     */
    private static function unlink_child(int $childid) {
        $option = singleton_service::get_instance_of_booking_by_optionid($childid);
        $optiondata = (object)[
            'cmid' => $option->cmid,
            'id' => $childid, // In the context of option_form class, id always refers to optionid.
            'optionid' => $childid, // Just kept on for legacy reasons.
        ];
        fields_info::set_data($optiondata);
        booking_option::remove_key_from_json($optiondata, 'recurringchilddata');
        $optiondata->parentid = 0;
        booking_option::update($optiondata);
    }

    /**
     * Once all changes are collected, also those triggered in save data, this is a possible hook for the fields.
     *
     * @param array $changes
     * @param object $data
     * @param object $newoption
     * @param object $originaloption
     *
     * @return void
     *
     */
    public static function changes_collected_action(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
        if (
            isset($data->apply_to_children)
            && !empty($data->apply_to_children)
        ) {
            self::update_options(
                $newoption->id,
                $changes,
                $data,
                $originaloption,
                MOD_BOOKING_RECURRING_UPDATE_CHILDREN
            );
        }

        if (
            isset($data->apply_to_siblings)
            && !empty($data->apply_to_siblings)
        ) {
            self::update_options(
                $newoption->id,
                $changes,
                $data,
                $originaloption,
                MOD_BOOKING_RECURRING_UPDATE_SIBLINGS
            );
        }
    }
}

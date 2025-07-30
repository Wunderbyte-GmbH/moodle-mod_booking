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

use core\event\competency_user_evidence_created;
use core_competency\api;
use core_competency\competency;
use core_competency\competency_framework;
use core_competency\user_evidence_competency;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\shortcodes;
use mod_booking\singleton_service;
use core_competency\user_evidence;
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
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competencies extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_COMPETENCIES;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_COMPETENCIES;

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
        "competency",
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
        $changes = [];
        $instance = new competencies();
        $key = fields_info::get_class_name(static::class);
        $value = $formdata->{$key} ?? null;

        // Important: get changes as array/object, before converting to string.
        $changes = $instance->check_for_changes($formdata, $instance);

        if (!empty($value)) {
            $stringvalue = implode(',', $value);
            $newoption->$key = $stringvalue;
            $formdata->$key = $stringvalue;
        } else {
            $newoption->$key = '';
            $formdata->$key = '';
        }
        return $changes;
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
            !get_config('booking', 'usecompetencies')
        ) {
            return;
        }
        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }
        $competencies = self::get_competencies_including_framework();
        if (empty($competencies)) {
            $mform->addElement(
                'static',
                'nocompetency',
                get_string('competencynonefound', 'mod_booking')
            );
        } else {
            $mform->addElement(
                'autocomplete',
                'competencies',
                get_string('competencychoose', 'mod_booking'),
                $competencies,
                ['multiple' => true]
            );
        }
        // Create url for button leading to creation of new competencies.
        $url = new moodle_url('/admin/tool/lp/competencyframeworks.php', [
            'pagecontextid' => 1,
        ]);
        $mform->addElement(
            'html',
            get_string('createcompetencylink', 'mod_booking', $url->out(true))
        );
    }

    /**
     * Get all given competencies grouped by their frameworks.
     *
     * @return array
     *
     */
    private static function get_competencies_including_framework(): array {
        $flat = [];

        // Get all frameworks.
        $frameworks = competency_framework::get_records();

        $onlyoneframework = false;
        if (count($frameworks) === 1) {
            $onlyoneframework = true;
        }
        foreach ($frameworks as $fw) {
            $frameworkname = format_string($fw->get('shortname'));

            // Get all competencies for this framework.
            $competencies = competency::get_records(['competencyframeworkid' => $fw->get('id')]);

            if (!$competencies) {
                continue;
            }

            foreach ($competencies as $comp) {
                $label = "";
                if (!$onlyoneframework) {
                    $label .= $frameworkname . ': ';
                }
                $label .= format_string($comp->get('shortname'));
                $flat[$comp->get('id')] = $label;
            }
        }

        return $flat;
    }

    /**
     * Return values for bookingoption_updated event.
     *
     * @param array $changes
     *
     * @return array
     *
     */
    public function get_changes_description(array $changes): array {
        $oldcompetencies = $changes['oldvalue'] ?? [];
        $newcompetencies = $changes['newvalue'] ?? [];

        // Ensure we have two arrays.
        if (!empty($oldcompetencies) && !is_array($oldcompetencies)) {
            $oldcompetencies = explode(',', $oldcompetencies) ?? [];
        }
        if (!empty($newcompetencies) && !is_array($newcompetencies)) {
            $newcompetencies = explode(',', $newcompetencies) ?? [];
        }
        // Get array of competencies.
        $competencies = self::get_competencies_including_framework();
        // Process each changes to get readable competency names.
        $oldvalue = [];
        $newvalue = [];
        foreach ($oldcompetencies as $compid) {
            $oldvalue[] = get_string(
                'changesinentity',
                'mod_booking',
                (object) ['id' => $compid, 'name' => $competencies[$compid] ?? '']
            );
        }
        foreach ($newcompetencies as $compid) {
            $newvalue[] = get_string(
                'changesinentity',
                'mod_booking',
                (object) ['id' => $compid, 'name' => $competencies[$compid] ?? '']
            );
        }
        // Create readable description.
        $fieldnamestring = get_string($changes['fieldname'], 'booking');
        $infotext = get_string('changeinfochanged', 'booking', $fieldnamestring);
        $oldvalue = !empty($oldvalue) ? implode(', ', $oldvalue) : '';
        $newvalue = !empty($newvalue) ? implode(', ', $newvalue) : '';

        $returnarray = [
            'oldvalue' => $oldvalue,
            'newvalue' => $newvalue,
            'fieldname' => get_string($changes['fieldname'], 'booking'),
        ];

        if (empty($oldvalue) && empty($newvalue)) {
            $returnarray['info'] = $infotext;
        }

        return $returnarray;
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        $key = fields_info::get_class_name(static::class);
        // Normally, we don't call set data after the first time loading.
        if (isset($data->{$key})) {
            return;
        }

        $value = $settings->{$key};
        if (!empty($value)) {
            $value = explode(',', $value);
        }

        $data->{$key} = $value;
    }

    /**
     * Definition after data callback
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     * @return void
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {
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

        return $errors;
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
    }

    /**
     * Assign competencies for user and return acquired competencies.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     *
     * @return array
     *
     */
    public static function assign_competencies(int $cmid, int $optionid, int $userid) {

        $bo = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $competencies = explode(',', $bo->settings->competencies ?? '');

        foreach ($competencies as $competencyid) {
            // Make sure empty competencies won't be a problem.
            if (empty($competencyid) || !is_numeric($competencyid)) {
                continue;
            }

            // Assign competence to user.
            $uc = api::get_user_competency($userid, $competencyid);

            // Link competence to user evidence to make it visible in the UI.
            // One competence can have multiple evidences.
            $record = new stdClass();
            $record->userid = $userid;
            $record->name = "Completed booking option with id: $optionid";
            $record->description = "Auto evidence from mod_booking";
            $record->url = (new moodle_url('/mod/booking/optionview.php', [
                'cmid' => $cmid,
                'optionid' => $optionid,
                'userid' => $userid,
                ]))->out(false);
            $record->contextid = $cmid;
            $record->status = 1; // 1 = active
            $record->timecreated = time();
            $record->timemodified = time();

            $userevidence = new user_evidence(0, $record);
            $userevidence->create();

            // Also create the event for the evidence.
            competency_user_evidence_created::create_from_user_evidence($userevidence);

            $link = new stdClass();
            $link->userevidenceid = $userevidence->get('id');
            $link->competencyid = $competencyid;

            $link = new user_evidence_competency(0, $link);
            $link->create();
            $grade = 1; // 1 = proficient; 0 = not proficient
            $note = 'Automatically graded by mod_booking plugin';

            // Assign and trigger event.
            api::grade_competency($userid, $competencyid, $grade, $note);
            // Now trigger the user event.
            $uc->read();
            // Create and trigger the evidence created event.
            $event = competency_user_evidence_created::create_from_user_evidence($userevidence);
            $event->trigger();
        }
        return $competencies;
    }

    /**
     * Resolve appelations of competencies.
     *
     * @return array
     */
    public static function get_filter_options(): array {
        $competencies = self::get_competencies_including_framework();
        $competencies['explode'] = ",";
        return $competencies;
    }

    /**
     * Return a rendered list of options with the same competencies assigned.
     *
     * @param string $competencies
     * @param booking_option|null $currentoption
     * @param bool $displayall
     * @return string
     */
    public static function get_list_of_similar_options(
        $competencies,
        $currentoption = null,
        $displayall = true
    ): string {
        if (
            !get_config('booking', 'usecompetencies')
            || empty($competencies)
        ) {
            return "";
        }

        $args = [
            'cmid' => isset($currentoption) && isset($currentoption->cmid) ? $currentoption->cmid : '',
            'columnfilter_competencies' => $competencies,
            'exclude' => 'competencies', // Make sure the button that triggers the filter is not displayed.
        ];
        if ($displayall) {
            $args['all'] = "true";
        }

        $env = new stdClass();
        $list = shortcodes::allbookingoptions('courselist', $args, null, $env, $env);
        return $list;
    }
}

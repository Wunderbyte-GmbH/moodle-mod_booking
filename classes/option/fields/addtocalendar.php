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

use context;
use context_module;
use context_system;
use html_writer;
use mod_booking\calendar;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use moodle_url;
use MoodleQuickForm;
use required_capability_exception;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * Handles the "Add to Moodle calendar" setting of a booking option (booking_options.addtocalendar):
 * 0 = no instance-wide event, 1 = course event, 2 = site event (visible to all users of the site).
 * Setting the value 2 requires the capability mod/booking:createcalendarsiteevents, which is
 * enforced in prepare_save_field() for ALL save paths (option form, bulk form, web service, import).
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addtocalendar extends field_base {
    /**
     * Capability needed to set "Add as site event" on a booking option.
     * @var string
     */
    public const CAP_CREATE_SITE_EVENTS = 'mod/booking:createcalendarsiteevents';

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_ADDTOCALENDAR;

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
    public static $header = MOD_BOOKING_HEADER_DATES;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [];

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

        global $DB;

        $optionid = $formdata->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $requested = isset($formdata->addtocalendar) ? (int)$formdata->addtocalendar : null;
        $stored = (int)($settings->addtocalendar ?? calendar::ADDTOCALENDAR_NONE);

        // Site events are visible to every user of the site, so SETTING the value requires a capability.
        // An option that already is a site event (set by a privileged user) may be saved by anyone
        // (the select is frozen in the form for users without the capability).
        if (
            $requested === calendar::ADDTOCALENDAR_SITE
            && $stored !== calendar::ADDTOCALENDAR_SITE
        ) {
            $context = self::get_context((int)($formdata->cmid ?? $settings->cmid ?? 0));
            if (!has_capability(self::CAP_CREATE_SITE_EVENTS, $context)) {
                // Defense in depth: neutralise the forbidden value in the (by-reference) form data first,
                // so that save_data() can never create site events even if the exception got swallowed
                // somewhere up the stack. Then fail loudly.
                $formdata->addtocalendar = $stored;
                $requested = $stored;
                throw new required_capability_exception($context, self::CAP_CREATE_SITE_EVENTS, 'nopermissions', '');
            }
        }

        // Delete calendar events if they are turned off in form.
        if ($requested === calendar::ADDTOCALENDAR_NONE) {
            if ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid])) {
                foreach ($optiondates as $optiondate) {
                    // Delete calendar course or site event for the optiondate.
                    if (
                        $DB->delete_records_select(
                            'event',
                            "eventtype IN ('course', 'site')
                            AND courseid <> 0
                            AND component = 'mod_booking'
                            AND uuid = :pattern",
                            ['pattern' => "{$optionid}-{$optiondate->id}"]
                        )
                    ) {
                        $optiondate->eventid = null;
                        $DB->update_record('booking_optiondates', $optiondate);
                    }
                }
            }
        }
        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

        $instance = new addtocalendar();
        $changes = $instance->check_for_changes($formdata, $instance);

        return $changes;
    }

    /**
     * Form validation: "Add as site event" needs the capability (unless the option already is a site event).
     * This gives a proper form error in the UI; the hard enforcement for all save paths is in prepare_save_field().
     *
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {
        if ((int)($data['addtocalendar'] ?? calendar::ADDTOCALENDAR_NONE) !== calendar::ADDTOCALENDAR_SITE) {
            return;
        }
        $optionid = (int)($data['id'] ?? $data['optionid'] ?? 0);
        $stored = calendar::ADDTOCALENDAR_NONE;
        if ($optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $stored = (int)($settings->addtocalendar ?? calendar::ADDTOCALENDAR_NONE);
        }
        if ($stored === calendar::ADDTOCALENDAR_SITE) {
            return;
        }
        if (!self::can_create_site_events((int)($data['cmid'] ?? 0))) {
            $errors['addtocalendar'] = get_string('error:nocapabilityforsiteevents', 'mod_booking');
        }
    }

    /**
     * Save data
     * @param stdClass $data
     * @param stdClass $option
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$data, stdClass &$option) {

        global $DB;

        $addtocalendar = (int)($data->addtocalendar ?? calendar::ADDTOCALENDAR_NONE);
        $expectedtype = calendar::instance_eventtype($addtocalendar);
        if ($expectedtype === null) {
            return;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        // We need to make sure not to run the calendar function on a template without a cmid.
        if (
            empty($settings->cmid)
            || !($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $option->id]))
        ) {
            return;
        }

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
        $courseid = !empty($bookingsettings->course) ? (int)$bookingsettings->course : 0;

        foreach ($optiondates as $optiondate) {
            if (!empty($optiondate->eventid) && $DB->record_exists('event', ['id' => $optiondate->eventid])) {
                // The event exists. Make sure it has the right type (course <-> site). This also repairs
                // events that were created with the OLD addtocalendar value by the bookingoptiondate_created
                // observer during this very save (the settings singleton may still hold the old value there).
                calendar::convert_instance_event(
                    (int)$optiondate->eventid,
                    $addtocalendar,
                    $courseid,
                    (int)$settings->bookingid
                );
                continue;
            }
            calendar::booking_optiondate_add_to_cal(
                $settings->cmid,
                $option->id,
                $optiondate,
                $settings->calendarid,
                0,
                $addtocalendar
            );
        }
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
        // Add header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $cmid = (int)($formdata['cmid'] ?? 0);
        // Stored value of the option (-1 = new option, no value yet). We deliberately use the
        // stored value and not a submitted one here.
        $optionid = (int)($formdata['id'] ?? $formdata['optionid'] ?? 0);
        $current = -1;
        if ($optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $current = isset($settings->addtocalendar) ? (int)$settings->addtocalendar : -1;
        }
        $cansite = self::can_create_site_events($cmid);

        // Add to calendar dropdown.
        $caleventtypes = [
            calendar::ADDTOCALENDAR_NONE => get_string('caldonotadd', 'mod_booking'),
            calendar::ADDTOCALENDAR_COURSE => get_string('caladdascourseevent', 'mod_booking'),
        ];
        // Site events can only be chosen with the capability. If the option already IS a site event,
        // we still show the value, but freeze the select below so it cannot be changed.
        if ($cansite || $current === calendar::ADDTOCALENDAR_SITE) {
            $caleventtypes[calendar::ADDTOCALENDAR_SITE] = get_string('caladdassiteevent', 'mod_booking');
        }
        $mform->addElement('select', 'addtocalendar', get_string('addtocalendar', 'mod_booking'), $caleventtypes);
        $mform->setDefault('addtocalendar', self::get_default_for_new_option($cansite));
        if ($mform->elementExists('selflearningcourse')) {
            $mform->hideIf('addtocalendar', 'selflearningcourse', 'eq', 1);
        }

        $locked = !empty(get_config('booking', 'addtocalendar_locked'));
        if ((!$cansite && $current === calendar::ADDTOCALENDAR_SITE) || $locked) {
            // Without the capability, a site event set by a privileged user must not be changed.
            // If the setting is locked in settings.php it will be frozen as well.
            $mform->freeze('addtocalendar');
        }

        // Tell administrators WHY the field is locked and where it can be unlocked.
        if ($locked && has_capability('moodle/site:config', context_system::instance())) {
            $link = html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'modsettingbooking'], 'admin-addtocalendar_locked'),
                get_string('addtocalendarlockedhint_link', 'mod_booking'),
                ['target' => '_blank']
            );
            $mform->addElement(
                'static',
                'addtocalendarlockedhint',
                '',
                '<div class="alert alert-info mb-0">' . get_string('addtocalendarlockedhint', 'mod_booking', $link) . '</div>'
            );
            if ($mform->elementExists('selflearningcourse')) {
                $mform->hideIf('addtocalendarlockedhint', 'selflearningcourse', 'eq', 1);
            }
        }
    }

    /**
     * Returns the value preselected in the "Add to Moodle calendar" dropdown for NEW booking options
     * (setting booking/addtocalendardefault). "Site event" falls back to "Course event" for users
     * who are not allowed to create site events.
     *
     * @param bool $cansite whether the current user may create site events
     * @return int one of the calendar::ADDTOCALENDAR_* constants
     */
    public static function get_default_for_new_option(bool $cansite): int {
        $default = (int)get_config('booking', 'addtocalendardefault');
        if (calendar::instance_eventtype($default) === null) {
            $default = calendar::ADDTOCALENDAR_NONE;
        }
        if ($default === calendar::ADDTOCALENDAR_SITE && !$cansite) {
            $default = calendar::ADDTOCALENDAR_COURSE;
        }
        return $default;
    }

    /**
     * Whether the current user may set "Add as site event" for booking options of the given instance.
     * Site admins always may (doanything).
     *
     * @param int $cmid course module id of the booking instance (0 = no instance, e.g. templates)
     * @return bool
     */
    public static function can_create_site_events(int $cmid): bool {
        return has_capability(self::CAP_CREATE_SITE_EVENTS, self::get_context($cmid));
    }

    /**
     * Context in which the site event capability is checked: the module context of the
     * booking instance, or the system context if there is no instance (e.g. templates).
     *
     * @param int $cmid
     * @return context
     */
    private static function get_context(int $cmid): context {
        return !empty($cmid) ? context_module::instance($cmid) : context_system::instance();
    }
}

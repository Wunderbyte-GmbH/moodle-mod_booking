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
 * Step definitions for bookingextension_todolist Behat tests.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use mod_booking\booking_option;
use mod_booking\singleton_service;

/**
 * Behat step definitions for the bookingextension_todolist plugin.
 */
class behat_bookingextension_todolist extends behat_base {
    /**
     * Navigate to the option view page for a named booking option.
     *
     * @Given /^I am on the option view page for option "([^"]*)" in booking "([^"]*)"$/
     * @param string $optiontext  The text/name of the booking option.
     * @param string $bookingname The name of the booking activity.
     * @return void
     */
    public function i_am_on_the_option_view_page_for_option_in_booking(
        string $optiontext,
        string $bookingname
    ): void {
        global $DB;

        $booking = $DB->get_record('booking', ['name' => $bookingname], '*', MUST_EXIST);
        $option  = $DB->get_record(
            'booking_options',
            ['bookingid' => (int)$booking->id, 'text' => $optiontext],
            '*',
            MUST_EXIST
        );
        $cm = get_coursemodule_from_instance('booking', (int)$booking->id, (int)$booking->course, false, MUST_EXIST);

        $url = new \moodle_url('/mod/booking/optionview.php', [
            'cmid'     => (int)$cm->id,
            'optionid' => (int)$option->id,
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Enable the todo list on a booking option and seed its items via the proper APIs.
     *
     * Uses booking_option::update() (with importing flag) to persist enable_todolist in the
     * option JSON, then todolist_helper::replace_items() to create the individual item rows.
     * Capability archetype defaults are applied via assign_capability() so that subsequent
     * browser requests can pass the has_capability() check without a stale MUC cache.
     *
     * Items are supplied as a newline-separated string.  Because Gherkin passes literal
     * backslash-n when writing \n inside a double-quoted argument, this method converts
     * that two-character sequence to a real newline before splitting.
     *
     * @Given /^I configure todo list "([^"]*)" for option "([^"]*)" in booking "([^"]*)"$/
     * @param string $itemsmultiline Newline-separated list of item texts (Gherkin \n or real newlines).
     * @param string $optiontext     The text/name of the booking option.
     * @param string $bookingname    The name of the booking activity.
     * @return void
     */
    public function i_configure_todo_list_for_option_in_booking(
        string $itemsmultiline,
        string $optiontext,
        string $bookingname
    ): void {
        global $DB, $USER;

        $booking = $DB->get_record('booking', ['name' => $bookingname], '*', MUST_EXIST);
        $option  = $DB->get_record(
            'booking_options',
            ['bookingid' => (int)$booking->id, 'text' => $optiontext],
            '*',
            MUST_EXIST
        );
        $cm = get_coursemodule_from_instance('booking', (int)$booking->id, (int)$booking->course, false, MUST_EXIST);

        // Convert Gherkin literal \n (two chars backslash + n) to real newlines.
        $itemsmultiline = str_replace('\n', "\n", $itemsmultiline);

        // Build the import data object. booking_option::update() with importing=true calls
        // fields_info::set_data() on each field, then prepare_save_fields() and save_data().
        // The todolist field's set_data() reads enable_todolist and todolist_items directly
        // from $data when importing=true; save_data() then calls todolist_helper::replace_items().
        $data = new \stdClass();
        $data->id              = (int)$option->id;
        $data->bookingid       = (int)$booking->id;
        $data->cmid            = (int)$cm->id;
        $data->text            = $option->text;
        $data->importing       = true;
        $data->enable_todolist = 1;
        // The $data->todolist property is the import field identifier checked by fields_info::ignore_class();
        // It must be set (even empty) so the todolist field class is not skipped during import.
        $data->todolist        = '';
        $data->todolist_items  = $itemsmultiline;
        $data->todolist_reset_completed_confirmation = 1;

        // Ensure todolist archetype capabilities are in role_capabilities so that the
        // subsequent browser request passes the has_capability() check.
        $this->ensure_todolist_archetype_capabilities();

        // Run as admin so the has_capability('edittodolist') check in save_data() passes.
        $previoususer = $USER;
        \core\session\manager::set_user(get_admin());

        booking_option::update($data);

        \core\session\manager::set_user($previoususer);

        // Destroy the singleton cache so the next page load reads fresh option data.
        singleton_service::destroy_instance();
    }

    /**
     * Ensure bookingextension_todolist archetype capabilities are present in role_capabilities.
     *
     * In some Behat environments the subplugin capabilities are not automatically assigned to
     * archetype roles (update_capabilities only sets defaults for *new* capabilities, and the
     * Behat snapshot may have been taken before this plugin existed).  assign_capability() is
     * the correct Moodle API: it writes to role_capabilities and fires the capability_assigned
     * event so that the role-definition MUC cache is invalidated for subsequent requests.
     *
     * @return void
     */
    protected function ensure_todolist_archetype_capabilities(): void {
        global $DB;

        $capsmap = [
            'bookingextension/todolist:viewtodolist'  => ['student', 'teacher', 'editingteacher', 'manager'],
            'bookingextension/todolist:checktodolist' => ['teacher', 'editingteacher', 'manager'],
            'bookingextension/todolist:edittodolist'  => ['editingteacher', 'manager'],
        ];

        $systemcontext = \context_system::instance();

        foreach ($capsmap as $capname => $rolenames) {
            foreach ($rolenames as $rolename) {
                $role = $DB->get_record('role', ['shortname' => $rolename], 'id', IGNORE_MISSING);
                if (!$role) {
                    continue;
                }
                // The assign_capability() function stores the capability name string (not integer ID),
                // fires the capability_assigned event and handles cache invalidation correctly.
                assign_capability($capname, CAP_ALLOW, (int)$role->id, $systemcontext->id, true);
            }
        }

        // Flush the in-process accesslib caches and the shared role-definition MUC cache so
        // the next browser request picks up the freshly written rows.
        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }
}

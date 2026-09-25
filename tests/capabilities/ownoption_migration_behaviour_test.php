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
 * What the migration of mod/booking:addeditownoption changes for an existing role.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\form\modal_confirmcancel;
use mod_booking\local\option_edit_access;
use mod_booking\local\ownoption_capabilities;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\capability_testcase;
use navigation_node;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The migration of mod/booking:addeditownoption must not widen any role.
 *
 * Before the split (e.g. branch USI, version 2026083101) a role holding
 * addeditownoption but not updatebooking could NOT duplicate or cancel an
 * option, not even one the user teaches:
 * - col_action() rendered "duplicate" and "cancel" only inside the
 *   updatebooking block of the action menu,
 * - editoptions.php refused the duplicate link (optionid -1): addeditownoption
 *   was only accepted together with booking_check_if_teacher($optionid),
 * - modal_confirmcancel required updatebooking.
 *
 * Every test builds the database as before the upgrade (the new capabilities
 * are not installed; addeditownoption is held by its archetypes editingteacher
 * and manager and by a custom trainer role that holds nothing else),
 * runs the upgrade and checks that the role keeps what it could do and gains
 * nothing: duplicateownoption and cancelownoption are new actions and must not
 * come from the migration (Wunderbyte-GmbH/moodle-mod_booking#1602).
 * The details of what the role can do are covered in own_option_role_test.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ownoption_migration_behaviour_test extends capability_testcase {
    /**
     * The former addeditownoption role gets the capabilities that replace
     * addeditownoption, but neither duplicateownoption nor cancelownoption.
     *
     * @covers \mod_booking\local\ownoption_capabilities::copy_permissions_to_installed_capabilities
     * @covers ::update_capabilities
     */
    public function test_upgrade_keeps_role_but_grants_no_duplicate_or_cancel(): void {
        [$user, $own] = $this->install_old_role_and_upgrade();
        $context = context_module::instance((int)$own->cmid);

        $this->assertFalse(
            has_capability('mod/booking:updatebooking', $context, $user),
            'Precondition: the role has no updatebooking, the old gate of duplicate and cancel.'
        );
        foreach (ownoption_capabilities::CLONED_CAPABILITIES as $capability) {
            $this->assertTrue(has_capability($capability, $context, $user), "$capability keeps the old permission.");
        }
        foreach (ownoption_capabilities::NOT_CLONED_CAPABILITIES as $capability) {
            $this->assertFalse(has_capability($capability, $context, $user), "$capability must not come from the upgrade.");
        }
    }

    /**
     * The action menu of the options list: after the upgrade the role still
     * edits its own option, but gets neither "duplicate" nor "cancel" - as
     * before the split.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_upgraded_role_sees_no_duplicate_or_cancel_in_menu(): void {
        global $PAGE;

        $PAGE->set_url('/mod/booking/view.php');
        [, $own] = $this->install_old_role_and_upgrade();

        $table = new bookingoptions_wbtable('ownoptionmigration_menu');
        $ownmenu = $table->col_action((object)['id' => (int)$own->id, 'status' => 0]);

        $this->assertStringContainsString(
            'editoptions.php?id=' . $own->cmid . '&amp;optionid=' . $own->id,
            $ownmenu,
            'Precondition: the role still edits its own option.'
        );
        foreach (['duplicatebookingoption', 'cancelthisbookingoption'] as $stringid) {
            $label = get_string($stringid, 'mod_booking');
            $this->assertStringNotContainsString($label, $ownmenu, "'$label' must not be offered after the upgrade.");
        }
    }

    /**
     * The settings navigation of the own option: after the upgrade the role
     * still gets "edit" and "manage bookings", but no "duplicate" - before the
     * split that node needed updatebooking.
     *
     * @covers ::booking_extend_settings_navigation
     */
    public function test_upgraded_role_gets_no_duplicate_in_navigation(): void {
        global $PAGE;

        [, $own] = $this->install_old_role_and_upgrade();
        [$course, $cm] = get_course_and_cm_from_cmid((int)$own->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $cm->id, 'optionid' => (int)$own->id]);

        $root = navigation_node::create('root');
        booking_extend_settings_navigation($PAGE->settingsnav, $root);
        $nodes = array_map('strval', $root->get_children_key_list());

        $this->assertContains('nav_edit', $nodes, 'Precondition: the role still gets "edit".');
        $this->assertContains('nav_manageresponses', $nodes, 'Precondition: the role still gets "manage bookings".');
        $this->assertNotContains('nav_duplicatebookingoption', $nodes, '"Duplicate" must not come from the upgrade.');
    }

    /**
     * The server side gates: after the upgrade the role neither opens the
     * duplicate form (editoptions.php with optionid -1) nor passes the cancel
     * modal, not even for its own option - as before the split.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     */
    public function test_upgraded_role_passes_no_duplicate_or_cancel_gate(): void {
        global $CFG;

        [, $own] = $this->install_old_role_and_upgrade();

        // Production runs without debugging. With debugging on, booking_check_if_teacher(-1) of the
        // duplicate link throws before the gate answers (Wunderbyte-GmbH/moodle-mod_booking#1604).
        $CFG->debug = DEBUG_NONE;

        $this->assertFalse(
            option_edit_access::can_edit_option((int)$own->cmid, -1, (int)$own->id),
            'The duplicate link of the own option stays closed after the upgrade.'
        );
        $this->assert_blocked_by_capability(
            $this->run_form_access_check(modal_confirmcancel::class, ['optionid' => (int)$own->id]),
            'mod/booking:updatebooking'
        );
    }

    /**
     * The archetype defaults: an editing teacher (standard role) who teaches an
     * option did not get duplicate or cancel before the split and must not get
     * them from the archetypes the upgrade assigns to the new capabilities.
     *
     * @covers ::update_capabilities
     */
    public function test_upgrade_grants_no_duplicate_or_cancel_to_editing_teachers(): void {
        global $DB;

        [, $own] = $this->install_old_role_and_upgrade();
        $context = context_module::instance((int)$own->cmid);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$this->course->id, 'editingteacher');
        $this->make_teacher_of((int)$own->id, (int)$teacher->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(
            has_capability('mod/booking:editownoption', $context, $teacher),
            'Precondition: editing teachers keep the edit capability of their archetype.'
        );
        foreach (ownoption_capabilities::NOT_CLONED_CAPABILITIES as $capability) {
            $this->assertFalse(has_capability($capability, $context, $teacher), "Editing teachers must not get $capability.");
        }
        $managerid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        foreach (ownoption_capabilities::NOT_CLONED_CAPABILITIES as $capability) {
            $this->assertEquals(
                [$managerid],
                array_map('intval', $DB->get_fieldset_select(
                    'role_capabilities',
                    'roleid',
                    'capability = :capability AND permission = :permission',
                    ['capability' => $capability, 'permission' => CAP_ALLOW]
                )),
                "Only the manager archetype holds $capability after the upgrade."
            );
        }
    }

    /**
     * Builds the database as before the upgrade, runs the upgrade and logs in
     * the user holding the former addeditownoption role.
     *
     * Before the upgrade: the new capabilities are not installed, the old one
     * is, held site wide by editingteacher, manager and a custom role that holds
     * nothing else. The user has the custom role in the booking activity and
     * teaches the first option.
     *
     * @return array{0: \stdClass, 1: booking_option_settings, 2: booking_option_settings}
     */
    private function install_old_role_and_upgrade(): array {
        global $DB;

        $own = $this->create_option();
        $other = $this->add_option('Option of somebody else');

        foreach (ownoption_capabilities::NEW_CAPABILITIES as $capability) {
            $DB->delete_records('role_capabilities', ['capability' => $capability]);
            $DB->delete_records('capabilities', ['name' => $capability]);
        }
        $DB->insert_record('capabilities', (object)[
            'name' => ownoption_capabilities::OLD_CAPABILITY,
            'captype' => 'write',
            'contextlevel' => CONTEXT_MODULE,
            'component' => 'mod_booking',
            'riskbitmask' => 0,
        ]);

        // The old capability had the archetypes editingteacher and manager, so a site with default
        // permissions has it on these roles - plus the custom trainer role under test.
        $roleid = (int)$this->getDataGenerator()->create_role(['shortname' => 'owntrainer']);
        $roleids = [
            $roleid,
            (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
            (int)$DB->get_field('role', 'id', ['shortname' => 'manager']),
        ];
        foreach ($roleids as $id) {
            $DB->insert_record('role_capabilities', (object)[
                'roleid' => $id,
                'contextid' => context_system::instance()->id,
                'capability' => ownoption_capabilities::OLD_CAPABILITY,
                'permission' => CAP_ALLOW,
                'timemodified' => time(),
                'modifierid' => 0,
            ]);
        }

        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, context_module::instance((int)$own->cmid)->id);
        $this->make_teacher_of((int)$own->id, (int)$user->id);

        // What the upgrade of mod_booking runs: upgrade.php, then update_capabilities().
        ownoption_capabilities::copy_permissions_to_installed_capabilities();
        update_capabilities('mod_booking');
        \cache::make('core', 'capabilities')->purge();
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(
            $DB->record_exists('capabilities', ['name' => ownoption_capabilities::OLD_CAPABILITY]),
            'The upgrade removed addeditownoption.'
        );

        $this->setUser($user);
        return [$user, $own, $other];
    }
}

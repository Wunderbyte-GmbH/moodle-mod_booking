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
 * Tests for the migration of mod/booking:addeditownoption.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_course;
use context_system;
use mod_booking\local\ownoption_capabilities;
use mod_booking\tests\capability_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The migration of mod/booking:addeditownoption to the capabilities for own
 * booking options.
 *
 * Every test first builds the database as it was BEFORE the upgrade: the old
 * capability is installed with some role permissions. Then it runs what the
 * upgrade runs: upgrade.php (ownoption_capabilities) and Moodle's
 * update_capabilities(), which clones and removes the old capability.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ownoption_capabilities_migration_test extends capability_testcase {
    /**
     * A site that has none of the new capabilities yet: every role permission of
     * addeditownoption is cloned into the capabilities that replace it - also
     * overrides in courses, and also where an admin removed the capability from
     * a standard role. cancelownoption and duplicateownoption only get their
     * archetype default (manager), nothing from addeditownoption.
     *
     * @covers \mod_booking\local\ownoption_capabilities::copy_permissions_to_installed_capabilities
     * @covers ::update_capabilities
     */
    public function test_upgrade_clones_all_permissions_into_new_capabilities(): void {
        global $DB;

        $oldpermissions = $this->install_old_capability_state(ownoption_capabilities::NEW_CAPABILITIES);

        $this->run_upgrade();

        foreach (ownoption_capabilities::CLONED_CAPABILITIES as $capability) {
            $this->assertSame($oldpermissions, $this->permissions_of($capability), "Permissions of $capability.");
        }
        $managerid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        $archetypeonly = [$managerid . '/' . context_system::instance()->id => CAP_ALLOW];
        foreach (ownoption_capabilities::NOT_CLONED_CAPABILITIES as $capability) {
            $this->assertSame($archetypeonly, $this->permissions_of($capability), "Permissions of $capability.");
        }
        $this->assert_old_capability_removed();
    }

    /**
     * A test system where some of the replacing capabilities are installed
     * already (with their default permissions): upgrade.php makes them equal to
     * addeditownoption, the others are cloned by Moodle.
     *
     * @covers \mod_booking\local\ownoption_capabilities::copy_permissions_to_installed_capabilities
     * @covers ::update_capabilities
     */
    public function test_upgrade_replaces_permissions_of_installed_capabilities(): void {
        $installed = ['mod/booking:editownoption', 'mod/booking:viewteacherreports'];
        $oldpermissions = $this->install_old_capability_state(
            array_diff(ownoption_capabilities::NEW_CAPABILITIES, $installed)
        );

        // Precondition: the installed ones have their defaults, which differ from the old capability.
        $this->assertNotSame($oldpermissions, $this->permissions_of('mod/booking:editownoption'));

        $this->run_upgrade();

        foreach (ownoption_capabilities::CLONED_CAPABILITIES as $capability) {
            $this->assertSame($oldpermissions, $this->permissions_of($capability), "Permissions of $capability.");
        }
        $this->assert_old_capability_removed();
    }

    /**
     * A test system where cancelownoption and duplicateownoption are installed
     * already: the upgrade leaves their permissions alone, it neither copies
     * addeditownoption into them nor removes what an admin granted.
     *
     * @covers \mod_booking\local\ownoption_capabilities::copy_permissions_to_installed_capabilities
     * @covers ::update_capabilities
     */
    public function test_upgrade_leaves_installed_not_cloned_capabilities_alone(): void {
        $this->install_old_capability_state(
            array_diff(ownoption_capabilities::NEW_CAPABILITIES, ownoption_capabilities::NOT_CLONED_CAPABILITIES)
        );
        $before = [];
        foreach (ownoption_capabilities::NOT_CLONED_CAPABILITIES as $capability) {
            $before[$capability] = $this->permissions_of($capability);
        }

        $this->run_upgrade();

        foreach (ownoption_capabilities::NOT_CLONED_CAPABILITIES as $capability) {
            $this->assertSame($before[$capability], $this->permissions_of($capability), "Permissions of $capability.");
        }
        $this->assert_old_capability_removed();
    }

    /**
     * Code that still asks for addeditownoption gets NO access any more, even for
     * a user holding all new capabilities: Moodle 4.5 shows a developer warning
     * naming the replacement, but checks the permissions of the old name, which
     * were removed. Other plugins (local_musi, local_urise, theme_musi) have to
     * check the new capabilities.
     *
     * @covers ::has_capability
     */
    public function test_deprecated_capability_gives_no_access_any_more(): void {
        $this->create_option();
        $context = \context_module::instance((int)$this->settings->cmid);

        $this->user_with(ownoption_capabilities::NEW_CAPABILITIES);
        $this->assertTrue(has_capability('mod/booking:editownoption', $context));

        $this->assertFalse(has_capability('mod/booking:addeditownoption', $context));
        $this->assertDebuggingCalled();
    }

    /**
     * Builds the database as before the upgrade.
     *
     * The old capability is installed and has these permissions:
     * - editingteacher: none at all (an admin removed it on the site),
     * - manager: allowed site wide,
     * - a custom role: allowed site wide, prohibited in one course.
     *
     * @param string[] $notinstalled new capabilities that do not exist yet
     * @return array the old permissions, see permissions_of()
     */
    private function install_old_capability_state(array $notinstalled): array {
        global $DB;

        $this->create_option();
        $syscontext = context_system::instance();
        $coursecontext = context_course::instance((int)$this->course->id);

        foreach ($notinstalled as $capability) {
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

        $managerid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        $customroleid = (int)$this->getDataGenerator()->create_role(['shortname' => 'owntrainer']);
        $rows = [
            [$managerid, $syscontext->id, CAP_ALLOW],
            [$customroleid, $syscontext->id, CAP_ALLOW],
            [$customroleid, $coursecontext->id, CAP_PROHIBIT],
        ];
        foreach ($rows as [$roleid, $contextid, $permission]) {
            $DB->insert_record('role_capabilities', (object)[
                'roleid' => $roleid,
                'contextid' => $contextid,
                'capability' => ownoption_capabilities::OLD_CAPABILITY,
                'permission' => $permission,
                'timemodified' => time(),
                'modifierid' => 0,
            ]);
        }

        $this->reset_capability_caches();
        return $this->permissions_of(ownoption_capabilities::OLD_CAPABILITY);
    }

    /**
     * Runs what the upgrade of mod_booking runs for capabilities: first
     * upgrade.php, then Moodle's update_capabilities().
     *
     * @return void
     */
    private function run_upgrade(): void {
        ownoption_capabilities::copy_permissions_to_installed_capabilities();
        update_capabilities('mod_booking');
        $this->reset_capability_caches();
    }

    /**
     * The role permissions of a capability, as sorted "roleid/contextid" => permission.
     *
     * @param string $capability
     * @return array
     */
    private function permissions_of(string $capability): array {
        global $DB;

        $permissions = [];
        foreach ($DB->get_records('role_capabilities', ['capability' => $capability]) as $row) {
            $permissions[$row->roleid . '/' . $row->contextid] = (int)$row->permission;
        }
        ksort($permissions);
        return $permissions;
    }

    /**
     * Asserts that the old capability and its permissions are gone.
     *
     * @return void
     */
    private function assert_old_capability_removed(): void {
        global $DB;

        $this->assertFalse($DB->record_exists('capabilities', ['name' => ownoption_capabilities::OLD_CAPABILITY]));
        $this->assertFalse($DB->record_exists('role_capabilities', ['capability' => ownoption_capabilities::OLD_CAPABILITY]));
    }

    /**
     * Clears the capability caches after changing the tables directly.
     *
     * @return void
     */
    private function reset_capability_caches(): void {
        \cache::make('core', 'capabilities')->purge();
        accesslib_clear_all_caches_for_unit_testing();
    }
}

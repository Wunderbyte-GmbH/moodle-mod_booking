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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\capability_defaults_sync;
use context_system;

/**
 * Archetype capability defaults must reach roles on existing installs (finding F41).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\capability_defaults_sync
 */
final class capability_defaults_sync_test extends advanced_testcase {
    /**
     * F41 replay: a manager role frozen in the pre-2026-07 state (skill capabilities
     * missing entirely) gets the declared archetype defaults back.
     */
    public function test_missing_archetype_defaults_are_reapplied(): void {
        global $DB;
        $this->resetAfterTest();

        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $syscontextid = (int)context_system::instance()->id;

        // Simulate the pre-dcabf0a install: these teacher-skill capabilities exist but the
        // manager role never received them (the exact F41 gap observed on the test VM).
        $frozencaps = [
            'bookingextension/agent:skill_core_get_current_user',
            'bookingextension/agent:skill_core_search_users',
            'bookingextension/agent:skill_wizard_search_skills',
        ];
        foreach ($frozencaps as $cap) {
            unassign_capability($cap, $managerroleid, $syscontextid);
            $this->assertFalse(
                $DB->record_exists('role_capabilities', [
                    'roleid' => $managerroleid, 'capability' => $cap, 'contextid' => $syscontextid,
                ]),
                "fixture: $cap must be absent before the sync"
            );
        }

        $granted = capability_defaults_sync::apply();

        foreach ($frozencaps as $cap) {
            $this->assertSame(
                (string)CAP_ALLOW,
                (string)$DB->get_field('role_capabilities', 'permission', [
                    'roleid' => $managerroleid, 'capability' => $cap, 'contextid' => $syscontextid,
                ]),
                "$cap must be re-granted to manager"
            );
            $this->assertContains($cap, (array)($granted['manager'] ?? []));
        }
    }

    /**
     * An explicit admin decision is never overridden: a capability set to PREVENT at the
     * system context stays PREVENT after the sync.
     */
    public function test_explicit_admin_setting_is_respected(): void {
        global $DB;
        $this->resetAfterTest();

        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $syscontextid = (int)context_system::instance()->id;
        $cap = 'bookingextension/agent:skill_core_search_users';

        assign_capability($cap, CAP_PREVENT, $managerroleid, $syscontextid, true);

        $granted = capability_defaults_sync::apply();

        $this->assertSame(
            (string)CAP_PREVENT,
            (string)$DB->get_field('role_capabilities', 'permission', [
                'roleid' => $managerroleid, 'capability' => $cap, 'contextid' => $syscontextid,
            ]),
            'an explicit PREVENT must survive the sync'
        );
        $this->assertNotContains($cap, (array)($granted['manager'] ?? []));
    }

    /**
     * The sync is idempotent: on a healthy site the second run grants nothing.
     */
    public function test_sync_is_idempotent_on_healthy_site(): void {
        $this->resetAfterTest();

        capability_defaults_sync::apply();
        $second = capability_defaults_sync::apply();

        $this->assertSame([], $second, 'a healthy site must produce zero grants');
    }
}

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
 * The discovery fallback capability must reach every authenticated agent user (finding F39b).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\capability_defaults_sync
 */
final class search_skills_fallback_capability_test extends advanced_testcase {
    /** @var string The fallback capability under test. */
    private const CAP = 'bookingextension/agent:skill_wizard_search_skills';

    /**
     * The capability is declared with honest read-only metadata (it is R0 catalog
     * introspection, not a write with data-loss risk).
     */
    public function test_capability_is_declared_read_only(): void {
        global $DB;
        $this->resetAfterTest();

        $record = $DB->get_record('capabilities', ['name' => self::CAP], '*', MUST_EXIST);

        $this->assertSame('read', (string)$record->captype);
        $this->assertSame(0, (int)$record->riskbitmask);
    }

    /**
     * A bare authenticated user (no role beyond the defaults) holds the fallback.
     */
    public function test_plain_authenticated_user_holds_the_fallback(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(
            has_capability(self::CAP, context_system::instance(), $user),
            'every authenticated user must hold the discovery fallback capability'
        );
    }

    /**
     * Deployment path for existing sites: a role frozen without the capability receives it
     * through the archetype-defaults sync — while an explicit admin withdrawal survives.
     */
    public function test_sync_deploys_the_fallback_but_respects_withdrawal(): void {
        global $DB;
        $this->resetAfterTest();

        $syscontextid = (int)context_system::instance()->id;
        $userroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        // Frozen pre-change site: the authenticated-user role never received the capability.
        unassign_capability(self::CAP, $userroleid, $syscontextid);
        // Deliberate admin withdrawal on manager.
        assign_capability(self::CAP, CAP_PREVENT, $managerroleid, $syscontextid, true);

        capability_defaults_sync::apply();

        $this->assertSame(
            (string)CAP_ALLOW,
            (string)$DB->get_field('role_capabilities', 'permission', [
                'roleid' => $userroleid, 'capability' => self::CAP, 'contextid' => $syscontextid,
            ]),
            'the sync must deploy the fallback to the authenticated-user role'
        );
        $this->assertSame(
            (string)CAP_PREVENT,
            (string)$DB->get_field('role_capabilities', 'permission', [
                'roleid' => $managerroleid, 'capability' => self::CAP, 'contextid' => $syscontextid,
            ]),
            'an explicit admin withdrawal must survive the sync'
        );
    }
}

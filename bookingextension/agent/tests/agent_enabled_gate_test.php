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
use context_system;
use core\hook\output\before_standard_head_html_generation;
use moodle_page;
use bookingextension_agent\local\hooks\page_injection;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * The "Enable Booking Wizard" checkbox (agent_enabled) must switch the whole engine off, not only the settings page.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\security\authorization_service
 * @covers     \bookingextension_agent\local\hooks\page_injection
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_enabled_gate_test extends advanced_testcase {
    /**
     * Run the navbar head hook on a fresh page as admin and return the collected end-of-page JS.
     *
     * @return string
     */
    private function run_navbar_hook(): string {
        global $PAGE;

        $this->setAdminUser();
        set_config('inject_in_navbar', 1, 'bookingextension_agent');

        $PAGE = new moodle_page();
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url('/my/index.php');
        $PAGE->set_pagelayout('standard');

        page_injection::extend_head(new before_standard_head_html_generation($PAGE->get_renderer('core')));
        return (string)$PAGE->requires->get_end_code();
    }

    /**
     * The positive cases only hold for the engine that owns the site: when the primary engine (local_wizard)
     * is installed, this bundled engine steps aside and must stay inactive whatever the checkbox says.
     */
    private function skip_unless_this_engine_owns_the_site(): void {
        if (authorization_service::primary_engine_takes_over()) {
            $this->markTestSkipped('the primary engine (local_wizard) owns the agent on this site');
        }
    }

    /**
     * Default off: with agent_enabled never saved (fresh install) the switch reads as disabled.
     */
    public function test_unset_agent_enabled_counts_as_disabled(): void {
        $this->resetAfterTest();
        unset_config('agent_enabled', 'bookingextension_agent');

        $this->assertFalse(authorization_service::is_agent_enabled());
    }

    /**
     * Default off: with agent_enabled never saved no wand is injected until an admin switches it on.
     */
    public function test_unset_agent_enabled_adds_no_navbar_wand(): void {
        $this->resetAfterTest();
        unset_config('agent_enabled', 'bookingextension_agent');

        $this->assertFalse(authorization_service::is_agent_engine_active());
        $this->assertStringNotContainsString('navbar_magic_wand', $this->run_navbar_hook());
    }

    /**
     * agent_enabled = 0 reads as switched off.
     */
    public function test_disabled_agent_switch_reads_off(): void {
        $this->resetAfterTest();
        set_config('agent_enabled', 0, 'bookingextension_agent');

        $this->assertFalse(authorization_service::is_agent_enabled());
    }

    /**
     * agent_enabled = 0 switches the navbar wand off.
     */
    public function test_disabled_agent_adds_no_navbar_wand(): void {
        $this->resetAfterTest();
        set_config('agent_enabled', 0, 'bookingextension_agent');

        $this->assertFalse(authorization_service::is_agent_engine_active());
        $this->assertStringNotContainsString('navbar_magic_wand', $this->run_navbar_hook());
    }

    /**
     * agent_enabled = 0 makes the webservice gate refuse, even for a user who holds every capability.
     */
    public function test_disabled_agent_refuses_webservice_gate(): void {
        $this->resetAfterTest();
        set_config('agent_enabled', 0, 'bookingextension_agent');
        $admin = get_admin();
        $ctxid = (int)context_system::instance()->id;

        $authz = new authorization_service();
        $problem = $authz->check_use_readiness((int)$admin->id, $ctxid);
        $this->assertIsArray($problem);
        $this->assertSame('agent_unavailable', $problem['code']);
        $this->assertFalse($authz->can_use((int)$admin->id, $ctxid));

        $this->expectException(\required_capability_exception::class);
        $authz->require_use_capability((int)$admin->id, $ctxid);
    }

    /**
     * Re-enabling (agent_enabled = 1) brings everything back without any further step.
     */
    public function test_enabled_agent_is_active(): void {
        $this->resetAfterTest();
        $this->skip_unless_this_engine_owns_the_site();
        set_config('agent_enabled', 1, 'bookingextension_agent');

        $this->assertTrue(authorization_service::is_agent_engine_active());
        $authz = new authorization_service();
        $this->assertNull($authz->check_use_readiness((int)get_admin()->id, (int)context_system::instance()->id));
    }
}

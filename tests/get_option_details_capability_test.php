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

namespace mod_booking;

use advanced_testcase;
use context_module;
use mod_booking\local\wizard\options\skills\get_option_details_skill;

/**
 * Capability-fidelity tests for booking.get_option_details (audit CAP-02).
 *
 * A teacher of instance A must not read the details of an option in instance B (no access), whether
 * by direct optionid or a system-context lookup. The hosting activity must be visible to the actor.
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\options\skills\get_option_details_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_option_details_capability_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Build two booking instances in two courses, an option in B, and a teacher in each.
     *
     * @return array{contexta:\context_module,contextb:\context_module,optionb:\stdClass,teachera:\stdClass,teacherb:\stdClass}
     */
    private function setup_two_instances(): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        /** @var \mod_booking_generator $bgen */
        $bgen = $gen->get_plugin_generator('mod_booking');

        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $bookinga = $gen->create_module('booking', ['course' => $coursea->id]);
        $bookingb = $gen->create_module('booking', ['course' => $courseb->id]);

        $optionb = $bgen->create_option([
            'bookingid' => (int)$bookingb->id,
            'text' => 'Option in B',
            'description' => 'Option in B',
        ]);

        $teachera = $gen->create_user();
        $teacherb = $gen->create_user();
        $gen->enrol_user($teachera->id, $coursea->id, 'editingteacher');
        $gen->enrol_user($teacherb->id, $courseb->id, 'editingteacher');

        return [
            'contexta' => context_module::instance($bookinga->cmid),
            'contextb' => context_module::instance($bookingb->cmid),
            'optionb' => $optionb,
            'teachera' => $teachera,
            'teacherb' => $teacherb,
        ];
    }

    /**
     * A teacher of instance A cannot read a B-option's details by direct optionid.
     */
    public function test_cross_instance_optionid_is_denied(): void {
        $env = $this->setup_two_instances();

        $this->setUser($env['teachera']);
        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$env['optionb']->id, 'requested_fields' => ['title']],
            (int)$env['contexta']->id,
            (int)$env['teachera']->id
        );

        $this->assertEmpty(
            (array)($result['optiondetails'] ?? []),
            'A teacher of instance A must not read an option in instance B by direct id.'
        );
    }

    /**
     * The same direct-id read from a system context (cmid=0) is also denied.
     */
    public function test_cross_instance_optionid_denied_in_system_context(): void {
        $env = $this->setup_two_instances();

        $this->setUser($env['teachera']);
        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$env['optionb']->id, 'requested_fields' => ['title']],
            (int)\context_system::instance()->id,
            (int)$env['teachera']->id
        );

        $this->assertEmpty(
            (array)($result['optiondetails'] ?? []),
            'System-context direct-id read of an inaccessible option must be denied.'
        );
    }

    /**
     * A fully privileged actor must still not cross the instance boundary in module context.
     *
     * The capability gate alone lets an admin through (the hosting activity is visible to them),
     * so this is the scope regression from Wunderbyte-GmbH/Wunderbyte-GmbH#2230: a chat running
     * in instance A answered about — and offered a booking card for — an option in instance B.
     */
    public function test_privileged_actor_cannot_cross_instance_in_module_context(): void {
        global $USER;

        $env = $this->setup_two_instances();

        $this->setAdminUser();
        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$env['optionb']->id, 'requested_fields' => ['title']],
            (int)$env['contexta']->id,
            (int)$USER->id
        );

        $this->assertSame('error', (string)($result['status'] ?? ''));
        $this->assertEmpty(
            (array)($result['optiondetails'] ?? []),
            'An option of another booking instance must not be readable from this module context.'
        );
        $this->assertEmpty(
            (array)($result['previewoptionids'] ?? []),
            'An out-of-scope option must not reach the preview card.'
        );
        $this->assertSame(
            get_string('agent_booking_details_error_option_out_of_scope', 'mod_booking'),
            (string)($result['detail'] ?? ''),
            'The rejection must name the instance scope rather than a generic resolution miss.'
        );
    }

    /**
     * A numeric optionquery ("88") must be rejected with the same scope wording as an optionid.
     *
     * Planners frequently pass a spoken option number through optionquery rather than optionid,
     * so the honest "belongs to another activity" answer must not depend on which field was used.
     */
    public function test_numeric_optionquery_from_other_instance_reports_scope(): void {
        global $USER;

        $env = $this->setup_two_instances();

        $this->setAdminUser();
        $result = (new get_option_details_skill())->execute(
            ['optionquery' => (string)$env['optionb']->id, 'requested_fields' => ['title']],
            (int)$env['contexta']->id,
            (int)$USER->id
        );

        $this->assertSame('error', (string)($result['status'] ?? ''));
        $this->assertEmpty((array)($result['optiondetails'] ?? []));
        $this->assertSame(
            get_string('agent_booking_details_error_option_out_of_scope', 'mod_booking'),
            (string)($result['detail'] ?? '')
        );
    }

    /**
     * A numeric query that matches no option at all stays a plain resolution miss.
     */
    public function test_unknown_numeric_optionquery_reports_generic_miss(): void {
        global $USER;

        $env = $this->setup_two_instances();

        $this->setAdminUser();
        $result = (new get_option_details_skill())->execute(
            ['optionquery' => '99999999', 'requested_fields' => ['title']],
            (int)$env['contexta']->id,
            (int)$USER->id
        );

        $this->assertSame('error', (string)($result['status'] ?? ''));
        $this->assertSame(
            get_string('agent_booking_diagnose_error_option_resolve', 'mod_booking'),
            (string)($result['detail'] ?? ''),
            'A non-existent id must not be reported as belonging to another activity.'
        );
    }

    /**
     * System context stays deliberately cross-instance for an actor who may see the option.
     */
    public function test_privileged_actor_may_read_other_instance_in_system_context(): void {
        global $USER;

        $env = $this->setup_two_instances();

        $this->setAdminUser();
        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$env['optionb']->id, 'requested_fields' => ['title']],
            (int)\context_system::instance()->id,
            (int)$USER->id
        );

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $ids = array_map(
            static fn(array $d): int => (int)($d['optionid'] ?? 0),
            (array)($result['optiondetails'] ?? [])
        );
        $this->assertContains((int)$env['optionb']->id, $ids);
    }

    /**
     * The owning instance's teacher CAN read their own option's details.
     */
    public function test_own_instance_option_is_visible(): void {
        $env = $this->setup_two_instances();

        $this->setUser($env['teacherb']);
        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$env['optionb']->id, 'requested_fields' => ['title']],
            (int)$env['contextb']->id,
            (int)$env['teacherb']->id
        );

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $ids = array_map(
            static fn(array $d): int => (int)($d['optionid'] ?? 0),
            (array)($result['optiondetails'] ?? [])
        );
        $this->assertContains(
            (int)$env['optionb']->id,
            $ids,
            'The owning instance teacher must see their own option.'
        );
    }
}

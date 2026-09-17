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
use mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill;

/**
 * Tests for the diagnose_waitinglist agent skill.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill
 */
final class diagnose_waitinglist_skill_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Create a course + booking instance + one option.
     *
     * @param array $optionoverrides
     * @return array [contextid, cmid, optionid]
     */
    private function seed(array $optionoverrides = []): array {
        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Waitlist diag booking',
        ]);
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) ($optionoverrides + [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Waitlist option',
            'description' => 'Waitlist option',
            'chooseorcreatecourse' => 0,
            'limitanswers' => 1,
            'maxanswers' => 2,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]));
        return [(int) context_module::instance($booking->cmid)->id, (int) $booking->cmid, (int) $option->id];
    }

    /**
     * R0 read-only contract and automatic discovery.
     */
    public function test_r0_readonly_contract_and_discovery(): void {
        $skill = new diagnose_waitinglist_skill();
        $this->assertSame('mod_booking.diagnose_waitinglist', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(
            \mod_booking\local\wizard\engine\skill_risk_class::R0,
            $skill->get_risk_class()
        );

        $names = array_map(
            static fn($s): string => $s->get_name(),
            (new \mod_booking\local\wizard\skill_provider())->get_skills()
        );
        $this->assertContains('mod_booking.diagnose_waitinglist', $names);
    }

    /**
     * check_structure demands an option reference.
     */
    public function test_check_structure_requires_option(): void {
        $skill = new diagnose_waitinglist_skill();
        $this->assertFalse($skill->check_structure([])['valid']);
        $this->assertTrue($skill->check_structure(['optionid' => 5])['valid']);
        $this->assertTrue($skill->check_structure(['optionquery' => 'x'])['valid']);
    }

    /**
     * A clean option reports no blocking gate and an empty issue set.
     */
    public function test_clean_option(): void {
        global $USER;
        [$contextid, $cmid, $optionid] = $this->seed();

        $result = (new diagnose_waitinglist_skill())->execute(
            ['optionid' => $optionid],
            $contextid,
            (int)$USER->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertNull($result['diagnosis']['blockinggate']);
        $this->assertContains(
            get_string('agent_booking_waitinglist_nothing_blocking', 'booking'),
            $result['diagnosis']['reasons']
        );
    }

    /**
     * The real-world cause (option started + instance allowupdate off) is reported as
     * the blocking gate with the corresponding localized reason.
     */
    public function test_started_option_reports_optionstarted_gate(): void {
        global $USER, $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Started diag booking',
            'allowupdate' => 0,
        ]);
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Started option',
            'description' => 'Started option',
            'chooseorcreatecourse' => 0,
            'optiondateid_0' => 0,
            'coursestarttime_0' => strtotime('now - 1 day'),
            'courseendtime_0' => strtotime('now + 1 day'),
            'limitanswers' => 1,
            'maxanswers' => 2,
            'maxoverbooking' => 5,
        ]);
        $contextid = (int) context_module::instance($booking->cmid)->id;
        $optionid = (int) $option->id;

        // Guard: the seed must really have produced a past start.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertGreaterThan(0, (int) $settings->coursestarttime);
        $this->assertLessThan(time(), (int) $settings->coursestarttime);

        $result = (new diagnose_waitinglist_skill())->execute(
            ['optionid' => $optionid],
            $contextid,
            (int)$USER->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame(
            \mod_booking\local\waitinglist\waitinglist_sync_status::GATE_OPTION_STARTED,
            $result['diagnosis']['blockinggate']
        );
        $this->assertContains(
            get_string('agent_booking_waitinglist_reason_optionstarted', 'booking'),
            $result['diagnosis']['reasons']
        );
    }

    /**
     * keepusersbookedonreducingmaxanswers surfaces as a reason.
     */
    public function test_keepusersbooked_reason(): void {
        global $USER;
        [$contextid, $cmid, $optionid] = $this->seed();
        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');

        $result = (new diagnose_waitinglist_skill())->execute(
            ['optionid' => $optionid],
            $contextid,
            (int)$USER->id
        );

        $this->assertContains(
            get_string('agent_booking_waitinglist_reason_keepusersbooked', 'booking'),
            $result['diagnosis']['reasons']
        );
    }

    /**
     * An unresolvable option returns an error rather than fataling.
     */
    public function test_unresolvable_option_returns_error(): void {
        global $USER;
        [$contextid, $cmid, $optionid] = $this->seed();

        $result = (new diagnose_waitinglist_skill())->execute(
            ['optionquery' => 'this option really does not exist at all'],
            $contextid,
            (int)$USER->id
        );

        $this->assertSame('error', $result['status']);
    }
}

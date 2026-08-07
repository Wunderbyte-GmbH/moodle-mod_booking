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

namespace mod_booking\local\waitinglist;

use mod_booking\booking_option;
use mod_booking\singleton_service;

/**
 * Tests for the waiting list sync gate explainer.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitinglist\waitinglist_sync_status
 */
final class waitinglist_sync_status_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Create a course + booking instance + one option with the given overrides.
     *
     * @param array $optionoverrides
     * @param array $instanceoverrides
     * @return array [cmid, optionid]
     */
    private function seed(array $optionoverrides = [], array $instanceoverrides = []): array {
        // The site default of this setting is 1 (keep users booked). Turn it off so a
        // "clean" option reports no issues unless a test enables it explicitly.
        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Explainer booking',
        ] + $instanceoverrides);

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        // Overrides on the left so they win key collisions with the base defaults.
        $option = $gen->create_option((object) ($optionoverrides + [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Explainer option',
            'description' => 'Explainer option',
            'chooseorcreatecourse' => 0,
            'limitanswers' => 1,
            'maxanswers' => 2,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]));

        return [(int) $booking->cmid, (int) $option->id];
    }

    /**
     * On a plain option with nothing blocking, explain reports no issues and a
     * present waiting list.
     */
    public function test_clean_option_reports_no_issues(): void {
        [$cmid, $optionid] = $this->seed();

        $report = waitinglist_sync_status::explain($optionid);

        $this->assertTrue($report['haswaitinglist']);
        $this->assertNull($report['blockinggate']);
        $this->assertSame([], $report['issues']);
        $this->assertSame(2, $report['counts']['maxanswers']);
        $this->assertSame(5, $report['counts']['maxoverbooking']);
    }

    /**
     * The global off-switch is reported as the blocking gate.
     */
    public function test_turnoffwaitinglist_is_reported(): void {
        [$cmid, $optionid] = $this->seed();
        set_config('turnoffwaitinglist', 1, 'booking');

        $report = waitinglist_sync_status::explain($optionid);

        $this->assertSame(waitinglist_sync_status::GATE_TURNOFF_GLOBAL, $report['blockinggate']);
        $this->assertContains(waitinglist_sync_status::GATE_TURNOFF_GLOBAL, $report['issues']);
    }

    /**
     * keepusersbookedonreducingmaxanswers surfaces as an issue (reduction won't demote).
     */
    public function test_keepusersbooked_is_reported(): void {
        [$cmid, $optionid] = $this->seed();
        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');

        $report = waitinglist_sync_status::explain($optionid);

        $this->assertContains('keepusersbooked', $report['issues']);
        $this->assertNull($report['blockinggate'], 'keepusersbooked is not a global early-exit gate');
    }

    /**
     * A priced option surfaces the paidoption issue.
     */
    public function test_paid_option_is_reported(): void {
        // The price category must exist before the option so the default price is imported.
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $gen->create_pricecategory((object) [
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standard',
            'defaultvalue' => 25,
        ]);

        [$cmid, $optionid] = $this->seed(['useprice' => 1]);

        $report = waitinglist_sync_status::explain($optionid);
        $this->assertContains('paidoption', $report['issues']);
    }

    /**
     * waitforconfirmation is surfaced.
     */
    public function test_waitforconfirmation_is_reported(): void {
        [$cmid, $optionid] = $this->seed(['waitforconfirmation' => 1]);

        $report = waitinglist_sync_status::explain($optionid);
        $this->assertContains('waitforconfirmation', $report['issues']);
    }

    /**
     * The predicate the sync uses for its early exit agrees with explain's gate.
     */
    public function test_predicate_matches_report(): void {
        [$cmid, $optionid] = $this->seed();
        set_config('turnoffwaitinglist', 1, 'booking');
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $this->assertSame(
            waitinglist_sync_status::GATE_TURNOFF_GLOBAL,
            waitinglist_sync_status::first_blocking_global_gate($settings)
        );
    }

    /**
     * A missing option id is reported gracefully instead of fataling.
     */
    public function test_unknown_option_returns_error(): void {
        $report = waitinglist_sync_status::explain(0);
        $this->assertSame('optionnotfound', $report['error']);
    }
}

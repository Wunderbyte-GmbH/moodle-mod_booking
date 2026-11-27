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
use mod_booking\local\bookingstracker\report2_access;
use mod_booking\local\report_access;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The pages with the booked users take the booking instance and the booking option from the
 * request. A right in one instance only opens the reports of the options of this instance.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\report_access
 * @covers \mod_booking\local\bookingstracker\report2_access
 */
final class report_option_instance_binding_test extends advanced_testcase {
    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * The right to see reports in one instance does not open the report of an option of another instance.
     */
    public function test_report_rights_are_bound_to_the_instance_of_the_option(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        singleton_service::destroy_instance();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $viewer = $this->getDataGenerator()->create_user();
        $cmids = [];
        $optionids = [];
        foreach (['own', 'other'] as $name) {
            $course = $this->getDataGenerator()->create_course();
            $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
            $this->getDataGenerator()->enrol_user($viewer->id, $course->id, 'student');
            $cmids[$name] = (int)$booking->cmid;
            $optionids[$name] = (int)$plugingenerator->create_option((object)[
                'bookingid' => $booking->id,
                'text' => "Option $name",
            ])->id;
        }
        $context = context_module::instance($cmids['own']);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:viewreports', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $viewer->id, $context->id);

        $this->setUser($viewer);
        singleton_service::destroy_instance();

        // The own option in the own instance.
        $this->assertTrue(report_access::has_report_access($cmids['own'], $optionids['own']));
        $this->assertTrue(report2_access::has_option_scope_access($cmids['own'], $optionids['own']));

        // The option of the other instance, asked for with the own instance.
        $this->assertFalse(report_access::has_report_access($cmids['own'], $optionids['other']));
        $this->assertFalse(report2_access::has_option_scope_access($cmids['own'], $optionids['other']));

        // The other instance itself.
        $this->assertFalse(report_access::has_report_access($cmids['other'], $optionids['other']));
        $this->assertFalse(report2_access::has_option_scope_access($cmids['other'], $optionids['other']));
    }
}

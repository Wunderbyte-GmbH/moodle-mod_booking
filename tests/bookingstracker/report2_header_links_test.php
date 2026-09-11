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
 * Tests for the slot management header links of the bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\bookingstracker\report2_header_links;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The slot management links (teacher unavailability, teacher assignments,
 * slot calendar) were migrated from the old report.php header to report2.php.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report2_header_links_test extends advanced_testcase {
    /**
     * Cleanup after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Non-slot options get no slot management links at all.
     *
     * @covers \mod_booking\local\bookingstracker\report2_header_links::slot_management_links
     */
    public function test_no_links_for_default_option(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings] = $this->create_option(false);

        $links = report2_header_links::slot_management_links((int)$settings->cmid, (int)$settings->id);
        $this->assertSame([], $links);
    }

    /**
     * Admins get all three links for a slot option, with the same URL params
     * the target pages expect (id = cmid, optionid, scopeoptionid for the
     * unavailability page).
     *
     * @covers \mod_booking\local\bookingstracker\report2_header_links::slot_management_links
     */
    public function test_admin_gets_all_links_for_slot_option(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings] = $this->create_option(true);

        $links = report2_header_links::slot_management_links((int)$settings->cmid, (int)$settings->id);
        $this->assertCount(3, $links);

        $urls = array_map(fn($link) => $link['url']->out(false), $links);
        $this->assertStringContainsString('/mod/booking/teacherunavailability.php', $urls[0]);
        $this->assertStringContainsString('/mod/booking/slotteacherassignments.php', $urls[1]);
        $this->assertStringContainsString('/mod/booking/slotcalendar.php', $urls[2]);

        // The target pages expect id (= cmid) and optionid; the unavailability
        // page additionally scopeoptionid=0 (like the old report.php link).
        $this->assertEquals((int)$settings->cmid, (int)$links[0]['url']->get_param('id'));
        $this->assertEquals((int)$settings->id, (int)$links[0]['url']->get_param('optionid'));
        $this->assertEquals(0, (int)$links[0]['url']->get_param('scopeoptionid'));
        $this->assertEquals((int)$settings->cmid, (int)$links[1]['url']->get_param('id'));
        $this->assertEquals((int)$settings->id, (int)$links[2]['url']->get_param('optionid'));

        foreach ($links as $link) {
            $this->assertNotEmpty($link['label']);
            $this->assertNotEmpty($link['iconclass']);
        }
    }

    /**
     * Users without special capabilities who are not teachers of the option
     * do not get the teacher unavailability link (but the other two).
     *
     * @covers \mod_booking\local\bookingstracker\report2_header_links::slot_management_links
     */
    public function test_user_without_capabilities_gets_no_unavailability_link(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings, $course] = $this->create_option(true);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $links = report2_header_links::slot_management_links((int)$settings->cmid, (int)$settings->id);
        $this->assertCount(2, $links);
        $urls = array_map(fn($link) => $link['url']->out(false), $links);
        $this->assertStringContainsString('/mod/booking/slotteacherassignments.php', $urls[0]);
        $this->assertStringContainsString('/mod/booking/slotcalendar.php', $urls[1]);
    }

    /**
     * Teachers of the option get the teacher unavailability link even without
     * any special capability.
     *
     * @covers \mod_booking\local\bookingstracker\report2_header_links::slot_management_links
     */
    public function test_teacher_of_option_gets_unavailability_link(): void {
        global $DB;

        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings, $course] = $this->create_option(true);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'student');
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => (int)$settings->bookingid,
            'optionid' => (int)$settings->id,
            'userid' => (int)$teacher->id,
        ]);
        booking_option::purge_cache_for_option((int)$settings->id);
        singleton_service::destroy_instance();
        $this->setUser($teacher);

        $links = report2_header_links::slot_management_links((int)$settings->cmid, (int)$settings->id);
        $this->assertCount(3, $links);
        $this->assertStringContainsString(
            '/mod/booking/teacherunavailability.php',
            $links[0]['url']->out(false)
        );
    }

    /**
     * Helper: booking instance with one option, optionally of slot type.
     *
     * @param bool $slotoption make the option a slot booking option
     * @return array{0: \mod_booking\booking_option_settings, 1: stdClass} settings and course
     */
    private function create_option(bool $slotoption): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Slot links test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optionrecord = new stdClass();
        $optionrecord->bookingid = $booking->id;
        $optionrecord->text = 'Option for slot links';
        $option = $plugingenerator->create_option($optionrecord);

        if ($slotoption) {
            $DB->set_field('booking_options', 'type', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING, ['id' => $option->id]);
            booking_option::purge_cache_for_option($option->id);
            singleton_service::destroy_instance();
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return [$settings, $course];
    }
}

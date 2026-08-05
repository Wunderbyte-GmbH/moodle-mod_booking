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
 * Tests for bookingoptions_wbtable col_text link behaviour.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Ala-Flucher
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\table\bookingoptions_wbtable;

/**
 * Tests for the title-column link rendering based on openbookingdetailinsametab config.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookingoptions_wbtable_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $PAGE->set_url('/mod/booking/view.php');
        $this->setAdminUser();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Creates a minimal booking option and returns it.
     *
     * @return \stdClass the created option record
     */
    private function create_option(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Test Booking',
            'eventtype' => 'Test',
            'bookingmanager' => $teacher->username,
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        return $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test Booking Option',
            'courseid' => $course->id,
        ]);
    }

    /**
     * Verifies that col_text renders the correct link style for each openbookingdetailinsametab value.
     *
     * @dataProvider col_text_config_provider
     * @covers \mod_booking\table\bookingoptions_wbtable::col_text
     * @param int $configvalue The openbookingdetailinsametab config value.
     * @param string[] $contains Strings the output must contain.
     * @param string[] $notcontains Strings the output must not contain.
     */
    public function test_col_text_link_behaviour(int $configvalue, array $contains, array $notcontains): void {
        set_config('openbookingdetailinsametab', $configvalue, 'booking');

        $option = $this->create_option();
        $table = new bookingoptions_wbtable("test_col_text_{$configvalue}");
        $values = (object)['id' => $option->id, 'text' => $option->text];

        $result = $table->col_text($values);

        $this->assertStringContainsString("class='bookingoptions-wbtable-option-title'", $result);
        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $result);
        }
        foreach ($notcontains as $needle) {
            $this->assertStringNotContainsString($needle, $result);
        }
    }

    /**
     * Data provider for test_col_text_link_behaviour.
     *
     * @return array
     */
    public static function col_text_config_provider(): array {
        return [
            'new_tab'  => [0, ['<a href=', "target='_blank'"], []],
            'same_tab' => [1, ['<a href='], ['target=']],
            'no_link'  => [2, [], ['<a ']],
        ];
    }

    /**
     * Creates course, booking instance, option and one user per user group.
     *
     * The option is not connected to a course, so booking an answer does not
     * enrol the booked user anywhere - the "booked" user stays without the
     * mod/booking:view capability on purpose.
     *
     * @return array env with keys option, cmid, student, teacher, authuser, bookeduser
     */
    private function create_access_env(): array {
        $course = $this->getDataGenerator()->create_course();

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $authuser = $this->getDataGenerator()->create_user();
        $bookeduser = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Access Test Booking',
            'eventtype' => 'Test',
            'bookingmanager' => $teacher->username,
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Access Test Option',
        ]);

        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $bookeduser->id]);

        return [
            'option' => $option,
            'cmid' => $booking->cmid,
            'student' => $student,
            'teacher' => $teacher,
            'authuser' => $authuser,
            'bookeduser' => $bookeduser,
        ];
    }

    /**
     * Logs the requested user group in (or out).
     *
     * @param array $env environment created by create_access_env
     * @param string $usergroup one of loggedout, guest, authuser, student, teacher, booked
     * @return void
     */
    private function apply_usergroup(array $env, string $usergroup): void {
        switch ($usergroup) {
            case 'loggedout':
                $this->setUser(null);
                break;
            case 'guest':
                $this->setGuestUser();
                break;
            case 'authuser':
                $this->setUser($env['authuser']);
                break;
            case 'student':
                $this->setUser($env['student']);
                break;
            case 'teacher':
                $this->setUser($env['teacher']);
                break;
            case 'booked':
                $this->setUser($env['bookeduser']);
                break;
            default:
                throw new \coding_exception("Unknown usergroup $usergroup");
        }
    }

    /**
     * The central optionview access rule and the col_text link have to agree for every
     * combination of showbookingdetailstoall, bookonlyondetailspage and user group.
     *
     * @dataProvider optionview_access_provider
     * @covers \mod_booking\booking_option::can_view_option_details
     * @covers \mod_booking\table\bookingoptions_wbtable::col_text
     * @param int $showall value for showbookingdetailstoall
     * @param int $bookonlyondetails value for bookonlyondetailspage
     * @param string $usergroup user group to run the check as
     * @param bool $canview expected result of the access rule
     */
    public function test_optionview_access_rule_and_link(
        int $showall,
        int $bookonlyondetails,
        string $usergroup,
        bool $canview
    ): void {
        set_config('showbookingdetailstoall', $showall, 'booking');
        set_config('bookonlyondetailspage', $bookonlyondetails, 'booking');

        $env = $this->create_access_env();
        $this->apply_usergroup($env, $usergroup);

        // The access rule optionview.php enforces (login redirect / capability exception).
        $this->assertSame(
            $canview,
            booking_option::can_view_option_details((int)$env['option']->id),
            "can_view_option_details for $usergroup"
        );

        // The option title only links to optionview.php if the viewer may see the page.
        $table = new bookingoptions_wbtable("access_{$usergroup}_{$showall}_{$bookonlyondetails}");
        $result = $table->col_text((object)['id' => $env['option']->id, 'text' => 'Access Test Option']);

        $this->assertStringContainsString('Access Test Option', $result);
        if ($canview) {
            $this->assertStringContainsString('<a href=', $result, "col_text link for $usergroup");
        } else {
            $this->assertStringNotContainsString('<a ', $result, "col_text link for $usergroup");
        }
    }

    /**
     * Data provider for test_optionview_access_rule_and_link.
     *
     * @return array
     */
    public static function optionview_access_provider(): array {
        return [
            // Default configuration: details need login + capability, booked users bypass.
            'default_loggedout' => [0, 0, 'loggedout', false],
            'default_guest' => [0, 0, 'guest', false],
            'default_authuser_unenrolled' => [0, 0, 'authuser', false],
            'default_student' => [0, 0, 'student', true],
            'default_teacher' => [0, 0, 'teacher', true],
            'default_booked_without_capability' => [0, 0, 'booked', true],
            // Details public for everyone.
            'showall_loggedout' => [1, 0, 'loggedout', true],
            'showall_guest' => [1, 0, 'guest', true],
            'showall_authuser' => [1, 0, 'authuser', true],
            // Booking only on details page: page reachable for logged-in users without
            // the capability, but logged-out users and guests still have to log in.
            'bookondetail_loggedout' => [0, 1, 'loggedout', false],
            'bookondetail_guest' => [0, 1, 'guest', false],
            'bookondetail_authuser' => [0, 1, 'authuser', true],
            'bookondetail_student' => [0, 1, 'student', true],
        ];
    }

    /**
     * The openbookingdetailinsametab link modes only apply to users who may see the
     * detail page; users without access never get a link, whatever the mode is.
     *
     * @dataProvider link_mode_provider
     * @covers \mod_booking\table\bookingoptions_wbtable::col_text
     * @param int $linkmode value for openbookingdetailinsametab
     * @param string[] $contains strings the student rendering must contain
     * @param string[] $notcontains strings the student rendering must not contain
     */
    public function test_col_text_link_modes_respect_permission(
        int $linkmode,
        array $contains,
        array $notcontains
    ): void {
        set_config('openbookingdetailinsametab', $linkmode, 'booking');

        $env = $this->create_access_env();

        // Student may see the page: the configured link mode applies.
        $this->apply_usergroup($env, 'student');
        $table = new bookingoptions_wbtable("linkmode_student_{$linkmode}");
        $result = $table->col_text((object)['id' => $env['option']->id, 'text' => 'Access Test Option']);
        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $result);
        }
        foreach ($notcontains as $needle) {
            $this->assertStringNotContainsString($needle, $result);
        }

        // Unenrolled user may not see the page: never a link, whatever the mode.
        $this->apply_usergroup($env, 'authuser');
        $table = new bookingoptions_wbtable("linkmode_authuser_{$linkmode}");
        $result = $table->col_text((object)['id' => $env['option']->id, 'text' => 'Access Test Option']);
        $this->assertStringNotContainsString('<a ', $result);
        $this->assertStringContainsString('Access Test Option', $result);
    }

    /**
     * Data provider for test_col_text_link_modes_respect_permission.
     *
     * @return array
     */
    public static function link_mode_provider(): array {
        return [
            'new_tab_mode' => [0, ['<a href=', "target='_blank'"], []],
            'same_tab_mode' => [1, ['<a href='], ['target=']],
            'no_link_mode' => [2, [], ['<a ']],
        ];
    }
}

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
 * Tests for the deep link back to a booking option's detail page after logging in.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * bo_info::set_login_returnurl() stores the option detail page as $SESSION->wantsurl so that
 * "Login to book" leads back to the option the user clicked, instead of the page they started
 * on. Both the "no login" and "no login, priced option" conditions rely on it: before
 * Wunderbyte-GmbH/Wunderbyte-GmbH#1182 only the former deep linked, the latter always sent
 * users to a bare /login/index.php.
 *
 * The deep link itself is only honoured when the admin opted into showing the destination to a
 * user who is not (yet) logged in: "showbookingdetailstoall" (plain option page) or
 * "redirectonlogintocourse" (redirect to the course instead). Both default to off, in which case
 * $SESSION->wantsurl is intentionally left untouched and Moodle falls back to its own default
 * (typically the referring page).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class login_returnurl_test extends booking_advanced_testcase {
    /** @var stdClass course used by the option settings */
    private stdClass $course;

    /** @var mod_booking_generator plugin generator */
    private mod_booking_generator $plugingenerator;

    /** @var int id of the booking instance options are created in */
    private int $bookingid;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();

        global $SESSION;
        unset($SESSION->wantsurl);

        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $this->course->id, 'editingteacher');

        $bdata = [
            'name' => 'Login return url test',
            'eventtype' => 'Test event',
            'course' => $this->course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->bookingid = $booking->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->plugingenerator = $plugingenerator;

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ]);
    }

    /**
     * Create a booking option.
     *
     * @param bool $withcourse whether the option is linked to a course (needed for redirectonlogintocourse)
     * @param bool $useprice whether the option uses a price (triggers isloggedinprice instead of isloggedin)
     * @return booking_option_settings
     */
    private function create_option_settings(bool $withcourse, bool $useprice = false): booking_option_settings {
        $record = new stdClass();
        $record->bookingid = $this->bookingid;
        $record->text = 'Option ' . ($withcourse ? 'with' : 'without') . ' course, price ' . ($useprice ? 'on' : 'off');
        $record->description = 'Login return url test option';
        $record->useprice = $useprice ? 1 : 0;
        if ($useprice) {
            $record->default = 50;
        }
        if ($withcourse) {
            $record->chooseorcreatecourse = 1;
            $record->courseid = $this->course->id;
        }
        $option = $this->plugingenerator->create_option($record);
        return singleton_service::get_instance_of_booking_option_settings($option->id);
    }

    /**
     * By default (both settings off) no deep link is stored: wantsurl is left untouched.
     *
     * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
     */
    public function test_default_settings_do_not_set_a_returnurl(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 0, 'booking');
        set_config('redirectonlogintocourse', 0, 'booking');

        $settings = $this->create_option_settings(true);
        $loginurl = bo_info::set_login_returnurl($settings);

        $this->assertSame((new moodle_url('/login/index.php'))->out(false), $loginurl);
        $this->assertTrue(empty($SESSION->wantsurl), 'wantsurl must not be set when both settings are off.');
    }

    /**
     * "Show booking details to all" makes the deep link point to the option's detail page.
     *
     * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
     */
    public function test_showbookingdetailstoall_deep_links_to_option(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        set_config('redirectonlogintocourse', 0, 'booking');

        $settings = $this->create_option_settings(false);
        $loginurl = bo_info::set_login_returnurl($settings);

        $expected = new moodle_url('/mod/booking/optionview.php', [
            'optionid' => $settings->id,
            'cmid' => $settings->cmid,
        ]);
        $this->assertSame($expected->out(false), $SESSION->wantsurl);
        $this->assertSame((new moodle_url('/login/index.php'))->out(false), $loginurl);
    }

    /**
     * "Redirect logged-out users to course" adds redirecttocourse=1 to the deep link.
     *
     * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
     */
    public function test_redirectonlogintocourse_adds_redirect_param(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 0, 'booking');
        set_config('redirectonlogintocourse', 1, 'booking');

        $settings = $this->create_option_settings(true);
        bo_info::set_login_returnurl($settings);

        $expected = new moodle_url('/mod/booking/optionview.php', [
            'optionid' => $settings->id,
            'cmid' => $settings->cmid,
            'redirecttocourse' => 1,
        ]);
        $this->assertSame($expected->out(false), $SESSION->wantsurl);
    }

    /**
     * Without a linked course, redirectonlogintocourse has nothing to redirect to and is a no-op.
     *
     * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
     */
    public function test_redirectonlogintocourse_without_course_does_not_set_a_returnurl(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 0, 'booking');
        set_config('redirectonlogintocourse', 1, 'booking');

        $settings = $this->create_option_settings(false);
        bo_info::set_login_returnurl($settings);

        $this->assertTrue(empty($SESSION->wantsurl), 'wantsurl must stay unset without a linked course.');
    }

    /**
     * When both settings are on, redirectonlogintocourse takes precedence: the user ends up
     * on the course, not the plain option detail page.
     *
     * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
     */
    public function test_redirectonlogintocourse_overrides_showbookingdetailstoall(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        set_config('redirectonlogintocourse', 1, 'booking');

        $settings = $this->create_option_settings(true);
        bo_info::set_login_returnurl($settings);

        $this->assertStringContainsString('redirecttocourse=1', $SESSION->wantsurl);
    }

    /**
     * Before Wunderbyte-GmbH/Wunderbyte-GmbH#1182, isloggedinprice (options with a price) linked
     * straight to /login/index.php while isloggedin (free options) already deep linked. Both must
     * now produce the same login link for a logged-out user so the behaviour cannot drift apart
     * again.
     *
     * @covers \mod_booking\bo_availability\conditions\isloggedin::render_button
     * @covers \mod_booking\bo_availability\conditions\isloggedinprice::render_button
     */
    public function test_isloggedin_and_isloggedinprice_render_the_same_login_link(): void {
        set_config('showbookingdetailstoall', 1, 'booking');
        set_config('redirectonlogintocourse', 0, 'booking');
        set_config('displayloginbuttonforbookingoptions', 1, 'booking');

        $free = $this->create_option_settings(false, false);
        $priced = $this->create_option_settings(false, true);

        $expectedfree = new moodle_url('/mod/booking/optionview.php', [
            'optionid' => $free->id,
            'cmid' => $free->cmid,
        ]);
        $expectedpriced = new moodle_url('/mod/booking/optionview.php', [
            'optionid' => $priced->id,
            'cmid' => $priced->cmid,
        ]);

        $this->setGuestUser();
        global $USER;
        $guestid = (int) $USER->id;

        $boinfofree = new bo_info($free);
        [$idfree, , $descfree] = $boinfofree->is_available($free->id, $guestid, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDIN, $idfree);

        $boinfopriced = new bo_info($priced);
        [$idpriced, , $descpriced] = $boinfopriced->is_available($priced->id, $guestid, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDINPRICE, $idpriced);

        // The login link itself never carries the destination (it is always /login/index.php);
        // the destination travels via $SESSION->wantsurl instead, so each button has to be
        // rendered (and its wantsurl checked) before the other button overwrites it.
        global $SESSION;
        $loginurl = (new moodle_url('/login/index.php'))->out(false);

        unset($SESSION->wantsurl);
        $htmlfree = booking_bookit::render_bookit_button($free, $guestid);
        $this->assertStringContainsString(
            'href="' . $loginurl . '"',
            $htmlfree,
            'The free option login button must link to /login/index.php.'
        );
        $this->assertSame(
            $expectedfree->out(false),
            $SESSION->wantsurl,
            'The free option must store its own detail page as the post-login destination.'
        );

        unset($SESSION->wantsurl);
        $htmlpriced = booking_bookit::render_bookit_button($priced, $guestid);
        $this->assertStringContainsString(
            'href="' . $loginurl . '"',
            $htmlpriced,
            'The priced option login button must link to /login/index.php, exactly like the free option does.'
        );
        $this->assertSame(
            $expectedpriced->out(false),
            $SESSION->wantsurl,
            'The priced option must store its own detail page as the post-login destination, exactly like the free option does.'
        );
    }
}

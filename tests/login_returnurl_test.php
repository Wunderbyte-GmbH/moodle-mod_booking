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
 * Tests the login button return URL for booking option login buttons.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
 * The option details return target must travel in the login link, not in $SESSION at render time.
 *
 * A booking shortcode/list renders a login button for every option a logged-out user sees. If the
 * return target were written to $SESSION->wantsurl while building each button, the last rendered
 * card would win and a plain frontpage login would redirect there instead of staying put. The
 * target therefore rides along as a wantsurl GET parameter on the login URL, so it only applies
 * when the user actually clicks that button.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
 */
final class login_returnurl_test extends booking_advanced_testcase {
    /**
     * A logged-out user building the login link gets the return target in the link, not the session.
     *
     * @return void
     */
    public function test_returnurl_rides_in_link_and_session_is_untouched(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        $fixture = $this->create_simple_option();

        // Not logged in, no pre-existing return target.
        $this->setUser(0);
        unset($SESSION->wantsurl);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $loginurl = bo_info::set_login_returnurl($settings);

        // Merely building the button must not mutate the session.
        $this->assertTrue(empty($SESSION->wantsurl));

        // The return target rides along in the login link.
        $url = new moodle_url($loginurl);
        $this->assertStringContainsString('/login/index.php', $url->out(false));
        $wantsurl = $url->param('wantsurl');
        $this->assertNotEmpty($wantsurl);
        $this->assertStringContainsString('/mod/booking/optionview.php', $wantsurl);
        $this->assertStringContainsString('optionid=' . $fixture->optionid, $wantsurl);
    }

    /**
     * Rendering many cards (as a shortcode list does) leaves the session clean.
     *
     * This is the frontpage-login regression: before the fix the last rendered option's optionview
     * ended up in $SESSION->wantsurl, so a normal login jumped there instead of staying on the page.
     *
     * @return void
     */
    public function test_many_cards_do_not_pollute_session(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        $first = $this->create_simple_option();
        $second = $this->create_simple_option();

        $this->setUser(0);
        unset($SESSION->wantsurl);

        // Render both login buttons, as a list of cards would.
        bo_info::set_login_returnurl(singleton_service::get_instance_of_booking_option_settings($first->optionid));
        bo_info::set_login_returnurl(singleton_service::get_instance_of_booking_option_settings($second->optionid));

        $this->assertTrue(empty($SESSION->wantsurl));
    }

    /**
     * The full round trip: a logged-out user clicks the login button and the session gets the target.
     *
     * Emulates what core login/index.php does with the wantsurl GET parameter:
     *   $wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);
     *   $SESSION->wantsurl = (new moodle_url($wantsurl))->out(false);
     * After login core redirects to $SESSION->wantsurl, which must point at the clicked option.
     *
     * @return void
     */
    public function test_login_consumes_wantsurl_and_lands_on_option(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        $fixture = $this->create_simple_option();

        $this->setUser(0);
        unset($SESSION->wantsurl);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $loginurl = new moodle_url(bo_info::set_login_returnurl($settings));

        // The user clicks the button: core reads and validates the wantsurl GET parameter.
        $wantsurl = clean_param($loginurl->param('wantsurl'), PARAM_LOCALURL);
        $this->assertNotEmpty($wantsurl);
        $SESSION->wantsurl = (new moodle_url($wantsurl))->out(false);

        // After login core redirects to the session target: the clicked option.
        $this->assertStringContainsString('/mod/booking/optionview.php', $SESSION->wantsurl);
        $this->assertStringContainsString('optionid=' . $fixture->optionid, $SESSION->wantsurl);
    }

    /**
     * redirectonlogintocourse adds the redirecttocourse flag to the return target.
     *
     * @return void
     */
    public function test_redirectonlogintocourse_flag_is_carried(): void {
        set_config('redirectonlogintocourse', 1, 'booking');
        $fixture = $this->create_simple_option();

        $this->setUser(0);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $wantsurl = (new moodle_url(bo_info::set_login_returnurl($settings)))->param('wantsurl');

        $this->assertNotEmpty($wantsurl);
        $this->assertStringContainsString('redirecttocourse=1', $wantsurl);
    }

    /**
     * With neither config enabled, the login link carries no return target and the session stays clean.
     *
     * @return void
     */
    public function test_no_returnurl_when_config_disabled(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 0, 'booking');
        set_config('redirectonlogintocourse', 0, 'booking');
        $fixture = $this->create_simple_option();

        $this->setUser(0);
        unset($SESSION->wantsurl);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $loginurl = new moodle_url(bo_info::set_login_returnurl($settings));

        $this->assertTrue(empty($SESSION->wantsurl));
        $this->assertEmpty($loginurl->param('wantsurl'));
    }

    /**
     * Create a minimal booking option with a connected course.
     *
     * @return stdClass with cmid and optionid
     */
    private function create_simple_option(): stdClass {
        global $DB;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Login button option',
            'course' => $course->id,
            'importing' => 1,
        ]);

        // Set the connected course directly: $settings->courseid is read from this column and
        // drives the redirectonlogintocourse branch of set_login_returnurl().
        $DB->set_field('booking_options', 'courseid', $course->id, ['id' => $option->id]);
        // The option settings are MUC-cached, so invalidate the stale entry before it is reloaded.
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($option->id);
        singleton_service::destroy_instance();

        return (object) ['cmid' => (int) $booking->cmid, 'optionid' => (int) $option->id];
    }
}

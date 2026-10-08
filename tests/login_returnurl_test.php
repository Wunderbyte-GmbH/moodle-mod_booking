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
use mod_booking\bo_availability\conditions\isloggedin;
use mod_booking\bo_availability\conditions\isloggedinprice;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The login button must carry its return target in its own href, never in $SESSION at render time.
 *
 * A booking shortcode/list renders a login button for every option a logged-out user sees. If the
 * return target were written to $SESSION->wantsurl while building each button, the last rendered
 * card would win and a plain frontpage login would redirect there instead of staying put. Instead
 * the button links to the option's optionview.php with forcelogin=1; a logged-out click makes
 * optionview call require_login(), which stores the target in $SESSION->wantsurl at click time and
 * returns there after login. (A wantsurl GET parameter on /login/index.php would not work: core
 * only reads that parameter under BEHAT_SITE_RUNNING.)
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\bo_info::set_login_returnurl
 * @covers \mod_booking\bo_availability\conditions\isloggedin::render_button
 * @covers \mod_booking\bo_availability\conditions\isloggedinprice::render_button
 */
final class login_returnurl_test extends booking_advanced_testcase {
    /**
     * The button links to the option (with forcelogin) and building it does not touch the session.
     *
     * @return void
     */
    public function test_button_targets_option_and_session_is_untouched(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        $fixture = $this->create_simple_option();

        // Not logged in, no pre-existing return target.
        $this->setUser(0);
        unset($SESSION->wantsurl);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $url = new moodle_url(bo_info::set_login_returnurl($settings));

        // Merely building the button must not mutate the session.
        $this->assertTrue(empty($SESSION->wantsurl));

        // The button points at the option's view page, with forcelogin so a logged-out click logs in.
        $this->assertStringContainsString('/mod/booking/optionview.php', $url->out(false));
        $this->assertEquals($fixture->optionid, $url->param('optionid'));
        $this->assertEquals(1, $url->param('forcelogin'));
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
     * Rendering the login buttons of several options through both login conditions leaves the session clean.
     *
     * Same regression as above, but through the conditions that render the buttons of a list, so a
     * condition writing the session on its own is caught as well. Each button links to its own option.
     *
     * @return void
     */
    public function test_rendering_buttons_through_conditions_leaves_session_clean(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 1, 'booking');
        set_config('displayloginbuttonforbookingoptions', 1, 'booking');
        $options = [$this->create_simple_option(), $this->create_simple_option(), $this->create_simple_option()];

        $this->setUser(0);
        unset($SESSION->wantsurl);

        foreach ($options as $option) {
            $settings = singleton_service::get_instance_of_booking_option_settings($option->optionid);
            foreach ([new isloggedin(), new isloggedinprice()] as $condition) {
                [, $data] = $condition->render_button($settings, 0);
                $link = new moodle_url($data['main']['link']);
                $this->assertTrue($link->compare(new moodle_url('/mod/booking/optionview.php'), URL_MATCH_BASE));
                $this->assertEquals($option->optionid, $link->param('optionid'));
                $this->assertEquals(1, $link->param('forcelogin'));
            }
        }

        $this->assertTrue(empty($SESSION->wantsurl));
    }

    /**
     * Each card's button carries its own option, so there is no cross-card bleed.
     *
     * @return void
     */
    public function test_each_button_targets_its_own_option(): void {
        set_config('showbookingdetailstoall', 1, 'booking');
        $first = $this->create_simple_option();
        $second = $this->create_simple_option();

        $this->setUser(0);

        $firsturl = new moodle_url(
            bo_info::set_login_returnurl(singleton_service::get_instance_of_booking_option_settings($first->optionid))
        );
        $secondurl = new moodle_url(
            bo_info::set_login_returnurl(singleton_service::get_instance_of_booking_option_settings($second->optionid))
        );

        $this->assertEquals($first->optionid, $firsturl->param('optionid'));
        $this->assertEquals($second->optionid, $secondurl->param('optionid'));
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
        $url = new moodle_url(bo_info::set_login_returnurl($settings));

        $this->assertStringContainsString('/mod/booking/optionview.php', $url->out(false));
        $this->assertEquals(1, $url->param('redirecttocourse'));
        $this->assertEquals(1, $url->param('forcelogin'));
    }

    /**
     * Without a linked course, redirectonlogintocourse has nothing to redirect to and is a no-op.
     *
     * @return void
     */
    public function test_redirectonlogintocourse_without_course_goes_to_login(): void {
        global $SESSION;

        set_config('showbookingdetailstoall', 0, 'booking');
        set_config('redirectonlogintocourse', 1, 'booking');
        $fixture = $this->create_simple_option(false);

        $this->setUser(0);
        unset($SESSION->wantsurl);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $url = new moodle_url(bo_info::set_login_returnurl($settings));

        $this->assertTrue(empty($SESSION->wantsurl));
        $this->assertStringContainsString('/login/index.php', $url->out(false));
        $this->assertEmpty($url->param('redirecttocourse'));
    }

    /**
     * With both configs enabled, redirectonlogintocourse wins: the user ends up on the course.
     *
     * @return void
     */
    public function test_redirectonlogintocourse_overrides_showbookingdetailstoall(): void {
        set_config('showbookingdetailstoall', 1, 'booking');
        set_config('redirectonlogintocourse', 1, 'booking');
        $fixture = $this->create_simple_option();

        $this->setUser(0);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $url = new moodle_url(bo_info::set_login_returnurl($settings));

        $this->assertEquals($fixture->optionid, $url->param('optionid'));
        $this->assertEquals(1, $url->param('redirecttocourse'));
        $this->assertEquals(1, $url->param('forcelogin'));
    }

    /**
     * With neither config enabled, the button goes straight to login and the session stays clean.
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
        $url = new moodle_url(bo_info::set_login_returnurl($settings));

        $this->assertTrue(empty($SESSION->wantsurl));
        $this->assertStringContainsString('/login/index.php', $url->out(false));
        $this->assertEmpty($url->param('forcelogin'));
    }

    /**
     * Create a minimal booking option, by default with a connected course.
     *
     * @param bool $withcourse whether to connect the option to a course
     * @return stdClass with cmid and optionid
     */
    private function create_simple_option(bool $withcourse = true): stdClass {
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

        if (!$withcourse) {
            return (object) ['cmid' => (int) $booking->cmid, 'optionid' => (int) $option->id];
        }

        // Set the connected course directly: $settings->courseid is read from this column and
        // drives the redirectonlogintocourse branch of set_login_returnurl().
        $DB->set_field('booking_options', 'courseid', $course->id, ['id' => $option->id]);
        // The option settings are MUC-cached, so invalidate the stale entry before it is reloaded.
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($option->id);
        singleton_service::destroy_instance();

        return (object) ['cmid' => (int) $booking->cmid, 'optionid' => (int) $option->id];
    }
}

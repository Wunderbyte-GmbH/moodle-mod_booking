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
 * Tests page scripts that need a booking option before require_login().
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use core\exception\coding_exception;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_page;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/filterlib.php');

/**
 * Loading booking_option_settings formats customfield values through the text filters.
 *
 * When a filter renders output (e.g. a shortcode in a dynamic dropdown label), the page theme gets
 * initialised and require_login() can no longer set the course. Page scripts must therefore either
 * resolve the course module without the settings (scan.php, bulk_book_handler.php) or set it on the
 * page before loading the option (link.php).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::get_cmid_from_optionid_before_login
 */
final class option_settings_before_login_test extends booking_advanced_testcase {
    /**
     * Guard: with this fixture, loading the option before require_login() sets up the theme.
     *
     * Without it the other tests would pass even if the page order were wrong again.
     *
     * @return void
     */
    public function test_fixture_sets_up_theme_when_option_is_loaded_first(): void {
        $fixture = $this->create_option_with_rendering_label();
        $this->start_fresh_request();

        singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        [$course, $cm] = get_course_and_cm_from_cmid($fixture->cmid, 'booking');

        $this->expectException(coding_exception::class);
        require_course_login($course, false, $cm);
    }

    /**
     * Order of scan.php and bulk_book_handler.php: cmid without settings, login, then settings.
     *
     * @return void
     */
    public function test_cmid_lookup_keeps_require_login_possible(): void {
        global $PAGE;

        $fixture = $this->create_option_with_rendering_label();
        $this->start_fresh_request();

        $cmid = booking_option::get_cmid_from_optionid_before_login($fixture->optionid);
        $this->assertSame($fixture->cmid, $cmid);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
        require_course_login($course, false, $cm);
        $this->assertSame((int) $course->id, (int) $PAGE->course->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($fixture->optionid);
        $this->assertSame($cmid, (int) $settings->cmid);
    }

    /**
     * Order of link.php: course module set on the page, option loaded, then require_login().
     *
     * @return void
     */
    public function test_set_cm_before_loading_option_keeps_require_login_possible(): void {
        global $PAGE;

        $fixture = $this->create_option_with_rendering_label();
        $this->start_fresh_request();

        [$course, $cm] = get_course_and_cm_from_cmid($fixture->cmid, 'booking');
        $PAGE->set_cm($cm, $course);
        $bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $fixture->optionid);
        $this->assertSame($fixture->optionid, (int) $bookingoption->id);

        require_login($course, false, $cm);
        $this->assertSame((int) $cm->id, (int) $PAGE->cm->id);
    }

    /**
     * Unknown options resolve to 0 so the page can raise its own error.
     *
     * @return void
     */
    public function test_cmid_lookup_unknown_option(): void {
        $this->resetAfterTest();
        $this->assertSame(0, booking_option::get_cmid_from_optionid_before_login(999999));
    }

    /**
     * Option whose selected dynamic dropdown label renders a table through the shortcodes filter.
     *
     * @return stdClass with cmid and optionid
     */
    private function create_option_with_rendering_label(): stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Shortcodes in labels are only resolved when the filter also applies to headings.
        filter_set_global_state('shortcodes', TEXTFILTER_ON);
        filter_set_applies_to_strings('shortcodes', true);
        set_config('filterall', 1);
        \filter_manager::reset_caches();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        $labelcourse = $this->getDataGenerator()->create_course([
            'shortname' => 'labelcourse',
            'fullname' => 'Label [courselist cmid=' . $booking->cmid . ']',
        ]);
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'Fields',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $category->save();
        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Recommended in',
            'shortname' => 'recommendedin',
            'type' => 'dynamicformat',
            'configdata' => '{"required":"0","uniquevalues":"0",'
                . '"dynamicsql":"SELECT shortname AS id, fullname AS data FROM {course}",'
                . '"autocomplete":"1","defaultvalue":"","multiselect":"1"}',
        ])->save();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option with rendered label',
            'course' => $course->id,
            'importing' => 1,
            'recommendedin' => $labelcourse->shortname,
        ]);

        return (object) ['cmid' => (int) $booking->cmid, 'optionid' => (int) $option->id];
    }

    /**
     * State of a new request: no singletons, no memoised format_string() results, untouched page.
     *
     * @return void
     */
    private function start_fresh_request(): void {
        global $PAGE;

        singleton_service::destroy_instance();
        \core\di::set(\core\formatting::class, new \core\formatting());
        $PAGE = new moodle_page();
    }
}

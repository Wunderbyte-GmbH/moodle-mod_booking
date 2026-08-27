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
 * Tests for placeholders in poll urls.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\utils\wb_payment;
use context_system;
use mod_booking_generator;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Poll urls of a booking option may contain the placeholders of the poll url list
 * (MOD_BOOKING_PLACEHOLDERS_POLLURL, for_pollurl()): {pollurl} and {pollurlteachers} render
 * them per user, all other placeholders are left alone.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\placeholders\placeholders_info::render_text
 * @covers \mod_booking\placeholders\placeholders\pollurl
 * @covers \mod_booking\placeholders\placeholders\pollurlteachers
 */
final class pollurl_test extends booking_advanced_testcase {
    /**
     * All placeholder classes (mod_booking and bookingextension plugins) by their tag.
     *
     * @return array tag => fully qualified classname
     */
    private function placeholder_classes(): array {
        $classes = \core_component::get_component_classes_in_namespace('mod_booking', 'placeholders\placeholders');
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classes += \core_component::get_component_classes_in_namespace(
                "bookingextension_{$plugin->name}",
                'placeholders'
            );
        }
        $bytag = [];
        foreach (array_keys($classes) as $class) {
            $bytag[substr(strrchr($class, '\\'), 1)] = $class;
        }
        return $bytag;
    }
    /**
     * Resets the placeholder cache and the PRO override.
     */
    protected function tearDown(): void {
        placeholders_info::$placeholders = [];
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * Booking instance with one option (given poll urls) and two booked users.
     *
     * @param string $pollurl
     * @param string $pollurlteachers
     * @param array $optiondata further properties of the option record, e.g. custom field values
     * @return array keys: settings, users (two booked users), course
     */
    private function setup_option(string $pollurl, string $pollurlteachers = '', array $optiondata = []): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Poll course']);
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Poll option';
        // Connect the option to the course: {courseid} and {coursename} refer to the connected course.
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->pollurl = $pollurl;
        $record->pollurlteachers = $pollurlteachers;
        $record->coursestarttime = strtotime('tomorrow 10:00');
        $record->courseendtime = strtotime('tomorrow 12:00');
        foreach ($optiondata as $key => $value) {
            $record->{$key} = $value;
        }
        $option = $plugingenerator->create_option($record);

        $users = [];
        foreach (['anna', 'ben'] as $name) {
            $user = $this->getDataGenerator()->create_user([
                'firstname' => ucfirst($name),
                'lastname' => 'Tester',
                'email' => $name . '@example.com',
            ]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
            $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);
            $users[] = $user;
        }

        singleton_service::destroy_instance();
        placeholders_info::$placeholders = [];
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return ['settings' => $settings, 'users' => $users, 'course' => $course];
    }

    /**
     * Renders the given placeholder text for the given user.
     *
     * @param string $text
     * @param booking_option_settings $settings
     * @param stdClass $user
     * @return string
     */
    private function render(string $text, booking_option_settings $settings, stdClass $user): string {
        return placeholders_info::render_text($text, (int) $settings->cmid, (int) $settings->id, (int) $user->id);
    }

    /**
     * The pollurl mode of render_text() replaces only the placeholders of the poll url list.
     */
    public function test_pollurl_mode_renders_only_pollurl_placeholders(): void {
        $env = $this->setup_option('');
        $settings = $env['settings'];
        $anna = $env['users'][0];

        $text = 'u={userid}&mail={email}&first={firstname}&last={lastname}&o={optionid}&c={courseid}'
            . '&name={bookingoptionname}&course={coursename}&dates={dates}&t={teachers}&l={location}&x={nonexisting}';
        $rendered = placeholders_info::render_text(
            $text,
            (int) $settings->cmid,
            (int) $settings->id,
            (int) $anna->id,
            0,
            0,
            0,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            null,
            true
        );

        $this->assertStringContainsString("u={$anna->id}&mail=anna@example.com&first=Anna&last=Tester", $rendered);
        $this->assertStringContainsString("&o={$settings->id}&c={$env['course']->id}", $rendered);
        $this->assertStringContainsString('&name=Poll option&course=Poll course', $rendered);
        // Placeholders outside of the poll url list are untouched - the date would be rendered otherwise.
        $this->assertStringContainsString('&dates={dates}&t={teachers}&l={location}&x={nonexisting}', $rendered);
    }

    /**
     * Every placeholder of the poll url list is a class marked with for_pollurl() - and vice versa.
     */
    public function test_pollurl_list_matches_for_pollurl(): void {
        $list = placeholders_info::return_list_of_placeholders(MOD_BOOKING_PLACEHOLDERS_POLLURL);
        preg_match_all("/data-id='([^']+)'/", $list, $matches);
        $listed = $matches[1];
        $this->assertNotEmpty($listed);

        $expected = [];
        foreach ($this->placeholder_classes() as $tag => $class) {
            if ($class::is_applicable() && $class::for_pollurl()) {
                $expected[] = $tag;
            }
        }
        sort($expected);
        sort($listed);
        $this->assertSame($expected, $listed);
        $this->assertContains('userid', $listed);
        $this->assertContains('email', $listed);
        $this->assertNotContains('dates', $listed);
        $this->assertNotContains('pollurl', $listed);
    }

    /**
     * {pollurl} renders the poll url of the option with the placeholders of the user the text is
     * rendered for - and is cached per user, so the second user gets their own url.
     */
    public function test_pollurl_placeholder_renders_per_user(): void {
        $env = $this->setup_option(
            'https://poll.example.com/survey?user={userid}&mail={email}&option={optionid}&name={bookingoptionname}'
        );
        $settings = $env['settings'];
        [$anna, $ben] = $env['users'];

        $expectedanna = (new moodle_url('https://poll.example.com/survey', [
            'user' => $anna->id,
            'mail' => 'anna@example.com',
            'option' => $settings->id,
            'name' => 'Poll option',
        ]))->out(false);
        $expectedben = (new moodle_url('https://poll.example.com/survey', [
            'user' => $ben->id,
            'mail' => 'ben@example.com',
            'option' => $settings->id,
            'name' => 'Poll option',
        ]))->out(false);

        $this->assertSame('Your poll: ' . $expectedanna, $this->render('Your poll: {pollurl}', $settings, $anna));
        $this->assertSame('Your poll: ' . $expectedben, $this->render('Your poll: {pollurl}', $settings, $ben));
        // Rendered again from the cache: still the url of the respective user.
        $this->assertSame($expectedanna, $this->render('{pollurl}', $settings, $anna));
        $this->assertArrayHasKey("pollurl-{$settings->id}-{$anna->id}", placeholders_info::$placeholders);
        $this->assertArrayHasKey("pollurl-{$settings->id}-{$ben->id}", placeholders_info::$placeholders);
    }

    /**
     * Placeholders that are not allowed in poll urls survive {pollurl} as (url encoded) literals.
     */
    public function test_pollurl_placeholder_leaves_other_placeholders_alone(): void {
        $env = $this->setup_option('https://poll.example.com/survey?d={dates}&u={userid}');
        $settings = $env['settings'];
        $anna = $env['users'][0];

        $rendered = $this->render('{pollurl}', $settings, $anna);

        $this->assertStringContainsString('u=' . $anna->id, $rendered);
        $this->assertStringContainsString('d=%7Bdates%7D', $rendered);
        $this->assertStringNotContainsString(userdate($settings->coursestarttime, '%Y'), $rendered);
    }

    /**
     * Poll urls that were stored url encoded (e.g. saved through a moodle_url) are decoded first,
     * so %7Buserid%7D is a placeholder as well.
     */
    public function test_pollurl_placeholder_decodes_encoded_braces(): void {
        $env = $this->setup_option('https://poll.example.com/survey?u=%7Buserid%7D');
        $settings = $env['settings'];
        $anna = $env['users'][0];

        $this->assertSame(
            'https://poll.example.com/survey?u=' . $anna->id,
            $this->render('{pollurl}', $settings, $anna)
        );
    }

    /**
     * Custom booking option fields can be used in poll urls via their shortname - resolved by the
     * customfields fallback of render_text(), which is not restricted to the poll url list.
     */
    public function test_custom_booking_option_fields_render_in_pollurl(): void {
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'Poll fields',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $category->save();
        $field = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Survey id',
            'shortname' => 'surveyid',
            'type' => 'text',
            'configdata' => '',
        ]);
        $field->save();

        $env = $this->setup_option(
            'https://poll.example.com/survey?id={surveyid}&u={userid}',
            '',
            ['customfield_surveyid' => 'SRV 42']
        );
        $settings = $env['settings'];
        $anna = $env['users'][0];

        $expected = (new moodle_url('https://poll.example.com/survey', ['id' => 'SRV 42', 'u' => $anna->id]))->out(false);
        $this->assertSame($expected, $this->render('{pollurl}', $settings, $anna));

        // The poll url mode of render_text() resolves them as well.
        $rendered = placeholders_info::render_text(
            'id={surveyid}',
            (int) $settings->cmid,
            (int) $settings->id,
            (int) $anna->id,
            0,
            0,
            0,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            null,
            true
        );
        $this->assertSame('id=SRV 42', $rendered);
    }

    /**
     * {pollurlteachers} works the same way with the poll url for teachers.
     */
    public function test_pollurlteachers_placeholder_renders_per_user(): void {
        $env = $this->setup_option(
            'https://poll.example.com/survey?u={userid}',
            'https://poll.example.com/teachers?t={userid}&o={optionid}'
        );
        $settings = $env['settings'];
        $anna = $env['users'][0];

        $this->assertSame(
            "https://poll.example.com/teachers?t={$anna->id}&o={$settings->id}",
            $this->render('{pollurlteachers}', $settings, $anna)
        );
        $this->assertSame("https://poll.example.com/survey?u={$anna->id}", $this->render('{pollurl}', $settings, $anna));
    }

    /**
     * Without Booking PRO the poll url is returned as stored, placeholders are not rendered.
     */
    public function test_pollurl_placeholders_need_pro_version(): void {
        $env = $this->setup_option('https://poll.example.com/survey?u={userid}');
        $settings = $env['settings'];
        $anna = $env['users'][0];

        wb_payment::override_pro_version_for_tests(false);

        $this->assertSame('https://poll.example.com/survey?u=%7Buserid%7D', $this->render('{pollurl}', $settings, $anna));
    }
}

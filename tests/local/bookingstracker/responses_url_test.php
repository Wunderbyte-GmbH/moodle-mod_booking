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

use mod_booking\local\bookingstracker\responses_url;
use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\booking_advanced_testcase;
use moodle_url;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Links to the booked users lead to the Bookings tracker or to "Manage responses" (Wunderbyte-GmbH/Wunderbyte-GmbH#2332).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\bookingstracker\responses_url
 */
final class responses_url_test extends booking_advanced_testcase {
    /**
     * Creates a booking instance with one option.
     *
     * @return array [cmid, optionid]
     */
    private function create_option(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option',
            'description' => 'Option',
            'importing' => 1,
        ]);
        return [(int) $booking->cmid, (int) $option->id];
    }

    /**
     * Sets the site setting and drops the caches, like saving it in the site administration.
     *
     * @param int $value
     */
    private function use_bookingstracker(int $value): void {
        set_config(responses_url::SETTING, $value, 'booking');
        responses_url::purge_caches();
        singleton_service::destroy_instance();
        placeholders_info::$placeholders = [];
    }

    /**
     * Path and parameters of a URL, for comparison.
     *
     * @param moodle_url|string $url
     * @return array
     */
    private function shape($url): array {
        $url = $url instanceof moodle_url ? $url : new moodle_url(html_entity_decode((string) $url, ENT_QUOTES));
        $params = $url->params();
        ksort($params);
        return [parse_url($url->out_as_local_url(false), PHP_URL_PATH), $params];
    }

    /**
     * A new installation uses the Bookings tracker (default of the site setting).
     */
    public function test_new_installation_uses_the_bookingstracker(): void {
        $this->assertSame('1', get_config('booking', responses_url::SETTING));
        $this->assertTrue(responses_url::uses_bookingstracker());
    }

    /**
     * The links follow the setting: Bookings tracker or "Manage responses".
     */
    public function test_links_follow_the_setting(): void {
        $this->use_bookingstracker(1);
        $this->assertSame(
            ['/mod/booking/report2.php', ['cmid' => '7', 'optionid' => '3']],
            $this->shape(responses_url::for_option(7, 3))
        );
        $this->assertSame(
            ['/mod/booking/report2.php', ['optiondateid' => '9', 'optionid' => '3']],
            $this->shape(responses_url::for_optiondate(7, 3, 9))
        );

        $this->use_bookingstracker(0);
        $this->assertSame(
            ['/mod/booking/report.php', ['id' => '7', 'optionid' => '3']],
            $this->shape(responses_url::for_option(7, 3))
        );
        $this->assertSame(
            ['/mod/booking/report.php', ['id' => '7', 'optionid' => '3']],
            $this->shape(responses_url::for_optiondate(7, 3, 9))
        );
    }

    /**
     * Data provider: previous activation of the Bookings tracker and the expected default.
     *
     * @return array
     */
    public static function upgrade_provider(): array {
        return [
            'activated, value still in the config' => ['1', [], 1],
            'deactivated, value still in the config' => ['0', [], 0],
            'activated, value only in the admin log' => [null, ['0', '1'], 1],
            'activated and deactivated again (admin log)' => [null, ['1', '0'], 0],
            'never saved' => [null, [], 0],
        ];
    }

    /**
     * On upgrade, sites that had activated the Bookings tracker keep it - also when step 2026072200 already removed
     * the old setting and only the admin settings log remembers it.
     *
     * @dataProvider upgrade_provider
     * @param string|null $current value of booking/bookingstracker, null = removed
     * @param array $logged values saved in the admin settings log, oldest first
     * @param int $expected
     */
    public function test_default_for_upgrade(?string $current, array $logged, int $expected): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('config_log', ['plugin' => 'booking', 'name' => 'bookingstracker']);
        unset_config('bookingstracker', 'booking');
        if ($current !== null) {
            set_config('bookingstracker', $current, 'booking');
        }
        $time = 1750000000;
        foreach ($logged as $value) {
            $DB->insert_record('config_log', (object) [
                'userid' => 2,
                'timemodified' => $time++,
                'plugin' => 'booking',
                'name' => 'bookingstracker',
                'value' => $value,
                'oldvalue' => null,
            ]);
        }

        $this->assertSame($expected, responses_url::default_for_upgrade());
    }

    /**
     * An administrator switches the whole site from "Manage responses"; the change is logged like one in the site
     * administration and links that were cached before follow it.
     */
    public function test_admin_switches_the_site_to_the_bookingstracker(): void {
        global $DB;
        [$cmid, $optionid] = $this->create_option();
        $this->use_bookingstracker(0);
        $before = singleton_service::get_instance_of_booking_option_settings($optionid)->manageresponsesurl;
        $this->assertSame('/mod/booking/report.php', $this->shape($before)[0]);

        $this->setAdminUser();
        responses_url::switch_site_to_bookingstracker();
        singleton_service::destroy_instance();

        $this->assertTrue(responses_url::uses_bookingstracker());
        $log = $DB->get_records('config_log', ['plugin' => 'booking', 'name' => responses_url::SETTING], 'id DESC', '*', 0, 1);
        $this->assertSame('1', reset($log)->value);
        $after = singleton_service::get_instance_of_booking_option_settings($optionid)->manageresponsesurl;
        $this->assertSame(
            ['/mod/booking/report2.php', ['cmid' => (string) $cmid, 'optionid' => (string) $optionid]],
            $this->shape($after)
        );
    }

    /**
     * Without the site configuration capability the site cannot be switched.
     */
    public function test_switch_requires_site_config(): void {
        $this->create_option();
        $this->use_bookingstracker(0);
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            responses_url::switch_site_to_bookingstracker();
            $this->fail('Switching must require moodle/site:config.');
        } catch (required_capability_exception $e) {
            $this->assertFalse(responses_url::uses_bookingstracker());
        }
    }

    /**
     * Data provider: setting value and the expected target page.
     *
     * @return array
     */
    public static function target_provider(): array {
        return [
            'Bookings tracker' => [1, '/mod/booking/report2.php'],
            'Manage responses' => [0, '/mod/booking/report.php'],
        ];
    }

    /**
     * Links built in the plugin follow the setting: option settings, the placeholder {bookingreportlink} and the
     * link of the log entries of an option.
     *
     * @dataProvider target_provider
     * @param int $setting
     * @param string $path
     */
    public function test_plugin_links_follow_the_setting(int $setting, string $path): void {
        [$cmid, $optionid] = $this->create_option();
        $this->use_bookingstracker($setting);
        $expected = $this->shape(responses_url::for_option($cmid, $optionid));
        $this->assertSame($path, $expected[0]);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertSame($expected, $this->shape($settings->manageresponsesurl), 'option settings');

        $placeholder = placeholders_info::render_text('{bookingreportlink}', $cmid, $optionid, 0);
        $this->assertMatchesRegularExpression('/href="([^"]+)"/', $placeholder);
        preg_match('/href="([^"]+)"/', $placeholder, $match);
        $this->assertSame($expected, $this->shape($match[1]), 'placeholder');

        $event = event\bookingoption_updated::create([
            'context' => \context_module::instance($cmid),
            'objectid' => $optionid,
            'userid' => 2,
            'relateduserid' => 2,
        ]);
        $this->assertSame($expected, $this->shape($event->get_url()), 'log entry');
    }
}

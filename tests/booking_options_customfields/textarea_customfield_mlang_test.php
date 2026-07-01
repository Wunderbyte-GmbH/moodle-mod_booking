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
use context_system;
use core_customfield\data_controller;
use mod_booking\customfield\booking_handler;
use mod_booking_generator;
use moodle_page;
use moodle_url;

/**
 * Tests that {mlang} multilang spans in textarea customfields are always resolved
 * for the current language when booking_option_settings is built.
 *
 * booking_option_settings::localize_customfields_for_templates() re-derives the display
 * value of every customfield on each instantiation. For textarea fields this runs
 * format_text() (the full filter chain, including filter_multilang2), so the resolved
 * value must follow the active language - both on a normal page and from back-end callers
 * that have no page context yet (AJAX submissions, scheduled tasks, events, CLI).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_option_settings
 */
final class textarea_customfield_mlang_test extends advanced_testcase {
    /** @var string The multilang value stored in the textarea customfield. */
    private const MLANG_VALUE = '{mlang en}Hello English{mlang}{mlang de}Hallo Deutsch{mlang}';

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        // The multilang2 filter must be active for {mlang} spans to be resolved by format_text().
        filter_set_global_state('multilang2', TEXTFILTER_ON);
        singleton_service::destroy_instance();
    }

    /**
     * A textarea customfield with {mlang} spans is resolved for the current language when the
     * option settings are built from a back-end context (no page URL/context set yet). This is
     * the path used by AJAX submissions, scheduled tasks and events.
     */
    public function test_mlang_resolved_from_backend_context(): void {
        global $PAGE;

        [$optionid] = $this->create_option_with_mlang_textarea();

        // Simulate a back-end caller: a fresh page with neither URL nor context set.
        $PAGE = new moodle_page();

        $this->assert_value_for_language($optionid, 'en', 'Hello English', 'Hallo Deutsch');
        $this->assert_value_for_language($optionid, 'de', 'Hallo Deutsch', 'Hello English');
    }

    /**
     * The same value is resolved for the current language on a normal page where the URL and a
     * module context have already been established (the option/report view path).
     */
    public function test_mlang_resolved_on_normal_page(): void {
        global $PAGE;

        [$optionid, $cmid] = $this->create_option_with_mlang_textarea();

        $PAGE = new moodle_page();
        $PAGE->set_url(new moodle_url('/mod/booking/view.php', ['id' => $cmid]));
        $PAGE->set_context(context_module::instance($cmid));

        $this->assert_value_for_language($optionid, 'en', 'Hello English', 'Hallo Deutsch');
        $this->assert_value_for_language($optionid, 'de', 'Hallo Deutsch', 'Hello English');
    }

    /**
     * Force the given language, rebuild the option settings and assert the resolved textarea
     * customfield value contains only the expected language variant.
     *
     * @param int $optionid the booking option id
     * @param string $lang the language to force via $SESSION->forcelang
     * @param string $expected the text that must be present for that language
     * @param string $notexpected the text of the other language that must be absent
     */
    private function assert_value_for_language(int $optionid, string $lang, string $expected, string $notexpected): void {
        global $SESSION;

        $SESSION->forcelang = $lang;
        // Drop the cached settings/singleton so localisation re-runs for the forced language.
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $value = $settings->customfieldsfortemplates['mlangtext']['value'];

        $this->assertStringContainsString($expected, $value, "Expected '$expected' for language '$lang'.");
        $this->assertStringNotContainsString($notexpected, $value, "Did not expect '$notexpected' for language '$lang'.");
    }

    /**
     * Create a booking module with one option carrying a textarea customfield that holds
     * {mlang} spans.
     *
     * @return array{0: int, 1: int} [optionid, cmid]
     */
    private function create_option_with_mlang_textarea(): array {
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'MlangFields',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'MlangText',
            'shortname' => 'mlangtext',
            'type' => 'textarea',
            'configdata' => '{"required":"0","uniquevalues":"0","locked":"0","visibility":"2",'
                . '"defaultvalue":"","defaultvalueformat":"1"}',
        ])->save();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking mlang',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option mlang',
            'course' => $course->id,
            'importing' => 1,
        ]);

        $this->set_textarea_customfield_value($option->id, 'mlangtext', self::MLANG_VALUE);

        return [(int) $option->id, (int) $booking->cmid];
    }

    /**
     * Store a raw value directly into a textarea booking customfield for the given option,
     * bypassing the form so we control the exact {mlang} content, then invalidate caches.
     *
     * @param int $optionid the booking option id (the customfield instance id)
     * @param string $shortname the customfield shortname
     * @param string $text the raw value to store (HTML format)
     */
    private function set_textarea_customfield_value(int $optionid, string $shortname, string $text): void {
        $handler = booking_handler::create();
        // Update the existing data record (option creation already inserted a default one).
        foreach ($handler->get_instance_data($optionid, true) as $data) {
            if ($data->get_field()->get('shortname') !== $shortname) {
                continue;
            }
            if (!$data->get('id')) {
                $data->set('contextid', context_system::instance()->id);
            }
            $data->set('value', $text);
            $data->set('valueformat', FORMAT_HTML);
            $data->save();
            break;
        }

        booking_handler::reset_caches();
        booking_option::purge_cache_for_option($optionid);
    }
}

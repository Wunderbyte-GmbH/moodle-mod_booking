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
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the sign-in sheet settings resolution chain.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class signinsheet_config_test extends advanced_testcase {
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
     * Option JSON wins over instance settings, instance settings win over
     * plugin config, "use plugin config" makes the instance transparent.
     *
     * @covers \mod_booking\signinsheet\signinsheet_config::for_option
     * @covers \mod_booking\signinsheet\signinsheet_config::for_instance
     * @covers \mod_booking\signinsheet\signinsheet_config::save_for_option
     */
    public function test_resolution_chain(): void {
        global $DB;

        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings, $bookingid] = $this->create_booking_option();
        $optionid = (int)$settings->id;

        // 1. Nothing configured anywhere: hardcoded defaults of the old form.
        $config = signinsheet_config::for_option($optionid);
        $this->assertEquals('P', $config['orientation']);
        $this->assertEquals('lastname', $config['orderby']);
        $this->assertEquals(-2, (int)$config['pdfsessions']);

        // 2. Global plugin config is used when nothing is stored below it.
        set_config('signinsheetorientation', 'L', 'booking');
        set_config('signinsheetaddemptyrows', 5, 'booking');
        $config = signinsheet_config::for_option($optionid);
        $this->assertEquals('L', $config['orientation']);
        $this->assertEquals(5, (int)$config['addemptyrows']);

        // 3. Instance settings win over plugin config - unless the instance
        // has "use plugin config" checked.
        $record = $DB->get_record('booking', ['id' => $bookingid]);
        booking::add_data_to_json($record, signinsheet_config::JSONKEY, (object)[
            'usepluginconfig' => 1,
            'orientation' => 'P',
            'orderby' => 'firstname',
        ]);
        $DB->update_record('booking', $record);
        // A direct DB write bypasses booking_update_instance, so purge like it would.
        booking::purge_cache_for_booking_instance_by_cmid((int)$settings->cmid);

        $config = signinsheet_config::for_option($optionid);
        $this->assertEquals('L', $config['orientation'], 'With usepluginconfig the plugin config must win.');
        $this->assertEquals('lastname', $config['orderby']);

        $record = $DB->get_record('booking', ['id' => $bookingid]);
        booking::add_data_to_json($record, signinsheet_config::JSONKEY, (object)[
            'usepluginconfig' => 0,
            'orientation' => 'P',
            'orderby' => 'firstname',
        ]);
        $DB->update_record('booking', $record);
        // A direct DB write bypasses booking_update_instance, so purge like it would.
        booking::purge_cache_for_booking_instance_by_cmid((int)$settings->cmid);

        $config = signinsheet_config::for_option($optionid);
        $this->assertEquals('P', $config['orientation'], 'Instance settings must win over plugin config.');
        $this->assertEquals('firstname', $config['orderby']);
        // Keys the instance did not store still fall back to the plugin config.
        $this->assertEquals(5, (int)$config['addemptyrows']);

        // 4. Settings persisted in the option JSON win over everything.
        signinsheet_config::save_for_option($optionid, [
            'orientation' => 'L',
            'addemptyrows' => 10,
        ]);
        $config = signinsheet_config::for_option($optionid);
        $this->assertEquals('L', $config['orientation']);
        $this->assertEquals(10, (int)$config['addemptyrows']);
        // Keys not persisted in the option fall back to the instance.
        $this->assertEquals('firstname', $config['orderby']);
    }

    /**
     * The mod_form save helper stores the instance settings in the JSON and
     * keeps values of fields the current mode does not show.
     *
     * @covers ::booking_store_signinsheet_instance_settings
     */
    public function test_instance_settings_are_stored_in_json(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [, $bookingid] = $this->create_booking_option();

        $formdata = new stdClass();
        $formdata->id = $bookingid;
        $formdata->json = '';
        $formdata->signinsheetusepluginconfig = 0;
        $formdata->signinsheetorientation = 'L';
        $formdata->signinsheetorderby = 'firstname';
        $formdata->signinsheetaddemptyrows = 3;
        $formdata->signinsheetpdftitle = 2;
        $formdata->signinsheetpdfsessions = 0;
        $formdata->signinsheetextrasessioncols = -1;
        $formdata->signinsheetincludeteachers = 1;

        booking_store_signinsheet_instance_settings($formdata);

        $stored = json_decode($formdata->json)->{signinsheet_config::JSONKEY};
        $this->assertEquals(0, (int)$stored->usepluginconfig);
        $this->assertEquals('L', $stored->orientation);
        $this->assertEquals('firstname', $stored->orderby);
        $this->assertEquals(3, (int)$stored->addemptyrows);
        $this->assertEquals(2, (int)$stored->pdftitle);
        $this->assertEquals(1, (int)$stored->includeteachers);
        // Not submitted (e.g. hidden in the current sign-in sheet mode) and
        // nothing stored before: the key is simply absent.
        $this->assertFalse(isset($stored->saveasformat));

        // Without the checkbox in the submitted data nothing is touched.
        $unrelated = new stdClass();
        $unrelated->id = $bookingid;
        $unrelated->json = '';
        booking_store_signinsheet_instance_settings($unrelated);
        $this->assertEquals('', $unrelated->json);
    }

    /**
     * In HTML template mode the "Add date manually" choice is not offered.
     *
     * @covers \mod_booking\signinsheet\signinsheet_config::pdfsessions_choices
     */
    public function test_pdfsessions_choices_depend_on_mode(): void {
        $this->resetAfterTest(true);

        $choices = signinsheet_config::pdfsessions_choices([7 => 'somedate']);
        $this->assertArrayHasKey(-1, $choices);
        $this->assertArrayHasKey(-2, $choices);
        $this->assertArrayHasKey(7, $choices);

        set_config('signinsheetmode', 'htmltemplate', 'booking');
        $choices = signinsheet_config::pdfsessions_choices([7 => 'somedate']);
        $this->assertArrayNotHasKey(-1, $choices);
        $this->assertArrayHasKey(-2, $choices);
        $this->assertArrayHasKey(7, $choices);
    }

    /**
     * Helper: set up a booking module with one booking option.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: int}
     */
    private function create_booking_option(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Sign-in sheet config test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $optionrecord = new stdClass();
        $optionrecord->bookingid = $booking->id;
        $optionrecord->courseid = $course->id;
        $optionrecord->text = 'Option for sign-in sheet config';
        $optionrecord->chooseorcreatecourse = 1;
        $option = $plugingenerator->create_option($optionrecord);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return [$settings, (int)$booking->id];
    }
}

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
use mod_booking\form\modal_signinsheet_download;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\singleton_service;
use mod_booking_generator;
use moodle_url;
use stdClass;

/**
 * Tests for modal_signinsheet_download dynamic form.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modal_signinsheet_download_test extends advanced_testcase {
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
     * In default (PDF) mode the form returns the download URL of the
     * existing sign-in sheet endpoint on report.php with all settings.
     *
     * @covers \mod_booking\form\modal_signinsheet_download::process_dynamic_submission
     */
    public function test_process_dynamic_submission_returns_pdf_download_url(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings] = $this->create_booking_option();

        $ajaxargs = [
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'orientation' => 'L',
            'orderby' => 'firstname',
            'addemptyrows' => 5,
            'pdftitle' => 2,
            'pdfsessions' => -2,
            'signinextrasessioncols' => 0,
        ];

        $submitdata = modal_signinsheet_download::mock_ajax_submit($ajaxargs);
        $mform = new modal_signinsheet_download(null, null, 'post', '', [], true, $submitdata, true);

        $this->assertTrue($mform->is_validated(), 'The sign-in sheet dynamic form should validate.');
        $result = $mform->process_dynamic_submission();

        $this->assertEquals(1, (int)($result['success'] ?? 0));
        $this->assertStringContainsString('/mod/booking/report.php', $result['downloadurl']);

        $url = new moodle_url($result['downloadurl']);
        $this->assertEquals('downloadsigninsheet', $url->get_param('action'));
        $this->assertEquals((int)$settings->cmid, (int)$url->get_param('id'));
        $this->assertEquals((int)$settings->id, (int)$url->get_param('optionid'));
        $this->assertEquals('L', $url->get_param('orientation'));
        $this->assertEquals('firstname', $url->get_param('orderby'));
        $this->assertEquals(5, (int)$url->get_param('addemptyrows'));
        $this->assertEquals(2, (int)$url->get_param('pdftitle'));
        $this->assertEquals(-2, (int)$url->get_param('pdfsessions'));
        $this->assertEquals(0, (int)$url->get_param('signinextrasessioncols'));
        $this->assertEquals(0, (int)$url->get_param('includeteachers'));
        // The save-as format only exists in HTML template mode.
        $this->assertNull($url->get_param('saveasformat'));

        // The submitted settings were persisted in the option JSON, so the
        // quick download button and the next modal opening reuse them.
        $persisted = booking_option::get_value_of_json_by_key((int)$settings->id, signinsheet_config::JSONKEY);
        $this->assertNotEmpty($persisted);
        $this->assertEquals('L', $persisted->orientation);
        $this->assertEquals('firstname', $persisted->orderby);
        $this->assertEquals(5, (int)$persisted->addemptyrows);
        $this->assertEquals(2, (int)$persisted->pdftitle);

        // And the resolution chain now returns them for the option.
        $config = signinsheet_config::for_option((int)$settings->id);
        $this->assertEquals('L', $config['orientation']);
        $this->assertEquals(5, (int)$config['addemptyrows']);
    }

    /**
     * In HTML template mode the form uses the HTML endpoint and passes the
     * save-as format instead of the empty rows setting.
     *
     * @covers \mod_booking\form\modal_signinsheet_download::process_dynamic_submission
     */
    public function test_process_dynamic_submission_returns_html_download_url(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        set_config('signinsheetmode', 'htmltemplate', 'booking');

        [$settings] = $this->create_booking_option();

        $ajaxargs = [
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'orientation' => 'P',
            'orderby' => 'lastname',
            'pdftitle' => 1,
            'pdfsessions' => 0,
            'signinextrasessioncols' => -1,
            'saveasformat' => 'word',
        ];

        $submitdata = modal_signinsheet_download::mock_ajax_submit($ajaxargs);
        $mform = new modal_signinsheet_download(null, null, 'post', '', [], true, $submitdata, true);

        $this->assertTrue($mform->is_validated(), 'The sign-in sheet dynamic form should validate.');
        $result = $mform->process_dynamic_submission();

        $this->assertEquals(1, (int)($result['success'] ?? 0));

        $url = new moodle_url($result['downloadurl']);
        $this->assertEquals('downloadsigninsheethtml', $url->get_param('action'));
        $this->assertEquals('word', $url->get_param('saveasformat'));
        $this->assertEquals(-1, (int)$url->get_param('signinextrasessioncols'));
        // The empty rows setting only exists in PDF mode.
        $this->assertNull($url->get_param('addemptyrows'));
    }

    /**
     * Helper: set up a booking module with one booking option.
     *
     * @return array{0: \mod_booking\booking_option_settings}
     */
    private function create_booking_option(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Sign-in sheet test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $optionrecord = new stdClass();
        $optionrecord->bookingid = $booking->id;
        $optionrecord->courseid = $course->id;
        $optionrecord->text = 'Option for sign-in sheet';
        $optionrecord->chooseorcreatecourse = 1;
        $option = $plugingenerator->create_option($optionrecord);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return [$settings];
    }
}

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
 * Tests for the dedicated sign-in sheet / checklist download endpoints.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\output\bookingoption_description;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * The download endpoints replace the old report.php?action=... flow: the URL
 * carries only cmid and optionid, everything else is resolved server-side.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class download_endpoint_test extends advanced_testcase {
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
     * pdfoptions_from_config maps the config keys to the (partly differently
     * named) properties the signinsheet_generator constructor expects.
     *
     * @covers \mod_booking\signinsheet\signinsheet_config::pdfoptions_from_config
     */
    public function test_pdfoptions_from_config_maps_all_keys(): void {
        $this->resetAfterTest(true);

        $config = [
            'orientation' => 'L',
            'orderby' => 'firstname',
            'addemptyrows' => 5,
            'pdftitle' => 2,
            'pdfsessions' => -1,
            'signinextrasessioncols' => 3,
            'includeteachers' => 1,
            'saveasformat' => 'word',
        ];

        $pdfoptions = signinsheet_config::pdfoptions_from_config($config);

        $this->assertSame('L', $pdfoptions->orientation);
        $this->assertSame('firstname', $pdfoptions->orderby);
        $this->assertSame(5, $pdfoptions->addemptyrows);
        // The renamed keys: pdftitle -> title, pdfsessions -> sessions,
        // signinextrasessioncols -> extrasessioncols.
        $this->assertSame(2, $pdfoptions->title);
        $this->assertSame(-1, $pdfoptions->sessions);
        $this->assertSame(3, $pdfoptions->extrasessioncols);
        $this->assertSame(1, $pdfoptions->includeteachers);
        $this->assertSame('word', $pdfoptions->saveasformat);
    }

    /**
     * Unknown keys are dropped and missing keys are filled with the defaults,
     * so a partial (or stale) config can never break the generator.
     *
     * @covers \mod_booking\signinsheet\signinsheet_config::pdfoptions_from_config
     */
    public function test_pdfoptions_from_config_applies_defaults(): void {
        $this->resetAfterTest(true);

        $pdfoptions = signinsheet_config::pdfoptions_from_config([
            'orientation' => 'L',
            'bogus' => 'ignored',
        ]);

        $this->assertSame('L', $pdfoptions->orientation);
        // Defaults of the remaining keys (see signinsheet_config::defaults()).
        $this->assertSame('lastname', $pdfoptions->orderby);
        $this->assertSame(0, $pdfoptions->addemptyrows);
        $this->assertSame(1, $pdfoptions->title);
        $this->assertSame(-2, $pdfoptions->sessions);
        $this->assertSame(0, $pdfoptions->extrasessioncols);
        $this->assertSame(0, $pdfoptions->includeteachers);
        $this->assertSame('pdf', $pdfoptions->saveasformat);
        $this->assertObjectNotHasProperty('bogus', $pdfoptions);
    }

    /**
     * The download URL points to the dedicated endpoint and carries only cmid
     * and optionid - in the legacy PDF mode as well as in HTML template mode
     * (the endpoint resolves the mode server-side).
     *
     * @covers \mod_booking\signinsheet\signinsheet_config::download_url
     */
    public function test_download_url_is_mode_independent_and_carries_no_settings(): void {
        $this->resetAfterTest(true);

        $pdfmodeurl = signinsheet_config::download_url(11, 22);
        $this->assertStringContainsString('/mod/booking/download_signinsheet.php', $pdfmodeurl->out(false));
        $this->assertEquals(11, (int)$pdfmodeurl->get_param('cmid'));
        $this->assertEquals(22, (int)$pdfmodeurl->get_param('optionid'));
        $this->assertNull($pdfmodeurl->get_param('action'));
        $this->assertNull($pdfmodeurl->get_param('orientation'));
        $this->assertNull($pdfmodeurl->get_param('saveasformat'));

        set_config('signinsheetmode', 'htmltemplate', 'booking');
        $htmlmodeurl = signinsheet_config::download_url(11, 22);
        $this->assertSame($pdfmodeurl->out(false), $htmlmodeurl->out(false));
    }

    /**
     * The checklist download button of the option description points to the
     * dedicated endpoint (no report.php action URL anymore).
     *
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_checklist_button_points_to_dedicated_endpoint(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        set_config('showchecklistdownloadbutton', 1, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Checklist test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optionrecord = new stdClass();
        $optionrecord->bookingid = $booking->id;
        $optionrecord->text = 'Option for checklist';
        $option = $plugingenerator->create_option($optionrecord);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $description = new bookingoption_description($option->id);
        $data = $description->export_for_template($PAGE->get_renderer('mod_booking'));

        $this->assertNotEmpty($data['showchecklistdownloadbutton'] ?? null, 'The checklist button must be present.');
        $url = (string)$data['showchecklistdownloadbutton'];
        $this->assertStringContainsString('/mod/booking/download_checklist.php', $url);
        $this->assertStringContainsString('cmid=' . $settings->cmid, $url);
        $this->assertStringContainsString('optionid=' . $option->id, $url);
        $this->assertStringNotContainsString('report.php', $url);
        $this->assertStringNotContainsString('action=', $url);
    }
}

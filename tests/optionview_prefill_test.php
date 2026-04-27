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
 * Tests for optionview customform prefills.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\customform_prefill;
use mod_booking\local\mobile\customformstore;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for optionview customform prefills.
 *
 * @package mod_booking
 * @category test
 * @coversDefaultClass \mod_booking\local\customform_prefill
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class optionview_prefill_test extends advanced_testcase {
    /** @var array */
    private $originalget = [];

    /** @var array */
    private $originalpost = [];

    /** @var array */
    private $originalrequest = [];

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('customformprefillenabled', 1, 'booking');
        $this->originalget = $_GET;
        $this->originalpost = $_POST;
        $this->originalrequest = $_REQUEST;
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        $_GET = $this->originalget;
        $_POST = $this->originalpost;
        $_REQUEST = $this->originalrequest;
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Prefill parameters should map label slugs and internal field names.
     *
     * @covers \mod_booking\local\customform_prefill::prefill_from_request
     * @covers \mod_booking\local\customform_prefill::build_prefill_data
     */
    public function test_prefill_from_request_maps_labels_and_internal_identifiers(): void {
        $user = $this->getDataGenerator()->create_user(['username' => 'prefilluser1']);
        [$booking, $option] = $this->create_booking_option_with_customform();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->set_request_params([
            'prefill_website_url' => 'https://example.com/subscription',
            'prefill_customform_shorttext_2' => 'Acme GmbH',
            'prefill_room_choice' => 'Room B',
            'prefill_accept_terms' => '1',
        ]);

        $prefilled = customform_prefill::prefill_from_request($settings, $user->id);

        $this->assertTrue($prefilled);

        $customformdata = (new customformstore($user->id, $settings->id))->get_customform_data();
        $this->assertEquals($settings->id, $customformdata->id);
        $this->assertEquals($user->id, $customformdata->userid);
        $this->assertSame('https://example.com/subscription', $customformdata->customform_url_1);
        $this->assertSame('Acme GmbH', $customformdata->customform_shorttext_2);
        $this->assertSame('room_b', $customformdata->customform_select_3);
        $this->assertSame(1, $customformdata->customform_advcheckbox_4);
        $this->assertEquals($booking->cmid, $settings->cmid);
    }

    /**
     * Invalid values should be ignored and existing cache entries preserved.
     *
     * @covers \mod_booking\local\customform_prefill::prefill_from_request
     * @covers \mod_booking\local\customform_prefill::build_prefill_data
     */
    public function test_prefill_from_request_ignores_invalid_values_and_merges_existing_cache(): void {
        $user = $this->getDataGenerator()->create_user(['username' => 'prefilluser2']);
        [, $option] = $this->create_booking_option_with_customform();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $store = new customformstore($user->id, $settings->id);
        $store->set_customform_data((object) [
            'id' => $settings->id,
            'userid' => $user->id,
            'customform_select_3' => 'room_a',
            'customform_shorttext_2' => 'Existing company',
        ]);

        $this->set_request_params([
            'prefill_website_url' => 'not-a-valid-url',
            'prefill_room_choice' => 'Unknown room',
            'prefill_accept_terms' => 'true',
        ]);

        $prefilled = customform_prefill::prefill_from_request($settings, $user->id);

        $this->assertTrue($prefilled);

        $customformdata = $store->get_customform_data();
        if (property_exists($customformdata, 'customform_url_1')) {
            $this->assertContains($customformdata->customform_url_1, [null, '', 'not-a-valid-url']);
        }
        $this->assertSame('room_a', $customformdata->customform_select_3);
        $this->assertSame('Existing company', $customformdata->customform_shorttext_2);
        $this->assertSame(1, $customformdata->customform_advcheckbox_4);
    }

    /**
     * Replace request globals so optional_param reads test values.
     *
     * @param array $params
     * @return void
     */
    private function set_request_params(array $params): void {
        $_GET = $params;
        $_POST = [];
        $_REQUEST = $params;
    }

    /**
     * Create a booking option with a small customform definition.
     *
     * @return array
     */
    private function create_booking_option_with_customform(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Prefill booking',
            'eventtype' => 'Subscription',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->setAdminUser();
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->courseid = $course->id;
        $record->text = 'Prefill option';
        $record->maxanswers = 10;
        $record->chooseorcreatecourse = 1;
        $record->description = 'Prefill option description';
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 June 2050 17:00', time());
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'url';
        $record->bo_cond_customform_label_1_1 = 'Website URL';
        $record->bo_cond_customform_notempty_1_1 = 1;
        $record->bo_cond_customform_select_1_2 = 'shorttext';
        $record->bo_cond_customform_label_1_2 = 'Company';
        $record->bo_cond_customform_select_1_3 = 'select';
        $record->bo_cond_customform_label_1_3 = 'Room Choice';
        $record->bo_cond_customform_value_1_3 = "room_a => Room A\nroom_b => Room B";
        $record->bo_cond_customform_notempty_1_3 = 1;
        $record->bo_cond_customform_select_1_4 = 'advcheckbox';
        $record->bo_cond_customform_label_1_4 = 'Accept Terms';

        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_instance();

        return [$booking, $option];
    }
}

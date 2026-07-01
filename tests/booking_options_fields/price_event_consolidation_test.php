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
 * Regression tests for consolidated bookingoption_updated events on price changes.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use mod_booking\event\bookingoption_updated;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for consolidated price change events.
 */
final class price_event_consolidation_test extends advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Cleanup.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Multiple changed price identifiers in one option update must emit a single bookingoption_updated event.
     * The event still needs to include other field changes.
     *
     * @covers \mod_booking\price::save_from_form
     * @covers \mod_booking\price::add_price
     * @covers \mod_booking\booking_option::update
     */
    public function test_single_event_for_multiple_price_identifier_changes(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->setAdminUser();

        $bdata = self::provide_bdata();
        $bdata['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $cat1 = (object) [
            'ordernum' => 1,
            'name' => 'Category A',
            'identifier' => 'cat_a',
            'defaultvalue' => 10,
            'pricecatsortorder' => 1,
        ];
        $cat2 = (object) [
            'ordernum' => 2,
            'name' => 'Category B',
            'identifier' => 'cat_b',
            'defaultvalue' => 20,
            'pricecatsortorder' => 2,
        ];
        $plugingenerator->create_pricecategory($cat1);
        $plugingenerator->create_pricecategory($cat2);

        $enc1 = bin2hex($cat1->identifier);
        $enc2 = bin2hex($cat2->identifier);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-created';
        $record->description = 'Desc-created';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->useprice = 1;
        $record->{"pricegroup_$enc1"}["bookingprice_$enc1"] = 10;
        $record->{"pricegroup_$enc2"}["bookingprice_$enc2"] = 20;
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $sink = $this->redirectEvents();

        $update = clone $record;
        $update->id = $option->id;
        $update->cmid = $settings->cmid;
        unset($update->importing);
        $update->text = 'Option-updated'; // Non-price change.
        $update->{"pricegroup_$enc1"}["bookingprice_$enc1"] = 11;
        $update->{"pricegroup_$enc2"}["bookingprice_$enc2"] = 22;

        booking_option::update($update);

        $events = $sink->get_events();
        $sink->close();

        $updevents = array_values(array_filter($events, function ($event) use ($option) {
            return $event instanceof bookingoption_updated && (int)$event->objectid === (int)$option->id;
        }));

        // Core assertion: only one option-updated event for this single update call.
        $this->assertCount(1, $updevents);

        $event = reset($updevents);
        $this->assertInstanceOf(bookingoption_updated::class, $event);

        $modulecontext = context_module::instance($settings->cmid);
        $this->assertEquals($modulecontext, $event->get_context());

        $data = $event->get_data();
        $this->assertIsArray($data['other']['changes']);

        $changes = $data['other']['changes'];

        $textchanges = array_values(array_filter($changes, fn($c) => ($c['fieldname'] ?? '') === 'text'));
        $this->assertCount(1, $textchanges);
        $this->assertEquals('Option-updated', $textchanges[0]['newvalue']);

        $pricechanges = array_values(array_filter($changes, fn($c) => ($c['fieldname'] ?? '') === 'price'));
        // We expect detailed rows for each changed price identifier but still only one event.
        $this->assertCount(2, $pricechanges);

        $priceformkeys = array_column($pricechanges, 'formkey');
        $this->assertContains('price_cat_a', $priceformkeys);
        $this->assertContains('price_cat_b', $priceformkeys);

        $byformkey = [];
        foreach ($pricechanges as $change) {
            $byformkey[$change['formkey']] = $change;
        }

        $this->assertStringContainsString('Category A : 10', $byformkey['price_cat_a']['oldvalue']);
        $this->assertStringContainsString('Category A : 11', $byformkey['price_cat_a']['newvalue']);
        $this->assertStringContainsString('Category B : 20', $byformkey['price_cat_b']['oldvalue']);
        $this->assertStringContainsString('Category B : 22', $byformkey['price_cat_b']['newvalue']);
    }

    /**
     * Provide stable booking activity defaults (reused pattern from existing tests).
     *
     * @return array
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking Price Consolidation',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
    }
}

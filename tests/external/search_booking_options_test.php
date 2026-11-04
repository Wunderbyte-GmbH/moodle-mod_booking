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
 * Tests for the booking option autocomplete backend (mod_booking_search_booking_options).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\external;

use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The autocomplete backend searches all booking options; the onlyfuture flag (used by the
 * "Only future booking options" checkbox of the transfer users modal) leaves out options that
 * have already ended, but keeps options without dates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\external\search_booking_options
 * @covers \mod_booking\booking_option::load_booking_options_filtered
 */
final class search_booking_options_test extends \advanced_testcase {
    /**
     * Common setup: fresh DB state and no cached option settings between tests.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Without the flag every matching option is suggested, with it only the ones not over yet.
     */
    public function test_onlyfuture_leaves_out_ended_options(): void {
        $this->setAdminUser();
        [$past, $future, $dateless] = $this->create_options();

        // Default (flag off): all three options are found, as before.
        $result = search_booking_options::execute('Searchfilter');
        $this->assertSame('', $result['warnings']);
        $this->assertEqualsCanonicalizing(
            [$past->id, $future->id, $dateless->id],
            array_keys($result['list']),
            'Without onlyfuture all matching options must be suggested.'
        );

        // Flag on: the ended option disappears, the future one and the one without dates stay.
        $result = search_booking_options::execute('Searchfilter', 0, 0, true);
        $this->assertSame('', $result['warnings']);
        $this->assertEqualsCanonicalizing(
            [$future->id, $dateless->id],
            array_keys($result['list']),
            'With onlyfuture the ended option must be left out and the dateless one kept.'
        );
    }

    /**
     * The future filter combines with the instance filter and the word search.
     */
    public function test_onlyfuture_combines_with_other_filters(): void {
        $this->setAdminUser();
        [$past, $future, $dateless, $booking] = $this->create_options();

        // Instance filter plus future filter.
        $result = booking_option::load_booking_options_filtered('', (int)$booking->id, 0, true);
        $this->assertEqualsCanonicalizing([$future->id, $dateless->id], array_keys($result['list']));

        // Instance filter via cmid plus a query that only matches the past option: nothing is left.
        $result = booking_option::load_booking_options_filtered('ended', 0, (int)$booking->cmid, true);
        $this->assertSame([], $result['list']);

        // The same query without the future filter still finds the past option.
        $result = booking_option::load_booking_options_filtered('ended', 0, (int)$booking->cmid, false);
        $this->assertSame([$past->id], array_keys($result['list']));
    }

    /**
     * Helper: one instance with an ended option, a future option and an option without dates.
     *
     * @return array{0: stdClass, 1: stdClass, 2: stdClass, 3: stdClass}
     */
    private function create_options(): array {
        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Searchfilter booking',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->useprice = 0;
        $record->maxanswers = 5;

        $record->text = 'Searchfilter ended option';
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('now - 6 days');
        $record->courseendtime_0 = strtotime('now - 3 days');
        $past = $plugingenerator->create_option($record);

        $record->text = 'Searchfilter future option';
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $future = $plugingenerator->create_option($record);

        $record->text = 'Searchfilter dateless option';
        unset($record->optiondateid_0, $record->daystonotify_0, $record->coursestarttime_0, $record->courseendtime_0);
        $dateless = $plugingenerator->create_option($record);

        return [$past, $future, $dateless, $booking];
    }
}

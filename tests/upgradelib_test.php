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
 * Tests for the upgrade helper functions.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/db/upgradelib.php');

/**
 * PHPUnit tests for the upgrade helpers in db/upgradelib.php.
 *
 * @covers ::booking_delete_duplicate_customfields_2017112101
 * @covers ::booking_performance_measurements_notnull_2026101401
 * @covers ::booking_add_fixed_canceluntil_to_purchases_without_dates_2026111800
 */
final class upgradelib_test extends booking_advanced_testcase {
    /**
     * Inserts a booking_customfields row and returns its id.
     *
     * @param int $optionid
     * @param string $cfgname
     * @param string $value
     * @return int
     */
    private function insert_customfield(int $optionid, string $cfgname, string $value): int {
        global $DB;
        return $DB->insert_record('booking_customfields', (object)[
            'bookingid' => 1,
            'optionid' => $optionid,
            'cfgname' => $cfgname,
            'value' => $value,
        ]);
    }

    /**
     * The deduplication keeps the oldest row of every (optionid, cfgname) group
     * and leaves unique rows untouched. Running it twice changes nothing.
     */
    public function test_delete_duplicate_customfields_keeps_oldest_row(): void {
        global $DB;

        // Three duplicates for option 1 / sports, one unique row for option 1 / music,
        // two duplicates for option 2 / sports.
        $keepsports1 = $this->insert_customfield(1, 'sports', 'first');
        $this->insert_customfield(1, 'sports', 'second');
        $this->insert_customfield(1, 'sports', 'third');
        $keepmusic = $this->insert_customfield(1, 'music', 'unique');
        $keepsports2 = $this->insert_customfield(2, 'sports', 'first');
        $this->insert_customfield(2, 'sports', 'second');

        booking_delete_duplicate_customfields_2017112101();

        $remaining = $DB->get_records('booking_customfields', [], 'id ASC');
        $this->assertSame(
            [(int)$keepsports1, (int)$keepmusic, (int)$keepsports2],
            array_map('intval', array_keys($remaining))
        );
        // The oldest row of each group survived with its value.
        $this->assertSame('first', $remaining[$keepsports1]->value);
        $this->assertSame('unique', $remaining[$keepmusic]->value);
        $this->assertSame('first', $remaining[$keepsports2]->value);

        // Running the deduplication again must not delete anything else.
        booking_delete_duplicate_customfields_2017112101();
        $this->assertCount(3, $DB->get_records('booking_customfields'));
    }

    /**
     * On a table without duplicates the deduplication is a no-op.
     */
    public function test_delete_duplicate_customfields_without_duplicates(): void {
        global $DB;

        $this->insert_customfield(1, 'sports', 'first');
        $this->insert_customfield(1, 'music', 'second');
        $this->insert_customfield(2, 'sports', 'third');

        booking_delete_duplicate_customfields_2017112101();

        $this->assertCount(3, $DB->get_records('booking_customfields'));
    }

    /**
     * Puts booking_performance_measurements into the state step 2026013000 left on upgraded sites:
     * shortcodename, shortcodehash and measurementname allow NULL.
     */
    private function make_performance_measurement_columns_nullable(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('booking_performance_measurements');
        $indexes = [
            new \xmldb_index('hashx', XMLDB_INDEX_NOTUNIQUE, ['shortcodehash']),
            new \xmldb_index('hashnameendx', XMLDB_INDEX_NOTUNIQUE, ['shortcodehash', 'endtime']),
        ];
        foreach ($indexes as $index) {
            $dbman->drop_index($table, $index);
        }
        foreach (['shortcodename' => '1333', 'shortcodehash' => '255', 'measurementname' => '255'] as $name => $length) {
            $dbman->change_field_notnull($table, new \xmldb_field($name, XMLDB_TYPE_CHAR, $length, null, null, null, null));
        }
        foreach ($indexes as $index) {
            $dbman->add_index($table, $index);
        }
    }

    /**
     * Returns the not_null flag of the three repaired columns.
     *
     * @return array
     */
    private function performance_measurement_notnull_flags(): array {
        global $DB;

        $columns = $DB->get_columns('booking_performance_measurements', false);
        return [
            'shortcodename' => $columns['shortcodename']->not_null,
            'shortcodehash' => $columns['shortcodehash']->not_null,
            'measurementname' => $columns['measurementname']->not_null,
        ];
    }

    /**
     * Upgraded sites with nullable columns get NOT NULL columns as in install.xml; NULL values become
     * empty strings, the indexes on shortcodehash exist again and a second run changes nothing.
     */
    public function test_performance_measurements_columns_become_not_null(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('booking_performance_measurements');
        $this->make_performance_measurement_columns_nullable();
        $this->assertSame(
            ['shortcodename' => false, 'shortcodehash' => false, 'measurementname' => false],
            $this->performance_measurement_notnull_flags()
        );

        $complete = $DB->insert_record('booking_performance_measurements', (object)[
            'starttime' => 1,
            'endtime' => 2,
            'shortcodename' => 'courselist',
            'shortcodehash' => 'abc',
            'measurementname' => 'render',
        ]);
        $incomplete = $DB->insert_record('booking_performance_measurements', (object)[
            'starttime' => 3,
            'endtime' => 4,
            'shortcodename' => null,
            'shortcodehash' => null,
            'measurementname' => null,
        ]);

        booking_performance_measurements_notnull_2026101401();

        $this->assertSame(
            ['shortcodename' => true, 'shortcodehash' => true, 'measurementname' => true],
            $this->performance_measurement_notnull_flags()
        );
        $row = $DB->get_record('booking_performance_measurements', ['id' => $incomplete]);
        $this->assertSame('', (string)$row->shortcodename);
        $this->assertSame('', (string)$row->shortcodehash);
        $this->assertSame('', (string)$row->measurementname);
        $row = $DB->get_record('booking_performance_measurements', ['id' => $complete]);
        $this->assertSame('courselist', $row->shortcodename);
        $this->assertSame('abc', $row->shortcodehash);
        $this->assertSame('render', $row->measurementname);
        $this->assertTrue($dbman->index_exists($table, new \xmldb_index('hashx', XMLDB_INDEX_NOTUNIQUE, ['shortcodehash'])));
        $this->assertTrue($dbman->index_exists(
            $table,
            new \xmldb_index('hashnameendx', XMLDB_INDEX_NOTUNIQUE, ['shortcodehash', 'endtime'])
        ));

        // A site that is already correct (fresh install or second run) stays unchanged.
        booking_performance_measurements_notnull_2026101401();
        $this->assertSame(
            ['shortcodename' => true, 'shortcodehash' => true, 'measurementname' => true],
            $this->performance_measurement_notnull_flags()
        );
        $this->assertCount(2, $DB->get_records('booking_performance_measurements'));
    }

    /**
     * The upgrade step adds the fixed cancellation date of the instance to the purchases without a deadline
     * of booking options without dates and of self-learning courses - and to nothing else.
     */
    public function test_add_fixed_canceluntil_to_purchases_without_dates(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fixeddate = strtotime('+ 10 days');
        $otherdate = strtotime('+ 50 days');
        $dates = [
            'optiondateid_0' => '0',
            'daystonotify_0' => '0',
            'coursestarttime_0' => strtotime('+ 30 days'),
            'courseendtime_0' => strtotime('+ 31 days'),
        ];
        $selflearning = ['selflearningcourse' => 1, 'duration' => 86400];

        $fixed = $this->create_booking_with_cancel_mode(
            ['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_ABSOLUTE, 'allowupdatetimestamp' => $fixeddate]
        );
        $relative = $this->create_booking_with_cancel_mode(
            ['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_RELATIVE, 'allowupdatedays' => 1]
        );
        $unlimited = $this->create_booking_with_cancel_mode(['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_UNLIMITED]);

        $nodates = $this->create_option_for_upgrade($fixed);
        $withdates = $this->create_option_for_upgrade($fixed, $dates);
        $selflearn = $this->create_option_for_upgrade($fixed, $selflearning);
        $relativenodates = $this->create_option_for_upgrade($relative);
        $unlimitednodates = $this->create_option_for_upgrade($unlimited);

        // Payment status: 0 pending, 1 aborted, 2 paid, 3 cancelled.
        $expected = [
            'paid, no deadline' => [$this->insert_purchase($nodates, 0), $fixeddate],
            'paid, deadline NULL' => [$this->insert_purchase($nodates, null), $fixeddate],
            'pending, no deadline' => [$this->insert_purchase($nodates, 0, 0), $fixeddate],
            'self-learning course' => [$this->insert_purchase($selflearn, 0), $fixeddate],
            'aborted payment' => [$this->insert_purchase($nodates, 0, 1), 0],
            'cancelled purchase' => [$this->insert_purchase($nodates, 0, 3), 0],
            'purchase with a deadline' => [$this->insert_purchase($nodates, $otherdate), $otherdate],
            'subbooking with the same id' => [$this->insert_purchase($nodates, 0, 2, 'subbooking'), 0],
            'item of another plugin' => [$this->insert_purchase($nodates, 0, 2, 'option', 'local_other'), 0],
            'option with dates' => [$this->insert_purchase($withdates, 0), 0],
            'instance with relative deadline' => [$this->insert_purchase($relativenodates, 0), 0],
            'instance without limit' => [$this->insert_purchase($unlimitednodates, 0), 0],
        ];

        $changed = booking_add_fixed_canceluntil_to_purchases_without_dates_2026111800();

        foreach ($expected as $case => [$historyid, $canceluntil]) {
            $this->assertSame(
                $canceluntil,
                (int)$DB->get_field('local_shopping_cart_history', 'canceluntil', ['id' => $historyid]),
                $case
            );
        }
        // The step reports exactly the purchases it changed.
        $changedids = array_column(array_filter($expected, fn($e) => $e[1] === $fixeddate), 0);
        $this->assertSame([$fixeddate => $changedids], $changed);

        // A second run changes nothing.
        $this->assertSame([], booking_add_fixed_canceluntil_to_purchases_without_dates_2026111800());
    }

    /**
     * Creates a booking instance where users can cancel, with the given cancel mode.
     *
     * @param array $cancelsettings
     * @return int id of the booking instance
     */
    private function create_booking_with_cancel_mode(array $cancelsettings): int {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', array_merge([
            'name' => 'Booking for upgrade step',
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'course' => $course->id,
            'bookingmanager' => $manager->username,
            'cancancelbook' => 1,
        ], $cancelsettings));

        return (int)$booking->id;
    }

    /**
     * Creates a booking option.
     *
     * @param int $bookingid
     * @param array $optiondata additional fields, e.g. dates
     * @return int id of the booking option
     */
    private function create_option_for_upgrade(int $bookingid, array $optiondata = []): int {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)array_merge([
            'bookingid' => $bookingid,
            'text' => 'Option for upgrade step',
            'courseid' => 0,
            'maxanswers' => 5,
            'importing' => 1,
        ], $optiondata));

        return (int)$option->id;
    }

    /**
     * Inserts a purchase into the history of shopping cart, like it is stored at checkout.
     *
     * @param int $itemid
     * @param int|null $canceluntil
     * @param int $paymentstatus 2 is paid
     * @param string $area
     * @param string $componentname
     * @return int id of the row
     */
    private function insert_purchase(
        int $itemid,
        ?int $canceluntil,
        int $paymentstatus = 2,
        string $area = 'option',
        string $componentname = 'mod_booking'
    ): int {
        global $DB, $USER;

        return (int)$DB->insert_record('local_shopping_cart_history', (object)[
            'userid' => $USER->id,
            'itemid' => $itemid,
            'itemname' => 'Purchase for upgrade step',
            'price' => 10,
            'currency' => 'EUR',
            'componentname' => $componentname,
            'area' => $area,
            'paymentstatus' => $paymentstatus,
            'usermodified' => $USER->id,
            'canceluntil' => $canceluntil,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}

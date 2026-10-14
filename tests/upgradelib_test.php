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
}

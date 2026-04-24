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
 * End-to-end tests: booking.bulk_update_options via executor (no real LLM).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * E2E tests for the booking.bulk_update_options agent task.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class agent_e2e_bulk_update_test extends abstract_agent_testcase {
    // Apply to all.

    /**
     * apply_to_all=true updates every option in the instance.
     */
    public function test_apply_to_all_updates_all_options(): void {
        $opt1 = $this->create_option('Bulk Alpha', ['maxanswers' => 1, 'maxoverbooking' => 0]);
        $opt2 = $this->create_option('Bulk Beta', ['maxanswers' => 2, 'maxoverbooking' => 0]);
        $opt3 = $this->create_option('Bulk Gamma', ['maxanswers' => 3, 'maxoverbooking' => 0]);

        $result = $this->exec_command('booking.bulk_update_options', [
            'apply_to_all'   => true,
            'maxanswers'     => 8,
            'maxoverbooking' => 3,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertArrayHasKey('previewoptionids', $result);
        $this->assertCount(3, $result['previewoptionids']);

        foreach ([$opt1->id, $opt2->id, $opt3->id] as $id) {
            $updated = $this->get_option_from_db((int)$id);
            $this->assertEquals(8, (int)$updated->maxanswers, "maxanswers mismatch on option $id");
            $this->assertEquals(3, (int)$updated->maxoverbooking, "maxoverbooking mismatch on option $id");
        }
    }

    /**
     * previewoptionids contains every updated ID.
     */
    public function test_apply_to_all_previewoptionids_contains_all_ids(): void {
        $opt1 = $this->create_option('Preview A');
        $opt2 = $this->create_option('Preview B');

        $result = $this->exec_command('booking.bulk_update_options', [
            'apply_to_all' => true,
            'maxanswers'   => 5,
        ]);

        $this->assertEquals('executed', $result['status']);
        $this->assertContains((int)$opt1->id, $result['previewoptionids']);
        $this->assertContains((int)$opt2->id, $result['previewoptionids']);
    }

    // Optionids array.

    /**
     * Only the options listed in optionids are updated.
     */
    public function test_explicit_optionids_updates_only_listed(): void {
        $opt1 = $this->create_option('Listed Option', ['maxanswers' => 1]);
        $opt2 = $this->create_option('Unlisted Option', ['maxanswers' => 1]);

        $result = $this->exec_command('booking.bulk_update_options', [
            'optionids'  => [$opt1->id],
            'maxanswers' => 10,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updated   = $this->get_option_from_db((int)$opt1->id);
        $untouched = $this->get_option_from_db((int)$opt2->id);

        $this->assertEquals(10, (int)$updated->maxanswers);
        $this->assertEquals(1, (int)$untouched->maxanswers);
    }

    /**
     * An optionid that does not belong to this booking instance → validation error.
     */
    public function test_foreign_optionid_returns_error(): void {
        $result = $this->exec_command('booking.bulk_update_options', [
            'optionids'  => [999999],
            'maxanswers' => 10,
        ]);

        $this->assertEquals('error', $result['status']);
    }

    // Optionquery.

    /**
     * optionquery selects a subset of options by name.
     */
    public function test_optionquery_updates_matching_options_only(): void {
        $yoga1 = $this->create_option('Yoga Morning', ['maxanswers' => 1]);
        $yoga2 = $this->create_option('Yoga Evening', ['maxanswers' => 1]);
        $other = $this->create_option('Pilates Class', ['maxanswers' => 1]);

        $result = $this->exec_command('booking.bulk_update_options', [
            'optionquery' => 'Yoga',
            'maxanswers'  => 7,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updatedyoga1 = $this->get_option_from_db((int)$yoga1->id);
        $updatedyoga2 = $this->get_option_from_db((int)$yoga2->id);
        $untouched    = $this->get_option_from_db((int)$other->id);

        $this->assertEquals(7, (int)$updatedyoga1->maxanswers);
        $this->assertEquals(7, (int)$updatedyoga2->maxanswers);
        $this->assertEquals(1, (int)$untouched->maxanswers);
    }

    // Error paths.

    /**
     * No target provided (no optionids, no optionquery, apply_to_all not set) → error.
     */
    public function test_missing_target_returns_error(): void {
        $result = $this->exec_command('booking.bulk_update_options', [
            'maxanswers' => 5,
        ]);

        $this->assertEquals('error', $result['status']);
    }

    /**
     * bookusersquery is forbidden for bulk_update_options → validation error.
     */
    public function test_bookusersquery_is_forbidden(): void {
        $opt = $this->create_option('Forbidden Field Test');

        $result = $this->exec_command('booking.bulk_update_options', [
            'apply_to_all'   => true,
            'bookusersquery' => 'some:query',
            'maxanswers'     => 5,
        ]);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('bookusersquery', $result['detail']);
    }

    /**
     * Applying bulk update with apply_to_all when there are no options → graceful error.
     */
    public function test_apply_to_all_no_options_returns_error(): void {
        // No options created; empty instance.
        $result = $this->exec_command('booking.bulk_update_options', [
            'apply_to_all' => true,
            'maxanswers'   => 5,
        ]);

        // Result should be error with a meaningful detail (no matching options).
        $this->assertEquals('error', $result['status']);
        $this->assertNotEmpty($result['detail']);
    }

    // Wbtable verification.

    /**
     * After apply_to_all, every option read via wbtable reflects the new values.
     */
    public function test_bulk_update_reflected_in_wbtable(): void {
        $opt1 = $this->create_option('WbBulk One', ['maxanswers' => 1]);
        $opt2 = $this->create_option('WbBulk Two', ['maxanswers' => 1]);

        $result = $this->exec_command('booking.bulk_update_options', [
            'apply_to_all' => true,
            'maxanswers'   => 9,
        ]);

        $this->assertEquals('executed', $result['status']);

        foreach ([$opt1->id, $opt2->id] as $id) {
            $rows = $this->gen->create_table_for_one_option((int)$id);
            $this->assertNotEmpty($rows, "wbtable returned no rows for option $id");
            $row = reset($rows);
            $this->assertEquals(9, (int)$row->maxanswers, "wbtable maxanswers mismatch for option $id");
        }
    }
}

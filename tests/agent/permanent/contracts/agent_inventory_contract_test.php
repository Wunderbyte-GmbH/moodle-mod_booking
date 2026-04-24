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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Permanent inventory contracts for booking AI test suite.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;

/**
 * Enforces a minimum inventory baseline so critical tests/features cannot disappear unnoticed.
 */
final class agent_inventory_contract_test extends advanced_testcase {
    /**
     * Critical agent phpunit files must remain present.
     */
    public function test_critical_agent_phpunit_files_exist(): void {
        $base = __DIR__ . '/../../';

        $requiredfiles = [
            'agent_interpreter_test.php',
            'agent_executor_test.php',
            'agent_e2e_create_option_test.php',
            'agent_e2e_update_option_test.php',
            'agent_e2e_bulk_update_test.php',
            'ai_send_message_internal_test.php',
            'agent_real_llm_test.php',
            'message_trigger_registry_test.php',
        ];

        foreach ($requiredfiles as $file) {
            $this->assertFileExists($base . $file, 'Missing critical test file: ' . $file);
        }
    }

    /**
     * AI instructions behat feature must remain available.
     */
    public function test_critical_ai_behat_feature_exists(): void {
        $feature = dirname(__DIR__, 3) . '/behat/booking_ai_instructions.feature';
        $this->assertFileExists($feature, 'Missing critical Behat feature booking_ai_instructions.feature');
    }

    /**
     * Permanent suite itself must have at least one file in each baseline subfolder.
     */
    public function test_permanent_suite_subfolders_not_empty(): void {
        $base = dirname(__DIR__);
        $requiredsubdirs = ['contracts', 'llm_sim', 'tasks'];

        foreach ($requiredsubdirs as $subdir) {
            $files = glob($base . '/' . $subdir . '/*.php') ?: [];
            $this->assertNotEmpty($files, 'Permanent suite subdir is empty: ' . $subdir);
        }
    }
}

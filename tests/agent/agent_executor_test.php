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
 * Unit tests for the agent executor, task registry, and authorization service.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\task_registry;
use required_capability_exception;
use moodle_exception;

/**
 * Tests for executor mechanics, registry internals, and authorization service.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class agent_executor_test extends abstract_agent_testcase {
    // Task registry.

    /**
     * make_default() auto-discovers all booking tasks including bulk_update_options.
     */
    public function test_registry_contains_bulk_update_options(): void {
        $registry = task_registry::make_default();
        $this->assertContains('booking.bulk_update_options', $registry->get_task_names());
    }

    /**
     * make_default() contains all expected core task names.
     */
    public function test_registry_contains_core_tasks(): void {
        $registry  = task_registry::make_default();
        $names     = $registry->get_task_names();

        $expected = [
            'booking.create_option',
            'booking.update_option',
            'booking.bulk_update_options',
            'booking.search_options',
            'booking.list_actions',
            'booking.list_option_properties',
        ];

        foreach ($expected as $name) {
            $this->assertContains($name, $names, "Task '$name' missing from default registry");
        }
    }

    /**
     * get_task() returns null for an unregistered task name.
     */
    public function test_registry_returns_null_for_unknown_task(): void {
        $registry = task_registry::make_default();
        $this->assertNull($registry->get_task('booking.nonexistent_task'));
    }

    /**
     * get_all_schemas() returns a non-empty schema for every registered task.
     */
    public function test_registry_all_schemas_non_empty(): void {
        $registry = task_registry::make_default();
        $schemas  = $registry->get_all_schemas();

        $this->assertNotEmpty($schemas);
        foreach ($schemas as $taskname => $schema) {
            $this->assertIsArray($schema, "Schema for '$taskname' is not an array");
            $this->assertNotEmpty($schema, "Schema for '$taskname' is empty");
        }
    }

    /**
     * is_read_only_task() returns false for unknown tasks (fail-safe default).
     */
    public function test_registry_unknown_task_is_not_readonly(): void {
        $registry = task_registry::make_default();
        $this->assertFalse($registry->is_read_only_task('booking.unknown'));
    }

    // Idempotency guard.

    /**
     * run_exists_other_than returns false when only one run with the given key exists.
     *
     * The idempotency guard in executor::execute_commands relies on this method
     * returning false for the normal non-duplicate case.
     */
    public function test_idempotency_guard_no_false_positive(): void {
        $this->setUser($this->teacher->id);
        $cmid  = (int)$this->booking->cmid;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($this->teacher->id, $cmid, (int)$this->booking->id);
        $key   = hash('sha256', 'idempotency-baseline-' . uniqid('', true));
        $runid = $store->create_run($thread->id, $this->teacher->id, $cmid, $key, []);

        // When only one run exists for this key, run_exists_other_than must return false.
        $this->assertFalse(
            $store->run_exists_other_than($key, $runid),
            'run_exists_other_than should return false when no competing run exists'
        );
    }
    /**
     * Unknown task name returns an error result (partial failure).
     */
    public function test_executor_unknown_task_returns_error(): void {
        $this->setUser($this->teacher->id);
        $cmid   = (int)$this->booking->cmid;
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread($this->teacher->id, $cmid, (int)$this->booking->id);
        $key    = hash('sha256', 'unknown-task-' . uniqid('', true));
        $runid  = $store->create_run($thread->id, $this->teacher->id, $cmid, $key, []);

        $authz  = new authorization_service();
        $exec   = new executor(task_registry::make_default(), $store, $authz);

        $results = $exec->execute_commands(
            [['task' => 'booking.this_does_not_exist', 'version' => 1, 'input' => []]],
            $cmid,
            $this->teacher->id,
            $key,
            $runid
        );

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['status']);
    }

    /**
     * Multi-command array: first command succeeds, second fails (partial success).
     */
    public function test_executor_partial_success(): void {
        $this->setUser($this->teacher->id);
        $cmid   = (int)$this->booking->cmid;
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread($this->teacher->id, $cmid, (int)$this->booking->id);
        $key    = hash('sha256', 'partial-' . uniqid('', true));
        $runid  = $store->create_run($thread->id, $this->teacher->id, $cmid, $key, []);

        $authz  = new authorization_service();
        $exec   = new executor(task_registry::make_default(), $store, $authz);

        $results = $exec->execute_commands(
            [
                ['task' => 'booking.create_option', 'version' => 1, 'input' => ['text' => 'Good Command']],
                ['task' => 'booking.this_is_unknown', 'version' => 1, 'input' => []],
            ],
            $cmid,
            $this->teacher->id,
            $key,
            $runid
        );

        $this->assertCount(2, $results);
        $this->assertEquals('executed', $results[0]['status']);
        $this->assertEquals('error', $results[1]['status']);
    }

    // Authorization service.

    /**
     * require_use_capability throws for a user without the capability.
     */
    public function test_authz_require_capability_throws_for_student(): void {
        $this->expectException(required_capability_exception::class);

        $authz = new authorization_service();
        $authz->require_use_capability((int)$this->student->id, (int)$this->booking->cmid);
    }

    /**
     * can_use() returns false for a student without the capability.
     */
    public function test_authz_can_use_false_for_student(): void {
        $authz = new authorization_service();
        $this->assertFalse($authz->can_use((int)$this->student->id, (int)$this->booking->cmid));
    }

    /**
     * can_use() returns true for a teacher with the capability.
     */
    public function test_authz_can_use_true_for_teacher(): void {
        $authz = new authorization_service();
        $this->assertTrue($authz->can_use((int)$this->teacher->id, (int)$this->booking->cmid));
    }

    /**
     * require_valid_context throws moodle_exception for a non-existent cmid.
     */
    public function test_authz_require_valid_context_throws_for_invalid_cmid(): void {
        $this->expectException(moodle_exception::class);

        $authz = new authorization_service();
        $authz->require_valid_context(999999);
    }

    /**
     * require_valid_context passes for a valid booking cmid.
     */
    public function test_authz_require_valid_context_passes_for_valid_cmid(): void {
        $authz = new authorization_service();
        // Must not throw.
        $authz->require_valid_context((int)$this->booking->cmid);
        $this->assertTrue(true);
    }

    /**
     * Executor re-checks authorization; a student's command is rejected.
     */
    public function test_executor_rejects_unauthorized_user(): void {
        $this->setUser($this->student->id);
        $cmid   = (int)$this->booking->cmid;
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread($this->student->id, $cmid, (int)$this->booking->id);
        $key    = hash('sha256', 'authz-student-' . uniqid('', true));
        $runid  = $store->create_run($thread->id, $this->student->id, $cmid, $key, []);

        $authz  = new authorization_service();
        $exec   = new executor(task_registry::make_default(), $store, $authz);

        $this->expectException(required_capability_exception::class);

        $exec->execute_commands(
            [['task' => 'booking.create_option', 'version' => 1, 'input' => ['text' => 'Attempt']]],
            $cmid,
            $this->student->id,
            $key,
            $runid
        );
    }
}

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

declare(strict_types=1);

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\preflight_execution_gate;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;
use context_module;

/**
 * Regression (thread 562): the operating context a module target resolves to during preflight must
 * be PERSISTED onto the queue item, so the confirmed/async execution and the guard token target the
 * resolved module instance — not the creation-time ambient context. Otherwise a site-home create
 * ran against the site context (cmid 0 → "Invalid course module").
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 * @covers \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class module_target_queue_persistence_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * set_prepared_input writes the resolved operating context back onto the item and binds the
     * guard token to it (not to the stale ambient context the item was created with).
     */
    public function test_set_prepared_input_persists_resolved_operating_context(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Sprechstunde']);
        $ambientcontextid = (int)context_course::instance($course->id)->id;
        $modulecontextid = (int)context_module::instance((int)$booking->cmid)->id;
        $this->assertNotSame($ambientcontextid, $modulecontextid);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $ambientcontextid);
        $threadid = (int)$thread->id;

        $queue = new queue_manager($store);
        $command = ['skill' => 'mod_booking.create_option', 'input' => ['text' => 'X'], 'risk_class' => skill_risk_class::R2];
        $item = $queue->enqueue_command($threadid, 1, 1, $command, 'mutating', 'blocked_confirmation', []);
        $queueitemid = (string)($item['queue_item_id'] ?? '');
        $this->assertNotSame('', $queueitemid);
        // At creation the item carries the ambient context (no operating_contextid on the raw command).
        $this->assertSame($ambientcontextid, (int)$queue->get_queue_item($threadid, $queueitemid)['operating_contextid']);

        $preparedinput = ['text' => 'X'];
        $queue->set_prepared_input($threadid, $queueitemid, $ambientcontextid, $preparedinput, $modulecontextid);

        $stored = $queue->get_queue_item($threadid, $queueitemid);
        // The resolved module context is now persisted on the item.
        $this->assertSame($modulecontextid, (int)$stored['operating_contextid']);
        // The guard token verifies against the RESOLVED context, and not against the ambient one.
        $this->assertTrue(preflight_execution_gate::verify_guard_token(
            (string)$stored['guard_token'],
            'mod_booking.create_option',
            $modulecontextid,
            $preparedinput
        ));
        $this->assertFalse(preflight_execution_gate::verify_guard_token(
            (string)$stored['guard_token'],
            'mod_booking.create_option',
            $ambientcontextid,
            $preparedinput
        ));
    }

    /**
     * Omitting the resolved operating context (legacy 4-arg call) leaves the item's existing
     * operating context untouched — backward compatible for same-context skills.
     */
    public function test_set_prepared_input_without_resolved_context_keeps_existing(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ambientcontextid = (int)context_course::instance($course->id)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, $ambientcontextid)->id;
        $queue = new queue_manager($store);
        $item = $queue->enqueue_command(
            $threadid,
            1,
            1,
            ['skill' => 'core.demo', 'input' => ['a' => 1], 'risk_class' => skill_risk_class::R2],
            'mutating',
            'blocked_confirmation',
            []
        );
        $queueitemid = (string)$item['queue_item_id'];

        $queue->set_prepared_input($threadid, $queueitemid, $ambientcontextid, ['a' => 1]);

        $this->assertSame($ambientcontextid, (int)$queue->get_queue_item($threadid, $queueitemid)['operating_contextid']);
    }

    /**
     * Preflight resolves a UNIQUE module target to the booking module context and stamps it on the
     * prepared command — the value the queue must then persist. The stamped context is the module
     * instance, not the ambient course context.
     */
    public function test_preflight_stamps_resolved_module_context_on_prepared_command(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Sprechstunde']);
        $ambientcontextid = (int)context_course::instance($course->id)->id;
        $modulecontextid = (int)context_module::instance((int)$booking->cmid)->id;

        $pipeline = new preflight_pipeline($this->build_registry(), $this->build_store());
        $result = $pipeline->run(
            [['skill' => 'demo.modtarget', 'input' => [], '_structural_validated' => true]],
            0,
            $ambientcontextid,
            (int)$USER->id
        );

        $this->assertSame('pass', (string)($result['status'] ?? ''));
        $prepared = (array)($result['prepared_commands'] ?? []);
        $this->assertCount(1, $prepared);
        $this->assertSame($modulecontextid, (int)$prepared[0]['operating_contextid']);
        $this->assertNotSame($ambientcontextid, (int)$prepared[0]['operating_contextid']);
    }

    /**
     * Registry returning a single module-targeting skill (modname 'booking').
     *
     * @return skill_registry
     */
    private function build_registry(): skill_registry {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill', 'get_skill_contract'])
            ->getMock();

        $registry->method('get_skill')->willReturnCallback(
            static fn(string $name): ?skill_interface => $name === 'demo.modtarget' ? self::module_target_skill() : null
        );
        $registry->method('get_skill_contract')->willReturnCallback(
            static fn(string $name): ?array => ['skill' => $name, 'version' => 1]
        );

        return $registry;
    }

    /**
     * A mocked conversation store (unused on the threadid=0 preflight path).
     *
     * @return conversation_store
     */
    private function build_store(): conversation_store {
        return $this->getMockBuilder(conversation_store::class)->disableOriginalConstructor()->getMock();
    }

    /**
     * Build a minimal R2 skill that opts into module targeting for the 'booking' module.
     *
     * @return skill_interface
     */
    private static function module_target_skill(): skill_interface {
        return new class implements skill_interface {
            /**
             * Return the unique skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'demo.modtarget';
            }

            /**
             * Return the input schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['version' => 1, 'properties' => []];
            }

            /**
             * Return an example input payload.
             *
             * @return array
             */
            public function get_example_input(): array {
                return [];
            }

            /**
             * Return the prompt contract describing this skill.
             *
             * @return skill_prompt_contract
             */
            public function get_prompt_contract(): skill_prompt_contract {
                return new skill_prompt_contract([
                    'intent' => 'demo',
                    'anchors' => [],
                    'minimal_input' => [],
                    'example_input' => [],
                    'namespace' => 'demo',
                    'version' => 1,
                    'capabilities' => [],
                    'context_scopes' => ['module'],
                    'risk_class' => skill_risk_class::R2,
                ]);
            }

            /**
             * Return the risk class of this skill.
             *
             * @return string
             */
            public function get_risk_class(): string {
                return skill_risk_class::R2;
            }

            /**
             * Validate the raw input structure.
             *
             * @param array $input
             * @return array
             */
            public function check_structure(array $input): array {
                return ['valid' => true, 'errors' => []];
            }

            /**
             * Run the preflight check and return the result.
             *
             * @param array $input
             * @param int $contextid
             * @param int $userid
             * @return preflight_result_v2
             */
            public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
                return preflight_result_v2::ok($input);
            }

            /**
             * Execute the skill against the prepared input.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }

            /**
             * Report whether the skill is read-only.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                return false;
            }

            /**
             * Opt into cross-context module targeting.
             *
             * @return bool
             */
            public function supports_target_context(): bool {
                return true;
            }

            /**
             * Return the targeted context level.
             *
             * @return int
             */
            public function get_target_context_level(): int {
                return CONTEXT_MODULE;
            }

            /**
             * Return the module name this skill targets.
             *
             * @return string
             */
            public function get_target_modname(): string {
                return 'booking';
            }

            /**
             * Return the target selector resolving the booking instance in scope.
             *
             * @param array $input
             * @return target_selector|null
             */
            public function get_target_selector(array $input): ?target_selector {
                return target_selector::for_module(null, null, 'booking');
            }
        };
    }
}

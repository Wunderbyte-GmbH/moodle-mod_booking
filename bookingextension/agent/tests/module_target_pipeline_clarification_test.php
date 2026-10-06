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
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * End-to-end: an ambiguous module target surfaces a clarification listing the candidate instances.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class module_target_pipeline_clarification_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Two booking instances in the ambient course → CONTEXT_TARGET_UNRESOLVED with both names listed.
     */
    public function test_ambiguous_module_target_lists_candidates(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $alpha = $this->getDataGenerator()->create_module(
            'booking',
            ['course' => $course->id, 'name' => 'Sprechstunde Alpha']
        );
        $beta = $this->getDataGenerator()->create_module(
            'booking',
            ['course' => $course->id, 'name' => 'Sprechstunde Beta']
        );
        $contextid = (int)context_course::instance($course->id)->id;

        $pipeline = new preflight_pipeline($this->build_registry(), $this->build_store());

        $result = $pipeline->run(
            [['skill' => 'demo.modtarget', 'input' => [], '_structural_validated' => true]],
            0,
            $contextid,
            (int)$USER->id
        );

        $this->assertContains('CONTEXT_TARGET_UNRESOLVED', $result['issue_codes']);
        $joinederrors = implode("\n", $result['errors']);
        // Each candidate line carries the unique cmid so a follow-up call can target it even
        // when names (or the containing course names) collide.
        $this->assertStringContainsString(
            'Sprechstunde Alpha (' . $course->fullname . ', cmid ' . (int)$alpha->cmid . ')',
            $joinederrors
        );
        $this->assertStringContainsString(
            'Sprechstunde Beta (' . $course->fullname . ', cmid ' . (int)$beta->cmid . ')',
            $joinederrors
        );
        // A needs_clarification issue is emitted so the run resolves to a clarification, not a raw error.
        $severities = array_map(static fn(array $i): string => (string)($i['severity'] ?? ''), $result['issues']);
        $this->assertContains('needs_clarification', $severities);
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
     * A mocked conversation store (unused on the threadid=0 path).
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
             * Empty selector → auto-resolve the unique booking instance in scope.
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

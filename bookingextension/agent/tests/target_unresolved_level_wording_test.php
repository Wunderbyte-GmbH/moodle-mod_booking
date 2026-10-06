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
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * CONTEXT_TARGET_UNRESOLVED clarifications must speak about the right target LEVEL (thread 589 / C2, F6).
 *
 * When a COURSE-level target cannot be resolved, the user today receives a message that talks about a
 * missing ACTIVITY (the not-found clarification string is level-blind and is shared with module
 * targets). The user asked about a course; being told about activities sends the conversation down
 * the wrong repair path. This test pins the level wording only — it deliberately does NOT constrain
 * the resolver's fallback order (in-course → site-wide → ask).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class target_unresolved_level_wording_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * A course-level target miss must tell the user about the missing COURSE, not about an activity.
     */
    public function test_course_target_miss_uses_course_level_wording(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int)context_course::instance($course->id)->id;

        $selector = target_selector::for_course(null, 'Course That Does Not Exist Zz421337');
        $skill = $this->build_target_skill('demo.coursetarget', CONTEXT_COURSE, $selector);
        $pipeline = new preflight_pipeline($this->build_registry('demo.coursetarget', $skill), $this->build_store());

        $result = $pipeline->run(
            [['skill' => 'demo.coursetarget', 'input' => [], '_structural_validated' => true]],
            0,
            $contextid,
            (int)$USER->id
        );

        $this->assertContains('CONTEXT_TARGET_UNRESOLVED', $result['issue_codes']);
        $message = $this->target_unresolved_message($result);
        $this->assertNotSame('', $message, 'The target miss must carry a user-facing clarification message.');

        $this->assertStringContainsStringIgnoringCase(
            'course',
            $message,
            'A COURSE-level target miss must reference the course level: ' . $message
        );
        // Today this fails: the shared not-found string speaks about a (missing) activity even though
        // the unresolved target was a course.
        $this->assertStringNotContainsStringIgnoringCase(
            'activit',
            $message,
            'A COURSE-level target miss must not talk about a missing ACTIVITY: ' . $message
        );
    }

    /**
     * Counter-probe against over-correction: a module-level miss keeps referencing the activity.
     */
    public function test_module_target_miss_keeps_activity_wording(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // A course without any booking instance → the named activity resolves nowhere (in-course
        // and site-wide scopes are both empty).
        $course = $this->getDataGenerator()->create_course();
        $contextid = (int)context_course::instance($course->id)->id;

        $selector = target_selector::for_module(null, 'Activity That Does Not Exist Zz421337', 'booking');
        $skill = $this->build_target_skill('demo.modtarget', CONTEXT_MODULE, $selector);
        $pipeline = new preflight_pipeline($this->build_registry('demo.modtarget', $skill), $this->build_store());

        $result = $pipeline->run(
            [['skill' => 'demo.modtarget', 'input' => [], '_structural_validated' => true]],
            0,
            $contextid,
            (int)$USER->id
        );

        $this->assertContains('CONTEXT_TARGET_UNRESOLVED', $result['issue_codes']);
        $message = $this->target_unresolved_message($result);
        $this->assertNotSame('', $message, 'The target miss must carry a user-facing clarification message.');

        $this->assertStringContainsStringIgnoringCase(
            'activity',
            $message,
            'A MODULE-level target miss must keep referencing the activity level: ' . $message
        );
    }

    /**
     * Extract the user-facing message of the CONTEXT_TARGET_UNRESOLVED issue.
     *
     * @param array $result the preflight_pipeline::run() output
     * @return string
     */
    private function target_unresolved_message(array $result): string {
        foreach ((array)($result['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            if ((string)($issue['code'] ?? '') === 'CONTEXT_TARGET_UNRESOLVED') {
                return trim((string)($issue['message'] ?? ''));
            }
        }
        return '';
    }

    /**
     * Registry mock exposing exactly one skill under the given name.
     *
     * @param string $name the skill name
     * @param skill_interface $skill the skill instance
     * @return skill_registry
     */
    private function build_registry(string $name, skill_interface $skill): skill_registry {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill', 'get_skill_contract'])
            ->getMock();

        $registry->method('get_skill')->willReturnCallback(
            static fn(string $requested): ?skill_interface => $requested === $name ? $skill : null
        );
        $registry->method('get_skill_contract')->willReturnCallback(
            static fn(string $requested): ?array => ['skill' => $requested, 'version' => 1]
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
     * Build a minimal mutating skill that opts into cross-context targeting with a fixed selector.
     *
     * @param string $name the skill name
     * @param int $level the targeted context level (CONTEXT_COURSE / CONTEXT_MODULE)
     * @param target_selector $selector the selector returned for any input
     * @return skill_interface
     */
    private function build_target_skill(string $name, int $level, target_selector $selector): skill_interface {
        return new class ($name, $level, $selector) implements skill_interface {
            /** @var string */
            private string $name;

            /** @var int */
            private int $level;

            /** @var target_selector */
            private target_selector $selector;

            /**
             * Constructor.
             *
             * @param string $name the skill name
             * @param int $level the targeted context level
             * @param target_selector $selector the fixed target selector
             */
            public function __construct(string $name, int $level, target_selector $selector) {
                $this->name = $name;
                $this->level = $level;
                $this->selector = $selector;
            }

            /**
             * Return the unique skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return $this->name;
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
                    'context_scopes' => ['course', 'module'],
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
             * Opt into cross-context targeting.
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
                return $this->level;
            }

            /**
             * Return the fixed target selector for any input.
             *
             * @param array $input
             * @return target_selector|null
             */
            public function get_target_selector(array $input): ?target_selector {
                return $this->selector;
            }
        };
    }
}

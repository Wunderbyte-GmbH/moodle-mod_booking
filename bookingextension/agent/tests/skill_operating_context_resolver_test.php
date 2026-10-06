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

namespace bookingextension_agent;

use advanced_testcase;
use context_course;
use context_module;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver;

/**
 * Tests for the skill → operating-context seam (cross-context Phase 1a).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_operating_context_resolver_test extends advanced_testcase {
    /**
     * A skill that does not opt into target context always operates in the ambient context.
     */
    public function test_non_optin_skill_stays_in_ambient_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ambient = agent_context::from_context(context_module::instance($page->cmid));

        $skill = $this->make_plain_skill();
        $operating = (new skill_operating_context_resolver())->resolve($skill, [], $ambient, 0);

        $this->assertSame($ambient->id(), $operating->id());
    }

    /**
     * An opted-in skill with an explicit target course resolves to that course context.
     */
    public function test_optin_skill_with_target_resolves_cross_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ambientcourse = $this->getDataGenerator()->create_course();
        $targetcourse = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $ambientcourse->id]);
        $ambient = agent_context::from_context(context_module::instance($page->cmid));

        $skill = $this->make_target_skill((int)$targetcourse->id);
        $operating = (new skill_operating_context_resolver())->resolve(
            $skill,
            ['courseid' => (int)$targetcourse->id],
            $ambient,
            0
        );

        $this->assertSame((int)context_course::instance($targetcourse->id)->id, $operating->id());
    }

    /**
     * An opted-in skill that yields an empty selector falls back to the ambient context.
     */
    public function test_optin_skill_with_empty_selector_stays_ambient(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ambient = agent_context::from_context(context_module::instance($page->cmid));

        $skill = $this->make_target_skill(0); // No id → empty selector.
        $operating = (new skill_operating_context_resolver())->resolve($skill, [], $ambient, 0);

        $this->assertSame($ambient->id(), $operating->id());
    }

    /**
     * Build a skill test double that does not expose the target-context opt-in.
     *
     * @return base_skill
     */
    private function make_plain_skill(): base_skill {
        return new class extends base_skill {
            /**
             * Read-only, lowest risk test double.
             */
            public function __construct() {
                parent::__construct(true, skill_risk_class::R0);
            }

            /**
             * Return the skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'test.plain';
            }

            /**
             * Return the skill schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['version' => 1, 'properties' => []];
            }

            /**
             * No-op execute for the double.
             *
             * @param array $input
             * @param int   $contextid
             * @param int   $userid
             * @return array
             */
            public function execute(array $input, int $contextid, int $userid): array {
                return ['status' => 'executed', 'detail' => '', 'resultid' => null];
            }
        };
    }

    /**
     * Build a skill test double that opts into course-level target context (by courseid input).
     *
     * @param int $courseid Course id to target (0 = no target).
     * @return base_skill
     */
    private function make_target_skill(int $courseid): base_skill {
        return new class ($courseid) extends base_skill {
            /** @var int Target course id (0 = none). */
            private int $targetcourseid;

            /**
             * Broad-write test double that targets a course.
             *
             * @param int $courseid
             */
            public function __construct(int $courseid) {
                parent::__construct(false, skill_risk_class::R2);
                $this->targetcourseid = $courseid;
            }

            /**
             * Return the skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'test.targeted';
            }

            /**
             * Return the skill schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['version' => 1, 'properties' => []];
            }

            /**
             * No-op execute for the double.
             *
             * @param array $input
             * @param int   $contextid
             * @param int   $userid
             * @return array
             */
            public function execute(array $input, int $contextid, int $userid): array {
                return ['status' => 'executed', 'detail' => '', 'resultid' => null];
            }

            /**
             * This double operates at course level.
             *
             * @return int
             */
            public function get_target_context_level(): int {
                return CONTEXT_COURSE;
            }

            /**
             * This double opts into cross-context execution.
             *
             * @return bool
             */
            public function supports_target_context(): bool {
                return true;
            }

            /**
             * Build the target selector from the courseid input.
             *
             * @param array $input
             * @return target_selector|null
             */
            public function get_target_selector(array $input): ?target_selector {
                $id = (int)($input['courseid'] ?? $this->targetcourseid);
                return target_selector::for_course($id > 0 ? $id : null);
            }
        };
    }
}

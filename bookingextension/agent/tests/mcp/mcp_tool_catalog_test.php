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
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\mcp\mcp_tool_catalog_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * Tests for the MCP tool catalog mapping.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_tool_catalog_service
 */
final class mcp_tool_catalog_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Build a catalog service instance.
     *
     * @return mcp_tool_catalog_service
     */
    private function make_catalog(): mcp_tool_catalog_service {
        $registry = skill_registry::make_default();
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        return new mcp_tool_catalog_service($registry, $evaluator);
    }

    /**
     * Create a course and an enrolled editing teacher.
     *
     * @return array [userid, contextid]
     */
    private function create_teacher_in_course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [(int)$teacher->id, (int)context_course::instance($course->id)->id];
    }

    /**
     * The tool name mapping is reversible and hides non-exposed skills.
     */
    public function test_tool_name_round_trip_and_exposure(): void {
        $this->resetAfterTest();
        $catalog = $this->make_catalog();

        $this->assertSame('course_search_courses', mcp_tool_catalog_service::tool_name_for('course.search_courses'));
        $this->assertSame('course.search_courses', $catalog->skill_for_tool_name('course_search_courses'));
        // The canonical dotted name resolves too.
        $this->assertSame('course.search_courses', $catalog->skill_for_tool_name('course.search_courses'));
        $this->assertNull($catalog->skill_for_tool_name('no_such_tool'));

        // PII/thread-coupled skills are hidden by default — including for direct calls.
        $this->assertNull($catalog->skill_for_tool_name('core_search_users'));
        $this->assertNull($catalog->skill_for_tool_name('wizard_remember'));
    }

    /**
     * Tool definitions carry a JSON-Schema input schema and MCP annotations.
     */
    public function test_tool_definition_shape(): void {
        $this->resetAfterTest();
        [$userid, $contextid] = $this->create_teacher_in_course();
        $catalog = $this->make_catalog();

        $tools = $catalog->get_tools($userid, $contextid);
        $bytoolname = array_column($tools, null, 'name');
        $this->assertArrayHasKey('course_search_courses', $bytoolname);

        $tool = $bytoolname['course_search_courses'];
        $this->assertNotEmpty($tool['description']);
        $this->assertSame('object', $tool['inputSchema']['type']);
        $this->assertFalse($tool['inputSchema']['additionalProperties']);
        $properties = (array)$tool['inputSchema']['properties'];
        $this->assertSame('string', $properties['query']['type']);
        $this->assertTrue($tool['annotations']['readOnlyHint']);
        $this->assertFalse($tool['annotations']['destructiveHint']);
        $this->assertSame('course.search_courses', $tool['annotations']['title']);

        // Default exposure policy: excluded skills never appear.
        $this->assertArrayNotHasKey('core_search_users', $bytoolname);
        $this->assertArrayNotHasKey('wizard_remember', $bytoolname);
    }

    /**
     * The executability evaluator filters the list per user.
     */
    public function test_catalog_is_filtered_by_executability(): void {
        $this->resetAfterTest();
        [, $contextid] = $this->create_teacher_in_course();
        $student = $this->getDataGenerator()->create_user();
        $catalog = $this->make_catalog();

        // Plugins may grant self-scoped skills to every authenticated user (local_taskflow does so for
        // its employee self-service skills), so "no tools at all" is no longer the invariant. The
        // invariant is the filter itself: every listed tool is executable for this user, and every
        // exposed skill the evaluator denies to this user is absent.
        $registry = skill_registry::make_default();
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        $names = array_column($catalog->get_tools((int)$student->id, $contextid), 'name');

        foreach ($names as $name) {
            $skillname = $catalog->skill_for_tool_name((string)$name);
            if ($skillname === null) {
                continue;
            }
            $verdict = $evaluator->evaluate_skill($skillname, (int)$student->id, $contextid);
            $this->assertSame('allow', (string)($verdict['executable_state'] ?? ''), "Listed tool $name must be executable.");
        }

        $denied = 0;
        foreach ($registry->get_skill_names() as $skillname) {
            if (!$catalog->is_exposed($skillname)) {
                continue;
            }
            $verdict = $evaluator->evaluate_skill($skillname, (int)$student->id, $contextid);
            if ((string)($verdict['executable_state'] ?? '') !== 'allow') {
                $denied++;
                $this->assertNotContains(mcp_tool_catalog_service::tool_name_for($skillname), $names);
            }
        }
        $this->assertGreaterThan(0, $denied, 'The fixture must contain skills this user may not run.');
    }

    /**
     * Mutating tools are hidden from the list while mcpallowmutations is off.
     */
    public function test_mutating_tools_hidden_without_mutations_optin(): void {
        $this->resetAfterTest();
        [$userid, $contextid] = $this->create_teacher_in_course();
        set_config('aiskillenableall', '1', 'bookingextension_agent');
        $catalog = $this->make_catalog();

        // Default: mutations off -> only read-only tools, no confirm tool.
        $names = array_column($catalog->get_tools($userid, $contextid), 'name');
        $this->assertNotContains('course_update_activity', $names);
        $this->assertNotContains('confirm_pending_action', $names);
        $this->assertContains('course_search_courses', $names);

        // Opt-in: mutating tools and the synthetic confirm tool appear.
        set_config('mcpallowmutations', '1', 'bookingextension_agent');
        $names = array_column($catalog->get_tools($userid, $contextid), 'name');
        $this->assertContains('course_update_activity', $names);
        $this->assertContains('confirm_pending_action', $names);
    }

    /**
     * A skill declaring itself unavailable via the optional is_available() is hidden from
     * the tool list, while an available sibling with the same shape stays listed.
     */
    public function test_unavailable_skill_is_skipped(): void {
        $this->resetAfterTest();

        $available = $this->make_stub_skill('demo.available', true);
        $unavailable = $this->make_stub_skill('demo.unavailable', false);

        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill_names', 'get_skill', 'is_read_only_skill'])
            ->getMock();
        $registry->method('get_skill_names')->willReturn(['demo.available', 'demo.unavailable']);
        $registry->method('is_read_only_skill')->willReturn(true);
        $registry->method('get_skill')->willReturnCallback(
            static function (string $name) use ($available, $unavailable): ?skill_interface {
                if ($name === 'demo.available') {
                    return $available;
                }
                return $name === 'demo.unavailable' ? $unavailable : null;
            }
        );

        $evaluator = $this->getMockBuilder(skill_executability_evaluator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['evaluate_skill'])
            ->getMock();
        $evaluator->method('evaluate_skill')->willReturn(['executable_state' => 'allow']);

        $catalog = new mcp_tool_catalog_service($registry, $evaluator);
        $names = array_column($catalog->get_tools(2, 1), 'name');

        $this->assertContains('demo_available', $names);
        $this->assertNotContains('demo_unavailable', $names);
    }

    /**
     * Build a minimal read-only stub skill with an is_available() self-declaration.
     *
     * @param string $name Canonical skill name.
     * @param bool $isavailable What is_available() reports.
     * @return skill_interface
     */
    private function make_stub_skill(string $name, bool $isavailable): skill_interface {
        return new class ($name, $isavailable) implements skill_interface {
            /** @var string Canonical skill name. */
            private string $name;

            /** @var bool What is_available() reports. */
            private bool $isavailable;

            /**
             * Constructor.
             *
             * @param string $name Canonical skill name.
             * @param bool $isavailable What is_available() reports.
             */
            public function __construct(string $name, bool $isavailable) {
                $this->name = $name;
                $this->isavailable = $isavailable;
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
                return ['version' => 1, 'description' => 'Stub skill.', 'properties' => []];
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
                    'intent' => 'stub',
                    'anchors' => [],
                    'minimal_input' => [],
                    'example_input' => [],
                    'namespace' => 'demo',
                    'version' => 1,
                    'capabilities' => [],
                    'context_scopes' => [],
                    'risk_class' => skill_risk_class::R0,
                ]);
            }

            /**
             * Return the risk class of this skill.
             *
             * @return string
             */
            public function get_risk_class(): string {
                return skill_risk_class::R0;
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
                return true;
            }

            /**
             * Self-declared availability on this instance (duck-typed by the catalog).
             *
             * @return bool
             */
            public function is_available(): bool {
                return $this->isavailable;
            }
        };
    }

    /**
     * An explicit mcpexposedskills allowlist overrides the default policy.
     */
    public function test_explicit_allowlist_is_authoritative(): void {
        $this->resetAfterTest();
        [$userid, $contextid] = $this->create_teacher_in_course();
        set_config('mcpexposedskills', 'course.search_courses', 'bookingextension_agent');
        $catalog = $this->make_catalog();

        $tools = $catalog->get_tools($userid, $contextid);
        $this->assertSame(['course_search_courses'], array_column($tools, 'name'));
        $this->assertNull($catalog->skill_for_tool_name('course_analyze_course_structure'));
    }
}

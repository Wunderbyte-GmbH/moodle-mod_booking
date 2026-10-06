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
 * Real-LLM smoke matrix across all currently registered skills.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_llm_skill_matrix_testcase.php');

/**
 * Exercises every current skill through the real LLM using one shared scenario matrix.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class all_skills_real_llm_test extends abstract_llm_skill_matrix_testcase {
    /**
     * Require a configured live provider for this smoke suite.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
        $this->build_runtime();
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Return the shared skill matrix through a local PHPUnit provider entrypoint.
     *
     * @return array
     */
    public static function real_skill_matrix_scenarios(): array {
        return llm_skill_matrix_scenario_provider::provide_registered_skill_scenarios();
    }

    public function test_skill_matrix_covers_all_registered_skills(): void {
        $this->assertSame([], llm_skill_matrix_scenario_provider::get_missing_registered_skill_scenarios());
    }

    /**
     * Smoke-test each registered skill through the real LLM using the shared matrix.
     *
     * @dataProvider real_skill_matrix_scenarios
     * @param array $scenario
     */
    public function test_all_registered_skills_can_complete_via_real_llm(array $scenario): void {
        $this->assert_llm_skill_scenario_success($scenario);
    }
}

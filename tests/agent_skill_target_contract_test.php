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

namespace mod_booking;

use advanced_testcase;
use ReflectionClass;
use mod_booking\local\wizard\options\skills\booking_skill_base;

/**
 * Guard: every activity-scoped mutating booking skill must resolve its target from the input.
 *
 * A mutating skill that acts on a booking activity or option MUST opt into the target contract
 * (supports_target_context via the option_targeted_skill / module_targeted_skill traits) so the
 * engine can resolve the operating context from the command parameters. A skill that instead
 * relies on the ambient context alone silently breaks the moment it is reached at a non-module
 * context — which is always the case over MCP (system context) and from the dashboard.
 *
 * This test fails for any new such skill that forgets the trait, preventing regression back to
 * ambient-only cmid resolution.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\option_targeted_skill
 */
final class agent_skill_target_contract_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip when the AI engine subplugin is absent: the booking skills extend its base_skill
     * through the class_alias layer and cannot even load without it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Activity-scoped mutating skills opt into the target contract.
     *
     * @return void
     */
    public function test_activity_scoped_mutating_skills_opt_into_target_contract(): void {
        $dir = __DIR__ . '/../classes/local/wizard/options/skills';
        $namespace = 'mod_booking\\local\\wizard\\options\\skills\\';

        $checked = 0;
        foreach (glob($dir . '/*_skill.php') as $file) {
            $class = $namespace . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isTrait()) {
                continue;
            }
            if (!$reflection->isSubclassOf(booking_skill_base::class)) {
                continue;
            }

            $skill = new $class();

            // Read-only skills never mutate, so ambient-scoped reads are acceptable.
            if ($skill->is_read_only()) {
                continue;
            }
            // Site-scoped skills (e.g. price categories) act at the system context and need no
            // booking-activity target; they are correct over MCP without the trait.
            if ((int)$skill->get_required_context_level() === CONTEXT_SYSTEM) {
                continue;
            }

            $checked++;
            $this->assertTrue(
                $skill->supports_target_context(),
                "{$class} is an activity-scoped mutating skill but does not opt into the target "
                . 'contract. Add the option_targeted_skill trait (option-scoped skills) or the '
                . 'module_targeted_skill trait (activity-scoped skills) so it resolves its operating '
                . 'context from the command input — otherwise it cannot run over MCP (system context) '
                . 'or from the dashboard, and Gate 2 would be checked at the wrong context.'
            );
        }

        $this->assertGreaterThan(
            4,
            $checked,
            'Expected several activity-scoped mutating booking skills to be verified by this guard.'
        );
    }
}

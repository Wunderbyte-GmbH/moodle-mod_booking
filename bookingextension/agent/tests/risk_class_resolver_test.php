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
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\services\risk\risk_class_resolver;

/**
 * Tests for the centralized risk-class resolver (S1).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\risk\risk_class_resolver
 */
final class risk_class_resolver_test extends advanced_testcase {
    /**
     * Valid classes pass through (trimmed); anything else falls back to R3.
     */
    public function test_normalize(): void {
        $this->assertSame(skill_risk_class::R0, risk_class_resolver::normalize(skill_risk_class::R0));
        $this->assertSame(skill_risk_class::R2, risk_class_resolver::normalize('  ' . skill_risk_class::R2 . ' '));
        $this->assertSame(skill_risk_class::R3, risk_class_resolver::normalize(''));
        $this->assertSame(skill_risk_class::R3, risk_class_resolver::normalize('garbage'));
    }

    /**
     * Rank orders R0 < R1 < R2 < R3, with unknown treated as the most restrictive.
     */
    public function test_rank(): void {
        $this->assertSame(0, risk_class_resolver::rank(skill_risk_class::R0));
        $this->assertSame(1, risk_class_resolver::rank(skill_risk_class::R1));
        $this->assertSame(2, risk_class_resolver::rank(skill_risk_class::R2));
        $this->assertSame(3, risk_class_resolver::rank(skill_risk_class::R3));
        $this->assertSame(3, risk_class_resolver::rank('garbage'));
    }

    /**
     * A command's own valid risk_class wins without consulting the registry.
     */
    public function test_resolve_for_command_prefers_command_class(): void {
        $this->resetAfterTest();
        $registry = skill_registry_factory::get_default();
        $this->assertSame(
            skill_risk_class::R1,
            risk_class_resolver::resolve_for_command(['risk_class' => skill_risk_class::R1], $registry)
        );
    }

    /**
     * With no usable class and an unknown skill, the resolver fails safe to R3.
     */
    public function test_resolve_for_command_fails_safe_to_r3(): void {
        $this->resetAfterTest();
        $registry = skill_registry_factory::get_default();
        $this->assertSame(
            skill_risk_class::R3,
            risk_class_resolver::resolve_for_command(['skill' => 'does.not.exist'], $registry)
        );
        $this->assertSame(
            skill_risk_class::R3,
            risk_class_resolver::resolve_for_command([], $registry)
        );
    }

    /**
     * A command without its own class inherits the declared class of a real registered skill.
     */
    public function test_resolve_for_command_uses_registered_skill_class(): void {
        $this->resetAfterTest();
        $registry = skill_registry_factory::get_default();

        // Find any registered skill and confirm the resolver returns its declared class.
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $skillname = (string)($contract['skill'] ?? '');
            if ($skillname === '') {
                continue;
            }
            $skill = $registry->get_skill($skillname);
            if ($skill === null) {
                continue;
            }
            $expected = risk_class_resolver::normalize($skill->get_risk_class());
            $this->assertSame(
                $expected,
                risk_class_resolver::resolve_for_command(['skill' => $skillname], $registry)
            );
            return;
        }

        $this->markTestSkipped('no registered skill available to exercise the registry path');
    }
}

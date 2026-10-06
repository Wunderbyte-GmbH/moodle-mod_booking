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
 * update_activity and update_quiz fence each other on their cards.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * UQ-2 (L44, L47 thread 16040): "Der Abschlusstest heißt jetzt ..." went to course.update_activity, which cannot edit
 * a quiz (module whitelist), and ended as "not found". Neither card fenced the other. A/B at the recorded selector call,
 * 20 runs: update_quiz 18 -> 20 with the mutual fence; the control UA-2 (a forum) stays 20/20 on update_activity.
 *
 * @covers \bookingextension_agent\local\wizard\skill_registry
 */
final class update_activity_quiz_fence_test extends \advanced_testcase {
    /**
     * Engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Each card's NOT line names the other skill.
     */
    public function test_update_activity_and_update_quiz_fence_each_other(): void {
        $this->resetAfterTest();
        $not = [];
        foreach (skill_registry_factory::get_default()->get_all_prompt_contracts() as $contract) {
            $not[(string)($contract['skill'] ?? '')] = (string)($contract['not'] ?? '');
        }
        $this->assertStringContainsString('update_quiz', $not['course.update_activity'] ?? '');
        $this->assertStringContainsString('update_activity', $not['course.update_quiz'] ?? '');
    }
}

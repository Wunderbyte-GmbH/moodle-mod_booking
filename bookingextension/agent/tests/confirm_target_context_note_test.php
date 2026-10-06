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
use ReflectionMethod;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;

/**
 * The confirmation for a mutating command must always name the target course (with its id), so a
 * mis-resolved course is visible before the write happens.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class confirm_target_context_note_test extends advanced_testcase {
    /**
     * Build a decision service with real (test-instantiable) dependencies.
     *
     * @return agent_decision_service
     */
    private function make_service(): agent_decision_service {
        return new agent_decision_service(
            skill_registry::make_default(),
            new conversation_store(),
            new authorization_service()
        );
    }

    /**
     * Invoke the private note builder.
     *
     * @param agent_decision_service $service
     * @param array $commands
     * @param int $ambientcontextid
     * @return string
     */
    private function note(agent_decision_service $service, array $commands, int $ambientcontextid): string {
        $method = new ReflectionMethod($service, 'build_operating_context_note');
        $method->setAccessible(true);
        return (string)$method->invoke($service, $commands, $ambientcontextid, '');
    }

    /**
     * A command carrying an explicit operating context names that course with its id.
     */
    public function test_note_names_target_course_with_id(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Demo Course']);
        $ctxid = (int)context_course::instance($course->id)->id;

        $note = $this->note(
            $this->make_service(),
            [['skill' => 'course.add_activity', 'operating_contextid' => $ctxid]],
            999999
        );

        $this->assertStringContainsString('Demo Course', $note);
        $this->assertStringContainsString('ID ' . $course->id, $note);
    }

    /**
     * A command WITHOUT an explicit operating context (the common same-course case) still names the
     * ambient course — point 4: the target must always be shown, not only for cross-context.
     */
    public function test_note_falls_back_to_ambient_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Ambient Course']);
        $ctxid = (int)context_course::instance($course->id)->id;

        $note = $this->note(
            $this->make_service(),
            [['skill' => 'course.add_activity']],
            $ctxid
        );

        $this->assertStringContainsString('Ambient Course', $note);
        $this->assertStringContainsString('ID ' . $course->id, $note);
    }

    /**
     * Two commands targeting the same course collapse to a single note entry (no duplication).
     */
    public function test_note_deduplicates_same_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Once Only']);
        $ctxid = (int)context_course::instance($course->id)->id;

        $note = $this->note(
            $this->make_service(),
            [
                ['skill' => 'course.add_activity', 'operating_contextid' => $ctxid],
                ['skill' => 'course.add_activity', 'operating_contextid' => $ctxid],
            ],
            999999
        );

        $this->assertSame(1, substr_count($note, 'Once Only'));
    }
}

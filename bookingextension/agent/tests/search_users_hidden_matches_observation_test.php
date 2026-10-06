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
use bookingextension_agent\local\wizard\core\skills\search_users_skill;

/**
 * Visibility-filtered matches must never be reported as non-existence (F59).
 *
 * The visibility gate (CAP-01) is correct and stays. But when it removes every match, the
 * observation and the user message must say "matches exist that you may not see" instead of
 * "found nothing" — otherwise the synchronizer CANNOT phrase the difference. Privacy boundary:
 * the count may travel, identities of hidden users never do.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\search_users_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_users_hidden_matches_observation_test extends advanced_testcase {
    /**
     * Seed a teacher in their own course plus a matching user in an unshared course.
     *
     * @param string $firstname
     * @param string $lastname
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} [teacher, hiddentarget, teachercourse]
     */
    private function seed_teacher_and_hidden_target(string $firstname, string $lastname): array {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $target = $this->getDataGenerator()->create_user([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => 'hidden.match@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $courseb->id, 'student');
        return [$teacher, $target, $coursea];
    }

    /**
     * Hidden-only matches: the reply must distinguish "not visible to you" from "does not exist".
     */
    public function test_hidden_only_matches_distinguish_from_nonexistence(): void {
        $this->resetAfterTest();
        [$teacher, $target, $coursea] = $this->seed_teacher_and_hidden_target('Zebra', 'Hiddenmatch');

        $this->setUser($teacher);
        $result = (new search_users_skill())->execute(
            ['query' => 'Zebra Hiddenmatch'],
            (int)context_course::instance($coursea->id)->id,
            (int)$teacher->id
        );

        $observation = (string)($result['observation_full'] ?? '');
        $usermessage = (string)($result['usermessage'] ?? '');

        $this->assertSame([], (array)($result['users'] ?? []), 'the visibility gate itself must keep filtering');
        $this->assertStringContainsString(
            'not visible',
            $observation,
            'the observation must carry the hidden-matches fact, or the synchronizer cannot phrase it'
        );
        $this->assertNotSame(
            'Found 0 user(s).',
            $observation,
            'hidden-only must not produce the byte-identical nonexistence observation'
        );
        $this->assertNotSame(
            get_string('agent_booking_search_users_no_results', 'bookingextension_agent'),
            $usermessage,
            'the user must not be told the account does not exist'
        );

        foreach (['Zebra', 'Hiddenmatch', 'hidden.match@example.com'] as $identity) {
            $this->assertStringNotContainsString($identity, $observation, 'no hidden identity in the observation');
            $this->assertStringNotContainsString($identity, $usermessage, 'no hidden identity in the user message');
        }
    }

    /**
     * Genuinely zero matches anywhere: the plain not-found wording must stay, so the two
     * cases remain distinguishable.
     */
    public function test_no_matches_keeps_plain_not_found(): void {
        $this->resetAfterTest();
        [$teacher, , $coursea] = $this->seed_teacher_and_hidden_target('Zebra', 'Hiddenmatch');

        $this->setUser($teacher);
        $result = (new search_users_skill())->execute(
            ['query' => 'Nonexistent Ghostperson'],
            (int)context_course::instance($coursea->id)->id,
            (int)$teacher->id
        );

        $this->assertSame('Found 0 user(s).', (string)($result['observation_full'] ?? ''));
        $this->assertSame(
            get_string('agent_booking_search_users_no_results', 'bookingextension_agent'),
            (string)($result['usermessage'] ?? '')
        );
    }

    /**
     * Visible results plus additional hidden matches: the observation must say the shown
     * list is not the complete set of matches — identities of the hidden stay out.
     */
    public function test_visible_plus_hidden_matches_annotate_observation(): void {
        $this->resetAfterTest();
        [$teacher, , $coursea] = $this->seed_teacher_and_hidden_target('Querxle', 'Hiddentwo');
        $visible = $this->getDataGenerator()->create_user([
            'firstname' => 'Querxle',
            'lastname' => 'Visibleone',
        ]);
        $this->getDataGenerator()->enrol_user($visible->id, $coursea->id, 'student');

        $this->setUser($teacher);
        $result = (new search_users_skill())->execute(
            ['query' => 'Querxle'],
            (int)context_course::instance($coursea->id)->id,
            (int)$teacher->id
        );

        $ids = array_map(static fn(array $u): int => (int)($u['userid'] ?? 0), (array)($result['users'] ?? []));
        $this->assertContains((int)$visible->id, $ids, 'the shared-course match stays visible');

        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringContainsString(
            'not visible',
            $observation,
            'with hidden extras the observation must not read as the complete match set'
        );
        $this->assertStringNotContainsString('Hiddentwo', $observation, 'no hidden identity in the observation');
    }
}

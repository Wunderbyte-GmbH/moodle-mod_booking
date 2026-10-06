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
use bookingextension_agent\local\wizard\skill_provider;

/**
 * The selector and the constructor see a skill description only up to the first sentence boundary
 * within 240 characters (planner_catalog_service::compact_catalog_description). Whatever a skill needs
 * to say about ITSELF must therefore sit inside that window.
 *
 * Since wave 17 (#2453) the boundary AGAINST a sibling is not said in the description at all: it travels
 * in the IS:/NOT: card lines, which the selector reads and the embedding anchor builder does not.
 *
 * Baseline run 9 (2026-09-16, Wunderbyte-GmbH#2419): FC-2 core.find_content → course.search_courses,
 * SC-2 course.search_courses → mod_booking.search_options, DUC-3/4 course.diagnose_user_in_course →
 * mod_booking.diagnose_user_booking, GQ-1 question.generate_questions → course.search_courses,
 * LS-1/LS-4 wizard.list_skills → wizard.search_skills (whose window was just "Last-resort capability
 * discovery."), SS-4 wizard.search_skills → wizard.explain_docs.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\skill_provider
 */
final class wizard_skill_description_budget_test extends advanced_testcase {
    /** @var int Character budget of the planner catalog description. */
    private const BUDGET = 240;

    /**
     * Replica of planner_catalog_service::compact_catalog_description (the service needs the assistant
     * summary dependency): whitespace-normalised, cut at the last sentence boundary within 240;
     * '' when no sentence boundary fits (the engine then falls back to a word cut + ellipsis).
     *
     * @param string $description Raw description.
     * @return string
     */
    private function window(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if (\core_text::strlen($normalized) <= self::BUDGET) {
            return $normalized;
        }
        $window = \core_text::substr($normalized, 0, self::BUDGET);
        if (preg_match('/^(.*[.!?]["\'\)\]]*)(?:\s|$)/us', $window, $matches)) {
            return rtrim($matches[1]);
        }
        return '';
    }

    /**
     * Every description keeps at least one full sentence inside the window (no word-boundary cut).
     */
    public function test_first_sentence_of_every_description_fits_the_window(): void {
        $this->resetAfterTest();
        $broken = [];
        foreach ((new skill_provider())->get_skills() as $skill) {
            $window = $this->window((string)($skill->get_schema()['description'] ?? ''));
            if ($window === '' || !preg_match('/[.!?]["\'\)\]]*$/u', $window)) {
                $broken[] = $skill->get_name() . ': ' . $window;
            }
        }
        $this->assertSame([], $broken, "no sentence boundary within 240 characters:\n" . implode("\n", $broken));
    }

    /**
     * Discriminating identifiers per confusable skill (skill ids / property names / object nouns).
     *
     * @return array<string,array{string,string[]}>
     */
    public static function discriminator_provider(): array {
        return [
            'find_content' => ['core.find_content', ['content', 'course.search_courses']],
            'search_courses' => ['course.search_courses', ['course', 'mod_booking.search_options', 'core.find_content']],
            // Run 10 (#2423): access to a named Moodle course = enrol (EU-4).
            'enrol_user' => ['course.enrol_user', ['course', 'mod_booking.book_users', 'coursequery']],
            // Run 10 (#2423): cannot open / grade missing is course; cannot book is booking (DBI-1, DUC-4).
            // Nachlauf 2026-09-17: DUC-2 ("cohort sync did not pull Tom into …") went to
            // local_taskflow.diagnose_import, so the window names the enrolment facet and that sibling.
            'diagnose_user_in_course' => [
                'course.diagnose_user_in_course',
                ['course', 'cohort sync', 'local_taskflow.diagnose_import'],
            ],
            // Run 10 (#2423): dictated questions go into the bank, not into a quiz (GQ-4).
            'generate_questions' => ['question.generate_questions', ['question bank', 'usecoursepdfs', 'course.add_quiz']],
            'add_activity' => ['course.add_activity', ['modname', 'question.generate_questions']],
            'add_quiz' => ['course.add_quiz', ['quiz', 'question.generate_questions']],
            'list_skills' => ['wizard.list_skills', ['no query', 'wizard.search_skills']],
            'search_skills' => ['wizard.search_skills', ['query', 'wizard.list_skills']],
            'explain_docs' => ['wizard.explain_docs', ['documentation', 'wizard.search_skills']],
            // Run 10 (#2423): a standing preference is stored, not searched as a capability (REM-2).
            'remember' => ['wizard.remember', ['memory', 'wizard.search_skills']],
        ];
    }

    /**
     * The discriminating identifiers sit inside the 240-character window.
     *
     * @dataProvider discriminator_provider
     * @param string $skillname Skill name.
     * @param string[] $identifiers Identifiers expected inside the window.
     */
    public function test_discriminating_identifiers_are_inside_the_window(string $skillname, array $identifiers): void {
        $this->resetAfterTest();
        $skill = null;
        foreach ((new skill_provider())->get_skills() as $candidate) {
            if ($candidate->get_name() === $skillname) {
                $skill = $candidate;
            }
        }
        $this->assertNotNull($skill, $skillname . ' not provided');
        $schema = (array)$skill->get_schema();
        $window = $this->window((string)($schema['description'] ?? ''));
        // Since wave 17 (#2453) a sibling's NAME no longer belongs in the description: the description is
        // embedding anchor #0, and a vector carries no negation, so a boundary sentence there pulled the
        // skill towards the queries it was meant to repel. The name now travels in the IS:/NOT: card
        // lines, which the selector reads but the anchor builder does not. The guarantee is unchanged —
        // the identifier must reach the selector — only its surface moved, so a skill identifier is
        // asserted against the whole card and subject vocabulary still against the description window.
        $card = $window . ' ' . trim((string)($schema['is'] ?? '')) . ' ' . trim((string)($schema['not'] ?? ''));
        foreach ($identifiers as $identifier) {
            if (strpos($identifier, '.') !== false) {
                // Inside its own namespace a card names a sibling by its short name, which is shorter and
                // still unambiguous; skill_catalog_discrimination_test proves no short name is shared by
                // two namespaces. Either spelling therefore identifies the sibling for the selector.
                $short = substr($identifier, (int)strrpos($identifier, '.') + 1);
                $reached = strpos($card, $identifier) !== false || strpos($card, $short) !== false;
                $this->assertTrue($reached, $skillname . ' does not name ' . $identifier . ' on its card: ' . $card);
                continue;
            }
            $this->assertStringContainsString($identifier, $window, $skillname . ' window: ' . $window);
        }
    }
}

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
use bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use context_course;

/**
 * Thread 587 (C5): the scaffold result must report the ACTUAL (IST) counts of what it created.
 *
 * The skill requests a fixed number of final-quiz questions (FINAL_QUIZ_QUESTIONS = 8), but the
 * model may deliver fewer parseable GIFT questions; import succeeds with whatever parsed
 * ("count > 0 is enough", commit 2e055de/F2 — that V1 semantic is NOT challenged here). The
 * defect: neither observation_full nor produced_outputs carries the actually-created question
 * count, so the synchronizer can only parrot the requested number instead of reporting the truth
 * (threads 587: "8 statt 10" reported as 10).
 *
 * Contract pinned by this test (documented choice, see thread 587): the skill result's
 * produced_outputs must carry the IST quiz-question count under the key 'questions'
 * (integer, questions actually created and referenced), alongside the existing
 * 'chapters' counter. Deliberately NOT asserted: any blocking/failing on Soll != Ist —
 * honest reporting only.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill
 */
final class scaffold_observation_counts_test extends advanced_testcase {
    /**
     * Reset the scripted responder after every test.
     */
    protected function tearDown(): void {
        llm_call_service::set_test_responder(null);
        parent::tearDown();
    }

    /**
     * Final quiz requested (Soll = 8 questions), scripted GIFT delivers only 5 parseable
     * questions. The DB ground truth is 5; produced_outputs must report exactly that 5.
     */
    public function test_result_reports_actual_created_question_count(): void {
        global $DB;
        $env = $this->setup_course();

        // Scripted generation: outline (2 chapters), chapter HTML, and a GIFT document with
        // only 5 questions although the skill asks for 8 (FINAL_QUIZ_QUESTIONS). The branches
        // key on the skills' own deterministic prompt scaffolding (like the exemplar test).
        llm_call_service::set_test_responder(function (string $actionclass, string $prompt): string {
            if (str_contains($prompt, 'GIFT format')) {
                return $this->gift_with_five_questions();
            }
            if (str_contains($prompt, 'drafting the structure')) {
                return json_encode([
                    'welcometitle' => 'Willkommen',
                    'welcomehtml' => '<p>Willkommen!</p>',
                    'overviewhtml' => '<h3>Ziele</h3><p>Überblick.</p>',
                    'chapters' => [
                        ['title' => 'Alltag und Gesellschaft'],
                        ['title' => 'Seefahrt und Schiffe'],
                    ],
                    'summarytitle' => 'Zusammenfassung',
                    'summaryhtml' => '<p>Recap.</p>',
                ]);
            }
            return '<h3>Abschnitt</h3><p>' . str_repeat('Inhalt. ', 50) . '</p>';
        });

        $skill = new scaffold_course_content_skill();
        $dto = $skill->preflight(
            ['topic' => 'Das Leben der Wikinger', 'chapters' => 2, 'finalquiz' => true, 'quizquestions' => 8],
            $env['contextid'],
            $env['userid']
        );
        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));

        $result = $skill->execute($dto->preparedinput, $env['contextid'], $env['userid']);
        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));

        // DB ground truth (postcondition, green today): exactly ONE final quiz exists and it
        // really carries the 5 imported questions — not the 8 that were requested.
        $course = get_course($env['courseid']);
        $quizzes = array_values(array_filter(
            get_fast_modinfo($course, $env['userid'])->get_cms(),
            static fn($cm): bool => $cm->modname === 'quiz'
        ));
        $this->assertCount(1, $quizzes, 'exactly one (final) quiz was requested');
        $slots = $DB->count_records('quiz_slots', ['quizid' => (int)$quizzes[0]->instance]);
        $this->assertSame(5, $slots, 'the scripted GIFT yields exactly 5 real questions in the quiz');

        // The honest-reporting contract (red today, thread 587): the result must carry the IST
        // count so the synchronizer can report 5 instead of parroting the requested 8.
        $produced = (array)($result['produced_outputs'] ?? []);
        $this->assertArrayHasKey(
            'questions',
            $produced,
            'produced_outputs must report the actually-created quiz question count (thread 587): '
                . json_encode($produced)
        );
        $this->assertSame(
            5,
            (int)$produced['questions'],
            'produced_outputs.questions must be the IST count (5), never the requested Soll (8)'
        );
    }

    /**
     * A GIFT document with exactly 5 importable questions (the skill asks for 8).
     *
     * @return string
     */
    private function gift_with_five_questions(): string {
        $questions = [
            'Ships' => ['How were Viking ships powered?', 'Sail and oar', 'Steam', 'Diesel'],
            'Runes' => ['What script did the Vikings write with?', 'Runes', 'Hieroglyphs', 'Cyrillic'],
            'Longhouse' => ['What was a typical Viking dwelling called?', 'Longhouse', 'Castle', 'Villa'],
            'Thing' => ['What was the Viking assembly called?', 'Thing', 'Senate', 'Court'],
            'Era' => ['Around which year did the Viking Age begin?', '793', '1492', '1066'],
        ];
        $lines = [];
        foreach ($questions as $name => $parts) {
            $lines[] = '::' . $name . ':: ' . $parts[0] . ' {';
            $lines[] = '=' . $parts[1];
            $lines[] = '~' . $parts[2];
            $lines[] = '~' . $parts[3];
            $lines[] = '}';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    /**
     * Create an empty course and return admin-context env.
     *
     * @return array
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        return [
            'courseid' => (int)$course->id,
            'contextid' => (int)context_course::instance($course->id)->id,
            'userid' => (int)$USER->id,
        ];
    }
}

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
 * Contract and behaviour tests for course.scaffold_course_content.
 *
 * Content generation is scripted through llm_call_service::set_test_responder(), so the
 * whole scaffold (sections, pages) runs deterministically without a provider.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill
 * @covers     \bookingextension_agent\local\wizard\services\course\course_content_generation_service
 */
final class scaffold_course_content_skill_test extends advanced_testcase {
    /**
     * Reset the scripted responder after every test.
     */
    protected function tearDown(): void {
        llm_call_service::set_test_responder(null);
        parent::tearDown();
    }

    /**
     * Contract: R2 mutating course-scoped skill with section+activity gates and course targeting.
     */
    public function test_contract_shape(): void {
        $skill = new scaffold_course_content_skill();

        $this->assertSame('course.scaffold_course_content', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
        $this->assertTrue($skill->supports_target_context());
        $this->assertSame(
            ['moodle/course:update', 'moodle/course:manageactivities'],
            $skill->get_required_native_capabilities()
        );

        $structure = $skill->check_structure([]);
        $this->assertFalse($structure['valid']);
    }

    /**
     * G2b: with NO structure parameter in the input, preflight asks the ONE consolidated
     * structure question (deterministic trigger, never lexical).
     */
    public function test_preflight_asks_structure_question_when_no_structure_given(): void {
        $env = $this->setup_course();

        $result = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Das Leben der Wikinger'],
            $env['contextid'],
            $env['userid']
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('SCAFFOLD_STRUCTURE_REQUIRED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Any explicit structure parameter suppresses the question — even "just the chapter count".
     */
    public function test_preflight_passes_with_explicit_structure(): void {
        $env = $this->setup_course();

        $dto = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Das Leben der Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        );

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $this->assertSame(2, (int)$dto->preparedinput['chapters']);
        $this->assertFalse((bool)$dto->preparedinput['practicequizzes']);
        $this->assertFalse((bool)$dto->preparedinput['finalquiz']);
    }

    /**
     * Wave 32 (SCC-2 L43, thread 13306): a chapter count that is not a number (the construction sent chapters=true)
     * keeps the documented default instead of becoming a one-chapter course through an (int) cast.
     */
    public function test_a_non_numeric_chapter_count_keeps_the_default(): void {
        $env = $this->setup_course();

        $dto = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Das Leben der Wikinger', 'chapters' => true],
            $env['contextid'],
            $env['userid']
        );

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $this->assertSame(4, (int)$dto->preparedinput['chapters']);
    }

    /**
     * Wave 32 (SCC-1 L39/L43, SCC-4 L33): the example shows the shape only. Its chapters 4, finalquiz true and
     * quizquestions 5 were copied into constructions whose user gave none of them, which skipped the structure
     * question and the question-count question (B4).
     */
    public function test_example_input_carries_no_structure_value(): void {
        $example = (new scaffold_course_content_skill())->get_example_input();

        foreach (['chapters', 'practicequizzes', 'finalquiz', 'quizquestions'] as $field) {
            $this->assertArrayNotHasKey($field, $example, $field . ' must not be copyable from the example');
        }
        $this->assertArrayHasKey('topic', $example);
    }

    /**
     * Wave 32: the constructor sees the first 160 characters of a field description. The quiz-count rule
     * ("leave out, the system asks") and the course-target rule used to lie behind that cut.
     */
    public function test_target_and_count_fields_keep_their_rule_inside_the_cut(): void {
        $properties = (new scaffold_course_content_skill())->get_schema()['properties'];

        foreach (['quizquestions', 'coursequery', 'courseid'] as $field) {
            $this->assertLessThanOrEqual(
                160,
                \core_text::strlen((string)$properties[$field]['description']),
                $field . ' fits the constructor prompt cut'
            );
        }
        $this->assertStringContainsString('leave out', (string)$properties['quizquestions']['description']);
        $this->assertStringContainsString('coursequery', (string)$properties['courseid']['description']);
    }

    /**
     * Wave 32 (SCC-4: create_course chosen for a named existing course in L35-L41 and L43): the card and the
     * create_course card fence each other; the selector window says the course already exists.
     */
    public function test_card_fences_course_creation(): void {
        $schema = (new scaffold_course_content_skill())->get_schema();
        $this->assertStringContainsString('course.create_course', (string)$schema['not']);

        $firstsentence = strtok((string)$schema['description'], '.');
        $this->assertStringContainsString('EXISTING', (string)$firstsentence);
        $this->assertLessThanOrEqual(240, \core_text::strlen((string)$firstsentence));

        $create = (new \bookingextension_agent\local\wizard\course\skills\create_course_skill())->get_schema();
        $this->assertStringContainsString('scaffold_course_content', (string)$create['not']);
    }

    /**
     * Moodle's auto-created announcements forum is an EXPECTED activity: a fresh course
     * containing only the news forum passes without any override (thread 586: the plain
     * count>0 check blocked the chain on every fresh course).
     */
    public function test_default_news_forum_does_not_block_scaffolding(): void {
        $env = $this->setup_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $env['courseid'], 'type' => 'news']);

        $result = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        )->to_array();

        $this->assertSame('pass', $result['status'], json_encode($result['issue_codes'] ?? []));
    }

    /**
     * A non-empty course soft-blocks once and passes with the override token.
     */
    public function test_non_empty_course_soft_blocks_until_override(): void {
        $env = $this->setup_course();
        $this->getDataGenerator()->create_module('page', ['course' => $env['courseid']]);

        $blocked = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        )->to_array();
        $this->assertNotSame('pass', $blocked['status']);
        $this->assertContains(
            'SCAFFOLD_COURSE_NOT_EMPTY_CONFIRM_REQUIRED',
            (array)($blocked['issue_codes'] ?? [])
        );

        $confirmed = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2, 'override' => ['course_not_empty']],
            $env['contextid'],
            $env['userid']
        )->to_array();
        $this->assertSame('pass', $confirmed['status']);
    }

    /**
     * Execute builds the full anatomy from scripted generation output: named sections,
     * welcome + chapter + summary pages — exactly N+2 pages, nothing more (exact-N doctrine).
     */
    public function test_execute_builds_anatomy_from_scripted_generation(): void {
        global $DB;
        $env = $this->setup_course();

        // The responder receives ($actionclass, $prompt) and returns the raw content string;
        // outline vs. chapter calls are told apart by this skill's own deterministic prompt text.
        llm_call_service::set_test_responder(function (string $actionclass, string $prompt): string {
            if (str_contains($prompt, 'drafting the structure')) {
                return json_encode([
                    'welcometitle' => 'Willkommen bei den Wikingern',
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
            ['topic' => 'Das Leben der Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        );
        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));

        $result = $skill->execute($dto->preparedinput, $env['contextid'], $env['userid']);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertStringNotContainsString('WARNINGS', (string)$result['detail']);

        $course = get_course($env['courseid']);
        $modinfo = get_fast_modinfo($course, $env['userid']);

        $pages = array_filter($modinfo->get_cms(), static fn($cm): bool => $cm->modname === 'page');
        $this->assertCount(4, $pages, 'welcome + 2 chapters + summary = exactly 4 pages');

        // Regression guard (Wunderbyte-GmbH#2201): the generated BODY must be persisted, not just
        // the module created — page_add_instance() drops the editor content when add_moduleinfo()
        // runs without a form, which shipped every agent-created page with an empty body.
        $allbodies = '';
        foreach ($pages as $cm) {
            $content = (string)$DB->get_field('page', 'content', ['id' => $cm->instance], MUST_EXIST);
            $this->assertNotSame('', trim($content), "page '{$cm->name}' was saved with an empty body");
            $allbodies .= $content;
        }
        $this->assertStringContainsString(
            'Inhalt.',
            $allbodies,
            'chapter bodies must contain the scripted generation output'
        );
        $quizzes = array_filter($modinfo->get_cms(), static fn($cm): bool => $cm->modname === 'quiz');
        $this->assertCount(0, $quizzes, 'no quizzes were requested');

        $sections = $modinfo->get_section_info_all();
        $this->assertGreaterThanOrEqual(4, count($sections));
        $this->assertSame('Willkommen bei den Wikingern', (string)$sections[0]->name);
        $this->assertSame('Alltag und Gesellschaft', (string)$sections[1]->name);
        $this->assertSame('Seefahrt und Schiffe', (string)$sections[2]->name);
        $this->assertSame('Zusammenfassung', (string)$sections[3]->name);

        $this->assertSame(2, (int)($result['produced_outputs']['chapters'] ?? 0));
    }

    /**
     * A failed outline call writes NOTHING (outline-first): the course stays empty.
     */
    public function test_failed_outline_writes_nothing(): void {
        $env = $this->setup_course();

        // The scripted seam cannot fail the call itself; an unparseable outline exercises the
        // same nothing-written guarantee (extract_json → null → error before any write).
        llm_call_service::set_test_responder(static function (): string {
            return 'this is not json';
        });

        $skill = new scaffold_course_content_skill();
        $dto = $skill->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        );
        $result = $skill->execute($dto->preparedinput, $env['contextid'], $env['userid']);

        $this->assertSame('error', $result['status']);
        $modinfo = get_fast_modinfo(get_course($env['courseid']), $env['userid']);
        $this->assertCount(0, $modinfo->get_cms(), 'a failed outline must not leave partial content');
    }

    /**
     * The skill is auto-discovered by the registry under its canonical name.
     */
    public function test_registry_discovers_the_skill(): void {
        $this->resetAfterTest();
        $registry = \bookingextension_agent\local\wizard\skill_registry::make_default();
        $this->assertInstanceOf(
            scaffold_course_content_skill::class,
            $registry->get_skill('course.scaffold_course_content')
        );
    }

    /**
     * Create an empty course and return admin-context env.
     *
     * @return array{courseid:int,contextid:int,userid:int}
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

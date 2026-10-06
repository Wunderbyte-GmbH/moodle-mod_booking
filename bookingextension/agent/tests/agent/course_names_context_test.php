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
 * The constructor of a course skill sees the names of the courses the request's words point to.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;

/**
 * Welle 33/34 (L45-L47 AQ-1, AQ-3, SCC-1): "vom Brandschutzkurs", "dans le cours de biologie", "Der Buchdruck-Kurs"
 * name a course by its subject, which is also the topic of the content; the constructor read it as content and the quiz
 * went to the course of the current page. 12 card and rule variants had no effect. A/B at the recorded constructor
 * calls with the matching course names as a fact block (George 2026-09-27: yes): AQ-1 2 -> 20/20, AQ-3 3 -> 20/20,
 * SCC-1 7 -> 19/20, the control AQ-2 20 -> 20/20.
 *
 * The block is engine state: courses the user can access whose name shares a word stem with the request (a comparison
 * against real course names, like the resolvers - no word list), at most ten, only in the construction phase of a skill
 * that targets a course (get_target_context_level() = CONTEXT_COURSE, a declarative hook). A course name passes the
 * anonymizer like every other block.
 *
 * @covers \bookingextension_agent\local\wizard\services\runtime_context_block_builder
 */
final class course_names_context_test extends \advanced_testcase {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Engine aliases and a few courses.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
        $this->resetAfterTest();
        $this->store = new conversation_store();
        // The course "Agent Smoke Course" as on the VM: live check 16568 listed it for "cours de biologie"
        // ("cours" inside "course").
        $names = ['Biologie', 'Brandschutz im Betrieb', 'Buchdruck (Kursrohling)', 'Excel-Kurs', 'Agent Smoke Course'];
        foreach ($names as $name) {
            $this->getDataGenerator()->create_course(['fullname' => $name]);
        }
    }

    /**
     * The volatile runtime block of one construction call.
     *
     * @param int $userid
     * @param string $message The user's request.
     * @param string $skill The selected skill.
     * @return string
     */
    private function construction_block(int $userid, string $message, string $skill): string {
        $contextid = (int)\context_system::instance()->id;
        $threadid = (int)$this->store->create_fresh_thread($userid, $contextid)->id;
        $this->store->add_message($threadid, 'user', $message);
        $builder = new runtime_context_block_builder(
            $this->store,
            new completed_command_history_service($this->store),
            new planner_catalog_service(new assistant_state_guidance_service())
        );
        return (string)$builder->build(
            $threadid,
            $contextid,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION,
            true,
            false,
            [['skill' => $skill]]
        )['volatile'];
    }

    /**
     * A course named by its subject: the matching course is listed for a course skill.
     */
    public function test_a_course_named_by_its_subject_is_listed(): void {
        $this->setAdminUser();
        $block = $this->construction_block(
            (int)get_admin()->id,
            'Fabrique un quiz sur la photosynthèse dans le cours de biologie, une dizaine de questions.',
            'course.add_quiz'
        );
        $this->assertStringContainsString('COURSE NAMES matching words of the request', $block);
        $this->assertStringContainsString('- Biologie', $block);
        $this->assertStringNotContainsString('Excel-Kurs', $block);
        $this->assertStringNotContainsString('Agent Smoke Course', $block, 'a weaker match than the named course is noise');

        $block = $this->construction_block(
            (int)get_admin()->id,
            'Zum Abschluss vom Brandschutzkurs hätte ich gern einen Test mit 15 Fragen.',
            'course.add_quiz'
        );
        $this->assertStringContainsString('- Brandschutz im Betrieb', $block, 'a course word inside a request word');
    }

    /**
     * Several courses that fit the request equally are not listed (baseline L48, AA-2 thread 16747): the list made the
     * constructor pick one of them - A/B at the recorded call with the live planner action: 4/20 "Winter School 2026"
     * picked silently, 5/20 asked without the course choices; without the list 19/20 hand "Winter School" to the course
     * resolution, which asks with both courses to choose from. Controls without the list: ACS-1 20/20, DUC-2 19/20,
     * UQ-1 19/20 (with it 16/20).
     */
    public function test_equally_matching_courses_are_not_listed(): void {
        $this->setAdminUser();
        $this->getDataGenerator()->create_course(['fullname' => 'Winter School 2026']);
        $this->getDataGenerator()->create_course(['fullname' => 'Winter School 2027']);
        $block = $this->construction_block(
            (int)get_admin()->id,
            'Drop a discussion board into the Winter School so participants can introduce themselves.',
            'course.add_activity'
        );
        $this->assertStringNotContainsString('COURSE NAMES', $block, 'two courses fit equally: the list would only invite a guess');

        $block = $this->construction_block(
            (int)get_admin()->id,
            'Fabrique un quiz sur la photosynthèse dans le cours de biologie.',
            'course.add_quiz'
        );
        $this->assertStringContainsString('- Biologie', $block, 'one course ahead of the rest is still listed');
    }

    /**
     * Only skills that target a course get the block; no matching word, no block.
     */
    public function test_no_block_without_a_course_skill_or_a_match(): void {
        $this->setAdminUser();
        $block = $this->construction_block((int)get_admin()->id, 'Stell den Biologie-Termin um.', 'mod_booking.update_option');
        $this->assertStringNotContainsString('COURSE NAMES', $block, 'a booking skill targets an activity, not a course');
        $block = $this->construction_block((int)get_admin()->id, 'Mach ein Quiz über Handball.', 'course.add_quiz');
        $this->assertStringNotContainsString('COURSE NAMES', $block, 'no course name shares a word with the request');
    }

    /**
     * Non-success paths: a course the user cannot access is not listed, and a person's name in a course name is masked.
     */
    public function test_only_accessible_courses_and_masked_names(): void {
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Tessa', 'lastname' => 'Quill']);
        $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Zeta']);
        $own = $this->getDataGenerator()->create_course(['fullname' => 'Biologie Oberstufe mit Anna Zeta']);
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$own->id, 'editingteacher');
        $this->setUser($teacher);
        $block = $this->construction_block((int)$teacher->id, 'Leg im Biologiekurs ein Quiz an.', 'course.add_quiz');
        $this->assertStringContainsString('Biologie Oberstufe', $block);
        $this->assertStringNotContainsString("\n- Biologie\n", $block . "\n", 'the course "Biologie" is not accessible to her');
        $this->assertStringNotContainsString('Anna', $block, 'a person name in a course name is masked');
    }
}

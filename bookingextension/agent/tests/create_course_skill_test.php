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
use bookingextension_agent\local\wizard\course\skills\create_course_skill;
use context_system;

/**
 * Contract and behaviour tests for course.create_course.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\create_course_skill
 */
final class create_course_skill_test extends advanced_testcase {
    /**
     * Contract: R2 mutating skill at system level with the native course:create gate.
     */
    public function test_contract_shape(): void {
        $skill = new create_course_skill();

        $this->assertSame('course.create_course', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(CONTEXT_SYSTEM, $skill->get_required_context_level());
        $this->assertSame(['moodle/course:create'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertArrayHasKey('fullname', (array)$schema['properties']);
        $this->assertSame(['system'], (array)($schema['prompt_meta']['context_scopes'] ?? []));

        $structure = $skill->check_structure([]);
        $this->assertFalse($structure['valid']);
    }

    /**
     * Category policy (§4/1): several writable categories and no categoryquery → ask with a
     * candidate list, never a silent default.
     */
    public function test_preflight_asks_for_category_when_ambiguous(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $gen->create_category(['name' => 'Wikingerkategorie']);
        $gen->create_category(['name' => 'Segelkategorie']);

        $result = (new create_course_skill())->preflight(
            ['fullname' => 'Das Leben der Wikinger'],
            (int)context_system::instance()->id,
            (int)$USER->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('CREATE_COURSE_CATEGORY_REQUIRED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A course that already exists is the first thing to ask about - before any category question.
     *
     * Baseline runs 25-32, SCC-4: "Mach aus 'Winter School 2027' ... einen fertigen Kurs" reached
     * create_course, which asked which of five categories to use while a course of exactly that name
     * existed; the decisive fact (fill the existing course, or really create a second one?) came second.
     */
    public function test_an_existing_course_is_asked_about_before_the_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $gen->create_category(['name' => 'Wikingerkategorie']);
        $gen->create_category(['name' => 'Segelkategorie']);
        $gen->create_course(['fullname' => 'Winter School 2027']);

        $result = (new create_course_skill())->preflight(
            ['fullname' => 'Winter School 2027'],
            (int)context_system::instance()->id,
            (int)$USER->id
        )->to_array();

        $codes = (array)($result['issue_codes'] ?? []);
        $this->assertContains('DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED', $codes, json_encode($result));
        $this->assertNotContains('CREATE_COURSE_CATEGORY_REQUIRED', $codes, 'the category is not the question here');
    }

    /**
     * A named category resolves silently when unambiguous; execute creates the course in it
     * and reports courseid + link material in the observation.
     */
    public function test_named_category_resolves_and_execute_creates_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB, $USER;

        $gen = $this->getDataGenerator();
        $category = $gen->create_category(['name' => 'Wikingerkategorie']);
        $gen->create_category(['name' => 'Segelkategorie']);

        $skill = new create_course_skill();
        $dto = $skill->preflight([
            'fullname' => 'Das Leben der Wikinger',
            'summary' => 'Alltag, Seefahrt und Kultur.',
            'categoryquery' => 'Wikinger',
        ], (int)context_system::instance()->id, (int)$USER->id);

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $prepared = $dto->preparedinput;
        $this->assertSame((int)$category->id, (int)$prepared['categoryid']);
        $this->assertNotSame('', (string)$prepared['shortname']);

        $result = $skill->execute($prepared, (int)context_system::instance()->id, (int)$USER->id);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $courseid = (int)$result['resultid'];
        $this->assertGreaterThan(0, $courseid);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $this->assertSame('Das Leben der Wikinger', $course->fullname);
        $this->assertSame((int)$category->id, (int)$course->category);
        $this->assertStringContainsString('linkedcoursequery', (string)$result['observation_full']);
        $this->assertSame($courseid, (int)($result['produced_outputs']['courseid'] ?? 0));
        // Expected-activities baseline (blueprint F2): whatever Moodle auto-created is
        // reported so downstream skills treat it as expected, not as foreign content.
        $this->assertIsArray($result['produced_outputs']['baseline_cmids'] ?? null);
    }

    /**
     * The clarification offers "Name (id N)" answers, so a bare or prefixed numeric
     * categoryquery must resolve by id (thread 585: "Id 2" bounced).
     */
    public function test_numeric_categoryquery_resolves_by_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $gen->create_category(['name' => 'Wikingerkategorie']);
        $target = $gen->create_category(['name' => 'Segelkategorie']);

        $dto = (new create_course_skill())->preflight([
            'fullname' => 'Das Leben der Wikinger',
            'categoryquery' => 'Id ' . (int)$target->id,
        ], (int)context_system::instance()->id, (int)$USER->id);

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $this->assertSame((int)$target->id, (int)$dto->preparedinput['categoryid']);
    }

    /**
     * A duplicate full name soft-blocks once and passes with the override token — the
     * create_option duplicate-title convention.
     */
    public function test_duplicate_fullname_soft_blocks_until_override(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $gen->create_category(['name' => 'Wikingerkategorie']);
        $gen->create_course(['fullname' => 'Das Leben der Wikinger']);
        $systemcontextid = (int)context_system::instance()->id;
        $skill = new create_course_skill();

        $blocked = $skill->preflight([
            'fullname' => 'Das Leben der Wikinger',
            'categoryquery' => 'Wikinger',
        ], $systemcontextid, (int)$USER->id)->to_array();
        $this->assertNotSame('pass', $blocked['status']);
        $this->assertContains(
            'DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED',
            (array)($blocked['issue_codes'] ?? [])
        );

        $confirmed = (new create_course_skill())->preflight([
            'fullname' => 'Das Leben der Wikinger',
            'categoryquery' => 'Wikinger',
            'override' => ['duplicate_fullname'],
        ], $systemcontextid, (int)$USER->id);
        $this->assertSame('pass', $confirmed->to_array()['status'], json_encode($confirmed->issues));
        // The derived shortname must dodge the existing course's shortname.
        $this->assertNotSame('', (string)$confirmed->preparedinput['shortname']);
    }

    /**
     * Without moodle/course:create anywhere, preflight clarifies instead of passing.
     */
    public function test_user_without_create_capability_is_stopped(): void {
        $this->resetAfterTest();
        global $USER;

        $gen = $this->getDataGenerator();
        $gen->create_category(['name' => 'Wikingerkategorie']);
        $user = $gen->create_user();
        $this->setUser($user);

        $result = (new create_course_skill())->preflight(
            ['fullname' => 'Das Leben der Wikinger', 'categoryquery' => 'Wikinger'],
            (int)context_system::instance()->id,
            (int)$USER->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('CREATE_COURSE_NO_WRITABLE_CATEGORY', (array)($result['issue_codes'] ?? []));
    }

    /**
     * The skill is auto-discovered by the registry under its canonical name.
     */
    public function test_registry_discovers_the_skill(): void {
        $this->resetAfterTest();
        $registry = \bookingextension_agent\local\wizard\skill_registry::make_default();
        $skill = $registry->get_skill('course.create_course');
        $this->assertInstanceOf(create_course_skill::class, $skill);
    }

    /**
     * The duplicate question offers the existing course as a choice, so the selector can fill it with
     * another skill instead of only being asked whether to create a second one.
     */
    public function test_an_existing_course_is_offered_as_a_choice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $existing = $this->getDataGenerator()->create_course(['fullname' => 'Winter School', 'shortname' => 'WS']);

        $result = (new create_course_skill())->preflight(
            ['fullname' => 'Winter School'],
            (int)\context_system::instance()->id,
            (int)$USER->id
        );

        $issues = (array)$result->issues;
        $offered = \bookingextension_agent\local\wizard\services\decision\agent_decision_service::offered_choices($issues);
        $this->assertCount(1, $offered, json_encode($issues));
        $this->assertSame('courseid', $offered[0]['field']);
        $this->assertSame((int)$existing->id, (int)$offered[0]['candidates'][0]['id']);
        $this->assertSame('Winter School', (string)$offered[0]['candidates'][0]['label']);

        // The confirmation question for a deliberate second course is unchanged.
        $codes = array_column($issues, 'code');
        $this->assertContains('DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED', $codes);
    }
}

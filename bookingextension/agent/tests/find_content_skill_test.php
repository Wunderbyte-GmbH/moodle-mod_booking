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
use bookingextension_agent\local\wizard\core\skills\find_content_skill;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\skill_provider;

/**
 * core.find_content skill + lenient area normalization + course/area restrictions.
 *
 * Uses an injected deterministic embedder so the whole path runs without the LLM provider or any
 * API call; the embeddings provider CLASS still has to exist for the readiness gate, so the
 * end-to-end tests skip where it is absent (the not-ready test does not need it).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\find_content_skill
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class find_content_skill_test extends advanced_testcase {
    /**
     * A deterministic embedder returning a fixed unit vector of the requested dimensionality.
     *
     * @return callable
     */
    private function fake_embedder(): callable {
        return function (string $text, int $contextid, int $userid, int $dims): array {
            unset($text, $contextid, $userid);
            $vector = array_fill(0, max(1, $dims), 0.0);
            $vector[0] = 1.0;
            return $vector;
        };
    }

    /**
     * Enable the DB backend and one search area; skip if the provider class is absent.
     *
     * @param string $areakey The search area to enable.
     * @return void
     */
    private function enable_site_search(string $areakey = 'mod_page-activity'): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        (new sitesearch_scope_repository())->set_enabled($areakey, true);
    }

    /**
     * The skill wired with a search service using the deterministic embedder.
     *
     * @return find_content_skill
     */
    private function skill(): find_content_skill {
        return new find_content_skill(new site_content_search_service($this->fake_embedder()));
    }

    /**
     * Lenient normalization matrix: modname, component, exact areaid and visible name all resolve;
     * unknown refs are dropped; nothing matched (or nothing given) means null = no restriction.
     */
    public function test_normalize_area_refs_lenient_matrix(): void {
        $this->resetAfterTest();
        $registry = new site_content_area_registry();

        // Bare module name.
        $this->assertSame(['mod_page-activity'], $registry->normalize_area_refs(['page']));
        // Component name.
        $this->assertSame(['mod_page-activity'], $registry->normalize_area_refs(['mod_page']));
        // Exact area id (case-insensitive).
        $this->assertSame(['mod_page-activity'], $registry->normalize_area_refs(['MOD_PAGE-ACTIVITY']));
        // Case-insensitive visible-name match.
        $areaobj = $registry->area_instance('mod_page-activity');
        $this->assertNotNull($areaobj);
        $matched = $registry->normalize_area_refs([\core_text::strtoupper($areaobj->get_visible_name())]);
        $this->assertNotNull($matched);
        $this->assertContains('mod_page-activity', $matched);
        // Unknown refs are dropped silently, known ones survive.
        $this->assertSame(['mod_page-activity'], $registry->normalize_area_refs(['quatsch', 'page']));
        // Nothing matched at all -> null (= deliberately no restriction), same for empty input.
        $this->assertNull($registry->normalize_area_refs(['quatsch', 'blub']));
        $this->assertNull($registry->normalize_area_refs([]));
        // A modname can map to several areas of that module (e.g. forum activity + posts).
        $forum = $registry->normalize_area_refs(['forum']);
        $this->assertNotNull($forum);
        $this->assertGreaterThanOrEqual(2, count($forum));
        foreach ($forum as $areaid) {
            $this->assertStringStartsWith('mod_forum-', $areaid);
        }
    }

    /**
     * End-to-end: an enrolled user finds an indexed page through the skill; the observation carries
     * the title, the deep link AND the course link plus the machine-usable ids; the area
     * restriction 'page' keeps the hit and a foreign-area restriction removes it.
     */
    public function test_skill_finds_page_with_both_links(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Alpha Page',
            'content' => 'Special enrolment instructions for the seminar.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        (new site_content_index_service($this->fake_embedder()))->update();

        $this->setUser($user);
        $contextid = (int)\context_course::instance($course->id)->id;
        $result = $this->skill()->execute(['query' => 'enrolment'], $contextid, (int)$user->id);

        $this->assertSame('executed', $result['status']);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('Alpha Page', $observation);
        // Georg's hard rule: EVERY hit line carries its deep link and its course link verbatim.
        $deeplink = (new \moodle_url('/mod/page/view.php', ['id' => $page->cmid]))->out(false);
        $this->assertStringContainsString('url=' . $deeplink, $observation);
        $this->assertStringContainsString(
            'courseurl=' . (new \moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false),
            $observation
        );
        // Machine-usable per-hit fields for follow-up steps.
        $this->assertStringContainsString('areaid=mod_page-activity', $observation);
        $this->assertStringContainsString('docid=' . (int)$page->id, $observation);
        $this->assertStringContainsString('courseid=' . (int)$course->id, $observation);
        $this->assertNotEmpty($result['results']);
        $this->assertSame((int)$page->id, (int)$result['results'][0]['docid']);
        // Default mode keeps prompts small: no content block.
        $this->assertStringNotContainsString('content:', $observation);

        // Area restriction 'page' (lenient hint) keeps the hit.
        $result = $this->skill()->execute(['query' => 'enrolment', 'areas' => ['page']], $contextid, (int)$user->id);
        $this->assertStringContainsString('Alpha Page', (string)$result['observation_full']);

        // A valid but foreign area restriction excludes the page hits.
        $result = $this->skill()->execute(['query' => 'enrolment', 'areas' => ['forum']], $contextid, (int)$user->id);
        $this->assertStringNotContainsString('Alpha Page', (string)$result['observation_full']);

        // All-unknown hints: dropped, all enabled areas searched, and the observation says so.
        $result = $this->skill()->execute(['query' => 'enrolment', 'areas' => ['quatsch']], $contextid, (int)$user->id);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('Alpha Page', $observation);
        $this->assertStringContainsString(
            get_string('agent_find_content_areasunmatched', 'bookingextension_agent'),
            $observation
        );
    }

    /**
     * The courseid restriction excludes other courses' hits — for regular users AND site admins
     * (the admin normally gets the global prefilter, so this proves the course scoping is not a
     * silent no-op for them).
     */
    public function test_courseid_restriction_excludes_other_courses(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $gen->create_module('page', [
            'course' => $coursea->id,
            'name' => 'Alpha Page',
            'content' => 'Enrolment guidance alpha.',
        ]);
        $gen->create_module('page', [
            'course' => $courseb->id,
            'name' => 'Beta Page',
            'content' => 'Enrolment guidance beta.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $coursea->id);
        $gen->enrol_user($user->id, $courseb->id);

        $this->setAdminUser();
        (new site_content_index_service($this->fake_embedder()))->update();

        $contextid = (int)\context_course::instance($coursea->id)->id;

        // Unrestricted: the enrolled user finds both.
        $this->setUser($user);
        $observation = (string)$this->skill()->execute(
            ['query' => 'enrolment'],
            $contextid,
            (int)$user->id
        )['observation_full'];
        $this->assertStringContainsString('Alpha Page', $observation);
        $this->assertStringContainsString('Beta Page', $observation);

        // Restricted to course A: course B's page disappears.
        $observation = (string)$this->skill()->execute(
            ['query' => 'enrolment', 'courseid' => (int)$coursea->id],
            $contextid,
            (int)$user->id
        )['observation_full'];
        $this->assertStringContainsString('Alpha Page', $observation);
        $this->assertStringNotContainsString('Beta Page', $observation);

        // Same for a site admin (course scoping must override the admin's global filter).
        $this->setAdminUser();
        global $USER;
        $observation = (string)$this->skill()->execute(
            ['query' => 'enrolment', 'courseid' => (int)$coursea->id],
            $contextid,
            (int)$USER->id
        )['observation_full'];
        $this->assertStringContainsString('Alpha Page', $observation);
        $this->assertStringNotContainsString('Beta Page', $observation);
    }

    /**
     * includecontent=true carries the document text per hit for downstream steps (e.g. building a
     * quiz from the found content).
     */
    public function test_includecontent_carries_document_text(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Alpha Page',
            'content' => 'Special enrolment instructions for the seminar.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        (new site_content_index_service($this->fake_embedder()))->update();

        $this->setUser($user);
        $contextid = (int)\context_course::instance($course->id)->id;
        $result = $this->skill()->execute(
            ['query' => 'enrolment', 'includecontent' => true],
            $contextid,
            (int)$user->id
        );

        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('content:', $observation);
        $this->assertStringContainsString('Special enrolment instructions for the seminar.', $observation);
        // The structured row carries the text too (machine-usable downstream).
        $this->assertStringContainsString(
            'Special enrolment instructions',
            (string)$result['results'][0]['documenttext']
        );
        $this->assertNotSame('', (string)$result['results'][0]['chunktext']);
    }

    /**
     * Not ready (no DB store configured) and no-areas-enabled both yield the graceful explanation,
     * never an exception.
     */
    public function test_not_ready_yields_graceful_observation(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $this->setUser($user);
        $contextid = (int)\context_system::instance()->id;
        $expected = get_string('agent_find_content_notready', 'bookingextension_agent');

        // Default config: no DB embeddings store -> not ready.
        $result = $this->skill()->execute(['query' => 'anything'], $contextid, (int)$user->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame($expected, (string)$result['observation_full']);
        $this->assertSame([], $result['results']);

        // DB store, but not a single enabled area -> same graceful message.
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        $result = $this->skill()->execute(['query' => 'anything'], $contextid, (int)$user->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame($expected, (string)$result['observation_full']);
    }

    /**
     * Zero hits produce a helpful observation: the query is echoed, the searched areas are named
     * and broadening is suggested.
     */
    public function test_zero_hits_observation_is_helpful(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $this->setUser($user);
        $contextid = (int)\context_system::instance()->id;

        // Nothing indexed at all -> zero hits.
        $result = $this->skill()->execute(['query' => 'unfindable topic'], $contextid, (int)$user->id);
        $this->assertSame('executed', $result['status']);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('unfindable topic', $observation);
        $registry = new site_content_area_registry();
        $this->assertStringContainsString(
            (string)$registry->area_instance('mod_page-activity')->get_visible_name(),
            $observation
        );
    }

    /**
     * Contract wiring: the skill is discovered by the provider, is readonly, validates its required
     * query field, and its name-derived governance capability is defined in db/access.php (the
     * dedicated skill_name_capability_test enforces this globally; asserted here as well so this
     * suite fails close to the cause).
     */
    public function test_contract_wiring(): void {
        $this->resetAfterTest();

        $skills = (new skill_provider())->get_skills();
        $names = array_map(static fn($skill): string => $skill->get_name(), $skills);
        $this->assertContains('core.find_content', $names);

        $skill = new find_content_skill();
        $this->assertTrue($skill->is_read_only());
        $schema = $skill->get_schema();
        $this->assertTrue($schema['readonly']);
        $this->assertTrue($schema['properties']['query']['required']);
        $this->assertNotEmpty($schema['example_utterances']);
        $this->assertNotEmpty($skill->get_message_triggers());

        $structure = $skill->check_structure([]);
        $this->assertFalse($structure['valid']);
        $structure = $skill->check_structure(['query' => 'find the page about x']);
        $this->assertTrue($structure['valid']);

        $this->assertNotNull(
            get_capability_info('bookingextension/agent:skill_core_find_content'),
            'The name-derived skill capability must be defined in db/access.php.'
        );
    }
}

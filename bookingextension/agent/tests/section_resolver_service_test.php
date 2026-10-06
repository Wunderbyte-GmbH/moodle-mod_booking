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
use bookingextension_agent\local\wizard\services\activities\section_resolver_service;

/**
 * Tests for the section resolver service.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\activities\section_resolver_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class section_resolver_service_test extends advanced_testcase {
    /**
     * Listing returns every existing section ordered by number.
     */
    public function test_list_sections(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $sections = (new section_resolver_service())->list_sections($course);

        $numbers = array_column($sections, 'sectionnum');
        $this->assertContains(0, $numbers);
        $this->assertContains(1, $numbers);
        $this->assertContains(3, $numbers);
        // Ordered ascending.
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers);
    }

    /**
     * The site front page is detected (format 'site' / SITEID) so callers can force section 1.
     */
    public function test_is_site_front_page(): void {
        $this->resetAfterTest();
        $normal = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $this->assertFalse(section_resolver_service::is_site_front_page($normal));
        $this->assertTrue(section_resolver_service::is_site_front_page(get_site()));
    }

    /**
     * Top / bottom / numeric resolution.
     */
    public function test_resolve_placement_keywords_and_number(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $resolver = new section_resolver_service();

        $this->assertSame(0, $resolver->resolve_placement($course, 'top'));
        $this->assertSame(0, $resolver->resolve_placement($course, 'ganz oben'));
        $this->assertSame(3, $resolver->resolve_placement($course, 'bottom'));
        $this->assertSame(3, $resolver->resolve_placement($course, 'ganz unten'));
        $this->assertSame(2, $resolver->resolve_placement($course, '2'));
        // Out of range numeric → null.
        $this->assertNull($resolver->resolve_placement($course, '99'));
        // Empty → null (caller asks).
        $this->assertNull($resolver->resolve_placement($course, ''));
    }

    /**
     * Name resolution against a renamed section.
     */
    public function test_resolve_placement_by_name(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $DB->set_field('course_sections', 'name', 'Introduction', ['course' => $course->id, 'section' => 1]);
        rebuild_course_cache($course->id, true);

        $resolver = new section_resolver_service();
        $this->assertSame(1, $resolver->resolve_placement($course, 'Introduction'));
        // Case-insensitive substring match.
        $this->assertSame(1, $resolver->resolve_placement($course, 'intro'));
        $this->assertTrue($resolver->section_exists($course, 1));
        $this->assertFalse($resolver->section_exists($course, 99));
    }

    /**
     * Wave 32 (AA-4, nine of ten runs): the user's words carry the section's own name.
     *
     * "im Einführungsabschnitt" reached the resolver as one compound word and never matched the section
     * "Einführung". A section whose own name stands inside the query, compared by shape, is the one meant.
     * The strings here are illustrative shapes, not the prompt: nothing in the resolver knows a word.
     */
    public function test_resolve_placement_when_the_query_carries_the_section_name(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $DB->set_field('course_sections', 'name', 'Grundlagen', ['course' => $course->id, 'section' => 2]);
        rebuild_course_cache($course->id, true);

        $resolver = new section_resolver_service();
        $this->assertSame(2, $resolver->resolve_placement($course, 'Grundlagenteil'));
        $this->assertSame(2, $resolver->resolve_placement($course, 'the Grundlagen block'));
    }

    /**
     * Only a teacher-given name counts; the format's generated default names are never carried.
     */
    public function test_generated_section_names_are_not_matched_inside_a_query(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $resolver = new section_resolver_service();
        $generated = (string)$resolver->list_sections($course)[1]['name'];

        $this->assertNull($resolver->resolve_placement($course, $generated . ' extra words'));
    }

    /**
     * Two sections carried by the same query are returned for the caller's question, not guessed.
     */
    public function test_two_carried_section_names_are_returned_as_candidates(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $DB->set_field('course_sections', 'name', 'Alpha', ['course' => $course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'name', 'Beta', ['course' => $course->id, 'section' => 2]);
        rebuild_course_cache($course->id, true);

        $resolved = (new section_resolver_service())->resolve_placement($course, 'AlphaBetaGamma');
        $this->assertIsArray($resolved);
        $this->assertSame([1, 2], array_column($resolved, 'sectionnum'));
    }
}

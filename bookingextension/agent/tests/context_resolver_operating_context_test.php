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
use context_module;
use context_system;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\context_target_resolution;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\services\security\context_resolver;
use bookingextension_agent\local\wizard\services\security\context_target_unresolved_exception;
use bookingextension_agent\local\wizard\services\security\operating_context_target_registry;

/**
 * Tests for operating-context resolution (cross-context target course, Phase 0).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\security\context_resolver
 * @covers     \bookingextension_agent\local\wizard\services\security\operating_context_target_registry
 * @covers     \bookingextension_agent\local\wizard\dto\target_selector
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class context_resolver_operating_context_test extends advanced_testcase {
    /**
     * With no target, an ambient module context resolves UP to its course (today's behaviour).
     */
    public function test_no_target_walks_up_to_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $modcontext = context_module::instance($page->cmid);
        $ambient = agent_context::from_context($modcontext);

        $resolver = new context_resolver();
        $operating = $resolver->resolve_operating_context($ambient, CONTEXT_COURSE, null);

        $this->assertSame(CONTEXT_COURSE, $operating->level());
        $this->assertSame((int)context_course::instance($course->id)->id, $operating->id());
    }

    /**
     * An empty selector behaves exactly like the no-target path.
     */
    public function test_empty_selector_falls_back_to_ancestor_walk(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ambient = agent_context::from_context(context_module::instance($page->cmid));

        $resolver = new context_resolver();
        $operating = $resolver->resolve_operating_context($ambient, CONTEXT_COURSE, target_selector::for_course());

        $this->assertSame((int)context_course::instance($course->id)->id, $operating->id());
    }

    /**
     * An explicit target course id resolves to THAT course, not the ambient one.
     */
    public function test_explicit_target_course_id_resolves_cross_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ambientcourse = $this->getDataGenerator()->create_course();
        $targetcourse = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $ambientcourse->id]);
        $ambient = agent_context::from_context(context_module::instance($page->cmid));

        $resolver = new context_resolver();
        $operating = $resolver->resolve_operating_context(
            $ambient,
            CONTEXT_COURSE,
            target_selector::for_course((int)$targetcourse->id)
        );

        $this->assertSame((int)context_course::instance($targetcourse->id)->id, $operating->id());
        $this->assertNotSame((int)context_course::instance($ambientcourse->id)->id, $operating->id());
    }

    /**
     * A unique free-text course name resolves to its context.
     */
    public function test_target_course_by_unique_name_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $target = $this->getDataGenerator()->create_course(['fullname' => 'Zoology Masterclass 9000']);
        $ambient = agent_context::from_context(context_system::instance());

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::for_course(null, 'Zoology Masterclass 9000'));

        $this->assertTrue($resolution->is_resolved());
        $this->assertSame((int)context_course::instance($target->id)->id, (int)$resolution->context()->id);
    }

    /**
     * Several name matches yield an ambiguous resolution carrying the candidates.
     */
    /**
     * Wave 28 (SCC-1, runs 28-37): "Buchdruck-Kurs" as one string finds nothing, the token "Buchdruck" finds the one course.
     */
    public function test_a_course_query_with_a_generic_noun_resolves_by_its_tokens(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $target = $this->getDataGenerator()->create_course(['fullname' => 'Buchdruck (Kursrohling)']);
        $this->getDataGenerator()->create_course(['fullname' => 'Biologie']);

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::for_course(null, 'Buchdruck-Kurs'));

        $this->assertTrue($resolution->is_resolved(), $resolution->status());
        $this->assertSame((int)context_course::instance($target->id)->id, (int)$resolution->context()->id);

        $this->getDataGenerator()->create_course(['fullname' => 'Buchdruck Aufbaukurs']);
        $ambiguous = $registry->resolve(target_selector::for_course(null, 'Buchdruck-Kurs'));
        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $ambiguous->status(), 'two courses share the token');
    }

    /**
     * Wave 35 (AQ-1, L49 thread 17770 and N43a 17382): the constructor writes the user's compound "Brandschutzkurs" into
     * the course field in 2 of 20 runs, although the course-names block listed "Brandschutz im Betrieb". One token, so
     * the token narrowing above finds nothing. A course whose name word is the stem of a request word (the comparison
     * the course-names block makes, five letters or more) is the course; two such courses stay ambiguous, a short word
     * ("Kurs") names nothing.
     */
    public function test_a_compound_request_word_resolves_by_the_course_name_stem(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $target = $this->getDataGenerator()->create_course(['fullname' => 'Brandschutz im Betrieb']);
        $this->getDataGenerator()->create_course(['fullname' => 'Biologie']);
        $registry = new operating_context_target_registry();

        $resolution = $registry->resolve(target_selector::for_course(null, 'Brandschutzkurs'));
        $this->assertTrue($resolution->is_resolved(), $resolution->status());
        $this->assertSame((int)context_course::instance($target->id)->id, (int)$resolution->context()->id);

        $this->assertSame(
            context_target_resolution::STATUS_NOT_FOUND,
            $registry->resolve(target_selector::for_course(null, 'Kurs'))->status(),
            'a word shorter than the stem names no course'
        );
        $this->assertSame(
            context_target_resolution::STATUS_NOT_FOUND,
            $registry->resolve(target_selector::for_course(null, 'Photosynthese'))->status(),
            'a word no course name word opens names no course'
        );

        $this->getDataGenerator()->create_course(['fullname' => 'Brandschutz Aufbau']);
        $ambiguous = $registry->resolve(target_selector::for_course(null, 'Brandschutzkurs'));
        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $ambiguous->status(), 'two courses share the stem');
        $this->assertCount(2, $ambiguous->candidates());
    }

    public function test_ambiguous_course_name_lists_candidates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_course(['fullname' => 'Shared Title Alpha']);
        $this->getDataGenerator()->create_course(['fullname' => 'Shared Title Beta']);
        $ambient = agent_context::from_context(context_system::instance());

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::for_course(null, 'Shared Title'));

        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $resolution->status());
        $this->assertGreaterThanOrEqual(2, count($resolution->candidates()));

        // The resolver surfacing the ambiguity must make resolve_operating_context ask for clarification.
        $resolver = new context_resolver();
        $this->expectException(context_target_unresolved_exception::class);
        $resolver->resolve_operating_context(
            $ambient,
            CONTEXT_COURSE,
            target_selector::for_course(null, 'Shared Title'),
            0,
            $registry
        );
    }

    /**
     * An exact (case-insensitive) fullname match wins over substring siblings: "booking"
     * resolves the course literally named "booking" instead of going ambiguous against
     * "slotbooking" — mirroring the module path's exact-match preference.
     */
    public function test_exact_course_fullname_beats_substring_siblings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $exact = $this->getDataGenerator()->create_course(['fullname' => 'booking', 'shortname' => 'bk-exact']);
        $this->getDataGenerator()->create_course(['fullname' => 'slotbooking', 'shortname' => 'bk-slot']);

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::for_course(null, 'Booking'));

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame((int)context_course::instance($exact->id)->id, (int)$resolution->context()->id);
    }

    /**
     * An exact shortname match resolves too, even when the fullname is only a substring match.
     */
    public function test_exact_course_shortname_beats_substring_siblings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $exact = $this->getDataGenerator()->create_course(['fullname' => 'Course smoke', 'shortname' => 'smoke']);
        $this->getDataGenerator()->create_course(['fullname' => 'smoke advanced training', 'shortname' => 'sat101']);

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::for_course(null, 'smoke'));

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame((int)context_course::instance($exact->id)->id, (int)$resolution->context()->id);
    }

    /**
     * Two courses sharing the SAME fullname stay ambiguous — but the candidates now carry the
     * course id and shortname, so the clarification list is resolvable by a unique identifier.
     */
    public function test_identically_named_courses_stay_ambiguous_with_identifiers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $a = $this->getDataGenerator()->create_course(['fullname' => 'Agent Smoke Course', 'shortname' => 'smokeA']);
        $b = $this->getDataGenerator()->create_course(['fullname' => 'Agent Smoke Course', 'shortname' => 'smokeB']);

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::for_course(null, 'Agent Smoke Course'));

        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $resolution->status());
        $candidates = $resolution->candidates();
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $candidates);
        $this->assertEqualsCanonicalizing([(int)$a->id, (int)$b->id], $ids);
        $shortnames = array_map(static fn(array $c): string => (string)$c['shortname'], $candidates);
        $this->assertEqualsCanonicalizing(['smokeA', 'smokeB'], $shortnames);
    }

    /**
     * The site course (front page, id 1) is a legitimate target: it resolves by its full/short name,
     * by its context name (what the front page shows as the current context, e.g. "Site home") and by
     * explicit id — even though the course catalog search never returns it. Resolving is not a grant;
     * the capability is still enforced separately at this context.
     */
    public function test_site_course_resolves_by_name_context_name_and_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = new operating_context_target_registry();
        $sitectxid = (int)context_course::instance(SITEID)->id;
        $site = get_site();

        $queries = array_filter([
            (string)$site->fullname,
            (string)$site->shortname,
            context_course::instance(SITEID)->get_context_name(false),
        ], static fn($q): bool => trim((string)$q) !== '');

        foreach ($queries as $query) {
            $resolution = $registry->resolve(target_selector::for_course(null, $query));
            $this->assertSame(
                context_target_resolution::STATUS_RESOLVED,
                $resolution->status(),
                'Query "' . $query . '" should resolve to the site course.'
            );
            $this->assertSame($sitectxid, (int)$resolution->context()->id);
        }

        // Explicit numeric id 1 resolves too.
        $byid = $registry->resolve(target_selector::for_course(SITEID));
        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $byid->status());
        $this->assertSame($sitectxid, (int)$byid->context()->id);
    }

    /**
     * An unknown course name is not-found, and surfaces as the unresolved exception.
     */
    public function test_unknown_course_name_is_not_found(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ambient = agent_context::from_context(context_system::instance());
        $registry = new operating_context_target_registry();

        $resolution = $registry->resolve(target_selector::for_course(null, 'No Such Course 31337'));
        $this->assertSame(context_target_resolution::STATUS_NOT_FOUND, $resolution->status());

        $resolver = new context_resolver();
        try {
            $resolver->resolve_operating_context(
                $ambient,
                CONTEXT_COURSE,
                target_selector::for_course(null, 'No Such Course 31337'),
                0,
                $registry
            );
            $this->fail('Expected context_target_unresolved_exception.');
        } catch (context_target_unresolved_exception $e) {
            $this->assertSame(context_target_resolution::STATUS_NOT_FOUND, $e->get_resolution()->status());
        }
    }

    /**
     * A target level with no core support and no provider is reported as unsupported.
     */
    public function test_unsupported_level_without_provider(): void {
        $this->resetAfterTest();

        $registry = new operating_context_target_registry();
        $resolution = $registry->resolve(target_selector::create(CONTEXT_MODULE, 42, null));

        $this->assertSame(context_target_resolution::STATUS_UNSUPPORTED, $resolution->status());
    }
}

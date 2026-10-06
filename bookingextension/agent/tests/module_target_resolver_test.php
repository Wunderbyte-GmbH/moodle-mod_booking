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

declare(strict_types=1);

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\context_target_resolution;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\services\security\module_target_resolver;
use context_course;
use context_module;

/**
 * Tests for the generic module-instance scope cascade.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\security\module_target_resolver
 */
final class module_target_resolver_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Create a booking instance in a course and return its cmid.
     *
     * @param int|string $courseid
     * @param string     $name
     * @return int
     */
    private function make_booking($courseid, string $name): int {
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => (int)$courseid, 'name' => $name]);
        return (int)$booking->cmid;
    }

    /**
     * The acting (admin) user id.
     *
     * @return int
     */
    private function userid(): int {
        global $USER;
        return (int)$USER->id;
    }

    /**
     * Exactly one instance in the ambient course → that instance, no clarification.
     */
    public function test_single_instance_in_ambient_course_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_booking($course->id, 'Sprechstunde');
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertInstanceOf(context_module::class, $resolution->context());
        $this->assertSame($cmid, (int)$resolution->context()->instanceid);
    }

    /**
     * A module CONTEXT id given where a cmid is expected still finds the activity.
     *
     * Run-23 finding (CBI-1): the runtime block hands the model both numbers, "context_id: 6168"
     * and "module: cmid: 25", and the model filled the cmid field with the context id. The target
     * then resolved to nothing and the turn ended as "I could not find a matching activity" -
     * about an activity the session was already sitting in.
     *
     * The two number spaces can collide, so a real cmid always wins; only when the value is no
     * course module of the wanted type is it tried as a context id.
     */
    public function test_a_module_context_id_in_the_cmid_field_still_finds_the_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_booking($course->id, 'Sprechstunde');
        $contextid = (int)context_module::instance($cmid)->id;
        $this->assertNotSame($cmid, $contextid, 'the fixture must not make the two numbers equal');

        // A second instance, so an accidental auto-pick cannot make this pass.
        $this->make_booking($course->id, 'Beratung');

        $ambient = agent_context::from_context(context_course::instance($course->id));
        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module($contextid, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame($cmid, (int)$resolution->context()->instanceid);
    }

    /**
     * Several instances in the ambient course → ambiguous, carrying the course's instances.
     */
    public function test_multiple_instances_in_course_are_ambiguous(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cmid1 = $this->make_booking($course->id, 'Erste Buchung');
        $cmid2 = $this->make_booking($course->id, 'Zweite Buchung');
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $resolution->status());
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $resolution->candidates());
        $this->assertEqualsCanonicalizing([$cmid1, $cmid2], $ids);
        // Candidates carry display metadata for the clarification — including the course
        // shortname, so identically named courses stay distinguishable in candidate lists.
        $first = $resolution->candidates()[0];
        $this->assertArrayHasKey('coursename', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('courseshortname', $first);
        $this->assertSame($course->shortname, $first['courseshortname']);
        $this->assertSame((int)$course->id, (int)$first['courseid']);
    }

    /**
     * A module instance on the site course (front page, id 1) is resolvable — the front page is a
     * valid activity host and must not be excluded from module targeting.
     */
    public function test_site_course_instance_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->make_booking(SITEID, 'Front page booking');
        $ambient = agent_context::from_context(context_course::instance(SITEID));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertInstanceOf(context_module::class, $resolution->context());
        $this->assertSame($cmid, (int)$resolution->context()->instanceid);
    }

    /**
     * No instance in the ambient course but exactly one site-wide → use it regardless of where I am.
     */
    public function test_zero_in_course_falls_back_to_single_site_instance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $emptycourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $cmid = $this->make_booking($othercourse->id, 'Die einzige Instanz');
        $ambient = agent_context::from_context(context_course::instance($emptycourse->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame($cmid, (int)$resolution->context()->instanceid);
    }

    /**
     * No instance in the ambient course and several site-wide → ambiguous (site list).
     */
    public function test_zero_in_course_with_many_site_instances_is_ambiguous(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $emptycourse = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_course();
        $b = $this->getDataGenerator()->create_course();
        $cmida = $this->make_booking($a->id, 'A');
        $cmidb = $this->make_booking($b->id, 'B');
        $ambient = agent_context::from_context(context_course::instance($emptycourse->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $resolution->status());
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $resolution->candidates());
        $this->assertEqualsCanonicalizing([$cmida, $cmidb], $ids);
    }

    /**
     * A name query prefers an exact (case-insensitive) match over a substring sibling.
     */
    public function test_named_exact_match_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $exact = $this->make_booking($course->id, 'Sprechstunde');
        $this->make_booking($course->id, 'Sprechstunde (copy)');
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, 'sprechstunde', 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame($exact, (int)$resolution->context()->instanceid);
    }

    /**
     * A substring query that matches several instances stays ambiguous.
     */
    public function test_named_substring_match_is_ambiguous(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cmid1 = $this->make_booking($course->id, 'Sprechstunde Mathe');
        $cmid2 = $this->make_booking($course->id, 'Sprechstunde Physik');
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, 'Sprechstunde', 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_AMBIGUOUS, $resolution->status());
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $resolution->candidates());
        $this->assertEqualsCanonicalizing([$cmid1, $cmid2], $ids);
    }

    /**
     * An explicit cmid resolves directly; a bogus one is reported not-found.
     */
    public function test_explicit_cmid_resolves_and_invalid_not_found(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_booking($course->id, 'Ziel');
        $ambient = agent_context::from_context(context_course::instance($course->id));
        $resolver = new module_target_resolver();

        $resolved = $resolver->resolve(target_selector::for_module($cmid, null, 'booking'), $ambient, $this->userid());
        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolved->status());
        $this->assertSame($cmid, (int)$resolved->context()->instanceid);

        $missing = $resolver->resolve(target_selector::for_module(99999999, null, 'booking'), $ambient, $this->userid());
        $this->assertSame(context_target_resolution::STATUS_NOT_FOUND, $missing->status());
    }

    /**
     * Already inside a matching module with nothing named → that ambient instance.
     */
    public function test_ambient_module_resolves_itself(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_booking($course->id, 'Hier');
        // A second instance exists so "use ambient" is a real choice, not the only one.
        $this->make_booking($course->id, 'Woanders');
        $ambient = agent_context::from_context(context_module::instance($cmid));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame($cmid, (int)$resolution->context()->instanceid);
    }

    /**
     * An unknown module name is unsupported (no generic resolution, guards the site-wide SQL).
     */
    public function test_unknown_modname_is_unsupported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'definitelynotamodule'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_UNSUPPORTED, $resolution->status());
    }

    /**
     * No instance anywhere → not found (the engine then asks to create one).
     */
    public function test_no_instance_anywhere_is_not_found(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, null, 'booking'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_NOT_FOUND, $resolution->status());
    }

    /**
     * A hyphen the user typed does not hide the forum "Vorstellungsforum" (baseline run 26, UA-2).
     */
    public function test_a_name_that_differs_only_in_hyphens_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => (int)$course->id, 'name' => 'Vorstellungsforum']);
        $this->getDataGenerator()->create_module('forum', ['course' => (int)$course->id, 'name' => 'Ankündigungen']);
        $ambient = agent_context::from_context(context_course::instance($course->id));

        $resolution = (new module_target_resolver())->resolve(
            target_selector::for_module(null, 'Vorstellungs-Forum', 'forum'),
            $ambient,
            $this->userid()
        );

        $this->assertSame(context_target_resolution::STATUS_RESOLVED, $resolution->status());
        $this->assertSame((int)$forum->cmid, (int)$resolution->context()->instanceid);
    }
}

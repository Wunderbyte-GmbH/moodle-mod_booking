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
 * Which plugin owns the page the user is looking at.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\discovery\context_owner_resolver;

/**
 * Tests for context_owner_resolver.
 *
 * "Which rules are switched on?" means booking rules on a booking page and taskflow rules on a taskflow page.
 * The engine can know that without reading a single word of the request: the Moodle context names the module,
 * and Moodle's own page-type contract names the component ("mod-booking-view", "local-taskflow-index").
 * Deriving the owner from those is structure, not phrase matching.
 *
 * The resolver never invents a namespace: it only ever returns one that is actually registered, so a page
 * belonging to a plugin without skills yields no owner at all rather than a preference for nothing.
 *
 * @covers \bookingextension_agent\local\wizard\services\discovery\context_owner_resolver
 */
final class context_owner_resolver_test extends \advanced_testcase {
    /** @var string[] Namespaces the registry knows in these tests. */
    private const KNOWN = ['mod_booking', 'local_taskflow', 'course', 'core', 'wizard'];

    /**
     * Skip when mod_booking is not installed.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * A module context names its plugin directly — the strongest and cheapest signal.
     */
    public function test_module_context_resolves_to_its_plugin(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $context = \context_module::instance($booking->cmid);

        $this->assertSame('mod_booking', (new context_owner_resolver())->resolve($context, '', self::KNOWN));
    }

    /**
     * A course context belongs to the course skills.
     */
    public function test_course_context_resolves_to_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(
            'course',
            (new context_owner_resolver())->resolve(\context_course::instance($course->id), '', self::KNOWN)
        );
    }

    /**
     * A taskflow page lives at system or course level, so only its page type identifies it.
     */
    public function test_page_type_identifies_a_plugin_the_context_cannot(): void {
        $this->resetAfterTest();
        $resolver = new context_owner_resolver();
        $system = \context_system::instance();

        $this->assertSame('local_taskflow', $resolver->resolve($system, 'local-taskflow-assignments', self::KNOWN));
        $this->assertSame('mod_booking', $resolver->resolve($system, 'mod-booking-view', self::KNOWN));
    }

    /**
     * No owner rather than a wrong one: a neutral page, an unknown component, an empty page type.
     */
    public function test_no_owner_when_nothing_identifies_one(): void {
        $this->resetAfterTest();
        $resolver = new context_owner_resolver();
        $system = \context_system::instance();

        $this->assertSame('', $resolver->resolve($system, '', self::KNOWN));
        $this->assertSame('', $resolver->resolve($system, 'site-index', self::KNOWN));
        $this->assertSame('', $resolver->resolve($system, 'my-index', self::KNOWN));
        // A component that exists in Moodle but registers no skills must not become a preference.
        $this->assertSame('', $resolver->resolve($system, 'mod-forum-view', self::KNOWN));
    }

    /**
     * The resolver never returns a namespace the registry does not know.
     */
    public function test_never_returns_an_unregistered_namespace(): void {
        $this->resetAfterTest();
        $resolver = new context_owner_resolver();

        $this->assertSame(
            '',
            $resolver->resolve(\context_system::instance(), 'local-taskflow-index', ['mod_booking', 'course'])
        );
    }
}

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
use bookingextension_agent\local\wizard\services\sitesearch\site_access_context_lister;
use context_course;
use context_module;

/**
 * Engine-free access-context prefilter: descriptor-driven module contexts plus the §7-B
 * course-level extension (enrolled + visible-not-enrolled + front page), always fail-closed.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_access_context_lister
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_access_context_lister_test extends advanced_testcase {
    /**
     * Course-level contexts (§7-B): enrolled course A and visible-not-enrolled course B are in,
     * the front page (SITEID) is in, hidden course C is out (safe subset).
     */
    public function test_course_level_contexts(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $coursec = $gen->create_course(['visible' => 0]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $coursea->id);

        $lister = new site_access_context_lister();
        $filter = $lister->allowed_context_filter(
            (int)$user->id,
            ['modnames' => [], 'includecourselevel' => true]
        );

        $contextids = $filter->contextids();
        $this->assertIsArray($contextids);
        $this->assertContains((int)context_course::instance($coursea->id)->id, $contextids);
        $this->assertContains((int)context_course::instance($courseb->id)->id, $contextids);
        $this->assertContains((int)context_course::instance(SITEID)->id, $contextids);
        $this->assertNotContains((int)context_course::instance($coursec->id)->id, $contextids);
    }

    /**
     * Module and course contexts combine; without course-level support only the enrolled courses'
     * visible module contexts of the requested modnames appear.
     */
    public function test_module_and_course_contexts_combine(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id, 'name' => 'P', 'content' => 'x']);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $lister = new site_access_context_lister();

        $moduleonly = $lister->allowed_context_filter(
            (int)$user->id,
            ['modnames' => ['page'], 'includecourselevel' => false]
        );
        $this->assertSame(
            [(int)context_module::instance($page->cmid)->id],
            $moduleonly->contextids()
        );

        $combined = $lister->allowed_context_filter(
            (int)$user->id,
            ['modnames' => ['page'], 'includecourselevel' => true]
        );
        $contextids = $combined->contextids();
        $this->assertContains((int)context_module::instance($page->cmid)->id, $contextids);
        $this->assertContains((int)context_course::instance($course->id)->id, $contextids);
        $this->assertContains((int)context_course::instance(SITEID)->id, $contextids);
    }

    /**
     * Fail-closed semantics: an empty descriptor (e.g. only 'other'-support areas enabled) yields
     * an EMPTY filter — zero rows, never global. A site admin gets the global (null) filter.
     */
    public function test_fail_closed_and_admin_shortcut(): void {
        global $USER;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $lister = new site_access_context_lister();

        $empty = $lister->allowed_context_filter(
            (int)$user->id,
            ['modnames' => [], 'includecourselevel' => false]
        );
        $this->assertSame([], $empty->contextids());
        $this->assertFalse($empty->is_global());

        $this->setAdminUser();
        $admin = $lister->allowed_context_filter(
            (int)$USER->id,
            ['modnames' => [], 'includecourselevel' => false]
        );
        $this->assertNull($admin->contextids());
    }
}

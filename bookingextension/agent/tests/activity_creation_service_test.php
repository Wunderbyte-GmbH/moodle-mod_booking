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
use bookingextension_agent\local\wizard\services\activities\activity_creation_service;
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use context_course;
use stdClass;

/**
 * Tests for the module-neutral activity creation service.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\activities\activity_creation_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_creation_service_test extends advanced_testcase {
    /**
     * A valid prepared moduleinfo creates a real course module.
     */
    public function test_create_succeeds(): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $coursecontext = context_course::instance($course->id);
        $this->setAdminUser();
        $PAGE->set_context($coursecontext);

        $moduleinfo = (new module_form_contract())->build_prepared_moduleinfo(
            $course,
            'page',
            0,
            'My page',
            'Intro text',
            ['content' => 'Body content.']
        );

        $created = (new activity_creation_service())->create($moduleinfo, $course);
        $this->assertGreaterThan(0, $created['cmid']);
        $this->assertSame('page', $created['modname']);

        $cm = get_fast_modinfo($course)->get_cm($created['cmid']);
        $this->assertSame('page', $cm->modname);
        $this->assertSame('My page', $cm->name);
    }

    /**
     * A failing creation leaves no orphaned course module behind (rollback).
     */
    public function test_create_failure_leaves_no_leftovers(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $before = $DB->count_records('course_modules', ['course' => $course->id]);

        // A bogus module name makes add_moduleinfo() fail; nothing must be persisted.
        $bogus = new stdClass();
        $bogus->modulename = 'definitely_not_a_real_module';
        $bogus->section = 0;
        $bogus->name = 'Nope';
        $bogus->visible = 1;
        $bogus->course = $course->id;

        try {
            (new activity_creation_service())->create($bogus, $course);
            $this->fail('Expected creation to throw for a bogus module.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $after = $DB->count_records('course_modules', ['course' => $course->id]);
        $this->assertSame($before, $after, 'A failed creation must not leave a course module behind.');
    }
}

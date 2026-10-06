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
use bookingextension_agent\local\wizard\services\activities\module_catalog_service;

/**
 * Tests for the activity module catalog service.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\activities\module_catalog_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_catalog_service_test extends advanced_testcase {
    /**
     * An editing teacher may add every supported whitelist module that is installed.
     */
    public function test_editing_teacher_sees_whitelist(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $modules = (new module_catalog_service())->list_addable_modules($course, (int)$teacher->id);
        $modnames = array_column($modules, 'modname');

        $this->assertNotEmpty($modules);
        foreach ($modnames as $modname) {
            $this->assertContains($modname, module_catalog_service::WHITELIST);
        }
        // The standard content modules ship with Moodle, so they must be offered.
        $this->assertContains('page', $modnames);
        $this->assertContains('url', $modnames);
        $this->assertContains('forum', $modnames);
    }

    /**
     * A plain student holds no addinstance capability and therefore sees nothing to add.
     */
    public function test_student_sees_nothing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $modules = (new module_catalog_service())->list_addable_modules($course, (int)$student->id);
        $this->assertSame([], $modules);
    }

    /**
     * Name resolution: canonical name, unknown query, and a unique match.
     */
    public function test_resolve_module_name(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $service = new module_catalog_service();
        $addable = $service->list_addable_modules($course, (int)$teacher->id);

        $page = $service->resolve_module_name($addable, 'page');
        $this->assertCount(1, $page);
        $this->assertSame('page', $page[0]['modname']);

        $forum = $service->resolve_module_name($addable, 'forum');
        $this->assertCount(1, $forum);
        $this->assertSame('forum', $forum[0]['modname']);

        $this->assertSame([], $service->resolve_module_name($addable, 'definitely-not-a-module'));
        $this->assertSame([], $service->resolve_module_name($addable, ''));
    }

    /**
     * The whitelist membership helper is case-insensitive and rejects unknown modules.
     */
    public function test_is_whitelisted(): void {
        $service = new module_catalog_service();
        $this->assertTrue($service->is_whitelisted('page'));
        $this->assertTrue($service->is_whitelisted('PAGE'));
        $this->assertFalse($service->is_whitelisted('quiz'));
        $this->assertFalse($service->is_whitelisted('assign'));
    }
}

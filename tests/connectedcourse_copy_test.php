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
 * Tests for copying the Moodle course connected to a booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\connectedcourse;

/**
 * Tests for \mod_booking\local\connectedcourse::copy_course.
 *
 * copy_course() is the bare mechanism: it performs no capability check and never depends on
 * the acting user, so that it keeps working from background code - adhoc tasks and restore
 * steps - where the person who triggered the duplication is not the user running the code.
 * Authorisation is the caller's job.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\local\connectedcourse::copy_course
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connectedcourse_copy_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * A course shell is created immediately and the heavy copy is deferred to the core task.
     *
     * @return void
     */
    public function test_copy_course_creates_shell_and_queues_task(): void {
        global $DB;

        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category(['name' => 'CopySourceCat']);
        $source = $this->getDataGenerator()->create_course([
            'shortname' => 'copysource',
            'fullname' => 'Copy source',
            'category' => $category->id,
        ]);

        $newcourseid = connectedcourse::copy_course($source->id);

        $this->assertNotEmpty($newcourseid);
        $this->assertNotEquals($source->id, $newcourseid);
        $this->assertTrue($DB->record_exists('course', ['id' => $newcourseid]));
        // The copy lands in the same category as its source.
        $this->assertEquals($category->id, (int) $DB->get_field('course', 'category', ['id' => $newcourseid]));

        // The actual backup and restore happen later, in the core async copy task.
        $copytasks = \core\task\manager::get_adhoc_tasks(\core\task\asynchronous_copy_task::class);
        $this->assertCount(1, $copytasks);
    }

    /**
     * The backup and restore are attributed to the admin user, not to whoever happens to be
     * logged in. This is what lets the copy run from cron and from restore steps, where the
     * acting user is not the person who triggered the duplication.
     *
     * @return void
     */
    public function test_copy_runs_as_admin_regardless_of_acting_user(): void {
        global $DB, $USER;

        $source = $this->getDataGenerator()->create_course(['shortname' => 'copysource']);

        // A plain user without any course copy capabilities anywhere.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $beforeuserid = $USER->id;

        $newcourseid = connectedcourse::copy_course($source->id);
        $this->assertNotEmpty($newcourseid);

        $adminid = (int) get_admin()->id;
        $this->assertNotEquals($adminid, $beforeuserid);

        $controllers = $DB->get_records('backup_controllers');
        $this->assertNotEmpty($controllers);
        foreach ($controllers as $controller) {
            $this->assertEquals($adminid, (int) $controller->userid);
        }

        // The session user was never swapped.
        $this->assertEquals($beforeuserid, $USER->id);
    }

    /**
     * The mechanism itself performs no capability check - the caller is responsible for that.
     *
     * @return void
     */
    public function test_copy_course_does_not_check_capabilities(): void {
        $source = $this->getDataGenerator()->create_course(['shortname' => 'copysource']);

        // Not logged in at all, the way a scheduled task runs.
        $this->setUser(null);

        $newcourseid = connectedcourse::copy_course($source->id);

        $this->assertNotEmpty($newcourseid);
    }

    /**
     * A missing or unknown source course yields 0 instead of blowing up.
     *
     * @return void
     */
    public function test_missing_source_course_returns_zero(): void {
        global $DB;

        $this->setAdminUser();

        $this->assertSame(0, connectedcourse::copy_course(0));

        $unusedid = ((int) $DB->get_field_sql('SELECT MAX(id) FROM {course}')) + 1000;
        $this->assertSame(0, connectedcourse::copy_course($unusedid));

        // Nothing was queued for a source that does not exist.
        $copytasks = \core\task\manager::get_adhoc_tasks(\core\task\asynchronous_copy_task::class);
        $this->assertCount(0, $copytasks);
    }
}

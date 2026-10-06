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
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;

/**
 * Tests for the diagnostic link builder.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostic_link_builder_test extends advanced_testcase {
    /**
     * Links point at the documented core pages with the right params.
     */
    public function test_link_targets(): void {
        $b = new diagnostic_link_builder();
        $this->assertStringContainsString('/course/view.php', $b->course(7)->out(false));
        $this->assertStringContainsString('id=7', $b->course(7)->out(false));
        $this->assertStringContainsString('/mod/page/view.php', $b->activity('page', 12)->out(false));
        $this->assertStringContainsString('/enrol/instances.php', $b->enrol_instances(7)->out(false));
        $this->assertStringContainsString('/admin/roles/check.php', $b->check_permissions(99)->out(false));
        $this->assertStringContainsString('contextid=99', $b->check_permissions(99)->out(false));

        $usercourse = $b->user_profile(5, 7)->out(false);
        $this->assertStringContainsString('/user/view.php', $usercourse);
        $this->assertStringContainsString('id=5', $usercourse);
        $this->assertStringContainsString('course=7', $usercourse);
        // Site profile drops the course param.
        $this->assertStringNotContainsString('course=', $b->user_profile(5)->out(false));

        $report = $b->user_grade_report(7, 5)->out(false);
        $this->assertStringContainsString('/grade/report/user/index.php', $report);
        $this->assertStringContainsString('userid=5', $report);
    }

    /**
     * Admin-only links are returned for admins and withheld from others.
     */
    public function test_admin_gate(): void {
        $this->resetAfterTest();
        $b = new diagnostic_link_builder();
        $admin = get_admin();
        $teacher = $this->getDataGenerator()->create_user();

        $this->assertNotNull($b->if_admin($b->scheduled_tasks(), (int)$admin->id));
        $this->assertNull($b->if_admin($b->outgoing_mail_config(), (int)$teacher->id));
    }

    /**
     * Capability-gated links respect the acting user's rights at the context.
     */
    public function test_capability_gate(): void {
        $this->resetAfterTest();
        $b = new diagnostic_link_builder();
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $url = $b->enrolled_users($course->id);
        $this->assertNotNull($b->if_capable($url, 'moodle/course:enrolreview', $context, (int)$teacher->id));
        $this->assertNull($b->if_capable($url, 'moodle/course:enrolreview', $context, (int)$student->id));
    }
}

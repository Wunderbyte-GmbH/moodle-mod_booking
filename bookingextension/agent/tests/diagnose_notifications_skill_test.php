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
use context_system;
use bookingextension_agent\local\wizard\core\skills\diagnose_notifications_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the core.diagnose_notifications skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_notifications_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_notifications_skill_test extends advanced_testcase {
    /**
     * Metadata: read-only R0.
     */
    public function test_metadata(): void {
        $skill = new diagnose_notifications_skill();
        $this->assertSame('core.diagnose_notifications', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
    }

    /**
     * Clean self-diagnosis: no blocker, plus the honest delivery-limit note.
     */
    public function test_self_clean(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = (new diagnose_notifications_skill())->execute([], (int)context_system::instance()->id, (int)$user->id);
        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('No user-level e-mail blocker found', $result['observation_full']);
        $this->assertStringContainsString('cannot be verified', $result['observation_full']);
    }

    /**
     * emailstop and unconfirmed accounts are flagged.
     */
    public function test_user_level_blockers(): void {
        $this->resetAfterTest();
        $skill = new diagnose_notifications_skill();
        $sysctxid = (int)context_system::instance()->id;

        $stopped = $this->getDataGenerator()->create_user(['emailstop' => 1]);
        $this->setUser($stopped);
        $r1 = $skill->execute([], $sysctxid, (int)$stopped->id);
        $this->assertStringContainsString('disabled all e-mail', $r1['observation_full']);

        $unconfirmed = $this->getDataGenerator()->create_user(['confirmed' => 0]);
        $this->setUser($unconfirmed);
        $r2 = $skill->execute([], $sysctxid, (int)$unconfirmed->id);
        $this->assertStringContainsString('not confirmed', $r2['observation_full']);
    }

    /**
     * Cross-user gate: a teacher (no viewalldetails) is blocked; a manager/admin is not.
     */
    public function test_cross_user_gate(): void {
        $this->resetAfterTest();
        $sysctxid = (int)context_system::instance()->id;
        $target = $this->getDataGenerator()->create_user();

        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $blocked = (new diagnose_notifications_skill())->execute(['userid' => (int)$target->id], $sysctxid, (int)$teacher->id);
        $this->assertSame('error', $blocked['status']);
        $this->assertSame('permission_denied', $blocked['error_class']);

        $this->setAdminUser();
        $admin = $this->getDataGenerator()->create_user();
        // Admin acting user passes the viewalldetails gate.
        global $USER;
        $allowed = (new diagnose_notifications_skill())->execute(['userid' => (int)$target->id], $sysctxid, (int)$USER->id);
        $this->assertSame('executed', $allowed['status']);
    }

    /**
     * Admin sees the site mail switches (noemailever).
     */
    public function test_admin_sees_site_mail_disabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        set_config('noemailever', 1);

        $result = (new diagnose_notifications_skill())->execute([], (int)context_system::instance()->id, (int)$USER->id);
        $this->assertStringContainsString('Site-wide e-mail is disabled', $result['observation_full']);
    }
}

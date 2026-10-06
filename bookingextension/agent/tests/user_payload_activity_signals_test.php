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
use bookingextension_agent\local\wizard\core\skills\get_current_user_skill;

/**
 * Tests the activity/recency signals added to the shared user payload (lastaccess/lastlogin/emailstop +
 * per-course lastaccess), surfaced via core.get_current_user.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\core_skill_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_payload_activity_signals_test extends advanced_testcase {
    /**
     * A user with access timestamps + emailstop exposes them in payload and observation.
     */
    public function test_signals_present(): void {
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user([
            'lastaccess' => $now - 3600,
            'lastlogin' => $now - 7200,
            'currentlogin' => $now - 60,
            'firstaccess' => $now - 86400,
            'emailstop' => 1,
        ]);
        $this->setUser($user);

        $result = (new get_current_user_skill())->execute([], (int)context_system::instance()->id, (int)$user->id);
        $payload = $result['user'];

        $this->assertSame(1, $payload['emailstop']);
        $this->assertNotSame(get_string('never'), $payload['lastaccess']);
        $this->assertNotSame(get_string('never'), $payload['lastlogin']);
        $this->assertArrayHasKey('currentlogin', $payload);
        $this->assertArrayHasKey('firstaccess', $payload);

        $this->assertStringContainsString('emailstop=1', $result['observation_full']);
        $this->assertStringContainsString('lastaccess=', $result['observation_full']);
        $this->assertStringContainsString('lastlogin=', $result['observation_full']);
    }

    /**
     * A never-accessed user reports "never" rather than a bogus epoch date.
     */
    public function test_never_accessed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'lastaccess' => 0,
            'lastlogin' => 0,
            'firstaccess' => 0,
        ]);
        $this->setUser($user);

        $payload = (new get_current_user_skill())->execute([], (int)context_system::instance()->id, (int)$user->id)['user'];
        $this->assertSame(get_string('never'), $payload['lastaccess']);
        $this->assertSame(get_string('never'), $payload['firstaccess']);
    }

    /**
     * Enrolled courses carry a per-course last access entry.
     */
    public function test_per_course_lastaccess(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $DB->insert_record('user_lastaccess', (object)[
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => time() - 120,
        ]);
        $this->setUser($user);

        $payload = (new get_current_user_skill())->execute([], (int)context_system::instance()->id, (int)$user->id)['user'];
        $found = false;
        foreach ($payload['enrolledcourses'] as $c) {
            if ((int)$c['courseid'] === (int)$course->id) {
                $found = true;
                $this->assertArrayHasKey('lastaccess', $c);
                $this->assertNotSame(get_string('never'), $c['lastaccess']);
            }
        }
        $this->assertTrue($found, 'enrolled course should be present with lastaccess');
    }
}

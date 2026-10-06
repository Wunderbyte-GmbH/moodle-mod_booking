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
use bookingextension_agent\local\wizard\core\skills\get_current_user_skill;
use context_system;

/**
 * Result-shape tests for core.get_current_user (payload dedup).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\get_current_user_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_current_user_skill_shape_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The full payload lives ONCE in 'user'; 'users' carries only a compact identity entry
     * (no duplicated enrolledcourses/roles blobs) and there is no top-level roles key.
     */
    public function test_users_list_is_compact_identity_only(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user(['firstname' => 'Greta', 'lastname' => 'Muster']);
        $gen->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        $result = (new get_current_user_skill())->execute(
            [],
            (int)context_system::instance()->id,
            (int)$user->id
        );

        $this->assertSame('executed', (string)$result['status']);
        $this->assertSame((int)$user->id, (int)$result['userid']);

        // The single complete payload: enrolments and roles live here, once.
        $full = (array)$result['user'];
        $this->assertSame((int)$user->id, (int)$full['userid']);
        $this->assertNotEmpty((array)$full['enrolledcourses']);
        $this->assertNotEmpty((array)$full['roles']);

        // The 'users' list is a compact identity mirror of the same person.
        $users = (array)$result['users'];
        $this->assertCount(1, $users);
        $identity = (array)$users[0];
        $this->assertEqualsCanonicalizing(
            ['userid', 'fullname', 'firstname', 'lastname', 'email', 'profileurl'],
            array_keys($identity)
        );
        $this->assertSame((int)$user->id, (int)$identity['userid']);
        $this->assertSame($full['fullname'], $identity['fullname']);
        $this->assertSame($full['email'], $identity['email']);
        $this->assertSame($full['profileurl'], $identity['profileurl']);
        $this->assertArrayNotHasKey('enrolledcourses', $identity);
        $this->assertArrayNotHasKey('roles', $identity);

        // No top-level roles duplication either.
        $this->assertArrayNotHasKey('roles', $result);

        // The observation keeps the full detail (built from the complete payload).
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('enrolledcourses=[', $observation);
        $this->assertStringContainsString('roles=[', $observation);
        $this->assertStringContainsString((string)$course->fullname, $observation);
    }
}

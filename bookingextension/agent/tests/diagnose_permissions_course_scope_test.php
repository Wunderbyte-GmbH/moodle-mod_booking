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
use bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill;
use context_system;

/**
 * An unresolvable coursequery must never silently answer at System scope (#2337, MCP-F6).
 *
 * "May Bauer create options in course X?" with a nonexistent X answered with Bauer's SYSTEM
 * role assignments — a real-looking answer to a different question. Same honesty class as
 * F59/#2325: never misname what could not be resolved.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_permissions_course_scope_test extends advanced_testcase {
    /**
     * Named-but-unresolvable course -> honest error naming the query, never a System answer.
     */
    public function test_unresolvable_coursequery_errors_instead_of_system_fallback(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Sybs', 'lastname' => 'Bauer']);

        $result = (new diagnose_permissions_skill())->execute(
            ['userquery' => 'Sybs Bauer', 'coursequery' => 'Alpinwandern gibts nicht'],
            (int)context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame(
            'error',
            (string)($result['status'] ?? ''),
            'an unresolvable named course must not produce a System-scope answer: ' . json_encode($result)
        );
        $this->assertStringContainsString(
            'Alpinwandern gibts nicht',
            (string)($result['usermessage'] ?? ''),
            'the honest cause names the query'
        );
    }

    /**
     * Guards: no coursequery keeps the ambient/system behaviour; a real course resolves.
     */
    public function test_ambient_and_valid_course_paths_unchanged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Echter Kurs 2337']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $ambient = (new diagnose_permissions_skill())->execute(
            ['userid' => (int)$user->id],
            (int)context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame('executed', (string)($ambient['status'] ?? ''));

        $incourse = (new diagnose_permissions_skill())->execute(
            ['userid' => (int)$user->id, 'coursequery' => 'Echter Kurs 2337'],
            (int)context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame('executed', (string)($incourse['status'] ?? ''));
        $this->assertStringContainsString('Echter Kurs 2337', (string)($incourse['usermessage'] ?? '')
            . (string)($incourse['observation_full'] ?? ''));
    }
}

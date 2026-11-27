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

namespace mod_booking;

use advanced_testcase;
use context_module;
use mod_booking\external\search_booking_instances;

/**
 * The search for booking instances fills the instance selector of the certificate conditions.
 * It only lists the instances in which the user may edit certificate conditions, so it cannot
 * be used to list the booking instances and courses of the whole site.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\external\search_booking_instances
 */
final class search_booking_instances_test extends advanced_testcase {
    /**
     * Names of the instances the search returns for the current user.
     *
     * @param string $query
     * @param int $cmid
     * @return string[]
     */
    private function search(string $query, int $cmid = 0): array {
        $result = search_booking_instances::execute($query, $cmid);
        $result = \core_external\external_api::clean_returnvalue(search_booking_instances::execute_returns(), $result);
        $names = array_column($result['list'], 'text');
        sort($names);
        return $names;
    }

    /**
     * Only the instances the user may manage are listed.
     */
    public function test_search_lists_only_instances_the_user_may_manage(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        singleton_service::destroy_instance();

        $course = $this->getDataGenerator()->create_course();
        $hiddencourse = $this->getDataGenerator()->create_course(['visible' => 0, 'fullname' => 'Hidden course']);
        $own = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Seminar own']);
        $other = $this->getDataGenerator()->create_module(
            'booking',
            ['course' => $hiddencourse->id, 'name' => 'Seminar other']
        );

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $editor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editor->id, $course->id, 'student');
        $context = context_module::instance($own->cmid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:editcertificateconditions', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $editor->id, $context->id);

        // A participant of a course gets no list of the booking instances of the site.
        $this->setUser($student);
        $this->assertSame([], $this->search('Seminar'));
        $this->assertSame([], $this->search('Seminar', (int)$other->cmid));

        // The editor of one instance gets this instance only.
        $this->setUser($editor);
        $this->assertSame(['Seminar own'], $this->search('Seminar'));
        $this->assertSame([], $this->search('Seminar', (int)$other->cmid));

        // Users who may edit the conditions of the whole site get all instances.
        $this->setAdminUser();
        $this->assertSame(['Seminar other', 'Seminar own'], $this->search('Seminar'));
    }
}

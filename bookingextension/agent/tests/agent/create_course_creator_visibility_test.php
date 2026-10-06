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
 * G5 symptom probe (B3): a non-admin creator must find their own fresh course.
 *
 * E2E audit blueprint compound_prompt §7 finding 3 / §8 B3: course.create_course
 * assigns the creator NO role in the new course (no creatornewroleid parity with
 * the Moodle UI). The step-3 course linking of the booking create skills resolves
 * linkedcoursequery through booking::load_courses, which without
 * mod/booking:duplicateanycourse only lists courses the user may manually enrol
 * into — so the non-admin creator cannot find the course they JUST created and
 * the authoring chain breaks with "course not found".
 *
 * The real-LLM E2E works around this by granting duplicateanycourse in a system
 * role (course_authoring_compound_real_llm_test::prepare_authoring_environment);
 * this test deliberately drops that workaround and pins the SYMPTOM: resolution
 * of the freshly created course must succeed for its own creator. It goes green
 * once create_course assigns the creator a role (creatornewroleid parity) OR the
 * courseid is passed through deterministically (G5 / output_bindings) and the
 * resolver accepts it.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wizard\course\skills\create_course_skill;
use context_system;
use mod_booking\local\wizard\booking\booking_skill_support;

/**
 * Creator visibility of a freshly created course on the linkedcoursequery path.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\course\skills\create_course_skill
 */
final class create_course_creator_visibility_test extends abstract_agent_testcase {
    /**
     * Step 1: the non-admin creator runs create_course (preflight + execute).
     * Step 2 (symptom assertion): the SAME user must be able to resolve the fresh
     * course through the resolver the booking create skills use for
     * linkedcoursequery (booking_skill_support::resolve_single_course, which is
     * backed by booking::load_courses).
     *
     * @return void
     */
    public function test_creator_resolves_their_fresh_course_via_linkedcoursequery(): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $gen->create_category(['name' => 'Wikingerkategorie']);

        // System role mirroring prepare_authoring_environment of the real-LLM E2E,
        // minus the mod/booking:duplicateanycourse workaround (manager-only cap the
        // authoring persona does not have) and minus the scaffold-only module caps.
        $systemcontext = context_system::instance();
        $roleid = $gen->create_role();
        $capabilities = [
            'moodle/course:create',
            'moodle/course:update',
            'moodle/course:view',
            'moodle/course:manageactivities',
            'bookingextension/agent:skill_course_create_course',
        ];
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, (int)$systemcontext->id);
        }
        role_assign($roleid, (int)$this->teacher->id, (int)$systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->teacher);

        // Preconditions of the defect scenario: not an admin, no duplicateanycourse.
        $this->assertFalse(is_siteadmin($this->teacher->id), 'Precondition: the creator must not be a site admin.');
        $this->assertFalse(
            has_capability('mod/booking:duplicateanycourse', $systemcontext, $this->teacher),
            'Precondition: the creator must not hold mod/booking:duplicateanycourse.'
        );

        // Step 1: create the course exactly as the executed skill does.
        $title = 'Das Leben der Wikinger ' . substr(sha1(uniqid('', true)), 0, 8);
        $skill = new create_course_skill();
        $dto = $skill->preflight([
            'fullname' => $title,
            'summary' => 'Alltag, Seefahrt und Kultur.',
            'categoryquery' => 'Wikinger',
        ], (int)$systemcontext->id, (int)$this->teacher->id);
        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));

        $result = $skill->execute($dto->preparedinput, (int)$systemcontext->id, (int)$this->teacher->id);
        $this->assertSame('executed', (string)$result['status'], (string)($result['detail'] ?? ''));
        $courseid = (int)$result['resultid'];
        $this->assertGreaterThan(0, $courseid);
        $this->assertNotFalse(
            $DB->get_record('course', ['id' => $courseid]),
            'The fresh course must exist in the database.'
        );

        // Step 2 (the G5 symptom): the creator resolves their own fresh course by
        // full name on the exact path the create-option skills use for
        // linkedcoursequery (create_option_skill preflight ->
        // booking_skill_support::resolve_single_course -> booking::load_courses).
        // Today this fails ("no course matched"): create_course gave the creator no
        // role in the new course and load_courses without duplicateanycourse only
        // lists manually-enrollable courses (blueprint §7 finding 3, §8 B3).
        $resolved = booking_skill_support::resolve_single_course($title);
        $this->assertSame(
            'ok',
            (string)($resolved['status'] ?? ''),
            'The creator must be able to resolve the course they just created via linkedcoursequery. '
                . 'Resolver said: status=' . (string)($resolved['status'] ?? '')
                . ', message=' . (string)($resolved['message'] ?? '')
                . ' (courseid=' . $courseid . ', fullname="' . $title . '").'
        );
        $this->assertSame($courseid, (int)($resolved['courseid'] ?? 0), 'The resolver must return the fresh course.');
    }
}

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
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;

/**
 * Guards for the linkedcoursequery create-time course link (U5c).
 *
 * linkedcoursequery is the PROMPT-FACING key that links the Moodle course participants
 * enter after booking; coursequery stays a rejected input key on create (thread-548
 * anti-hallucination guidance) and only appears in the PREPARED input after this skill
 * resolved the link deliberately.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\create_selflearning_option_skill
 */
final class agent_create_option_linkedcourse_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Both create skills expose linkedcoursequery; the ambiguous coursequery key stays hidden.
     */
    public function test_create_schemas_expose_linkedcoursequery_not_coursequery(): void {
        $this->resetAfterTest();

        foreach ([new create_option_skill(), new create_selflearning_option_skill()] as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('linkedcoursequery', $properties, $skill->get_name());
            $this->assertArrayNotHasKey('coursequery', $properties, $skill->get_name());
        }
    }

    /**
     * Raw coursequery input keeps rejecting through the schema-mismatch repair path —
     * exposing linkedcoursequery must not reopen the thread-545/548 confusion surface.
     */
    public function test_raw_coursequery_input_still_rejects(): void {
        $this->resetAfterTest();
        $skill = new create_option_skill();

        $result = $skill->check_structure([
            'text' => 'Wikinger',
            'coursequery' => 'Wikingerkurs',
        ]);

        $this->assertFalse($result['valid']);
        // F3-W2 two-channel contract: the offending key is named in the planner-only 'repair'
        // channel; the user channel ('errors') carries only the plain-English cause.
        $this->assertStringContainsString('coursequery', implode(' ', (array)($result['repair'] ?? [])));
        $this->assertStringNotContainsString('coursequery', implode(' ', (array)$result['errors']));
    }

    /**
     * A resolvable linkedcoursequery passes preflight and lands as the mutation service's
     * canonical coursequery key in the PREPARED input (booking → enrolment link).
     */
    public function test_linkedcoursequery_resolves_into_prepared_input(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $linked = $gen->create_course(['fullname' => 'Das Leben der Wikinger', 'shortname' => 'wikinger']);
        $host = $gen->create_course();
        $booking = $gen->create_module('booking', ['course' => $host->id]);
        $context = context_module::instance($booking->cmid);

        $dto = (new create_option_skill())->preflight([
            'text' => 'Wikinger Selbstlernkurs',
            'maxanswers' => 20,
            'linkedcoursequery' => 'Das Leben der Wikinger',
        ], (int)$context->id, (int)$USER->id);
        $result = $dto->to_array();

        $this->assertSame('pass', $result['status'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertSame('Das Leben der Wikinger', $prepared['coursequery'] ?? null);
        $this->assertArrayNotHasKey('linkedcoursequery', $prepared);
        $this->assertNotEmpty($linked->id);
    }

    /**
     * An unresolvable linked course surfaces as a clarification in preflight instead of a
     * late execute error.
     */
    public function test_unresolvable_linked_course_clarifies_in_preflight(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $host = $gen->create_course();
        $booking = $gen->create_module('booking', ['course' => $host->id]);
        $context = context_module::instance($booking->cmid);

        $result = (new create_option_skill())->preflight([
            'text' => 'Wikinger Selbstlernkurs',
            'linkedcoursequery' => 'No such course exists here',
        ], (int)$context->id, (int)$USER->id)->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('LINKED_COURSE_UNRESOLVED', (array)($result['issue_codes'] ?? []));
    }
}

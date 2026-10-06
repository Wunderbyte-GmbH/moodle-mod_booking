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
use context_system;
use mod_booking\customfield\booking_handler;
use mod_booking\local\wizard\options\skills\create_option_field_skill;
use mod_booking\local\wizard\options\skills\list_option_fields_skill;
use mod_booking\local\wizard\options\skills\option_field_support;
use mod_booking\local\wizard\options\skills\update_option_field_skill;

/**
 * Tests for the booking option field skills.
 *
 * The non-success paths come first on purpose: a shortname that shadows a booking option property,
 * a shortname that is taken, a field type this site has not installed and a missing capability all
 * have to end as a question with nothing written.
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\options\skills\create_option_field_skill
 * @covers     \mod_booking\local\wizard\options\skills\update_option_field_skill
 * @covers     \mod_booking\local\wizard\options\skills\list_option_fields_skill
 * @covers     \mod_booking\customfield\booking_handler
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_option_field_skills_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * The system context id the site-wide field skills operate in.
     *
     * @return int
     */
    private function system_contextid(): int {
        return (int)context_system::instance()->id;
    }

    /**
     * Number of booking option fields currently defined on the site.
     *
     * @return int
     */
    private function count_fields(): int {
        return count(option_field_support::get_field_records());
    }

    /**
     * Collect the issue codes of a preflight result.
     *
     * @param object $preflight
     * @return string[]
     */
    private function issue_codes($preflight): array {
        return array_values(array_map(
            static fn(array $issue): string => (string)($issue['code'] ?? ''),
            (array)$preflight->issues
        ));
    }

    /**
     * Create one field through the skill and return its id.
     *
     * @param array $input
     * @return int
     */
    private function create_field(array $input): int {
        global $USER;

        $skill = new create_option_field_skill();
        $preflight = $skill->preflight($input, $this->system_contextid(), (int)$USER->id);
        $this->assertSame('pass', $preflight->status);

        $result = $skill->execute((array)$preflight->preparedinput, $this->system_contextid(), (int)$USER->id);
        $this->assertSame('executed', $result['status']);
        return (int)$result['resultid'];
    }

    /**
     * A shortname that shadows a booking option property is a question, and nothing is written.
     */
    public function test_reserved_shortname_ends_as_clarification(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $before = $this->count_fields();
        $preflight = (new create_option_field_skill())->preflight(
            ['shortname' => 'maxanswers', 'name' => 'Max answers', 'type' => 'text'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_SHORTNAME_RESERVED', $this->issue_codes($preflight));
        $this->assertSame($before, $this->count_fields());
    }

    /**
     * The clarification for a reserved shortname carries no issue code, skill name or field list.
     */
    public function test_reserved_shortname_question_has_no_internals(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $preflight = (new create_option_field_skill())->preflight(
            ['shortname' => 'maxanswers', 'name' => 'Max answers', 'type' => 'text'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $question = '';
        foreach ((array)$preflight->issues as $issue) {
            $question .= (string)($issue['user_question'] ?? $issue['message'] ?? '');
        }

        $this->assertNotSame('', $question);
        $this->assertStringNotContainsString('OPTION_FIELD_', $question);
        $this->assertStringNotContainsString('mod_booking.', $question);
    }

    /**
     * A shortname another field already uses is a question, and nothing is written.
     */
    public function test_taken_shortname_ends_as_clarification(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $this->create_field(['shortname' => 'sportlevel', 'name' => 'Sport level', 'type' => 'text']);
        $before = $this->count_fields();

        $preflight = (new create_option_field_skill())->preflight(
            ['shortname' => 'sportlevel', 'name' => 'Another one', 'type' => 'text'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_SHORTNAME_TAKEN', $this->issue_codes($preflight));
        $this->assertSame($before, $this->count_fields());
    }

    /**
     * A field type that is not installed is a question, and nothing is written.
     */
    public function test_unknown_type_ends_as_clarification(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $before = $this->count_fields();
        $preflight = (new create_option_field_skill())->preflight(
            ['shortname' => 'sportlevel', 'name' => 'Sport level', 'type' => 'notinstalledtype'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_TYPE_UNKNOWN', $this->issue_codes($preflight));
        $this->assertSame($before, $this->count_fields());
    }

    /**
     * A select field without any selectable value is a question, and nothing is written.
     */
    public function test_select_without_options_ends_as_clarification(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        if (!option_field_support::type_is_installed('select')) {
            $this->markTestSkipped('customfield_select is not installed.');
        }

        $before = $this->count_fields();
        $preflight = (new create_option_field_skill())->preflight(
            ['shortname' => 'distance', 'name' => 'Distance', 'type' => 'select'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_SELECT_OPTIONS_REQUIRED', $this->issue_codes($preflight));
        $this->assertSame($before, $this->count_fields());
    }

    /**
     * Without the capability the skill stops before the write.
     */
    public function test_missing_capability_ends_without_write(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $before = $this->count_fields();
        $preflight = (new create_option_field_skill())->preflight(
            ['shortname' => 'sportlevel', 'name' => 'Sport level', 'type' => 'text'],
            $this->system_contextid(),
            (int)$user->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_CAPABILITY_REQUIRED', $this->issue_codes($preflight));
        $this->assertSame($before, $this->count_fields());
    }

    /**
     * A rename onto a reserved shortname is refused and the field keeps the shortname it had.
     */
    public function test_rename_to_reserved_shortname_keeps_the_old_shortname(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $fieldid = $this->create_field(['shortname' => 'sportlevel', 'name' => 'Sport level', 'type' => 'text']);

        $preflight = (new update_option_field_skill())->preflight(
            ['currentshortname' => 'sportlevel', 'shortname' => 'maxanswers'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_SHORTNAME_RESERVED', $this->issue_codes($preflight));

        $record = option_field_support::get_field_by_id($fieldid);
        $this->assertNotNull($record);
        $this->assertSame('sportlevel', $record->shortname);
    }

    /**
     * An update that names a field which does not exist is a question, not an error.
     */
    public function test_unknown_field_ends_as_clarification(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $preflight = (new update_option_field_skill())->preflight(
            ['currentshortname' => 'doesnotexist', 'name' => 'New name'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains('OPTION_FIELD_NOT_FOUND', $this->issue_codes($preflight));
    }

    /**
     * Create, then list: the field is returned with its type, category and usage count.
     */
    public function test_create_then_list_returns_the_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $fieldid = $this->create_field([
            'shortname' => 'sportlevel',
            'name' => 'Sport level',
            'type' => 'text',
            'categoryname' => 'Agent fields',
            'required' => true,
        ]);

        $result = (new list_option_fields_skill())->execute(
            ['shortname' => 'sportlevel'],
            $this->system_contextid(),
            (int)$USER->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertCount(1, $result['fields']);

        $field = $result['fields'][0];
        $this->assertSame($fieldid, $field['id']);
        $this->assertSame('sportlevel', $field['shortname']);
        $this->assertSame('text', $field['type']);
        $this->assertSame('Agent fields', $field['categoryname']);
        $this->assertTrue($field['required']);
        $this->assertFalse($field['shadowsoptionproperty']);
        $this->assertSame(0, $field['optionswithvalue']);
    }

    /**
     * Every installed field type can be created.
     */
    public function test_every_installed_type_can_be_created(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $types = option_field_support::get_installed_types();
        $this->assertNotEmpty($types);

        foreach (array_keys($types) as $index => $type) {
            $input = [
                'shortname' => 'agentfield' . $index,
                'name' => 'Agent field ' . $index,
                'type' => $type,
            ];
            if ($type === 'select') {
                $input['options'] = ['near', 'far'];
            }

            $fieldid = $this->create_field($input);
            $record = option_field_support::get_field_by_id($fieldid);
            $this->assertNotNull($record, 'Field of type ' . $type . ' was not created.');
            $this->assertSame($type, $record->type);
        }
    }

    /**
     * A rename to a free shortname goes through and is stored.
     */
    public function test_rename_to_free_shortname_is_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $fieldid = $this->create_field(['shortname' => 'sportlevel', 'name' => 'Sport level', 'type' => 'text']);

        $skill = new update_option_field_skill();
        $preflight = $skill->preflight(
            ['currentshortname' => 'sportlevel', 'shortname' => 'discipline', 'name' => 'Discipline'],
            $this->system_contextid(),
            (int)$USER->id
        );
        $this->assertSame('pass', $preflight->status);

        $result = $skill->execute((array)$preflight->preparedinput, $this->system_contextid(), (int)$USER->id);
        $this->assertSame('executed', $result['status']);

        $record = option_field_support::get_field_by_id($fieldid);
        $this->assertNotNull($record);
        $this->assertSame('discipline', $record->shortname);
        $this->assertSame('Discipline', $record->name);
    }

    /**
     * The management page warning and the skills judge a shortname by the same rule.
     */
    public function test_page_warning_and_skill_share_one_rule(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertTrue(booking_handler::is_reserved_shortname('maxanswers'));
        $this->assertFalse(booking_handler::is_reserved_shortname('sportlevel'));

        $reserved = booking_handler::get_reserved_shortnames();
        $this->assertContains('maxanswers', $reserved);
        $this->assertContains('text', $reserved);

        // Nothing is in use yet, so the page has nothing to warn about.
        $this->assertSame([], booking_handler::get_forbidden_shortnames_in_use());
    }
}

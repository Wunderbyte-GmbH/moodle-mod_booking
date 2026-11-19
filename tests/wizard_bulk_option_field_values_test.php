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
use bookingextension_agent\local\wizard\services\skill_input_schema_projection;
use context_module;
use context_system;
use mod_booking\customfield\booking_handler;
use mod_booking\local\wizard\booking\support\option_field_values;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\create_option_field_skill;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The wizard sets booking option field values (Wunderbyte-GmbH/Wunderbyte-GmbH#2680).
 *
 * Training thread 227: "set Kursformat on every option that has none" had no field to go to, so the planner chained
 * twenty single updates with customformjson, which is the booking-time form condition. Here a select field is set
 * through bulk and single update, narrowed to the empty options, and refused for an unknown field or value.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\booking\support\option_field_values
 * @covers \mod_booking\local\wizard\options\skills\bulk_update_options_skill
 */
final class wizard_bulk_option_field_values_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip without the agent extension.
     */
    public function setUp(): void {
        $this->skip_without_agent_extension();
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        // The custom-field handler is a singleton holding field objects; a field recreated after the reset has a new id.
        booking_handler::reset_caches();
    }

    /**
     * Bulk sets a select field on every matched option, stored as the position of the displayed value.
     */
    public function test_bulk_sets_a_select_field_on_every_matched_option(): void {
        global $USER;
        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options();
        $this->create_select_field();
        $contextid = (int)context_module::instance($cmid)->id;
        $skill = new bulk_update_options_skill();

        $preflight = $skill->preflight(
            ['optionids' => [$optionid1, $optionid2], 'optionfields' => ['kursformat' => 'präsenz']],
            $contextid,
            (int)$USER->id
        );
        $this->assertSame('pass', $preflight->status, json_encode($preflight->issues ?? []));
        $prepared = (array)$preflight->preparedinput;
        $this->assertSame(['kursformat' => 1], (array)$prepared['optionfieldvalues']);

        $rows = (array)((array)$skill->describe_proposed_action($prepared))['rows'];
        $labels = array_column($rows, 'value', 'label');
        $this->assertSame('präsenz', $labels['Kursformat'] ?? null, json_encode($rows));

        $result = $skill->execute($prepared, $contextid, (int)$USER->id);
        $this->assertSame('executed', $result['status'] ?? '', (string)($result['detail'] ?? ''));
        // The success list names the options by title, not only by id (Wunderbyte-GmbH#2621).
        $this->assertMatchesRegularExpression('/#\\d+ \\("[^"]+" /', (string)$result['detail']);
        foreach ($result['persisted_fields'] as $entry) {
            $this->assertContains('optionfields', (array)$entry['fields']);
        }
        $this->assertSame('1', $this->stored_value($optionid1, 'kursformat'));
        $this->assertSame('1', $this->stored_value($optionid2, 'kursformat'));
    }

    /**
     * optionfield_empty keeps only the options without a value; a single update sets the field too.
     */
    public function test_optionfield_empty_keeps_only_the_options_without_a_value(): void {
        global $USER;
        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options();
        $this->create_select_field();
        $contextid = (int)context_module::instance($cmid)->id;

        $single = (new update_option_skill())->execute(
            ['optionid' => $optionid1, 'optionfields' => ['kursformat' => 'Präsenz']],
            $contextid,
            (int)$USER->id
        );
        $this->assertSame('executed', $single['status'] ?? '', (string)($single['detail'] ?? ''));
        $this->assertSame('1', $this->stored_value($optionid1, 'kursformat'));

        $skill = new bulk_update_options_skill();
        $preflight = $skill->preflight(
            ['optionfield_empty' => 'kursformat', 'optionfields' => ['kursformat' => 'Online']],
            $contextid,
            (int)$USER->id
        );
        $this->assertSame('pass', $preflight->status, json_encode($preflight->issues ?? []));
        $prepared = (array)$preflight->preparedinput;
        $this->assertSame([$optionid2], array_map('intval', (array)$prepared['optionids']));
        $this->assertTrue((bool)($prepared['apply_to_all'] ?? false), 'the scope key alone means every option of the instance');
        $labels = array_column((array)((array)$skill->describe_proposed_action($prepared))['rows'], 'value', 'label');
        $this->assertSame('Kursformat', $labels[get_string('previewlabel_optionfieldempty', 'booking')] ?? null);

        $result = $skill->execute($prepared, $contextid, (int)$USER->id);
        $this->assertSame('executed', $result['status'] ?? '', (string)($result['detail'] ?? ''));
        $this->assertSame('1', $this->stored_value($optionid1, 'kursformat'));
        $this->assertSame('2', $this->stored_value($optionid2, 'kursformat'));

        // The scope key on its own selects every option of the instance where the field is still empty (the
        // constructor sends exactly that for "bei allen Optionen, bei denen das Kursformat noch leer ist", OF4).
        $only = $skill->preflight(
            ['optionfield_empty' => 'kursformat', 'optionfields' => ['kursformat' => 'Präsenz']],
            $contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', $only->status, 'nothing is empty any more');
        $this->assertContains('EMPTY_BULK_TARGET_SELECTION', $this->issue_codes($only));

        // Nothing left to fill: a clarification, not a confirmable no-op.
        $again = $skill->preflight(
            ['apply_to_all' => true, 'optionfield_empty' => 'kursformat', 'optionfields' => ['kursformat' => 'Online']],
            $contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', $again->status);
        $this->assertContains('EMPTY_BULK_TARGET_SELECTION', $this->issue_codes($again));
    }

    /**
     * An unknown value or field ends as a clarification with the possible values, and nothing is written.
     */
    public function test_an_unknown_value_or_field_is_a_clarification(): void {
        global $USER;
        [$cmid, $optionid1] = $this->create_booking_with_two_options();
        $this->create_select_field();
        $contextid = (int)context_module::instance($cmid)->id;
        $skill = new bulk_update_options_skill();

        $preflight = $skill->preflight(
            ['optionids' => [$optionid1], 'optionfields' => ['kursformat' => 'Hybrid']],
            $contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains(option_field_values::ISSUE_VALUE_INVALID, $this->issue_codes($preflight));
        $issue = $this->issue_with_code($preflight, option_field_values::ISSUE_VALUE_INVALID);
        $this->assertStringContainsString('Präsenz', (string)$issue['user_question']);
        $this->assertStringContainsString('Online', (string)$issue['user_question']);

        $preflight = $skill->preflight(
            ['optionids' => [$optionid1], 'optionfields' => ['raumformat' => 'Präsenz']],
            $contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', $preflight->status);
        $this->assertContains(option_field_values::ISSUE_UNKNOWN, $this->issue_codes($preflight));

        // An untouched select field reads 0 (no position chosen).
        $this->assertContains($this->stored_value($optionid1, 'kursformat'), ['', '0']);
    }

    /**
     * The constructor sees the field on update, create and bulk, and the scope key on bulk.
     */
    public function test_the_fields_are_listed_for_the_constructor(): void {
        foreach ([new update_option_skill(), new create_option_skill(), new bulk_update_options_skill()] as $skill) {
            $this->assertContains('optionfields', $this->listed_fields($skill), $skill->get_name());
        }
        $this->assertContains('optionfield_empty', $this->listed_fields(new bulk_update_options_skill()));
    }

    /**
     * A booking with two options, as admin.
     *
     * @return array{0:int,1:int,2:int} cmid, option ids
     */
    private function create_booking_with_two_options(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Option fields']);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $ids = [];
        foreach (['First option', 'Second option'] as $text) {
            $record = new stdClass();
            $record->bookingid = $booking->id;
            $record->text = $text;
            $record->courseid = $course->id;
            $record->importing = 1;
            $ids[] = (int)$generator->create_option($record)->id;
        }
        singleton_service::destroy_instance();
        return [(int)$booking->cmid, $ids[0], $ids[1]];
    }

    /**
     * The select field "Kursformat" (Präsenz, Online), created the way the skill does it.
     */
    private function create_select_field(): void {
        global $USER;
        $skill = new create_option_field_skill();
        $contextid = (int)context_system::instance()->id;
        $input = ['name' => 'Kursformat', 'shortname' => 'kursformat', 'type' => 'select', 'options' => ['Präsenz', 'Online']];
        $preflight = $skill->preflight($input, $contextid, (int)$USER->id);
        $this->assertSame('pass', $preflight->status, json_encode($preflight->issues ?? []));
        $result = $skill->execute((array)$preflight->preparedinput, $contextid, (int)$USER->id);
        $this->assertSame('executed', $result['status']);
    }

    /**
     * The stored value of a field on an option ('' when unset).
     *
     * @param int $optionid
     * @param string $key
     * @return string
     */
    private function stored_value(int $optionid, string $key): string {
        singleton_service::destroy_booking_option_singleton($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return (string)($settings->customfields[$key] ?? '');
    }

    /**
     * Field names the constructor is shown for a skill.
     *
     * @param object $skill
     * @return string[]
     */
    private function listed_fields(object $skill): array {
        $names = [];
        foreach (skill_input_schema_projection::for_skill($skill) as $line) {
            if (preg_match('/^\W*([a-z_0-9]+)\s*\(/', (string)$line, $m)) {
                $names[] = $m[1];
            }
        }
        return $names;
    }

    /**
     * Issue codes of a preflight result.
     *
     * @param object $preflight
     * @return string[]
     */
    private function issue_codes(object $preflight): array {
        return array_values(array_map(
            static fn(array $issue): string => (string)($issue['code'] ?? ''),
            (array)$preflight->issues
        ));
    }

    /**
     * The first issue with a code.
     *
     * @param object $preflight
     * @param string $code
     * @return array
     */
    private function issue_with_code(object $preflight, string $code): array {
        foreach ((array)$preflight->issues as $issue) {
            if ((string)($issue['code'] ?? '') === $code) {
                return (array)$issue;
            }
        }
        return [];
    }
}

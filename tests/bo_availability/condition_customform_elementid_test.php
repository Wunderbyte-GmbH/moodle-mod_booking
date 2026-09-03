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
 * Tests for the stable elementid of customform availability condition elements.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for stable elementids: identity must survive moving, inserting and deleting
 * of customform elements, so stored answer keys customform_{formtype}_{elementid}
 * keep pointing to the right element (issue #2195).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_customform_elementid_test extends booking_advanced_testcase {
    /**
     * Build a fromform object with three customform rows like the option form submits them.
     *
     * @param bool $withelementids also submit the hidden elementid fields
     * @return stdClass
     */
    private function get_base_fromform(bool $withelementids): stdClass {
        $fromform = new stdClass();
        $fromform->bo_cond_customform_restrict = 1;
        $rows = [
            1 => ['formtype' => 'shorttext', 'label' => 'first', 'value' => 'v1'],
            2 => ['formtype' => 'select', 'label' => 'second', 'value' => "a => A\nb => B"],
            3 => ['formtype' => 'mail', 'label' => 'third', 'value' => 'test@example.com'],
        ];
        foreach ($rows as $i => $row) {
            $fromform->{'bo_cond_customform_select_1_' . $i} = $row['formtype'];
            $fromform->{'bo_cond_customform_label_1_' . $i} = $row['label'];
            $fromform->{'bo_cond_customform_value_1_' . $i} = $row['value'];
            $fromform->{'bo_cond_customform_notempty_1_' . $i} = 0;
            if ($withelementids) {
                $fromform->{'bo_cond_customform_elementid_1_' . $i} = $i;
            }
        }
        if ($withelementids) {
            $fromform->bo_cond_customform_nextelementid = 4;
        }
        return $fromform;
    }

    /**
     * Return [position => elementid] of the first form of a condition object.
     *
     * @param stdClass $conditionobject
     * @return array
     */
    private function get_elementids(stdClass $conditionobject): array {
        $ids = [];
        foreach ((array)$conditionobject->formsarray[1] as $position => $formelement) {
            $ids[$position] = $formelement->elementid;
        }
        return $ids;
    }

    /**
     * Programmatic setters (webservices, wizard, generator) submit no elementids:
     * fresh ids must equal the historical numbering 1...N.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::get_condition_object_for_json
     */
    public function test_elementids_assigned_without_submitted_ids(): void {
        $condition = customform::instance()->get_condition_object_for_json($this->get_base_fromform(false));

        $this->assertSame([1 => 1, 2 => 2, 3 => 3], $this->get_elementids($condition));
        $this->assertSame(4, $condition->nextelementid);
    }

    /**
     * T1: an unchanged save must keep ids and order bit-identical.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::get_condition_object_for_json
     * @covers \mod_booking\bo_availability\conditions\customform::set_defaults
     */
    public function test_unchanged_roundtrip_is_identical(): void {
        $instance = customform::instance();
        $saved = $instance->get_condition_object_for_json($this->get_base_fromform(false));

        // Load the saved condition into form defaults, feed them back unchanged.
        $defaults = new stdClass();
        $instance->set_defaults($defaults, json_decode(json_encode($saved)));
        $resaved = $instance->get_condition_object_for_json($defaults);

        $this->assertEquals(
            json_decode(json_encode($saved), true),
            json_decode(json_encode($resaved), true)
        );
    }

    /**
     * T3/T5: moving rows (like the customformeditor JS does, elementid travels with
     * the tuple) must keep each element's id, so answer keys stay stable.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::get_condition_object_for_json
     */
    public function test_reorder_keeps_elementids(): void {
        $fromform = $this->get_base_fromform(true);

        // Swap rows 1 and 2 including their hidden elementid, like the JS module does.
        foreach (['select', 'label', 'value', 'elementid'] as $shortname) {
            $key1 = 'bo_cond_customform_' . $shortname . '_1_1';
            $key2 = 'bo_cond_customform_' . $shortname . '_1_2';
            [$fromform->{$key1}, $fromform->{$key2}] = [$fromform->{$key2}, $fromform->{$key1}];
        }

        $condition = customform::instance()->get_condition_object_for_json($fromform);

        // Position 1 now holds the select element, but its identity is still 2.
        $this->assertSame('select', $condition->formsarray[1][1]->formtype);
        $this->assertSame([1 => 2, 2 => 1, 3 => 3], $this->get_elementids($condition));
        $this->assertSame(4, $condition->nextelementid);
    }

    /**
     * T3: an inserted row (empty elementid) gets a fresh id from the counter;
     * all other elements keep theirs.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::get_condition_object_for_json
     */
    public function test_insert_assigns_fresh_id(): void {
        $fromform = $this->get_base_fromform(true);

        // Shift rows 2 and 3 down by one and put a new row at position 2,
        // exactly what the insert operation of the JS module produces.
        foreach (['select', 'label', 'value', 'elementid'] as $shortname) {
            $base = 'bo_cond_customform_' . $shortname . '_1_';
            $fromform->{$base . '4'} = $fromform->{$base . '3'};
            $fromform->{$base . '3'} = $fromform->{$base . '2'};
        }
        $fromform->bo_cond_customform_select_1_2 = 'url';
        $fromform->bo_cond_customform_label_1_2 = 'inserted';
        $fromform->bo_cond_customform_value_1_2 = 'https://example.com';
        $fromform->bo_cond_customform_elementid_1_2 = 0;
        $fromform->bo_cond_customform_notempty_1_4 = 0;

        $condition = customform::instance()->get_condition_object_for_json($fromform);

        $this->assertSame([1 => 1, 2 => 4, 3 => 2, 4 => 3], $this->get_elementids($condition));
        $this->assertSame(5, $condition->nextelementid);
    }

    /**
     * T4: deleting the element with the highest id must not reset the counter,
     * so its id is never reused for a new element.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::get_condition_object_for_json
     */
    public function test_delete_does_not_reuse_ids(): void {
        $fromform = $this->get_base_fromform(true);

        // Delete row 3 (elementid 3), like the JS module: clear the last row.
        $fromform->bo_cond_customform_select_1_3 = '0';

        $condition = customform::instance()->get_condition_object_for_json($fromform);
        $this->assertSame([1 => 1, 2 => 2], $this->get_elementids($condition));
        $this->assertSame(4, $condition->nextelementid);

        // A new element added afterwards gets id 4, not the freed id 3.
        $defaults = new stdClass();
        customform::instance()->set_defaults($defaults, json_decode(json_encode($condition)));
        $defaults->bo_cond_customform_select_1_3 = 'shorttext';
        $defaults->bo_cond_customform_label_1_3 = 'new element';
        $defaults->bo_cond_customform_value_1_3 = '';
        $defaults->bo_cond_customform_elementid_1_3 = 0;

        $condition = customform::instance()->get_condition_object_for_json($defaults);
        $this->assertSame([1 => 1, 2 => 2, 3 => 4], $this->get_elementids($condition));
        $this->assertSame(5, $condition->nextelementid);
    }

    /**
     * Deleting a form element removes it from the definition, but never touches what
     * participants already entered: their answers stay in booking_answers under the
     * unchanged key customform_{formtype}_{elementid}.
     *
     * @covers \mod_booking\bo_availability\bo_info::save_json_conditions_from_form
     * @covers \mod_booking\bo_availability\conditions\customform::get_condition_object_for_json
     */
    public function test_deleting_an_element_keeps_stored_user_answers(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Test Booking',
            'eventtype' => 'Test event',
        ]);
        $user = $this->getDataGenerator()->create_user();

        $optionid = $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'identifier' => uniqid(),
            'text' => 'Option with a custom form',
            'description' => '',
            'address' => '',
            'location' => '',
            'institution' => '',
        ]);

        // Save the three-element form the way the option form does.
        $fromform = $this->get_base_fromform(true);
        $fromform->id = $optionid;
        bo_info::save_json_conditions_from_form($fromform);
        $DB->set_field('booking_options', 'availability', $fromform->availability, ['id' => $optionid]);

        // A participant filled in all three fields.
        $answerid = $DB->insert_record('booking_answers', (object)[
            'bookingid' => $booking->id,
            'optionid' => $optionid,
            'userid' => $user->id,
            'json' => json_encode((object)[
                'condition_customform' => (object)[
                    'customform_shorttext_1' => 'Anna',
                    'customform_select_2' => 'a',
                    'customform_mail_3' => 'anna@example.com',
                ],
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Now the third element is deleted in the option form: the row is cleared,
        // exactly as the customformeditor module leaves it behind.
        $delete = $this->get_base_fromform(true);
        $delete->id = $optionid;
        $delete->bo_cond_customform_select_1_3 = '0';
        $delete->bo_cond_customform_label_1_3 = '';
        $delete->bo_cond_customform_value_1_3 = '';
        $delete->bo_cond_customform_elementid_1_3 = 0;
        bo_info::save_json_conditions_from_form($delete);
        $DB->set_field('booking_options', 'availability', $delete->availability, ['id' => $optionid]);

        singleton_service::destroy_instance();
        \cache_helper::purge_by_definition('mod_booking', 'bookingoptionsettings');

        // The definition lost the element.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $formelements = customform::return_formelements($settings);
        $this->assertCount(2, (array)$formelements);
        $this->assertNull(customform::find_element_by_id((array)$formelements, 3));

        // The answers of the participant are untouched - including the one behind the
        // deleted element, which keeps its key because elementids are never reused.
        $stored = json_decode($DB->get_field('booking_answers', 'json', ['id' => $answerid]));
        $this->assertSame('Anna', $stored->condition_customform->customform_shorttext_1);
        $this->assertSame('a', $stored->condition_customform->customform_select_2);
        $this->assertSame('anna@example.com', $stored->condition_customform->customform_mail_3);
    }

    /**
     * T2: normalization of legacy json (also used by the upgrade step): elementid :=
     * position, counter := max + 1, idempotent, mixed states keep existing ids.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::add_elementids_to_condition
     */
    public function test_normalization_of_legacy_json(): void {
        $legacy = json_decode(json_encode([
            'id' => MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
            'name' => 'customform',
            'class' => 'mod_booking\\bo_availability\\conditions\\customform',
            'formsarray' => [
                1 => [
                    1 => ['formtype' => 'shorttext', 'label' => 'first'],
                    2 => ['formtype' => 'select', 'label' => 'second'],
                ],
            ],
        ]));

        $this->assertTrue(customform::add_elementids_to_condition($legacy));
        $this->assertSame(1, $legacy->formsarray->{1}->{1}->elementid);
        $this->assertSame(2, $legacy->formsarray->{1}->{2}->elementid);
        $this->assertSame(3, $legacy->nextelementid);

        // Idempotent: a second run changes nothing.
        $this->assertFalse(customform::add_elementids_to_condition($legacy));

        // Mixed state: existing elementids are kept, the counter respects them.
        $mixed = json_decode(json_encode([
            'formsarray' => [
                1 => [
                    1 => ['formtype' => 'shorttext', 'elementid' => 7],
                    2 => ['formtype' => 'select'],
                ],
            ],
        ]));
        $this->assertTrue(customform::add_elementids_to_condition($mixed));
        $this->assertSame(7, $mixed->formsarray->{1}->{1}->elementid);
        $this->assertSame(2, $mixed->formsarray->{1}->{2}->elementid);
        $this->assertSame(8, $mixed->nextelementid);
    }

    /**
     * The upgrade step migrates stored availability json of existing options.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::add_elementids_to_condition
     */
    public function test_migration_of_stored_option(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Migration test',
            'eventtype' => 'Test',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'showviews' => ['showall'],
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Migration option';
        $record->maxanswers = 5;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'shorttext';
        $record->bo_cond_customform_label_1_1 = 'first';
        $record->bo_cond_customform_value_1_1 = '';
        $option = $plugingenerator->create_option($record);

        // Strip the elementids again to simulate a pre-upgrade option.
        $availability = json_decode($DB->get_field('booking_options', 'availability', ['id' => $option->id]));
        foreach ($availability as $condition) {
            if (strpos($condition->class ?? '', 'conditions\\customform') === false) {
                continue;
            }
            unset($condition->nextelementid);
            foreach ($condition->formsarray as $form) {
                foreach ($form as $formelement) {
                    unset($formelement->elementid);
                }
            }
        }
        $DB->set_field('booking_options', 'availability', json_encode($availability), ['id' => $option->id]);

        // Apply the same normalization the upgrade step runs.
        $availability = json_decode($DB->get_field('booking_options', 'availability', ['id' => $option->id]));
        foreach ($availability as $condition) {
            if (strpos($condition->class ?? '', 'conditions\\customform') !== false) {
                $this->assertTrue(customform::add_elementids_to_condition($condition));
                $this->assertSame(1, $condition->formsarray->{1}->{1}->elementid);
                $this->assertSame(2, $condition->nextelementid);
            }
        }
    }

    /**
     * Answers stored under the old position keys must still resolve after reordering,
     * because the identifier is built from the stable elementid.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::get_customform_field_value
     * @covers \mod_booking\bo_availability\conditions\customform::get_element_identifier
     * @covers \mod_booking\bo_availability\conditions\customform::find_element_by_id
     */
    public function test_answer_lookup_survives_reorder(): void {
        // Saved condition after a reorder: element 2 (select "second") moved to position 1.
        $fromform = $this->get_base_fromform(true);
        foreach (['select', 'label', 'value', 'elementid'] as $shortname) {
            $key1 = 'bo_cond_customform_' . $shortname . '_1_1';
            $key2 = 'bo_cond_customform_' . $shortname . '_1_2';
            [$fromform->{$key1}, $fromform->{$key2}] = [$fromform->{$key2}, $fromform->{$key1}];
        }
        $condition = customform::instance()->get_condition_object_for_json($fromform);

        // The identifier of the moved element is still built from elementid 2.
        $movedelement = $condition->formsarray[1][1];
        $this->assertSame('customform_select_2', customform::get_element_identifier($movedelement));

        // An answer stored before the reorder under customform_select_2 is found again.
        $found = customform::find_element_by_id($condition->formsarray[1], 2);
        $this->assertNotNull($found);
        $this->assertSame('second', $found->label);
    }
}

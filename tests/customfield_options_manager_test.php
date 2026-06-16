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
 * Tests for the customfield options_manager.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\customfield\hierarchy_manager;

/**
 * Tests for the customfield options_manager.
 *
 * @covers \mod_booking\customfield\options_manager
 */
final class customfield_options_manager_test extends advanced_testcase {
    /**
     * Reset the booking custom field handler singleton (it survives the DB rollback).
     */
    protected function setUp(): void {
        parent::setUp();
        \mod_booking\customfield\booking_handler::reset_caches();
    }

    /**
     * Creating a field returns a manageable dynamicformat booking field.
     */
    public function test_create_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fieldid = hierarchy_manager::create_field('Competencies', 'competencies');
        $this->assertGreaterThan(0, $fieldid);

        $manageable = hierarchy_manager::get_manageable_fields();
        $this->assertArrayHasKey($fieldid, $manageable);

        $field = \core_customfield\field_controller::create($fieldid);
        $this->assertSame('dynamicformat', $field->get('type'));
        $this->assertTrue(hierarchy_manager::shortname_exists('competencies'));
    }

    /**
     * Save then load returns the same hierarchy and the generated SQL renders the options.
     */
    public function test_save_and_load_roundtrip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fieldid = hierarchy_manager::create_field('Competencies', 'competencies');

        $rows = [
            ['id' => 0, 'label' => 'Teaching', 'parentid' => 0],
            ['id' => 0, 'label' => 'Planning', 'parentid' => 0],
        ];
        hierarchy_manager::save($fieldid, $rows);

        $loaded = hierarchy_manager::load_rows($fieldid);
        $this->assertCount(2, $loaded);
        // New rows received stable ids.
        $this->assertNotEquals(0, $loaded[0]['id']);
        $this->assertNotEquals($loaded[0]['id'], $loaded[1]['id']);

        // Make "Planning" a child of "Teaching" and save again; ids must stay stable.
        $teachingid = $loaded[0]['id'];
        $planningid = $loaded[1]['id'];
        $loaded[1]['parentid'] = $teachingid;
        hierarchy_manager::save($fieldid, $loaded);

        $reloaded = hierarchy_manager::load_rows($fieldid);
        $this->assertSame($teachingid, $reloaded[0]['id']);
        $this->assertSame($planningid, $reloaded[1]['id']);
        $this->assertSame($teachingid, $reloaded[1]['parentid']);

        // The generated dynamicsql must render both options keyed by their stable ids.
        $field = \core_customfield\field_controller::create($fieldid);
        $options = \customfield_dynamicformat\field_controller::get_options_array($field);
        $this->assertArrayHasKey($teachingid, $options);
        $this->assertArrayHasKey($planningid, $options);
        $this->assertStringContainsString('Teaching', $options[$teachingid]);
    }

    /**
     * Deleted/blank rows are dropped and ids are never reused.
     */
    public function test_save_drops_deleted_and_keeps_ids_unique(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fieldid = hierarchy_manager::create_field('Competencies', 'competencies');

        hierarchy_manager::save($fieldid, [
            ['id' => 0, 'label' => 'Alpha', 'parentid' => 0],
            ['id' => 0, 'label' => 'Beta', 'parentid' => 0],
        ]);
        $loaded = hierarchy_manager::load_rows($fieldid);
        $alphaid = $loaded[0]['id'];
        $betaid = $loaded[1]['id'];

        // Delete Beta, add Gamma.
        hierarchy_manager::save($fieldid, [
            ['id' => $alphaid, 'label' => 'Alpha', 'parentid' => 0],
            ['id' => $betaid, 'label' => 'Beta', 'parentid' => 0, 'delete' => true],
            ['id' => 0, 'label' => 'Gamma', 'parentid' => 0],
            ['id' => 0, 'label' => '', 'parentid' => 0],
        ]);

        $loaded = hierarchy_manager::load_rows($fieldid);
        $this->assertCount(2, $loaded);
        $labels = array_column($loaded, 'label');
        $this->assertContains('Alpha', $labels);
        $this->assertContains('Gamma', $labels);
        // Gamma must not reuse Beta's id.
        $gamma = array_values(array_filter($loaded, fn($r) => $r['label'] === 'Gamma'))[0];
        $this->assertNotSame($betaid, $gamma['id']);
    }

    /**
     * Saving a non mod_booking field is rejected.
     */
    public function test_save_rejects_non_booking_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category(); // Defaults to core_course/course.
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'dynamicformat',
        ]);

        $this->expectException(\moodle_exception::class);
        hierarchy_manager::save((int) $field->get('id'), [
            ['id' => 0, 'label' => 'X', 'parentid' => 0],
        ]);
    }

    /**
     * Saving a non dynamicformat booking field is rejected.
     */
    public function test_save_rejects_non_dynamic_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category([
            'component' => 'mod_booking',
            'area' => 'booking',
        ]);
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'select',
            'configdata' => ['options' => "a\nb"],
        ]);

        $this->expectException(\moodle_exception::class);
        hierarchy_manager::save((int) $field->get('id'), [
            ['id' => 0, 'label' => 'X', 'parentid' => 0],
        ]);
    }

    /**
     * A direct self-parent is reported as a cycle.
     */
    public function test_validate_rejects_self_parent(): void {
        $errors = hierarchy_manager::validate_rows([
            ['id' => 1, 'label' => 'A', 'parentid' => 1],
        ]);
        $this->assertArrayHasKey(0, $errors);
    }

    /**
     * Two rows made parents of each other are rejected (neither is a top level option).
     */
    public function test_validate_rejects_indirect_cycle(): void {
        $errors = hierarchy_manager::validate_rows([
            ['id' => 1, 'label' => 'A', 'parentid' => 2],
            ['id' => 2, 'label' => 'B', 'parentid' => 1],
        ]);
        $this->assertNotEmpty($errors);
    }

    /**
     * A parent that does not exist is reported.
     */
    public function test_validate_rejects_unknown_parent(): void {
        $errors = hierarchy_manager::validate_rows([
            ['id' => 1, 'label' => 'A', 'parentid' => 99],
        ]);
        $this->assertArrayHasKey(0, $errors);
    }

    /**
     * A valid single level hierarchy (top level options with direct children) produces no errors.
     */
    public function test_validate_accepts_valid_hierarchy(): void {
        $errors = hierarchy_manager::validate_rows([
            ['id' => 1, 'label' => 'A', 'parentid' => 0],
            ['id' => 2, 'label' => 'B', 'parentid' => 1],
            ['id' => 3, 'label' => 'C', 'parentid' => 1],
            ['id' => 4, 'label' => 'D', 'parentid' => 0],
        ]);
        $this->assertSame([], $errors);
    }

    /**
     * Nesting deeper than one level (a grandchild) is rejected.
     */
    public function test_validate_rejects_grandchild(): void {
        $errors = hierarchy_manager::validate_rows([
            ['id' => 1, 'label' => 'A', 'parentid' => 0],
            ['id' => 2, 'label' => 'B', 'parentid' => 1],
            ['id' => 3, 'label' => 'C', 'parentid' => 2],
        ]);
        // Only the grandchild (row index 2) is too deep; the parent/child pair is fine.
        $this->assertArrayHasKey(2, $errors);
        $this->assertArrayNotHasKey(0, $errors);
        $this->assertArrayNotHasKey(1, $errors);
    }

    /**
     * Duplicate ids are reported.
     */
    public function test_validate_rejects_duplicate_ids(): void {
        $errors = hierarchy_manager::validate_rows([
            ['id' => 1, 'label' => 'A', 'parentid' => 0],
            ['id' => 1, 'label' => 'Another A', 'parentid' => 0],
        ]);
        $this->assertNotEmpty($errors);
    }
}

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

use context_module;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;

/**
 * Price-category grounding + fallback canonicalizer for the constructor (thread 593).
 *
 * Two-layer fix: (1) get_dynamic_construction_hints injects the site's REAL price categories
 * into the constructor prompt so it stops guessing an array-of-labels; (2) normalize_prices_input
 * canonicalizes any residual list-of-objects shape to the canonical {identifier: price}.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class agent_construction_price_grounding_test extends \advanced_testcase {
    /**
     * Grounding: the hint names the REAL category identifiers and labels + the object-shape rule.
     */
    public function test_dynamic_hints_name_the_real_price_categories(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_categories();

        $hints = (new create_selflearning_option_skill())
            ->get_dynamic_construction_hints((int)\context_system::instance()->id, (int)get_admin()->id);
        $guidance = implode("\n", (array)($hints['guidance'] ?? []));

        $this->assertStringContainsString('default (Standardpreis)', $guidance);
        $this->assertStringContainsString('student (Studierende)', $guidance);
        $this->assertStringContainsString('JSON object', $guidance);
        $this->assertStringContainsString('NEVER use an array', $guidance);
    }

    /**
     * With no categories configured, the hint tells the model NOT to send a prices field.
     */
    public function test_dynamic_hints_without_categories_suppress_prices(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $hints = (new create_option_skill())
            ->get_dynamic_construction_hints((int)\context_system::instance()->id, (int)get_admin()->id);
        $this->assertStringContainsString(
            'do not send a prices field',
            implode("\n", (array)($hints['guidance'] ?? []))
        );
    }

    /**
     * Fallback canonicalizer: a list of price objects keyed by category identifier becomes the
     * canonical map (the residual shape after grounding).
     */
    public function test_price_object_list_by_category_canonicalizes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_categories();

        $this->assertSame(
            ['default' => 30.0, 'student' => 20.0],
            booking_skill_support::normalize_prices_input_for_execute([
                ['price' => 30, 'category' => 'default'],
                ['price' => 20, 'category' => 'student'],
            ])
        );
    }

    /**
     * Fallback canonicalizer: a label that equals a category NAME resolves to its identifier;
     * a label matching nothing is KEPT so the unknown-category clarification lists the real
     * categories — a price is never silently dropped.
     */
    public function test_price_object_list_by_label_resolves_or_clarifies(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_categories();

        // The German word "Studierende" is the student category's NAME → resolves to identifier "student".
        $this->assertSame(
            ['student' => 20.0],
            booking_skill_support::normalize_prices_input_for_execute([['price' => 20, 'label' => 'Studierende']])
        );

        // An invented label maps to no category → kept as key → validation reports it unknown
        // (with the category list), never dropped.
        $validation = booking_skill_support::validate_prices_input([
            'prices' => [['price' => 30, 'label' => 'Frühbucher']],
        ]);
        $this->assertNotEmpty(
            (array)$validation['errors'] + (array)$validation['ambiguities'],
            'An unmappable label must clarify, not pass silently.'
        );
    }

    /**
     * End-to-end preflight: the labelled array from thread 593 lands in the prepared input as
     * the canonical object, so the confirmation preview and execute both see {default, student}.
     */
    public function test_preflight_prepares_canonical_prices_from_category_array(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cmid, $contextid] = $this->seed_instance_and_categories();
        set_config('selflearningcourseactive', 1, 'booking');

        $result = (new create_selflearning_option_skill())->preflight(
            [
                'text' => 'Cicero Selbstlernkurs',
                'duration' => 30 * DAYSECS,
                'prices' => [
                    ['price' => 30, 'category' => 'default'],
                    ['price' => 20, 'category' => 'student'],
                ],
            ],
            $contextid,
            (int)get_admin()->id
        );

        $this->assertContains((string)$result->status, ['pass', 'soft_block'], json_encode($result->issues));
        $this->assertSame(
            ['default' => 30.0, 'student' => 20.0],
            array_map('floatval', (array)($result->preparedinput['prices'] ?? [])),
            'The prepared input must carry the canonical prices object.'
        );
    }

    /**
     * Seed default + student price categories.
     *
     * @return void
     */
    private function seed_categories(): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        foreach ([['default', 'Standardpreis', 30], ['student', 'Studierende', 20]] as $i => $c) {
            $gen->create_pricecategory((object)[
                'ordernum' => $i + 1,
                'identifier' => $c[0],
                'name' => $c[1],
                'defaultvalue' => $c[2],
            ]);
        }
    }

    /**
     * Seed a booking instance plus the categories; return [cmid, modulecontextid].
     *
     * @return array{0:int,1:int}
     */
    private function seed_instance_and_categories(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Probe booking',
            'bookingmanager' => 'admin',
            'eventtype' => 'Test',
        ]);
        $this->seed_categories();
        return [(int)$booking->cmid, (int)context_module::instance((int)$booking->cmid)->id];
    }
}

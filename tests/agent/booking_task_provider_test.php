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
 * Tests for the booking task provider (schema validation).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\agent\booking\booking_task_provider;

/**
 * Tests for the booking domain task provider.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_task_provider_test extends advanced_testcase {

    /** @var booking_task_provider */
    private booking_task_provider $provider;

    /**
     * Set up test provider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->provider = new booking_task_provider();
    }

    /**
     * Test that the provider declares the expected task names.
     */
    public function test_get_task_names(): void {
        $names = $this->provider->get_task_names();
        $this->assertContains('booking.create_option', $names);
        $this->assertContains('booking.update_option', $names);
    }

    /**
     * Test schema for create_option has required fields.
     */
    public function test_create_option_schema_has_text_field(): void {
        $schema = $this->provider->get_task_schema('booking.create_option');
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('text', $schema['properties']);
        $this->assertEquals(true, $schema['properties']['text']['required'] ?? false);
    }

    /**
     * Test schema for update_option has optionid field.
     */
    public function test_update_option_schema_has_optionid(): void {
        $schema = $this->provider->get_task_schema('booking.update_option');
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('optionid', $schema['properties']);
    }

    /**
     * Test create_option validation fails without text.
     */
    public function test_create_option_validation_fails_without_text(): void {
        $result = $this->provider->validate('booking.create_option', [], 1);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test create_option validation passes with text.
     */
    public function test_create_option_validation_passes_with_text(): void {
        $result = $this->provider->validate('booking.create_option', ['text' => 'My Option'], 1);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['ambiguities']);
    }

    /**
     * Test update_option validation produces ambiguity without optionid.
     */
    public function test_update_option_validation_ambiguity_without_optionid(): void {
        $result = $this->provider->validate('booking.update_option', ['location' => 'Room A'], 1);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['ambiguities']);
    }

    /**
     * Test that invalid datetime produces an error.
     */
    public function test_invalid_datetime_produces_error(): void {
        $result = $this->provider->validate('booking.create_option', [
            'text'            => 'Test',
            'coursestarttime' => 'not-a-date',
        ], 1);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test that valid ISO 8601 datetime passes validation.
     */
    public function test_valid_iso_datetime_passes(): void {
        $result = $this->provider->validate('booking.create_option', [
            'text'            => 'Test',
            'coursestarttime' => '2025-06-01T09:00:00',
            'courseendtime'   => '2025-06-01T17:00:00',
        ], 1);
        $this->assertTrue($result['valid']);
    }
}

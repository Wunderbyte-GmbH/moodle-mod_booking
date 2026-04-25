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
use mod_booking\local\wbagent\booking\booking_task_provider;

/**
 * Tests for the booking domain task provider.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_task_provider_test extends advanced_testcase {
    /** @var booking_task_provider */
    private booking_task_provider $provider;

    /** @var \stdClass */
    private $booking;

    /**
     * Set up test provider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Provider Test Booking',
            'eventtype' => 'Webinar',
            'bookingmanager' => 'admin',
        ]);

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
        $this->assertArrayHasKey('invisible', $schema['properties']);
        $this->assertArrayHasKey('visibility', $schema['properties']);
    }

    /**
     * Test create_option validation fails without text.
     */
    public function test_create_option_validation_fails_without_text(): void {
        $result = $this->provider->validate('booking.create_option', [], (int)$this->booking->cmid);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test create_option validation passes with text.
     */
    public function test_create_option_validation_passes_with_text(): void {
        $result = $this->provider->validate('booking.create_option', [
            'text' => 'My Option ' . uniqid('', true),
        ], (int)$this->booking->cmid);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['ambiguities']);
    }

    /**
     * Test update_option validation produces ambiguity without optionid.
     */
    public function test_update_option_validation_ambiguity_without_optionid(): void {
        $result = $this->provider->validate('booking.update_option', ['location' => 'Room A'], (int)$this->booking->cmid);
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
        ], (int)$this->booking->cmid);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test that valid ISO 8601 datetime passes validation.
     */
    public function test_valid_iso_datetime_passes(): void {
        $result = $this->provider->validate('booking.create_option', [
            'text'            => 'Test',
            'coursestarttime' => '2036-06-01T09:00:00',
            'courseendtime'   => '2036-06-01T17:00:00',
        ], (int)$this->booking->cmid);
        $this->assertTrue($result['valid']);
    }

    /**
     * Visibility aliases should validate for update_option.
     */
    public function test_update_option_visibility_alias_passes_validation(): void {
        $result = $this->provider->validate('booking.update_option', [
            'optionquery' => 'last option',
            'visibility' => 'directlink',
        ], (int)$this->booking->cmid);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Invalid visibility values should be rejected.
     */
    public function test_update_option_visibility_rejects_invalid_value(): void {
        $result = $this->provider->validate('booking.update_option', [
            'optionquery' => 'last option',
            'visibility' => 'semi-visible',
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * optiondatesmode must be append or replace.
     */
    public function test_update_option_optiondatesmode_rejects_invalid_value(): void {
        $result = $this->provider->validate('booking.update_option', [
            'optionquery' => 'last option',
            'optiondatesmode' => 'merge',
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * bulk_update_options schema exposes all target selector fields.
     */
    public function test_bulk_update_schema_has_target_fields(): void {
        $schema = $this->provider->get_task_schema('booking.bulk_update_options');
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('optionids', $schema['properties']);
        $this->assertArrayHasKey('optionquery', $schema['properties']);
        $this->assertArrayHasKey('apply_to_all', $schema['properties']);
    }

    /**
     * bulk_update_options must fail when no target selector is supplied.
     */
    public function test_bulk_update_validation_requires_target(): void {
        $result = $this->provider->validate('booking.bulk_update_options', [
            'maxanswers' => 10,
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * bulk_update_options must reject bookusersquery.
     */
    public function test_bulk_update_validation_forbids_bookusersquery(): void {
        $result = $this->provider->validate('booking.bulk_update_options', [
            'apply_to_all' => true,
            'bookusersquery' => 'john',
            'maxanswers' => 8,
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $detail = implode(' ', array_merge($result['errors'] ?? [], $result['ambiguities'] ?? []));
        $this->assertStringContainsString('bookusersquery', $detail);
    }

    /**
     * bulk_update_options must reject option ids that are not part of this booking instance.
     */
    public function test_bulk_update_validation_rejects_foreign_optionid(): void {
        $result = $this->provider->validate('booking.bulk_update_options', [
            'optionids' => [999999],
            'maxanswers' => 8,
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
}

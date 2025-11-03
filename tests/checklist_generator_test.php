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

use mod_booking\checklist\checklist_generator;
use mod_booking\booking_option;
use advanced_testcase;
use ReflectionClass;

/**
 * Class handling tests for checklist.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checklist_generator_test extends advanced_testcase {
    /**
     * Test HTML generation with placeholder replacement for PDF.
     * @covers \mod_booking\classes\checklist\
     */
    public function test_html_generation_for_pdf(): void {
        $this->resetAfterTest();

        // Mock the booking_option class.
        $mockoption = $this->createMock(booking_option::class);
        $mockoption->option = $this->get_standard_option();
        $mockoption->expects($this->any())
            ->method('return_array_of_sessions')
            ->willReturn($this->get_standard_sessions());

        // Instantiate checklist_generator with the mocked option.
        $generator = new checklist_generator($mockoption);

        // Access the private get_placeholder_replacements method using reflection.
        $refgenerator = new ReflectionClass(checklist_generator::class);
        $method = $refgenerator->getMethod('get_placeholder_replacements');
        $method->setAccessible(true);

        // Validate placeholder replacements.
        $replacements = $method->invoke($generator);

        // Assert each placeholder individually.
        $this->assertArrayHasKey('[[booking_id]]', $replacements);
        $this->assertSame(1, $replacements['[[booking_id]]']);

        $this->assertArrayHasKey('[[booking_text]]', $replacements);
        $this->assertSame('Sample Booking Text', $replacements['[[booking_text]]']);

        $this->assertArrayHasKey('[[institution]]', $replacements);
        $this->assertSame('Sample Institution', $replacements['[[institution]]']);

        $this->assertArrayHasKey('[[location]]', $replacements);
        $this->assertSame('Sample Location', $replacements['[[location]]']);

        $this->assertArrayHasKey('[[coursestarttime]]', $replacements);
        $this->assertSame(userdate($mockoption->option->coursestarttime), $replacements['[[coursestarttime]]']);

        $this->assertArrayHasKey('[[courseendtime]]', $replacements);
        $this->assertSame(userdate($mockoption->option->courseendtime), $replacements['[[courseendtime]]']);

        $this->assertArrayHasKey('[[description]]', $replacements);
        $this->assertSame(format_text($mockoption->option->description, FORMAT_HTML), $replacements['[[description]]']);

        $this->assertArrayHasKey('[[address]]', $replacements);
        $this->assertSame('123 Sample Street', $replacements['[[address]]']);

        $this->assertArrayHasKey('[[teachers]]', $replacements);
        $this->assertSame('', $replacements['[[teachers]]']); // Assuming no teachers were set.

        $this->assertArrayHasKey('[[titleprefix]]', $replacements);
        $this->assertSame('', $replacements['[[titleprefix]]']);

        $this->assertArrayHasKey('[[courseid]]', $replacements);
        $this->assertSame('', $replacements['[[courseid]]']);

        $this->assertArrayHasKey('[[course_url]]', $replacements);
        $this->assertSame('', $replacements['[[course_url]]']);

        $this->assertArrayHasKey('[[contact]]', $replacements);
        $this->assertSame('Not specified', $replacements['[[contact]]']);

        $dates = $replacements['[[dates]]'];
        $this->assertStringContainsString('2023-01-01', $dates);
        $this->assertStringContainsString('2023-01-02', $dates);
    }

    /**
     * A helper function to return a standard booking option object.
     */
    private function get_standard_option() {
        return (object) [
            'id' => 1,
            'text' => 'Sample Booking Text',
            'maxanswers' => 10,
            'institution' => 'Sample Institution',
            'location' => 'Sample Location',
            'coursestarttime' => time(),
            'courseendtime' => time() + 3600,
            'description' => 'Course description here.',
            'address' => '123 Sample Street',
        ];
    }

    /**
     * A helper function to provide standard session dates.
     */
    private function get_standard_sessions() {
        return [
            ['datestring' => '2023-01-01'],
            ['datestring' => '2023-01-02'],
        ];
    }
}

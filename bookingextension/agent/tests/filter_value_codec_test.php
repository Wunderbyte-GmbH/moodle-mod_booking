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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\reportbuilder\filter_value_codec;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\text;

/**
 * Tests for the structural encoding of condition values into core's flat filter form.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\filter_value_codec
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filter_value_codec_test extends advanced_testcase {
    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        report_source_catalog_service::reset_caches();
    }

    /**
     * A condition of the users source by identifier.
     *
     * @param string $identifier
     * @return \core_reportbuilder\local\report\filter
     */
    private function condition(string $identifier): \core_reportbuilder\local\report\filter {
        $instance = (new report_source_catalog_service())->instantiate(self::USERS_SOURCE);
        $condition = $instance->get_condition($identifier);
        $this->assertNotNull($condition, $identifier);
        return $condition;
    }

    /**
     * Text: operator by constant name (any case) or integer; value as string.
     */
    public function test_text_operator_and_value(): void {
        global $USER;
        $codec = new filter_value_codec();
        $condition = $this->condition('user:fullname');

        $encoded = $codec->encode($condition, ['operator' => 'contains', 'value' => 'Ann'], (int)$USER->id);
        $this->assertSame('', $encoded['error']);
        $this->assertSame([
            'user:fullname_operator' => text::CONTAINS,
            'user:fullname_value' => 'Ann',
        ], $encoded['values']);

        $byint = $codec->encode($condition, ['operator' => text::IS_EQUAL_TO], (int)$USER->id);
        $this->assertSame(text::IS_EQUAL_TO, $byint['values']['user:fullname_operator']);
    }

    /**
     * An unknown operator is an error that carries the allowed operator keys; no value is guessed.
     */
    public function test_unknown_operator_lists_allowed(): void {
        global $USER;
        $codec = new filter_value_codec();
        $encoded = $codec->encode($this->condition('user:fullname'), ['operator' => 'SOMETHING_ELSE'], (int)$USER->id);
        $this->assertSame(filter_value_codec::ERROR_OPERATOR, $encoded['error']);
        $this->assertSame([], $encoded['values']);
        $this->assertContains('IS_EQUAL_TO', $encoded['allowed']);
        $this->assertNotContains('DATE_UNIT_DAY', $encoded['allowed']);

        $missing = $codec->encode($this->condition('user:fullname'), ['value' => 'x'], (int)$USER->id);
        $this->assertSame(filter_value_codec::ERROR_OPERATOR, $missing['error']);
    }

    /**
     * Date: a relative operator takes a count of units, a range takes ISO dates in the user's zone,
     * units by name or constant.
     */
    public function test_date_relative_and_range(): void {
        global $USER;
        $codec = new filter_value_codec();
        $condition = $this->condition('user:timecreated');

        $relative = $codec->encode($condition, ['operator' => 'DATE_LAST', 'value' => 3, 'unit' => 'month'], (int)$USER->id);
        $this->assertSame('', $relative['error']);
        $this->assertSame(date::DATE_LAST, $relative['values']['user:timecreated_operator']);
        $this->assertSame(3, $relative['values']['user:timecreated_value']);
        $this->assertSame(date::DATE_UNIT_MONTH, $relative['values']['user:timecreated_unit']);

        $range = $codec->encode($condition, [
            'operator' => 'DATE_RANGE',
            'value' => '2026-01-01',
            'value_to' => '2026-06-30 23:59',
        ], (int)$USER->id);
        $this->assertSame('', $range['error']);
        $this->assertSame(date::DATE_RANGE, $range['values']['user:timecreated_operator']);
        $this->assertArrayHasKey('user:timecreated_from', $range['values']);
        $this->assertArrayHasKey('user:timecreated_to', $range['values']);
        $this->assertLessThan($range['values']['user:timecreated_to'], $range['values']['user:timecreated_from']);

        $badunit = $codec->encode($condition, ['operator' => 'DATE_LAST', 'value' => 1, 'unit' => 'fortnight'], (int)$USER->id);
        $this->assertSame(filter_value_codec::ERROR_VALUE, $badunit['error']);
        $this->assertContains('DATE_UNIT_DAY', $badunit['allowed']);

        $baddate = $codec->encode($condition, ['operator' => 'DATE_RANGE', 'value' => 'next monday'], (int)$USER->id);
        $this->assertSame(filter_value_codec::ERROR_VALUE, $baddate['error'], 'no natural-language dates');
    }

    /**
     * Boolean: operator only; a list of ids for selector-style filters.
     */
    public function test_boolean_and_lists(): void {
        global $USER;
        $codec = new filter_value_codec();

        $suspended = $codec->encode($this->condition('user:suspended'), ['operator' => 'NOT_CHECKED'], (int)$USER->id);
        $this->assertSame(['user:suspended_operator' => boolean_select::NOT_CHECKED], $suspended['values']);

        $map = $codec->operator_map(date::class);
        $this->assertSame(date::DATE_LAST, $map['DATE_LAST']);
        $this->assertArrayNotHasKey('DATE_PREVIOUS', $map, 'aliases collapse');
        $this->assertArrayNotHasKey('DATE_UNIT_DAY', $map);
    }
}

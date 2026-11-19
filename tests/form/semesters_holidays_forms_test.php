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
 * Tests for the semesters and holidays forms on semesters.php.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use DOMDocument;
use DOMXPath;
use mod_booking\form\dynamicholidaysform;
use mod_booking\form\dynamicsemestersform;
use mod_booking\tests\booking_advanced_testcase;

/**
 * Tests for the semesters and holidays forms on semesters.php.
 *
 * Both forms show one repeated block with two date selectors per record.
 * Memory used to grow quadratically with the number of records, so the page
 * broke with a fatal error with about 50 holidays (Wunderbyte-GmbH/moodle-mod_booking#1675).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class semesters_holidays_forms_test extends booking_advanced_testcase {
    /** @var int Number of records for the memory tests. */
    private const MANY = 100;

    /**
     * Upper bound for the memory used to build and render a form with self::MANY records.
     * Fixed code needs about 55 MB here. Before the fix, 20 records already needed 150 MB
     * and 100 records ran into the memory limit.
     * @var int
     */
    private const MAXMEMORY = 128 * 1024 * 1024;

    /**
     * The holidays form can be built and rendered with many holidays.
     *
     * @covers \mod_booking\form\dynamicholidaysform::definition
     */
    public function test_holidaysform_with_many_holidays(): void {
        global $DB;

        $this->setAdminUser();

        $holidays = [];
        for ($i = 0; $i < self::MANY; $i++) {
            $start = make_timestamp(2020, 1, 1 + $i * 7);
            $holidays[] = (object) ['startdate' => $start, 'enddate' => $start, 'name' => "Holiday $i"];
        }
        $DB->insert_records('booking_holidays', $holidays);

        [$html, $memory] = $this->build_and_render(dynamicholidaysform::class);

        $this->assertEquals(self::MANY, substr_count($html, 'name="holidayname['));
        $this->assertStringContainsString('name="holidaystart[' . (self::MANY - 1) . '][day]"', $html);
        $this->assert_memory_below_limit(
            $memory,
            'Memory for the holidays form must not grow quadratically with the number of holidays.'
        );
    }

    /**
     * The semesters form can be built and rendered with many semesters.
     *
     * @covers \mod_booking\form\dynamicsemestersform::definition
     */
    public function test_semestersform_with_many_semesters(): void {
        global $DB;

        $this->setAdminUser();

        $semesters = [];
        for ($i = 0; $i < self::MANY; $i++) {
            $semesters[] = (object) [
                'identifier' => "sem$i",
                'name' => "Semester $i",
                'startdate' => make_timestamp(2000 + $i, 3, 1),
                'enddate' => make_timestamp(2000 + $i, 6, 30),
            ];
        }
        $DB->insert_records('booking_semesters', $semesters);

        [$html, $memory] = $this->build_and_render(dynamicsemestersform::class);

        $this->assertEquals(self::MANY, substr_count($html, 'name="semesteridentifier['));
        $this->assertStringContainsString('name="semesterstart[' . (self::MANY - 1) . '][day]"', $html);
        $this->assert_memory_below_limit(
            $memory,
            'Memory for the semesters form must not grow quadratically with the number of semesters.'
        );
    }

    /**
     * Saving the holidays form unchanged keeps all stored dates, also for holidays without end date.
     *
     * @covers \mod_booking\form\dynamicholidaysform::set_data_for_dynamic_submission
     * @covers \mod_booking\form\dynamicholidaysform::process_dynamic_submission
     */
    public function test_holidaysform_round_trip_keeps_dates(): void {
        global $DB;

        $this->setAdminUser();

        // Date selectors store the start of the day in the timezone of the user.
        $DB->insert_records('booking_holidays', [
            // Several days.
            (object) ['startdate' => make_timestamp(2026, 12, 24), 'enddate' => make_timestamp(2027, 1, 6), 'name' => 'Christmas'],
            // One day only: no end date in the form, enddate is the same as startdate.
            (object) ['startdate' => make_timestamp(2026, 5, 1), 'enddate' => make_timestamp(2026, 5, 1), 'name' => 'Labour Day'],
            (object) ['startdate' => make_timestamp(2026, 7, 1), 'enddate' => make_timestamp(2026, 8, 31), 'name' => 'Summer'],
        ]);
        $before = $DB->get_records('booking_holidays', null, 'id');

        $this->submit_rendered_form(dynamicholidaysform::class);

        $this->assertEquals($before, $DB->get_records('booking_holidays', null, 'id'));
    }

    /**
     * A holiday without name (the column allows null) is shown with an empty name and causes no PHP deprecation.
     *
     * @covers \mod_booking\form\dynamicholidaysform::set_data_for_dynamic_submission
     */
    public function test_holidaysform_holiday_without_name(): void {
        global $DB;

        $this->setAdminUser();

        $start = make_timestamp(2026, 5, 1);
        $DB->insert_record('booking_holidays', (object) ['startdate' => $start, 'enddate' => $start, 'name' => null]);

        // PHPUnit does not turn deprecations into errors here, so collect the ones raised by the form.
        $deprecations = [];
        set_error_handler(
            function (int $errno, string $errstr, string $errfile, int $errline) use (&$deprecations): bool {
                if (str_ends_with($errfile, 'dynamicholidaysform.php')) {
                    $deprecations[] = "$errstr (line $errline)";
                    return true;
                }
                return false;
            },
            E_DEPRECATED
        );
        try {
            [$html] = $this->build_and_render(dynamicholidaysform::class);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame('', $this->get_form_values($html)['holidayname'][0]);
    }

    /**
     * Saving the semesters form unchanged keeps all stored dates.
     *
     * @covers \mod_booking\form\dynamicsemestersform::set_data_for_dynamic_submission
     * @covers \mod_booking\form\dynamicsemestersform::process_dynamic_submission
     */
    public function test_semestersform_round_trip_keeps_dates(): void {
        global $DB;

        $this->setAdminUser();

        $DB->insert_records('booking_semesters', [
            (object) [
                'identifier' => 'ws2026',
                'name' => 'Winter 2026',
                'startdate' => make_timestamp(2026, 10, 1),
                'enddate' => make_timestamp(2027, 1, 31),
            ],
            (object) [
                'identifier' => 'ss2027',
                'name' => 'Summer 2027',
                'startdate' => make_timestamp(2027, 3, 1),
                'enddate' => make_timestamp(2027, 6, 30),
            ],
        ]);
        $before = $DB->get_records('booking_semesters', null, 'id');

        $this->submit_rendered_form(dynamicsemestersform::class);

        $this->assertEquals($before, $DB->get_records('booking_semesters', null, 'id'));
    }

    /**
     * Build a form like semesters.php does and return the HTML and the memory used for it.
     *
     * The memory is the highest memory use while the form is built and rendered, minus the memory used before.
     * Only PHP 8.2+ can reset the highest memory use (memory_reset_peak_usage()). Without the reset,
     * memory_get_peak_usage() can return the highest point of an earlier test, so the value would be too big
     * and the test could fail although the form is fine. So on PHP 8.1 the memory is not measured (null).
     *
     * @param string $formclass dynamicholidaysform or dynamicsemestersform
     * @return array [string $html, int|null $memory in bytes, null on PHP 8.1]
     */
    private function build_and_render(string $formclass): array {
        $canmeasure = function_exists('memory_reset_peak_usage');
        if ($canmeasure) {
            memory_reset_peak_usage();
        }
        $start = memory_get_usage();

        $form = new $formclass();
        $form->set_data_for_dynamic_submission();
        $html = $form->render();

        $memory = $canmeasure ? memory_get_peak_usage() - $start : null;
        return [$html, $memory];
    }

    /**
     * Check that the memory used for a form is below self::MAXMEMORY, if it could be measured.
     *
     * On PHP 8.1 the memory is not measured (see build_and_render()) and only this check is skipped.
     * A quadratic growth still shows there: with self::MANY records the old code runs out of memory.
     *
     * @param int|null $memory memory in bytes from build_and_render(), null if not measured
     * @param string $message
     * @return void
     */
    private function assert_memory_below_limit(?int $memory, string $message): void {
        if ($memory === null) {
            return;
        }
        $this->assertLessThan(self::MAXMEMORY, $memory, $message);
    }

    /**
     * Render the form with the stored data, then submit all its values like a browser and save.
     *
     * @param string $formclass dynamicholidaysform or dynamicsemestersform
     * @return void
     */
    private function submit_rendered_form(string $formclass): void {
        [$html] = $this->build_and_render($formclass);
        $values = $this->get_form_values($html);

        $submitdata = $formclass::mock_ajax_submit($values);
        $form = new $formclass(null, null, 'post', '', [], true, $submitdata, true);
        $this->assertTrue($form->is_validated(), 'The unchanged form must be valid.');
        $form->process_dynamic_submission();
    }

    /**
     * Get the values a browser would submit for the rendered form (submit buttons are not clicked).
     *
     * @param string $html
     * @return array nested array, like $_POST
     */
    private function get_form_values(string $html): array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $pairs = [];
        foreach ($xpath->query('//input[@name]') as $input) {
            $type = strtolower($input->getAttribute('type'));
            if ($type === 'submit' || ($type === 'checkbox' && !$input->hasAttribute('checked'))) {
                continue;
            }
            $pairs[] = urlencode($input->getAttribute('name')) . '=' . urlencode($input->getAttribute('value'));
        }
        foreach ($xpath->query('//select[@name]') as $select) {
            $selected = $xpath->query('.//option[@selected]', $select)->item(0)
                ?? $xpath->query('.//option', $select)->item(0);
            $pairs[] = urlencode($select->getAttribute('name')) . '=' . urlencode($selected->getAttribute('value'));
        }

        parse_str(implode('&', $pairs), $values);
        return $values;
    }
}

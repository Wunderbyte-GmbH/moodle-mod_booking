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
 * Tests of the form element "sortable select".
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form\element;

use advanced_testcase;
use core\url;
use mod_booking\fixtures\sortableselect_test_form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/booking/tests/fixtures/sortableselect_test_form.php');

/**
 * Tests of the form element "sortable select".
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\form\element\sortableselect
 */
final class sortableselect_test extends advanced_testcase {
    /**
     * Returns the form after a simulated submission.
     *
     * @param array $submitted the submitted data of the form
     * @param array $customdata custom data of the form
     * @return sortableselect_test_form
     */
    private function submit(array $submitted, array $customdata = []): sortableselect_test_form {
        sortableselect_test_form::mock_submit($submitted);
        return new sortableselect_test_form(null, $customdata);
    }

    /**
     * Returns the HTML of a form that was not submitted.
     *
     * @param array $customdata custom data of the form
     * @return string
     */
    private function render(array $customdata): string {
        global $PAGE;
        $PAGE->set_url(new url('/mod/booking/tests/sortableselect.php'));
        return (new sortableselect_test_form(null, $customdata))->render();
    }

    /**
     * Returns the values of the inputs of the element in the order of the HTML.
     *
     * @param string $html
     * @return array [values of the inputs "columns", values of the inputs "columns[]"]
     */
    private function get_submitted_inputs(string $html): array {
        preg_match_all('/<input type="hidden" name="columns" value="([^"]*)"/', $html, $single);
        preg_match_all('/<input type="hidden" name="columns\[\]" value="([^"]*)"/', $html, $list);
        return [$single[1], $list[1]];
    }

    /**
     * The value is the list of the submitted values in the submitted order, not in the order of the options.
     */
    public function test_submitted_order_is_the_value(): void {
        $this->resetAfterTest();

        $form = $this->submit(['columns' => ['c', 'a', 'b']]);
        $this->assertTrue($form->is_validated());
        $this->assertSame(['c', 'a', 'b'], $form->get_data()->columns);

        $form = $this->submit(['columns' => ['b', 'c']]);
        $this->assertSame(['b', 'c'], $form->get_data()->columns);
    }

    /**
     * An empty list submits only the hidden input of the element: the value is an empty array, also when
     * the element has a default value. Without that input nothing would be submitted.
     */
    public function test_empty_list_is_an_empty_array(): void {
        $this->resetAfterTest();

        $form = $this->submit(['columns' => sortableselect::EMPTYVALUE], ['defaults' => ['a', 'b']]);
        $this->assertTrue($form->is_validated());
        $this->assertSame([], $form->get_data()->columns);
    }

    /**
     * Duplicates and empty entries are removed, the first position of a value counts.
     */
    public function test_duplicates_and_empty_entries_are_removed(): void {
        $this->resetAfterTest();

        $form = $this->submit(['columns' => ['b', '', 'a', 'b', ' a ']]);
        $this->assertSame(['b', 'a'], $form->get_data()->columns);

        $this->assertSame(['b', 'a'], sortableselect::clean_values('b, a,,b'));
        $this->assertSame([], sortableselect::clean_values(null));
        $this->assertSame([], sortableselect::clean_values(''));
        $this->assertSame([], sortableselect::clean_values(sortableselect::EMPTYVALUE));
        $this->assertSame(['1', '2'], sortableselect::clean_values([1, 2, ['nested']]));
    }

    /**
     * A value that is no option is rejected on the server with an error message at the element.
     */
    public function test_unknown_value_is_rejected(): void {
        $this->resetAfterTest();

        $form = $this->submit(['columns' => ['a', 'evil<script>', 'b']]);
        $this->assertFalse($form->is_validated());
        $this->assertNull($form->get_data());

        global $PAGE;
        $PAGE->set_url(new url('/mod/booking/tests/sortableselect.php'));
        $html = $form->render();
        $this->assertStringContainsString(get_string('sortableselect:invalid', 'mod_booking', 'evil'), $html);
        // The form is shown again with the valid values only.
        [, $list] = $this->get_submitted_inputs($html);
        $this->assertSame(['a', 'b'], $list);
    }

    /**
     * A stored value that is not offered as an option is shown and kept, so that saving the form does not
     * lose it. It can only come from the default value: the same submission without that default is rejected.
     */
    public function test_stored_value_without_option_is_kept(): void {
        $this->resetAfterTest();

        $html = $this->render(['defaults' => ['old', 'a']]);
        [, $list] = $this->get_submitted_inputs($html);
        $this->assertSame(['old', 'a'], $list);
        $this->assertStringContainsString(get_string('sortableselect:legacy', 'mod_booking'), $html);

        $form = $this->submit(['columns' => ['a', 'old']], ['defaults' => ['old', 'a']]);
        $this->assertTrue($form->is_validated());
        $this->assertSame(['a', 'old'], $form->get_data()->columns);

        // The user removed the stored value.
        $form = $this->submit(['columns' => ['a']], ['defaults' => ['old', 'a']]);
        $this->assertSame(['a'], $form->get_data()->columns);

        $form = $this->submit(['columns' => ['a', 'old']], ['defaults' => ['a']]);
        $this->assertFalse($form->is_validated());
    }

    /**
     * The default value can be a list or comma separated values. The element renders one hidden input per
     * value in the order of the value, the input for the empty list before them, and the chosen options
     * cannot be chosen again.
     */
    public function test_default_value_is_rendered_in_its_order(): void {
        $this->resetAfterTest();

        foreach ([['c', 'a'], 'c, a'] as $defaults) {
            $html = $this->render(['defaults' => $defaults]);
            [$single, $list] = $this->get_submitted_inputs($html);
            $this->assertSame([sortableselect::EMPTYVALUE], $single);
            $this->assertSame(['c', 'a'], $list);
            $this->assertLessThan(
                strpos($html, 'name="columns[]"'),
                strpos($html, 'name="columns" value="' . sortableselect::EMPTYVALUE . '"')
            );
        }

        $this->assertMatchesRegularExpression('/<option value="c" disabled hidden>/', $html);
        $this->assertMatchesRegularExpression('/<option value="a" disabled hidden>/', $html);
        $this->assertMatchesRegularExpression('/<option value="b" >Beta</', $html);
        // The label of an option is text, no HTML.
        $this->assertStringContainsString('Gamma &lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Gamma <b>bold</b>', $html);
        // The texts of the element come from the form.
        $this->assertStringContainsString('Choose column...', $html);
        $this->assertStringContainsString('Add column', $html);
        // The element is in a form row like the core elements, with its label.
        $this->assertStringContainsString('id="fitem_id_columns"', $html);
        $this->assertStringContainsString('data-fieldtype="sortableselect"', $html);
        $this->assertStringContainsString('Move up: Alpha', $html);
    }

    /**
     * Without a value the text for the empty list is shown and only the input for the empty list is rendered.
     */
    public function test_empty_element_is_rendered(): void {
        $this->resetAfterTest();

        $html = $this->render([]);
        [$single, $list] = $this->get_submitted_inputs($html);
        $this->assertSame([sortableselect::EMPTYVALUE], $single);
        $this->assertSame([], $list);
        $this->assertStringContainsString('No columns', $html);
    }

    /**
     * set_data() fills the element like a default value.
     */
    public function test_set_data(): void {
        $this->resetAfterTest();
        global $PAGE;
        $PAGE->set_url(new url('/mod/booking/tests/sortableselect.php'));

        $form = new sortableselect_test_form();
        $form->set_data(['columns' => ['b', 'c', 'a']]);
        [, $list] = $this->get_submitted_inputs($form->render());
        $this->assertSame(['b', 'c', 'a'], $list);
    }

    /**
     * A frozen element shows the chosen entries without buttons and still submits its value.
     */
    public function test_frozen_element_keeps_its_value(): void {
        $this->resetAfterTest();

        $html = $this->render(['defaults' => ['b', 'a'], 'freeze' => true]);
        [$single, $list] = $this->get_submitted_inputs($html);
        $this->assertSame([sortableselect::EMPTYVALUE], $single);
        $this->assertSame(['b', 'a'], $list);
        $this->assertStringContainsString('data-region="sortableselect-frozen"', $html);
        $this->assertStringNotContainsString('data-action="remove"', $html);
        $this->assertStringNotContainsString('Add column', $html);

        $form = $this->submit(['columns' => ['b', 'a']], ['defaults' => ['b', 'a'], 'freeze' => true]);
        $this->assertSame(['b', 'a'], $form->get_data()->columns);
    }
}

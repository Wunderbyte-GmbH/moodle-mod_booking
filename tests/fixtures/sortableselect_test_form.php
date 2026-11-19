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
 * Test form with the form element "sortable select".
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\fixtures;

use mod_booking\form\element\sortableselect;
use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form with a sortable select, as a plugin would use the element.
 *
 * The custom data may hold: defaults (default value of the element), required (true to require a value),
 * freeze (true to freeze the element).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sortableselect_test_form extends moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        sortableselect::register();
        $mform->addElement(
            'sortableselect',
            'columns',
            'Columns',
            ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma <b>bold</b>'],
            ['noselectionstring' => 'No columns', 'choosestring' => 'Choose column...', 'addstring' => 'Add column']
        );
        $mform->setType('columns', PARAM_TEXT);
        if (array_key_exists('defaults', $this->_customdata ?? [])) {
            $mform->setDefault('columns', $this->_customdata['defaults']);
        }
        if (!empty($this->_customdata['required'])) {
            $mform->addRule('columns', get_string('required'), 'required');
        }
        if (!empty($this->_customdata['freeze'])) {
            $mform->freeze('columns');
        }
        $this->add_action_buttons();
    }
}

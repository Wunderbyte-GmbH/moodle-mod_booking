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
 * Handling tagtemplates add form
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Class to handle tagtemplates add form
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tagtemplatesadd_form extends moodleform {

    /**
     *
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form; // Don't forget the underscore.

        $mform->addElement('text', 'tag', get_string('tagtag', 'booking'));
        $mform->setType('tag', PARAM_NOTAGS);
        $mform->addRule('tag', null, 'required', null, 'client');

        $mform->addElement('editor', 'text', get_string('tagtext', 'booking'), null, null);
        $mform->setType('text', PARAM_CLEANHTML);
        $mform->addRule('text', null, 'required', null, 'client');

        $mform->addElement('hidden', 'tagid');
        $mform->setType('tagid', PARAM_RAW);

        $this->add_action_buttons(true, get_string('savenewtagtemplate', 'booking'));
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     *
     * @return array
     *
     */
    public function validation($data, $files) {
        return [];
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->text = $data->text['text'];
        }

        return $data;
    }
}

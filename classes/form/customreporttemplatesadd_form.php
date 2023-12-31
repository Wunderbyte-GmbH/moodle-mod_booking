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
 * Custom report templates form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Class importoptions_form
 *
 * @package mod_booking
 */
class customreporttemplatesadd_form extends moodleform {

    /**
     *
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('filemanager', 'templatefile', get_string('templatefile', 'booking'), null,
                        ['subdirs' => 0,
                        'maxbytes' => $CFG->maxbytes,
                        'areamaxbytes' => 10485760,
                        'maxfiles' => 1,
                        'accepted_types' => ['odt', 'ods', 'docx', 'xlsx'],
                        ]);
        $mform->addRule('templatefile', null, 'required', null, 'client');

        $this->add_action_buttons();
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {
        return [];
    }
}

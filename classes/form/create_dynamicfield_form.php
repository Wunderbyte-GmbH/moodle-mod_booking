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
 * Form to create a new dynamicformat booking custom field.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use moodleform;
use mod_booking\customfield\hierarchy_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

/**
 * Lets an admin create a new dynamicformat custom field for mod_booking options.
 */
class create_dynamicfield_form extends moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $categories = $this->_customdata['categories'] ?? [];

        $mform->addElement('header', 'createfieldhdr', get_string('createfield', 'mod_booking'));
        $mform->setExpanded('createfieldhdr', false);

        $categoryoptions = $categories + [0 => get_string('newcategory', 'mod_booking')];
        $mform->addElement('select', 'newfieldcategory', get_string('newfieldcategory', 'mod_booking'), $categoryoptions);
        $mform->setType('newfieldcategory', PARAM_INT);

        $mform->addElement('text', 'newfieldname', get_string('newfieldname', 'mod_booking'));
        $mform->setType('newfieldname', PARAM_TEXT);
        $mform->addRule('newfieldname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'newfieldshortname', get_string('newfieldshortname', 'mod_booking'));
        $mform->setType('newfieldshortname', PARAM_TEXT);
        $mform->addRule('newfieldshortname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('newfieldshortname', 'newfieldshortname', 'mod_booking');

        $mform->addElement('submit', 'createfield', get_string('createfield', 'mod_booking'));
    }

    /**
     * Validate the new field name and shortname.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $shortname = trim($data['newfieldshortname'] ?? '');
        if ($shortname === '') {
            $errors['newfieldshortname'] = get_string('required');
        } else if (!preg_match('/^[a-z0-9_]+$/', $shortname)) {
            $errors['newfieldshortname'] = get_string('error_invalidshortname', 'mod_booking');
        } else if (hierarchy_manager::shortname_exists($shortname)) {
            $errors['newfieldshortname'] = get_string('error_shortnameexists', 'mod_booking');
        }

        if (trim($data['newfieldname'] ?? '') === '') {
            $errors['newfieldname'] = get_string('required');
        }

        return $errors;
    }
}

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
 * Categories form class
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Class for handling categories form
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_categories_form extends moodleform {

    /**
     * Show sub categories.
     *
     * @param int $catid
     * @param string $dashes
     * @param array $options
     *
     * @return array
     *
     */
    private function show_sub_categories($catid, $dashes = '', $options = []) {
        global $DB;
        $dashes .= '&nbsp;&nbsp;';
        $categories = $DB->get_records('booking_category', ['cid' => $catid]);
        if (count((array) $categories) > 0) {
            foreach ($categories as $category) {
                $options[$category->id] = $dashes . $category->name;
                $options = $this->show_sub_categories($category->id, $dashes, $options);
            }
        }
        return $options;
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform::definition()
     *
     */
    public function definition() {
        global $DB, $COURSE;

        // Get all root categories.
        $categories = $DB->get_records('booking_category', ['course' => $COURSE->id, 'cid' => 0]);

        $options = [0 => get_string('rootcategory', 'mod_booking')];

        foreach ($categories as $category) {
            $options[$category->id] = $category->name;
            $options = $this->show_sub_categories($category->id, '', $options);
        }

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('categoryname', 'booking'),
                ['size' => '64']);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        $mform->addElement('select', 'cid', get_string('selectcategory', 'mod_booking'), $options);
        $mform->setDefault('cid', 0);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_RAW);

        $mform->addElement('hidden', 'course');
        $mform->setType('course', PARAM_RAW);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_RAW);

        $this->add_action_buttons();
    }
}

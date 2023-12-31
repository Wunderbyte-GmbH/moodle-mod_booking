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
 * Moodle form for user activity subscription.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Class to handle form for user activity subscription.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscribeusersactivity extends \moodleform {

    /**
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form; // Don't forget the underscore!

        $bookingoptions = $DB->get_records_list("booking_options", "bookingid", [$this->_customdata['bookingid']], '',
            'id,text,coursestarttime,location', '', '');

        $values = [];

        foreach ($bookingoptions as $key => $value) {
            $stringarray = [];
            $stringarray[] = $value->text;
            if ($value->coursestarttime != 0) {
                $stringarray[] = userdate($value->coursestarttime);
            }
            if ($value->location != '') {
                $stringarray[] = $value->location;
            }
            $values[$value->id] = implode(', ', $stringarray);
        }

        unset($values[$this->_customdata['optionid']]);

        // Add elements to your form.
        $mform->addElement('select', 'bookingoption', get_string('bookingoptionsmenu', 'booking'), $values);

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('transefusers', 'booking'));
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
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
        $errors = parent::validation($data, $files);

        return $errors;
    }
}

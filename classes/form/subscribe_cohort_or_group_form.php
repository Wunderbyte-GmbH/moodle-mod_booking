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
 * Moodle form for booking option cohort and group subscription.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/formslib.php');

use context_system;
use moodleform;

/**
 * Moodle form for booking option cohort and group subscription.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscribe_cohort_or_group_form extends moodleform {

    /**
     * Defines the form fields.
     */
    public function definition() {
        global $COURSE;

        $mform = $this->_form;

        // The form has to provide hidden id & optionid for it to work.
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', 0);
        $mform->setType('optionid', PARAM_INT);

        // Cohort subscription header.
        $mform->addElement('header', 'scgfcohortheader', get_string('scgfcohortheader', 'booking'));

        $context = context_system::instance();
        $options = [
            'ajax' => 'core/form-cohort-selector',
            'multiple' => true,
            'data-contextid' => $context->id,
            'data-includes' => 'all',
        ];
        $mform->addElement('autocomplete', 'cohortids', get_string('scgfselectcohorts', 'booking'), [], $options);
        $mform->addRule('cohortids', null, 'required');

        $mform->setExpanded('scgfcohortheader', true);

        // Group subscription header.
        $mform->addElement('header', 'scgfgroupheader', get_string('scgfgroupheader', 'booking'));
        $mform->setExpanded('scgfgroupheader', true);

        $groups = groups_get_all_groups($COURSE->id);
        // Associative array containing key value pairs for autocomplete.
        $groupsacvalues = [];
        foreach ($groups as $group) {
            $groupsacvalues[$group->id] = $group->name;
        }

        $options = [
            'tags' => true,
            'multiple' => true,
        ];

        $mform->addElement('autocomplete', 'groupids', get_string('scgfselectgroups', 'booking'),
            $groupsacvalues, $options);
        $mform->addRule('groupids', null, 'required');
        $mform->setDefault('groupids', null);

        $this->add_action_buttons(false, get_string('scgfbookgroupscohorts', 'booking'));
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

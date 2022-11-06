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

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\booking_rules\rules_info;
use moodle_url;
use moodleform;
use MoodleQuickForm;

/**
 * Dynamic rules form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @package mod_booking
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rulesform extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $customdata = $this->_customdata;
        $ajaxformdata = $this->_ajaxformdata;

        // If we open an existing rule, we need to save the id right away.
        if (!empty($ajaxformdata['id'])) {
            $mform->addElement('hidden', 'id', $ajaxformdata['id']);
        }

        // When a specific rule is chosen, we can load the right handlers already here.
        // Also, a change in rule, condition or action type will result in the call of different...
        // ... handlers in the definition.
        // Therefore, we need to load all these informations here.
        // Subhandlers won't have the values via _ajaxformdata, so we need to add hidden elements to mform.

        $this->preload_defintion_values($mform);

        $repeateloptions = [];

        rules_info::add_rules_to_mform($mform, $repeateloptions);

        // As this form is called normally from a modal, we don't need the action buttons.
        // Add submit button to create optiondate series. (Use $this, not $mform).
        // $this->add_action_buttons();
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();
        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $customdata = $this->_customdata;
        $ajaxformdata = $this->_ajaxformdata;

    }

    /**
     * Validate dates.
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {
        $errors = [];

        return $errors;
    }


    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/edit_rules.php');
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/site:config', context_system::instance());
    }

    /**
     * This function adds hidden settings to mform, depending on submitted or preloaded data.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function preload_defintion_values(MoodleQuickForm &$mform) {

        $data = $this->_ajaxformdata;
        // If we have just submitted via nosubmit button, we need to set the right id.
        if (!empty($data["bookingruletype"])) {
            $mform->addElement('hidden', 'bookingruletypeid', $data["bookingruletype"]);
        }

        if (!empty($data["bookingruleconditiontype"])) {
            $mform->addElement('hidden', 'bookingruleconditiontypeid', $data["bookingruleconditiontype"]);
        }

        if (!empty($data["bookingruleactiontype"])) {
            $mform->addElement('hidden', 'bookingruleactiontypeid', $data["bookingruleactiontype"]);
        }

        // TODO: Preload values from saved booking rule.
    }
}

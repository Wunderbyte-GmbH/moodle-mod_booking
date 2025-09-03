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
require_once("$CFG->libdir/formslib.php");

use context;
use context_system;
use core_form\dynamic_form;
use moodle_url;
use stdClass;

/**
 * Dynamic select users form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @package   mod_booking
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamicdeputyselect extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form;

        $options = [
            'multiple' => false,
            'noselectionstring' => '',
            'ajax' => 'mod_booking/form_users_selector',
        ];

        $mform->addElement('autocomplete', 'userid', get_string('selectdeputy', 'booking'), [], $options);
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     *
     * Submission data can be accessed as: $this->get_data()
     *
     * @return mixed
     */
    public function process_dynamic_submission() {

        $data = $this->get_data();

        return $data;
    }


    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $this->set_data(get_entity($this->_ajaxformdata['cmid']));
     */
    public function set_data_for_dynamic_submission(): void {

        global $USER;
        $data = new stdClass();

        if (!empty($data->userid)) {
            // TODO: check if this really works
            $this->update_user_field($data->userid);
        }

        $this->set_data($data);
    }

    /**
     * Updates a Moodle user field (standard or custom) safely.
     *
     * @param mixed $value The value to assign.
     * @return bool True on success, false on failure.
     */
    private function update_user_field($value) {
        global $DB, $USER;

        // TODO: Make sure, this works for multiple users!

        $field = get_config('mod_booking', 'confirmationdeputyfield');

        // $userid = $USER->id;
        // if (!$user = $DB->get_record('user', ['id' => $userid], '*')) {
        //     return false; // User not found
        // }
        // Maybe we need to use a different user than user.

        // Load custom profile data.
        profile_load_data($USER);

        // Check if field is a standard field (exists in mdl_user table).
        $standardfields = $DB->get_columns('user');
        $isstandard = array_key_exists($field, $standardfields);

        if ($isstandard) {
            // Update standard field.
            $USER->$field = $value;
            user_update_user($user, false, false);
        } else {
            // Assume custom profile field; prefix required by Moodle.
            $customfield = 'profile_field_' . $field;
            $USER->$customfield = $value;
            profile_save_data($user);
        }
        return true;
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        return context_system::instance();
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {

        // We don't need it, as we only use it in modal.
        return new moodle_url('/');
    }

    /**
     * Validate form.
     *
     * @param stdClass $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {

        $errors = [];
        return $errors;
    }

    /**
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}

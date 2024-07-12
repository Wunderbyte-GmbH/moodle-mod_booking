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
use mod_booking\import\fileparser;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once($CFG->libdir . "/csvlib.class.php");

use context;
use context_system;
use context_module;
use core_form\dynamic_form;
use moodle_url;
use stdClass;
use core_text;

/**
 * Dynamic form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @package   mod_booking
 * @author    Wunderbyte Gmbh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csvimport extends dynamic_form {

    /**
     *
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        global $CFG, $DB, $PAGE;

        $mform = $this->_form;
        $data = (object) $this->_ajaxformdata;

        if (isset($data->id)) {
            $mform->addElement('hidden', 'id', $data->id);
            $mform->setType('id', PARAM_INT);
        }

        if (isset($data->cmid)) {
            $mform->addElement('hidden', 'cmid', $data->cmid);
            $mform->setType('cmid', PARAM_INT);
        }

        if (isset($data->settingscallback)) {
            $mform->addElement('hidden', 'settingscallback', $data->settingscallback);
            $mform->setType('settingscallback', PARAM_TEXT); // Check which type applies here!
        }
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('importcsv', 'mod_booking'),
            null,
            [
                'maxbytes' => $CFG->maxbytes,
                'accepted_types' => 'csv',
            ]
        );
        $mform->addRule('csvfile', null, 'required', null, 'client');
        $choices = $this->get_delimiter_list();
        $mform->addElement('select', 'delimiter_name', get_string('csvdelimiter', 'tool_uploaduser'), $choices);
        if (array_key_exists('cfg', $choices)) {
            $mform->setDefault('delimiter_name', 'cfg');
        } else if (get_string('listsep', 'langconfig') == ';') {
            $mform->setDefault('delimiter_name', 'semicolon');
        } else {
            $mform->setDefault('delimiter_name', 'comma');
        }

        $choices = core_text::get_encodings();
        $mform->addElement('select', 'encoding', get_string('encoding', 'tool_uploaduser'), $choices);
        $mform->setDefault('encoding', 'UTF-8');

        $mform->addElement('text', 'dateparseformat', get_string('dateparseformat', 'mod_booking'));
        $mform->setType('dateparseformat', PARAM_NOTAGS);
        $mform->setDefault('dateparseformat', get_string('defaultdateformat', 'mod_booking'));
        $mform->addRule('dateparseformat', null, 'required', null, 'client');
        $mform->addHelpButton('dateparseformat', 'dateparseformat', 'mod_booking');

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('submit'));
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
    }



    /**
     * Get list of cvs delimiters
     *
     * @return array suitable for selection box
     */
    public static function get_delimiter_list() {
        global $CFG;
        $delimiters = ['comma' => ',', 'semicolon' => ';', 'colon' => ':', 'tab' => '\\t'];
        if (isset($CFG->CSV_DELIMITER) && strlen($CFG->CSV_DELIMITER) === 1 && !in_array($CFG->CSV_DELIMITER, $delimiters)) {
            $delimiters['cfg'] = $CFG->CSV_DELIMITER;
        }
        return $delimiters;
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:updatebooking', $this->get_context_for_dynamic_submission());
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     *
     * Submission data can be accessed as: $this->get_data()
     *
     * @return array
     */
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $content = $this->get_file_content('csvfile');

        $callback = $data->settingscallback;
        $returndata = $callback($data, $content);
        $returndata['id'] = $data->id;
        $returndata['settingscallback'] = $data->settingscallback;

        // Should return array with ['success'] == 1 in case of success.
        return $returndata;
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
        $data = (object) $this->_ajaxformdata;

        $this->set_data($data);
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

        $mform = $this->_form;
        $data = (object) $this->_ajaxformdata;

        if (!empty($data->cmid)) {
            $context = context_module::instance($data->cmid);
        } else {
            $context = context_system::instance();
        }
        return $context;
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
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        return $errors;
    }
}

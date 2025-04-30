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
 * Moodle form to change notes for bookings of users
 * for specific optiondates (sessions).
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form\optiondates;

use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache_helper;
use context;
use context_system;
use context_module;
use core_form\dynamic_form;
use mod_booking\local\optiondates\optiondate_answer;
use moodle_url;

/**
 * Moodle form to change notes for bookings of users
 * for specific optiondates (sessions).
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_change_notes extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;

        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $optionid = $this->_ajaxformdata['optionid'] ?? 0;
        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $scope = $this->_ajaxformdata['scope'] ?? '';
        $mform->addElement('hidden', 'scope', $scope);
        $mform->setType('scope', PARAM_TEXT);

        /* IDs are passed in the following formats:
        For option scope: optionid-userid
        For optiondate scope: optionid-optiondateid-userid */
        $mform->addElement('hidden', 'checkedids', '');
        $mform->setType('checkedids', PARAM_TEXT);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_RAW);

        if (!empty($this->_ajaxformdata['checkedids'])) {
            $mform->addElement('textarea', 'notes', get_string('notes', 'mod_booking'));
            $mform->setType('notes', PARAM_TEXT);
        } else {
            $mform->addElement(
                'html',
                '<div class="alert alert-warning">'
                . get_string('norowsselected', 'mod_booking')
                . '</div>'
            );
        }
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:managebookedusers', $this->get_context_for_dynamic_submission());
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
        global $DB;
        $data = $this->get_data();
        $scope = $data->scope ?? '';
        if (empty($scope)) {
            return $data;
        }
        if (empty($data->notes)) {
            $data->notes = '';
        }
        if (empty($data->optionid)) {
            $data->optionid = 0;
        }
        if (empty($data->checkedids)) {
            $data->checkedids = $data->id;
        }
        $checkedids = explode(',', $data->checkedids);
        // Just to make sure, we have no empty IDs here.
        $checkedids = array_filter($checkedids, fn($checkedid) => !empty($checkedid));
        // If it's still empty at this point, we just return.
        if (empty($checkedids)) {
            return $data;
        }

        /* IDs are passed in the following formats:
        For option scope: optionid-userid
        For optiondate scope: optionid-optiondateid-userid */
        switch ($scope) {
            case 'optiondate':
                foreach ($checkedids as $checkedid) {
                    [$optionid, $optiondateid, $userid] = explode('-', $checkedid);
                    if (empty($optionid) || empty($optiondateid) || empty($userid)) {
                        continue;
                    }
                    if (!is_int((int) $optionid) || !is_int((int) $optiondateid) || !is_int((int) $userid)) {
                        continue;
                    }
                    $optiondateanswer = new optiondate_answer($userid, $optiondateid, $optionid);
                    $optiondateanswer->add_or_update_notes($data->notes);
                }
                break;
            case 'option':
                $optionid = $data->optionid;
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $cmid = $settings->cmid;
                $answers = singleton_service::get_instance_of_booking_answers($settings);
                $notes = $data->notes;
                // Note: In option scope, we have normal booking answer IDs.
                $selectedusers = [];
                foreach ($checkedids as $answerid) {
                    $answer = $answers->answers[$answerid] ?? null;
                    if (empty($answer) || empty($answer->userid)) {
                        continue;
                    }
                    $selectedusers[] = $answer->userid;
                }
                $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
                $option->edit_notes($selectedusers, $notes);
                break;
        }
        cache_helper::purge_by_event('setbackbookedusertable');
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
        $data = (object)$this->_ajaxformdata;
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
        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        if (empty($cmid)) {
            $cmid = $this->optional_param('cmid', 0, PARAM_INT);
            if ($cmid == 0) {
                return context_system::instance();
            }
        }
        return context_module::instance($cmid);
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
        $scope = $this->_ajaxformdata['scope'] ?? '';
        if (empty($scope)) {
            return new moodle_url('/mod/booking/report2.php');
        }
        // Optiondateid only exists in optiondate scope.
        if ($scope == 'optiondate') {
            $optiondateid = $this->_ajaxformdata['optiondateid'] ?? 0;
            if (empty($optiondateid)) {
                $optiondateid = $this->optional_param('optiondateid', 0, PARAM_INT);
            }
        }
        // Optionid exists in both scopes (option and optiondate).
        $optionid = $this->_ajaxformdata['optionid'] ?? 0;
        if (empty($optionid)) {
            $optionid = $this->optional_param('optionid', 0, PARAM_INT);
        }
        // Cmid exists in both scopes (option and optiondate).
        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        if (empty($cmid)) {
            $cmid = $this->optional_param('cmid', 0, PARAM_INT);
        }
        // URL depending on available scope ids.
        if (!empty($optionid) && !empty($optiondateid)) {
            return new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid, 'optiondateid' => $optiondateid]);
        } else if (!empty($optionid) && empty($optiondateid)) {
            return new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]);
        } else if (!empty($cmid)) {
            return new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid]);
        }
        return new moodle_url('/mod/booking/report2.php');
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
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

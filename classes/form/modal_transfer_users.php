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
 * Modal dynamic form to transfer selected booked users to another booking option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use context;
use context_module;
use context_system;
use core_form\dynamic_form;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Modal dynamic form to transfer selected booked users to another booking option in report2.php.
 *
 * The target option is chosen through an ajax autocomplete that searches booking options across
 * all instances (showing title prefix, option id and instance name). Before the transfer is
 * carried out, non-blocking warnings are shown if the target has a different option type, if a
 * user filled out a custom form (data loss) or if the target has a different price. The admin can
 * override the warnings by ticking the "transfer anyway" checkbox.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_transfer_users extends dynamic_form {
    /**
     * Resolve the selected booking_answers row ids (checkedids) to distinct user ids.
     *
     * @return array<int>
     */
    private function get_selected_userids(): array {
        global $DB;

        $raw = $this->_ajaxformdata['checkedids'] ?? '';
        if (is_array($raw)) {
            $answerids = array_filter(array_map('intval', $raw));
        } else {
            $answerids = array_filter(array_map('intval', explode(',', (string) $raw)));
        }

        if (empty($answerids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($answerids, SQL_PARAMS_NAMED);
        $userids = $DB->get_fieldset_select('booking_answers', 'DISTINCT userid', "id $insql", $inparams);

        return array_values(array_unique(array_map('intval', $userids)));
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $submitdata = $this->_ajaxformdata;

        $cmid = (int) ($submitdata['cmid'] ?? 0);
        $optionid = (int) ($submitdata['optionid'] ?? 0);

        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'checkedids', $submitdata['checkedids'] ?? '');
        $mform->setType('checkedids', PARAM_TEXT);

        // Searchable autocomplete for the target booking option (searches all instances).
        $options = [
            'multiple' => false,
            'tags' => false,
            'ajax' => 'mod_booking/form_booking_options_selector',
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT, $DB;
                $value = (int) $value;
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                $settings = singleton_service::get_instance_of_booking_option_settings($value);
                $instancename = $DB->get_field('booking', 'name', ['id' => $settings->bookingid]);
                return $OUTPUT->render_from_template('mod_booking/form_booking_options_selector_suggestion', [
                    'id' => $settings->id,
                    'titleprefix' => $settings->titleprefix,
                    'text' => $settings->text,
                    'instancename' => $instancename ?: '',
                ]);
            },
        ];
        $mform->addElement(
            'autocomplete',
            'targetoptionid',
            get_string('transfertargetoption', 'mod_booking'),
            [],
            $options
        );
        $mform->addRule('targetoptionid', null, 'required', null, 'client');
        $mform->addHelpButton('targetoptionid', 'transfertargetoption', 'mod_booking');

        // If a target has been chosen, compute warnings and show them together with an override checkbox.
        $targetoptionid = (int) ($submitdata['targetoptionid'] ?? 0);
        if (!empty($targetoptionid) && $targetoptionid !== $optionid) {
            $warnings = booking_option::get_transfer_warnings($optionid, $targetoptionid, $this->get_selected_userids());
            if (!empty($warnings)) {
                $items = '';
                foreach ($warnings as $warning) {
                    $items .= \html_writer::tag('li', $warning);
                }
                $alert = \html_writer::div(
                    \html_writer::tag('strong', get_string('transferwarningheading', 'mod_booking')) .
                        \html_writer::tag('ul', $items, ['class' => 'mb-0 mt-2']),
                    'alert alert-warning'
                );
                $mform->addElement('static', 'transferwarnings', '', $alert);

                $mform->addElement(
                    'advcheckbox',
                    'confirmtransfer',
                    '',
                    get_string('transferconfirmlabel', 'mod_booking')
                );
                $mform->setType('confirmtransfer', PARAM_INT);
            }
        }
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = [];

        $optionid = (int) ($data['optionid'] ?? 0);
        $targetoptionid = (int) ($data['targetoptionid'] ?? 0);

        if (empty($targetoptionid)) {
            $errors['targetoptionid'] = get_string('required');
            return $errors;
        }

        if ($targetoptionid === $optionid) {
            $errors['targetoptionid'] = get_string('transfersameoption', 'mod_booking');
            return $errors;
        }

        // If there are warnings, the override checkbox must be ticked to proceed.
        $warnings = booking_option::get_transfer_warnings($optionid, $targetoptionid, $this->get_selected_userids());
        if (!empty($warnings) && empty($data['confirmtransfer'])) {
            $errors['confirmtransfer'] = get_string('transferconfirmrequired', 'mod_booking');
        }

        return $errors;
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:subscribeusers', $this->get_context_for_dynamic_submission());
    }

    /**
     * Set data for dynamic submission.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object) $this->_ajaxformdata;
        $this->set_data($data);
    }

    /**
     * Process dynamic submission and transfer the selected users to the target option.
     *
     * @return stdClass|null
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();

        $cmid = (int) $data->cmid;
        $optionid = (int) $data->optionid;
        $targetoptionid = (int) $data->targetoptionid;

        $userids = $this->get_selected_userids();

        if (empty($userids) || empty($targetoptionid)) {
            $data->message = get_string('transferproblem', 'mod_booking', '');
            $data->success = 0;
            return $data;
        }

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $result = $bookingoption->transfer_users_to_otheroption($targetoptionid, $userids);

        if (!empty($result->success)) {
            $data->message = get_string('transfersuccess', 'mod_booking', $result);
            $data->success = 1;
        } else {
            $names = '';
            if (!empty($result->no)) {
                foreach ($result->no as $user) {
                    $names .= trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) . ' ';
                }
            }
            $data->message = get_string('transferproblem', 'mod_booking', trim($names));
            $data->success = 0;
        }

        return $data;
    }

    /**
     * Get context for dynamic submission.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int) ($this->_ajaxformdata['cmid'] ?? 0);

        if (empty($cmid)) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Get page URL for dynamic submission.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/report2.php', [
            'optionid' => $this->_ajaxformdata['optionid'] ?? 0,
        ]);
    }
}

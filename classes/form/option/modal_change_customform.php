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
 * Moodle form to edit the customform values of a single booking answer
 * in the bookings tracker (report2.php).
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form\option;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once("$CFG->dirroot/mod/booking/lib.php");

use cache_helper;
use context;
use context_module;
use context_system;
use core_form\dynamic_form;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Moodle form to edit the customform values of a single booking answer
 * in the bookings tracker (report2.php).
 *
 * The values a user entered when booking are stored in the json column of
 * booking_answers (condition_customform). This modal loads them based on the
 * customform definition of the option and updates the json after validation.
 * PRO feature, gated by mod/booking:changecustomformofotherusers.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_change_customform extends dynamic_form {
    /**
     * Formtypes that can be edited in this modal.
     * Excluded on purpose (shown read-only): enrolusersaction (coupled to the
     * booked places of the answer via update_places_with_customformdata, so
     * editing it could cause overbooking), deleteinfoscheckboxuser (the privacy
     * consent of the user, not to be changed on their behalf) and static
     * (display text without a user value).
     *
     * @var string[]
     */
    public const EDITABLE_FORMTYPES = ['advcheckbox', 'shorttext', 'select', 'url', 'mail'];

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

        // In option scope, checkedids are booking_answers ids.
        $mform->addElement('hidden', 'checkedids', '');
        $mform->setType('checkedids', PARAM_TEXT);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_RAW);

        $checkedids = self::parse_checkedids($this->_ajaxformdata['checkedids'] ?? '');
        if (empty($checkedids)) {
            $mform->addElement(
                'html',
                '<div class="alert alert-warning">'
                . get_string('norowsselected', 'mod_booking')
                . '</div>'
            );
            return;
        }
        // Customform values differ per person, so this action works on exactly one answer.
        if (count($checkedids) > 1) {
            $mform->addElement(
                'html',
                '<div class="alert alert-warning">'
                . get_string('error:selectonlyonerow', 'mod_booking')
                . '</div>'
            );
            return;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$optionid);
        $formelements = (array)customform::return_formelements($settings);
        $storedvalues = $this->get_stored_customform_values((int)reset($checkedids));

        foreach ($formelements as $counter => $formelement) {
            $formtype = $formelement->formtype ?? '';
            $identifier = self::get_element_identifier($formtype, (int)($formelement->elementid ?? $counter));
            $label = format_string($formelement->label ?? '');

            if (!in_array($formtype, self::EDITABLE_FORMTYPES, true)) {
                // Read-only: show the configured text (static) or the stored value.
                $value = $formtype === 'static'
                    ? format_text($formelement->value ?? '')
                    : s($storedvalues[$identifier] ?? '');
                $mform->addElement('static', $identifier . '_static', $label, $value);
                continue;
            }

            switch ($formtype) {
                case 'advcheckbox':
                    $mform->addElement('advcheckbox', $identifier, '', $label);
                    break;
                case 'select':
                    $mform->addElement(
                        'select',
                        $identifier,
                        $label,
                        self::get_select_options($formelement)
                    );
                    break;
                case 'shorttext':
                case 'url':
                case 'mail':
                default:
                    $mform->addElement('text', $identifier, $label);
                    $mform->setType($identifier, PARAM_TEXT);
                    break;
            }

            if (!empty($formelement->notempty)) {
                $mform->addRule($identifier, get_string('error:mustnotbeempty', 'mod_booking'), 'required', null, 'client');
            }
        }

        // Safeguard 1: the editor has to explicitly accept that saving
        // overwrites the values the participant entered (mandatory, red label,
        // enforced in validation()).
        $mform->addElement(
            'advcheckbox',
            'confirmoverwrite',
            '',
            '<span class="text-danger">' . get_string('confirmcustomformoverwrite', 'mod_booking') . '</span>'
        );

        // Safeguard 2 (two-step save): the first "Save" click never saves.
        // It fails in validation() and redisplays the form with the explicit
        // question below and armed with confirmsave=1 - only that resubmission
        // (or a cancel) ends the dialog. The initially loaded form carries NO
        // confirmsave element at all: this way the first submission has no
        // confirmsave key (validation fails), and on the redisplay the armed
        // default 1 is not overridden by a submitted value. The initial modal
        // load is detected by the missing confirmoverwrite key (submissions
        // always carry it, the action button data never does).
        if (isset($this->_ajaxformdata['confirmoverwrite'])) {
            $mform->addElement(
                'html',
                '<div class="alert alert-danger">'
                . get_string('confirmcustomformoverwritequestion', 'mod_booking')
                . '</div>'
            );
            $mform->addElement('hidden', 'confirmsave', 1);
            $mform->setType('confirmsave', PARAM_INT);
        }
    }

    /**
     * Check access for dynamic submission: capability, PRO version and
     * (if an answer is given) that answer, option and cmid belong together.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability(
            'mod/booking:changecustomformofotherusers',
            $this->get_context_for_dynamic_submission()
        );

        // UI hiding is not enough, so the PRO gate is enforced on the server too.
        if (!wb_payment::pro_version_is_activated()) {
            throw new moodle_exception('proversiononly', 'mod_booking');
        }

        $checkedids = self::parse_checkedids($this->_ajaxformdata['checkedids'] ?? '');
        if (count($checkedids) === 1) {
            $this->get_answer_for_option((int)reset($checkedids));
        }
    }

    /**
     * Process the form submission: replace the submitted values of editable
     * formtypes in the answer json, log the diff to the booking history.
     *
     * Keys of non-editable formtypes (and any other json content) stay
     * untouched, even if they are part of the submitted data. The timemodified
     * of the answer is not changed either, because it is the sort key of the
     * waiting list.
     *
     * @return mixed
     */
    public function process_dynamic_submission() {
        global $DB;

        $data = $this->get_data();

        $checkedids = self::parse_checkedids($data->checkedids ?? '');
        if (count($checkedids) !== 1) {
            throw new moodle_exception('error:selectonlyonerow', 'mod_booking');
        }

        $answer = $this->get_answer_for_option((int)reset($checkedids));
        $optionid = (int)$answer->optionid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $formelements = (array)customform::return_formelements($settings);

        $answerjson = json_decode($answer->json ?? '') ?? new stdClass();
        if (empty($answerjson->condition_customform)) {
            $answerjson->condition_customform = new stdClass();
        }

        $diff = [];
        foreach ($formelements as $counter => $formelement) {
            $formtype = $formelement->formtype ?? '';
            if (!in_array($formtype, self::EDITABLE_FORMTYPES, true)) {
                continue;
            }
            $identifier = self::get_element_identifier($formtype, (int)($formelement->elementid ?? $counter));
            if (!property_exists($data, $identifier)) {
                continue;
            }
            $newvalue = (string)$data->{$identifier};
            $oldvalue = (string)($answerjson->condition_customform->{$identifier} ?? '');
            if ($oldvalue === $newvalue) {
                continue;
            }
            $label = format_string($formelement->label ?? '');
            $diff[$identifier] = [
                'label' => !empty($label) ? $label : $identifier,
                'oldvalue' => $oldvalue,
                'newvalue' => $newvalue,
            ];
            $answerjson->condition_customform->{$identifier} = $newvalue;
        }

        if (empty($diff)) {
            return $data;
        }

        // Only update the json column: timemodified stays untouched on purpose.
        $update = (object)[
            'id' => $answer->id,
            'json' => json_encode($answerjson),
        ];
        $DB->update_record('booking_answers', $update);

        booking_option::booking_history_insert(
            MOD_BOOKING_STATUSPARAM_CUSTOMFORM_EDITED,
            (int)$answer->id,
            $optionid,
            (int)$answer->bookingid,
            (int)$answer->userid,
            $diff
        );

        booking_option::purge_cache_for_answers($optionid);
        cache_helper::purge_by_event('setbackbookedusertable');

        return $data;
    }

    /**
     * Load the stored customform values of the answer as form defaults.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object)$this->_ajaxformdata;

        $checkedids = self::parse_checkedids($this->_ajaxformdata['checkedids'] ?? '');
        if (count($checkedids) === 1) {
            foreach ($this->get_stored_customform_values((int)reset($checkedids)) as $key => $value) {
                $data->{$key} = $value;
            }
        }

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
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $optionid = $this->_ajaxformdata['optionid'] ?? 0;
        if (empty($optionid)) {
            $optionid = $this->optional_param('optionid', 0, PARAM_INT);
        }
        if (!empty($optionid)) {
            return new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]);
        }
        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        if (empty($cmid)) {
            $cmid = $this->optional_param('cmid', 0, PARAM_INT);
        }
        if (!empty($cmid)) {
            return new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid]);
        }
        return new moodle_url('/mod/booking/report2.php');
    }

    /**
     * Server-side validation: notempty rules of the field definitions,
     * mail and url formats and the whitelist of select options.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {
        $errors = [];

        $optionid = (int)($data['optionid'] ?? 0);
        if (empty($optionid)) {
            return $errors;
        }

        // Without exactly one selected answer the form only shows a warning
        // (no elements); process_dynamic_submission() enforces the hard guard.
        $checkedids = self::parse_checkedids((string)($data['checkedids'] ?? ''));
        if (count($checkedids) !== 1) {
            return $errors;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $formelements = (array)customform::return_formelements($settings);

        foreach ($formelements as $counter => $formelement) {
            $formtype = $formelement->formtype ?? '';
            if (!in_array($formtype, self::EDITABLE_FORMTYPES, true)) {
                continue;
            }
            $identifier = self::get_element_identifier($formtype, (int)($formelement->elementid ?? $counter));
            if (!array_key_exists($identifier, $data)) {
                continue;
            }
            $value = (string)$data[$identifier];

            if (!empty($formelement->notempty) && $value === '') {
                $errors[$identifier] = get_string('error:mustnotbeempty', 'mod_booking');
                continue;
            }

            switch ($formtype) {
                case 'mail':
                    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$identifier] = get_string('bocondcustomformmailerror', 'mod_booking');
                    }
                    break;
                case 'url':
                    if (
                        $value !== ''
                        && !(filter_var($value, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $value))
                    ) {
                        $errors[$identifier] = get_string('bocondcustomformurlerror', 'mod_booking');
                    }
                    break;
                case 'select':
                    // No free text in select keys: the value has to be a configured option.
                    $options = self::get_select_options($formelement);
                    if (!array_key_exists($value, $options)) {
                        $errors[$identifier] = get_string('error:choosevalue', 'mod_booking');
                    }
                    break;
            }
        }

        // Safeguard 1: the overwrite acknowledgement checkbox is mandatory.
        if (empty($data['confirmoverwrite'])) {
            $errors['confirmoverwrite'] = get_string('error:confirmthatyouaresure', 'mod_booking');
        }

        // Safeguard 2 (two-step save): the first valid "Save" click never
        // saves - it fails here, and the form is redisplayed with the explicit
        // question and confirmsave armed to 1 (see definition()). Only that
        // resubmission passes. The raw submitted value is checked on purpose:
        // the armed element default of the redisplay must not count for the
        // request that arms it.
        if (empty($errors) && empty($this->_ajaxformdata['confirmsave'])) {
            $errors['confirmsave'] = get_string('confirmcustomformoverwritequestion', 'mod_booking');
        }

        return $errors;
    }

    /**
     * Returns the editable formelements of an option (counter => element),
     * e.g. to decide if the action button makes sense at all.
     *
     * @param booking_option_settings $settings
     * @return array
     */
    public static function editable_formelements(booking_option_settings $settings): array {
        $formelements = (array)customform::return_formelements($settings);
        return array_filter(
            $formelements,
            fn($formelement) => in_array($formelement->formtype ?? '', self::EDITABLE_FORMTYPES, true)
        );
    }

    /**
     * The json key of one customform element, like it is stored in the answer.
     *
     * @param string $formtype
     * @param int $counter
     * @return string
     */
    private static function get_element_identifier(string $formtype, int $counter): string {
        // The privacy checkbox of the user is stored without counter.
        if ($formtype === 'deleteinfoscheckboxuser') {
            return 'customform_deleteinfoscheckboxuser';
        }
        return 'customform_' . $formtype . '_' . $counter;
    }

    /**
     * The select options of a select formelement, keyed like the values are
     * stored in the answer json (mapped key or numeric line index).
     * Availability and price decorations of the booking form are left out on
     * purpose, but the keys are built exactly like in customform_form.
     *
     * @param stdClass $formelement
     * @return array
     */
    private static function get_select_options(stdClass $formelement): array {
        $lines = explode(PHP_EOL, (string)($formelement->value ?? ''));
        $options = [];
        foreach ($lines as $line) {
            $linearray = explode(' => ', $line);
            if (count($linearray) > 1) {
                $options[$linearray[0]] = format_string($linearray[1]);
            } else {
                $options[] = format_string($line);
            }
        }
        return $options;
    }

    /**
     * Split the checkedids param into an array of non-empty ids.
     *
     * @param string $checkedids
     * @return array
     */
    private static function parse_checkedids(string $checkedids): array {
        return array_values(array_filter(
            explode(',', $checkedids),
            fn($checkedid) => !empty($checkedid)
        ));
    }

    /**
     * Load the answer record and make sure it belongs to the option of the
     * request and the option to the cmid (no acting on foreign answers by
     * passing manipulated ids).
     *
     * @param int $answerid
     * @return stdClass
     */
    private function get_answer_for_option(int $answerid): stdClass {
        global $DB;

        $optionid = (int)($this->_ajaxformdata['optionid'] ?? 0);
        $cmid = (int)($this->_ajaxformdata['cmid'] ?? 0);

        $answer = $DB->get_record('booking_answers', ['id' => $answerid], '*', MUST_EXIST);
        if ((int)$answer->optionid !== $optionid) {
            throw new moodle_exception(
                'error:answernotinthisoption',
                'mod_booking',
                '',
                (object)['answerid' => $answerid, 'optionid' => $optionid]
            );
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if ((int)($settings->cmid ?? 0) !== $cmid) {
            throw new moodle_exception(
                'error:optionnotinthisinstance',
                'mod_booking',
                '',
                (object)['optionid' => $optionid, 'cmid' => $cmid]
            );
        }

        return $answer;
    }

    /**
     * The customform values stored in the json of the answer
     * (json key => value).
     *
     * @param int $answerid
     * @return array
     */
    private function get_stored_customform_values(int $answerid): array {
        $answer = $this->get_answer_for_option($answerid);
        $answerjson = json_decode($answer->json ?? '');
        if (empty($answerjson->condition_customform)) {
            return [];
        }
        $values = [];
        foreach ($answerjson->condition_customform as $key => $value) {
            if (strpos($key, 'customform_') === 0) {
                $values[$key] = $value;
            }
        }
        return $values;
    }
}

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

use context;
use context_module;
use core_form\dynamic_form;
use moodle_url;

/**
 * Dynamic form to create one sync rule.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_rule_form extends dynamic_form {
    /**
     * Define form elements.
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        $mform->addElement('hidden', 'optionid', (int)($formdata['optionid'] ?? 0));
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'cmid', (int)($formdata['cmid'] ?? 0));
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'ruleid', (int)($formdata['ruleid'] ?? 0));
        $mform->setType('ruleid', PARAM_INT);

        $sourcechoices = [];
        $ruleid = (int)($formdata['ruleid'] ?? 0);
        $optionid = (int)($formdata['optionid'] ?? 0);
        if ($ruleid > 0 && $optionid > 0) {
            $rule = \mod_booking\local\sync\booking_enrolment::get_rule_for_option($optionid, $ruleid);
            if (!empty($rule)) {
                if ($rule->sourcetype === 'cohort') {
                    $sourcechoices[(int)$rule->sourceid] = $DB->get_field('cohort', 'name', ['id' => $rule->sourceid]) ?: '?';
                } else {
                    $sourcechoices[(int)$rule->sourceid] = $DB->get_field('groups', 'name', ['id' => $rule->sourceid]) ?: '?';
                }
            }
        }

        $sourcetypes = [
            'cohort' => get_string('syncsourcetypecohort', 'mod_booking'),
            'group' => get_string('syncsourcetypegroup', 'mod_booking'),
        ];
        $mform->addElement('select', 'sourcetype', get_string('syncsourcetype', 'mod_booking'), $sourcetypes);
        $mform->setDefault('sourcetype', 'cohort');

        $sourceoptions = [
            'ajax' => 'mod_booking/form_sync_source_selector',
            'multiple' => false,
            'data-cmid' => (int)($formdata['cmid'] ?? 0),
        ];
        $mform->addElement(
            'autocomplete',
            'sourceid',
            get_string('syncsourceselect', 'mod_booking'),
            $sourcechoices,
            $sourceoptions
        );

        $mform->addElement('advcheckbox', 'syncenrolaction', get_string('syncenrolaction', 'mod_booking'));
        $mform->setDefault('syncenrolaction', 1);

        $mform->addElement('advcheckbox', 'syncunenrolaction', get_string('syncunenrolaction', 'mod_booking'));
        $mform->setDefault('syncunenrolaction', 0);

        $conditionpolicyoptions = [
            \mod_booking\local\sync\booking_enrolment::CONDITION_POLICY_RESPECT =>
                get_string('syncconditionpolicy_respect', 'mod_booking'),
            \mod_booking\local\sync\booking_enrolment::CONDITION_POLICY_OVERRIDE =>
                get_string('syncconditionpolicy_override', 'mod_booking'),
        ];
        $mform->addElement(
            'select',
            'syncconditionpolicy',
            get_string('syncconditionpolicy', 'mod_booking'),
            $conditionpolicyoptions
        );
        $mform->setDefault('syncconditionpolicy', \mod_booking\local\sync\booking_enrolment::CONDITION_POLICY_RESPECT);

        $mform->addElement('advcheckbox', 'syncapplycurrentmembers', get_string('syncapplycurrentmembers', 'mod_booking'));
        $mform->setDefault('syncapplycurrentmembers', 0);
    }

    /**
     * Handle form submission.
     *
     * @return object
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();

        $ruleid = \mod_booking\local\sync\booking_enrolment::save_single_rule((int)$data->optionid, $data);
        if (!empty($data->syncapplycurrentmembers)) {
            \mod_booking\local\sync\booking_enrolment::apply_rule_to_current_members($ruleid);
        }

        $data->ruleid = $ruleid;
        return $data;
    }

    /**
     * Set data for dynamic submission.
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object)($this->_ajaxformdata ?? $this->_customdata ?? []);

        $ruleid = (int)($data->ruleid ?? 0);
        $optionid = (int)($data->optionid ?? 0);
        if ($ruleid > 0 && $optionid > 0) {
            $rule = \mod_booking\local\sync\booking_enrolment::get_rule_for_option($optionid, $ruleid);
            if (!empty($rule)) {
                $data->sourcetype = $rule->sourcetype;
                $data->sourceid = (int)$rule->sourceid;
                $data->syncenrolaction = (int)$rule->syncenrol;
                $data->syncunenrolaction = (int)$rule->syncunenrol;
                $data->syncconditionpolicy = (int)$rule->conditionpolicy;
                $data->syncapplycurrentmembers = 0;
            }
        }

        $this->set_data($data);
    }

    /**
     * Validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = [];

        if (empty($data['sourceid'])) {
            $errors['sourceid'] = get_string('required');
        }

        if (empty($data['syncenrolaction']) && empty($data['syncunenrolaction'])) {
            $errors['syncenrolaction'] = get_string('syncruleactionrequired', 'mod_booking');
        }

        $sourcetype = (string)($data['sourcetype'] ?? '');
        $sourceid = (int)($data['sourceid'] ?? 0);
        if (!\mod_booking\local\sync\booking_enrolment::source_exists($sourcetype, $sourceid)) {
            $errors['sourceid'] = get_string('invaliddata', 'error');
        }

        $ruleid = (int)($data['ruleid'] ?? 0);
        $optionid = (int)($data['optionid'] ?? 0);
        if ($optionid > 0) {
            global $DB;
            $existing = $DB->get_record('booking_sync_rules', [
                'bookingoptionid' => $optionid,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ]);
            if (!empty($existing) && (int)$existing->id !== $ruleid) {
                $errors['sourceid'] = get_string('syncrulealreadyexists', 'mod_booking');
            }
        }

        return $errors;
    }

    /**
     * Form page URL.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/subscribeusers.php');
    }

    /**
     * Context for dynamic submission.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int)($this->_ajaxformdata['cmid'] ?? $this->_customdata['cmid'] ?? 0);
        return context_module::instance($cmid);
    }

    /**
     * Permission check.
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        require_capability('mod/booking:bookforothers', $context);
    }
}

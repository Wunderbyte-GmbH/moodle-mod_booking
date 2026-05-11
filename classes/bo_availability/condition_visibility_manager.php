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
 * Handles visibility of condition settings and fields for skippable conditions.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use context_system;
use moodle_url;
use MoodleQuickForm;


/**
 * Class for managing visibility of condition settings and fields for skippable conditions.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition_visibility_manager {
    /**
     * Retrieves the list of skipped condition IDs from the configuration.
     *
     * @return array
     */
    public function get_skipped_conditions(): array {
        $skippedconditions = get_config('booking', 'skipableconditions');
        if (empty($skippedconditions)) {
            return [];
        }
        $skippedconditionsarray = explode(',', $skippedconditions);
        // Remove any zero values from the array.
        $skippedconditionsarray = array_filter($skippedconditionsarray, fn($v) => $v != "0");
        return $skippedconditionsarray;
    }

    /**
     * Freezes form fields based on condition ID.
     *
     * @param MoodleQuickForm $mform
     * @param int $conditionid
     * @return void
     */
    public function freeze_fields_for_condition(MoodleQuickForm &$mform, int $conditionid): void {
        $firstelementname = null;

        switch ($conditionid) {
            case MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE:
                $firstelementname = 'bo_cond_allowedtobookininstance_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_allowedtobookininstance_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_allowedtobookininstance_capabilitynotneeded');
                $this->disable_element_without_warning($mform, 'bo_cond_allowedtobookininstance_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_allowedtobookininstance_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_allowedtobookininstance_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_BOOKING_TIME:
                $firstelementname = 'restrictanswerperiodopening';
                $this->disable_element_without_warning($mform, 'restrictanswerperiodopening');
                $this->disable_element_without_warning($mform, 'bookingopeningtime');
                $this->disable_element_without_warning($mform, 'restrictanswerperiodclosing');
                $this->disable_element_without_warning($mform, 'bookingclosingtime');
                $this->disable_element_without_warning($mform, 'bo_cond_booking_time_sqlfiltercheck');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMFORM:
                $firstelementname = 'bo_cond_customform_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_customform_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_customform_deleteinfoscheckboxadmin');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS:
                $firstelementname = 'bo_cond_enrolledincohorts_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_cohortids');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_cohortids_operator');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_sqlfiltercheck');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincohorts_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE:
                $firstelementname = 'bo_cond_enrolledincourse_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_courseids');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_courseids_operator');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_sqlfiltercheck');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_enrolledincourse_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY:
                $firstelementname = 'bo_cond_hascompetency_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_hascompetency_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_hascompetency_competencyids');
                $this->disable_element_without_warning($mform, 'bo_cond_hascompetency_competencyids_operator');
                $this->disable_element_without_warning($mform, 'bo_cond_hascompetency_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_hascompetency_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_hascompetency_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING:
                $firstelementname = 'bo_cond_nooverlapping_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_nooverlapping_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_nooverlapping_handling');
                break;
            case MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED:
                $firstelementname = 'bo_cond_previouslybooked_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_previouslybooked_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_previouslybooked_optionid');
                $this->disable_element_without_warning($mform, 'bo_cond_previouslybooked_requirecompletion');
                $this->disable_element_without_warning($mform, 'bo_cond_previouslybooked_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_previouslybooked_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_previouslybooked_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_SELECTUSERS:
                $firstelementname = 'bo_cond_selectusers_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_selectusers_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_selectusers_userids');
                $this->disable_element_without_warning($mform, 'bo_cond_selectusers_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_selectusers_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_selectusers_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD:
                $firstelementname = 'bo_cond_userprofilefield_1_default_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_1_default_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_field');
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_operator');
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_value');
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD:
                $firstelementname = 'bo_cond_userprofilefield_2_custom_restrict';
                $this->disable_element_without_warning($mform, 'bo_cond_userprofilefield_2_custom_restrict');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_field');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_operator');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_value');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_connectsecondfield');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_field2');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_operator2');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_value2');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_sqlfiltercheck');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_overrideconditioncheckbox');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_overrideoperator');
                $this->disable_element_without_warning($mform, 'bo_cond_customuserprofilefield_overridecondition');
                break;
        }

        // Add warning only once, before the first element of the condition.
        if ($firstelementname !== null && $mform->elementExists($firstelementname)) {
            $linktosetting = new moodle_url(
                '/admin/settings.php',
                ['section' => 'modsettingbooking'],
                'admin-skipableconditions'
            );
            $warningname = 'condition_' . $conditionid . '_warning';
            $warningelement = $mform->createElement(
                'static',
                $warningname,
                '',
                get_string('conditionsskippedwarning', 'mod_booking', $linktosetting)
            );
            $mform->insertElementBefore($warningelement, $firstelementname);
        }
    }

    /**
     * Hides form fields based on condition ID.
     *
     * @param MoodleQuickForm $mform
     * @param int $conditionid
     * @return void
     */
    public function hide_fields_for_condition(MoodleQuickForm &$mform, int $conditionid): void {
        switch ($conditionid) {
            case MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE:
                $this->hide_element($mform, 'bo_cond_allowedtobookininstance_restrict');
                $this->hide_element($mform, 'bo_cond_allowedtobookininstance_capabilitynotneeded');
                $this->hide_element($mform, 'bo_cond_allowedtobookininstance_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_allowedtobookininstance_overrideoperator');
                $this->hide_element($mform, 'bo_cond_allowedtobookininstance_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_BOOKING_TIME:
                $this->hide_element($mform, 'restrictanswerperiodopening');
                $this->hide_element($mform, 'bookingopeningtime');
                $this->hide_element($mform, 'restrictanswerperiodclosing');
                $this->hide_element($mform, 'bookingclosingtime');
                $this->hide_element($mform, 'bo_cond_booking_time_sqlfiltercheck');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMFORM:
                $this->hide_element($mform, 'bo_cond_customform_restrict');
                $this->hide_element($mform, 'bo_cond_customform_deleteinfoscheckboxadmin');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS:
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_restrict');
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_cohortids');
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_cohortids_operator');
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_sqlfiltercheck');
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_overrideoperator');
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE:
                $this->hide_element($mform, 'bo_cond_enrolledincourse_restrict');
                $this->hide_element($mform, 'bo_cond_enrolledincourse_courseids');
                $this->hide_element($mform, 'bo_cond_enrolledincourse_courseids_operator');
                $this->hide_element($mform, 'bo_cond_enrolledincourse_sqlfiltercheck');
                $this->hide_element($mform, 'bo_cond_enrolledincourse_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_enrolledincourse_overrideoperator');
                $this->hide_element($mform, 'bo_cond_enrolledincourse_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY:
                $this->hide_element($mform, 'bo_cond_hascompetency_restrict');
                $this->hide_element($mform, 'bo_cond_hascompetency_competencyids');
                $this->hide_element($mform, 'bo_cond_hascompetency_competencyids_operator');
                $this->hide_element($mform, 'bo_cond_hascompetency_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_hascompetency_overrideoperator');
                $this->hide_element($mform, 'bo_cond_hascompetency_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING:
                $this->hide_element($mform, 'bo_cond_nooverlapping_restrict');
                $this->hide_element($mform, 'bo_cond_nooverlapping_handling');
                break;
            case MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED:
                $this->hide_element($mform, 'bo_cond_previouslybooked_restrict');
                $this->hide_element($mform, 'bo_cond_previouslybooked_optionid');
                $this->hide_element($mform, 'bo_cond_previouslybooked_requirecompletion');
                $this->hide_element($mform, 'bo_cond_previouslybooked_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_previouslybooked_overrideoperator');
                $this->hide_element($mform, 'bo_cond_previouslybooked_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_SELECTUSERS:
                $this->hide_element($mform, 'bo_cond_selectusers_restrict');
                $this->hide_element($mform, 'bo_cond_selectusers_userids');
                $this->hide_element($mform, 'bo_cond_selectusers_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_selectusers_overrideoperator');
                $this->hide_element($mform, 'bo_cond_selectusers_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD:
                $this->hide_element($mform, 'bo_cond_userprofilefield_1_default_restrict');
                $this->hide_element($mform, 'bo_cond_userprofilefield_field');
                $this->hide_element($mform, 'bo_cond_userprofilefield_operator');
                $this->hide_element($mform, 'bo_cond_userprofilefield_value');
                $this->hide_element($mform, 'bo_cond_userprofilefield_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_userprofilefield_overrideoperator');
                $this->hide_element($mform, 'bo_cond_userprofilefield_overridecondition');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD:
                $this->hide_element($mform, 'bo_cond_userprofilefield_2_custom_restrict');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_field');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_operator');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_value');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_connectsecondfield');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_field2');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_operator2');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_value2');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_sqlfiltercheck');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_overrideconditioncheckbox');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_overrideoperator');
                $this->hide_element($mform, 'bo_cond_customuserprofilefield_overridecondition');
                break;
        }
    }

    /**
     * Applies freeze and adds warning to all fields from skipped conditions.
     *
     * @param MoodleQuickForm $mform
     * @param int $conditionid
     * @return void
     */
    public function disable_elements_in_mform(MoodleQuickForm &$mform, int $conditionid): void {
        if (has_capability('mod/booking:updatebooking', context_system::instance())) {
            // Users with the updatebooking capability see the skipped conditions but with the fields frozen and a warning added.
            $this->freeze_fields_for_condition($mform, $conditionid);
        } else {
            // A user without the updatebooking capability should not see any skipped conditions at all.
            $this->hide_fields_for_condition($mform, $conditionid);
        }
    }

    /**
     * Freezes a specific form element without adding a warning.
     *
     * @param MoodleQuickForm $mform
     * @param string $elementname
     *
     * @return void
     *
     */
    private function disable_element_without_warning(MoodleQuickForm &$mform, string $elementname) {
        if ($mform->elementExists($elementname)) {
            $mform->freeze($elementname);
        }
    }

    /**
     * Hides a specific form element.
     *
     * @param MoodleQuickForm $mform
     * @param string $elementname
     *
     * @return void
     *
     */
    private function hide_element(MoodleQuickForm &$mform, string $elementname) {
        if (!$mform->elementExists('permanentvalueone')) {
            $mform->addElement('hidden', 'permanentvalueone', 1);
            $mform->setType('permanentvalueone', PARAM_INT);
        }
        if ($mform->elementExists($elementname)) {
            $mform->hideIf($elementname, 'permanentvalueone', 'eq', 1);
        }
        // Also hide the associated <hr> wrapper div if it exists.
        $hrid = $elementname . '_hr';
        $mform->addElement('html', '<script>document.getElementById("' . $hrid . '")?.remove();</script>');
    }

    /**
     * Checks if a condition is skipped.
     *
     * @param int $conditionid
     *
     * @return bool
     *
     */
    public function is_condition_skipped(int $conditionid): bool {
        $skippedconditions = $this->get_skipped_conditions();
        if (empty($skippedconditions)) {
            return false;
        }
        if (in_array($conditionid, $skippedconditions)) {
            return true;
        }
        return false;
    }
}

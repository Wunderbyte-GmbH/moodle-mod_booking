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
        switch ($conditionid) {
            case MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE:
                $this->disable_element($mform, 'bo_cond_allowedtobookininstance_restrict');
                break;
            case MOD_BOOKING_BO_COND_BOOKING_TIME:
                $this->disable_element($mform, 'restrictanswerperiodopening');
                $this->disable_element($mform, 'restrictanswerperiodclosing');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMFORM:
                $this->disable_element($mform, 'bo_cond_customform_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS:
                $this->disable_element($mform, 'bo_cond_enrolledincohorts_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE:
                $this->disable_element($mform, 'bo_cond_enrolledincourse_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY:
                $this->disable_element($mform, 'bo_cond_hascompetency_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING:
                $this->disable_element($mform, 'bo_cond_nooverlapping_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED:
                $this->disable_element($mform, 'bo_cond_previouslybooked_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_SELECTUSERS:
                $this->disable_element($mform, 'bo_cond_selectusers_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD:
                $this->disable_element($mform, 'bo_cond_userprofilefield_1_default_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD:
                $this->disable_element($mform, 'bo_cond_userprofilefield_2_custom_restrict');
                break;
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
                break;
            case MOD_BOOKING_BO_COND_BOOKING_TIME:
                $this->hide_element($mform, 'restrictanswerperiodopening');
                $this->hide_element($mform, 'restrictanswerperiodclosing');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMFORM:
                $this->hide_element($mform, 'bo_cond_customform_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS:
                $this->hide_element($mform, 'bo_cond_enrolledincohorts_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE:
                $this->hide_element($mform, 'bo_cond_enrolledincourse_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY:
                $this->hide_element($mform, 'bo_cond_hascompetency_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING:
                $this->hide_element($mform, 'bo_cond_nooverlapping_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED:
                $this->hide_element($mform, 'bo_cond_previouslybooked_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_SELECTUSERS:
                $this->hide_element($mform, 'bo_cond_selectusers_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD:
                $this->hide_element($mform, 'bo_cond_userprofilefield_1_default_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD:
                $this->hide_element($mform, 'bo_cond_userprofilefield_2_custom_restrict');
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
     * Freezes a specific form element and adds a warning message.
     *
     * @param MoodleQuickForm $mform
     * @param string $elementname
     *
     * @return void
     *
     */
    private function disable_element(MoodleQuickForm &$mform, string $elementname) {
        if ($mform->elementExists($elementname)) {
            $linktosetting = new moodle_url(
                '/admin/settings.php',
                ['section' => 'modsettingbooking'],
                'admin-skipableconditions'
            );
            $mform->freeze($elementname);
            $warningname = $elementname . '_warning';
            $warningelement = $mform->createElement(
                'static',
                $warningname,
                '',
                get_string('conditionsskippedwarning', 'mod_booking', $linktosetting)
            );
            $mform->insertElementBefore($warningelement, $elementname);
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

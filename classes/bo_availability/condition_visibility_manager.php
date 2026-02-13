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

use admin_setting;
use admin_setting_flag;
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
        $skippedconditions = get_config('booking', 'skippableconditions');
        if (empty($skippedconditions)) {
            return [];
        }
        return explode(',', $skippedconditions);
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
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE:
                $this->freeze_element($mform, 'bo_cond_enrolledincourse_restrict');
                break;

            case MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD:
                $this->freeze_element($mform, 'bo_cond_userprofilefield_2_custom_restrict');
                break;
            case MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS:
                $this->freeze_element($mform, 'bo_cond_enrolledincohorts_restrict');
                break;
        }
    }

    /**
     * Locks a setting for a condition. Experimental.
     *
     * @param admin_setting $setting
     *
     * @return void
     *
     */
    public function lock_setting(admin_setting $setting) {
        $setting->set_locked_flag_options(admin_setting_flag::ENABLED, true);
    }
    /**
     * Applies freezing to all skipped conditions.
     *
     * @param MoodleQuickForm $mform
     * @param int $conditionid
     * @return void
     */
    public function apply_freeze_to_mform(MoodleQuickForm &$mform, int $conditionid): void {
            $this->freeze_fields_for_condition($mform, $conditionid);
    }

    /**
     * Freezes a specific form element. It is a fallback since form elements might not exist depending on other settings.
     *
     * @param MoodleQuickForm $mform
     * @param string $elementname
     *
     * @return void
     *
     */
    public function freeze_element(MoodleQuickForm &$mform, string $elementname) {
        if ($mform->elementExists($elementname)) {
            $mform->freeze($elementname);
        }
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
        if (empty($this->get_skipped_conditions())) {
            return false;
        }
        if (in_array($conditionid, $this->get_skipped_conditions())) {
            return true;
        }
        return false;
    }
}

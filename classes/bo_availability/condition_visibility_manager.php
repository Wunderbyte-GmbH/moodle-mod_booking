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
        $statehelper = new condition_state_helper();
        $skippedconditions = [];

        foreach (bo_info::get_skippable_conditions() as $conditionid => $conditionname) {
            if ($statehelper->should_skip_condition((int)$conditionid)) {
                $skippedconditions[] = (int)$conditionid;
            }
        }

        return $skippedconditions;
    }
    /**
     * Freezes all form fields declared by the condition and inserts a warning message.
     *
     * @param MoodleQuickForm $mform
     * @param freezable_condition $condition
     * @param bool $skipandfreeze True for skip-and-freeze state, false for freeze-only state.
     * @return void
     */
    public function freeze_fields_for_condition(
        MoodleQuickForm &$mform,
        freezable_condition $condition,
        bool $skipandfreeze = true
    ): void {
        $elements = $condition->get_condition_form_elements();
        $firstelementname = $elements[0] ?? null;
        foreach ($elements as $elementname) {
            $this->disable_element_without_warning($mform, $elementname);
        }
        $this->insert_warning_before_first_element($mform, $condition->id, $firstelementname, $skipandfreeze);
    }
    /**
     * Hides all form fields declared by the condition.
     *
     * @param MoodleQuickForm $mform
     * @param freezable_condition $condition
     * @return void
     */
    public function hide_fields_for_condition(MoodleQuickForm &$mform, freezable_condition $condition): void {
        foreach ($condition->get_condition_form_elements() as $elementname) {
            $this->hide_element($mform, $elementname);
        }
    }

    /**
     * Applies freeze or hide to all form fields of the condition based on user capability.
     *
     * @param MoodleQuickForm $mform
     * @param bo_condition $condition
     * @param bool $showwarning True for skip-and-freeze warning, false for freeze-only warning.
     * @return void
     */
    public function disable_elements_in_mform(
        MoodleQuickForm &$mform,
        bo_condition $condition,
        bool $showwarning = true
    ): void {
        if (!($condition instanceof freezable_condition)) {
            return;
        }
        if (has_capability('mod/booking:updatebooking', context_system::instance())) {
            // Users with the updatebooking capability see frozen fields with a warning.
            $this->freeze_fields_for_condition($mform, $condition, $showwarning);
        } else {
            // Users without the capability do not see frozen/skipped conditions at all.
            $this->hide_fields_for_condition($mform, $condition);
        }
    }

    /**
     * Checks if a condition should be frozen in the option form.
     *
     * @param int $conditionid
     * @return bool
     */
    public function is_condition_frozen(int $conditionid): bool {
        $statehelper = new condition_state_helper();
        return $statehelper->should_freeze_condition($conditionid);
    }

    /**
     * Inserts a single warning before the first element of a condition block.
     *
     * @param MoodleQuickForm $mform
     * @param int $conditionid
     * @param string|null $firstelementname
     * @param bool $skipandfreeze
     * @return void
     */
    private function insert_warning_before_first_element(
        MoodleQuickForm &$mform,
        int $conditionid,
        ?string $firstelementname,
        bool $skipandfreeze
    ): void {
        if ($firstelementname === null || !$mform->elementExists($firstelementname)) {
            return;
        }

        $warningkey = $skipandfreeze ? 'conditionsskippedwarning' : 'conditionsfrozenwarning';
        $linktosetting = new moodle_url('/mod/booking/availabilityconditions.php');
        $warningname = 'condition_' . $conditionid . '_warning';
        $warningelement = $mform->createElement(
            'static',
            $warningname,
            '',
            get_string($warningkey, 'mod_booking', $linktosetting)
        );
        $mform->insertElementBefore($warningelement, $firstelementname);
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
        $statehelper = new condition_state_helper();
        return $statehelper->should_skip_condition($conditionid);
    }
}

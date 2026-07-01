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
     * Freezes all form fields declared by the condition and adds the warning as a normal static
     * element.
     *
     * By default the warning is placed above the condition's fields (the standard behaviour). When
     * the 'conditionwarningatbottom' admin setting is enabled, it is instead placed at the bottom
     * of the condition, above its trailing <hr> divider.
     *
     * @param MoodleQuickForm $mform
     * @param freezable_condition $condition
     * @param bool $skipandfreeze True for the skip-and-freeze warning, false for freeze-only.
     * @return void
     */
    public function freeze_fields_for_condition(
        MoodleQuickForm &$mform,
        freezable_condition $condition,
        bool $skipandfreeze = true
    ): void {
        $elements = $condition->get_condition_form_elements();
        foreach ($elements as $elementname) {
            $this->disable_element_without_warning($mform, $elementname);
        }

        $firstelementname = $elements[0] ?? null;
        if ($firstelementname === null || !$mform->elementExists($firstelementname)) {
            return;
        }

        $warningkey = $skipandfreeze ? 'conditionsskippedwarning' : 'conditionsfrozenwarning';
        $linktosetting = new moodle_url('/mod/booking/availabilityconditions.php');
        $warningelement = $mform->createElement(
            'static',
            $firstelementname . '_frozenwarning',
            '',
            get_string($warningkey, 'mod_booking', $linktosetting)
        );

        if (empty(get_config('booking', 'conditionwarningatbottom'))) {
            // Standard behaviour: warning above the condition's fields.
            $mform->insertElementBefore($warningelement, $firstelementname);
            return;
        }

        // Optional behaviour: warning at the bottom of the condition, above its trailing <hr>
        // divider. Conditions end with an unnamed <hr> divider element, and QuickForm indexes
        // every unnamed element under the empty name, so when that divider is the last element on
        // the form the empty anchor targets it - letting us drop the warning in just above it.
        $lastkey = array_key_last($mform->_elements);
        $last = $lastkey === null ? null : $mform->_elements[$lastkey];
        if ($last !== null && $last->getType() === 'html' && strpos($last->toHtml(), '<hr') !== false) {
            $mform->insertElementBefore($warningelement, '');
        } else {
            $mform->addElement($warningelement);
        }
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
     * @param bool $skipandfreeze True for the skip-and-freeze warning, false for freeze-only.
     * @return void
     */
    public function disable_elements_in_mform(
        MoodleQuickForm &$mform,
        bo_condition $condition,
        bool $skipandfreeze = true
    ): void {
        if (!($condition instanceof freezable_condition)) {
            return;
        }
        if (has_capability('mod/booking:updatebooking', context_system::instance())) {
            // Users with the updatebooking capability see frozen fields with a warning.
            $this->freeze_fields_for_condition($mform, $condition, $skipandfreeze);
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

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
 * Interface for booking conditions that declare their own form elements for freeze/hide.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

/**
 * Interface for booking conditions whose option-form elements can be frozen or hidden.
 *
 * Implement this interface in a condition class to remove the need for a central
 * switch-statement in condition_visibility_manager. The first element in the returned
 * array is used as the anchor point for inserting the freeze/skip warning message.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface freezable_condition {
    /**
     * Returns an ordered list of all MoodleQuickForm element names that this condition
     * adds to the option form. The first element is used as the warning insertion anchor.
     *
     * Elements that are only conditionally rendered (e.g. relative-mode pickers) should
     * still be listed here; condition_visibility_manager checks elementExists() before
     * acting on each name, so absent elements are silently skipped.
     *
     * @return string[]
     */
    public function get_condition_form_elements(): array;
}

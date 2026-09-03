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
 * Modal dynamic form to send a custom message to the responsible contacts of a booking option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use mod_booking\singleton_service;

/**
 * Modal dynamic form to send a custom message to the responsible contacts of a booking option in report2.php.
 *
 * Works exactly like modal_send_message_to_teachers, but the recipient pool and
 * the preselection are the responsible contact(s) of the booking option instead
 * of its teachers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_send_message_to_responsiblecontacts extends modal_send_custom_message {
    /**
     * Get the responsible contacts of the booking option as autocomplete options.
     *
     * @param int $optionid Booking option ID.
     * @return array<int, string>
     */
    protected function get_possible_recipients_for_custom_message(int $optionid): array {
        if (empty($optionid)) {
            return [];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $options = [];
        // Missing (e.g. deleted) users resolve to false, filter them out.
        foreach (array_filter($settings->responsiblecontactuser) as $contactuser) {
            $name = trim($contactuser->firstname . ' ' . $contactuser->lastname);
            $options[(int)$contactuser->id] = $name . ' (' . (int)$contactuser->id . ')';
        }

        return $options;
    }

    /**
     * Set data for dynamic submission: preselect all responsible contacts of the
     * booking option (instead of the checked users of the table).
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object) $this->_ajaxformdata;
        if (empty($data->selecteduserids) || !is_array($data->selecteduserids)) {
            $data->selecteduserids = array_keys(
                $this->get_possible_recipients_for_custom_message((int)($data->optionid ?? 0))
            );
        }
        // Initialise the filepicker with a fresh draft item so the upload widget renders correctly.
        $data->attachment = file_get_unused_draft_itemid();
        $this->set_data($data);
    }

    /**
     * Messages to responsible contacts never fire the custom_bulk_message_sent event:
     * its "share of booked users" semantics don't apply to the contact pool.
     *
     * @return bool
     */
    protected function should_fire_bulk_event(): bool {
        return false;
    }
}

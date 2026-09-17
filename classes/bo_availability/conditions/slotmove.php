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
 * Self-service slot rebooking (move) prepage condition.
 *
 * Sits above alreadybooked (id 155 > 150): when a booked participant may move their own
 * slot (opt-in + deadline + future slot, gated by slot_mover::self_rebooking_allowed()),
 * this condition owns the booked-state button and opens a prepage that shows the move
 * calendar. The move itself is committed via the move_slot webservice (slot_mover::move_self),
 * never through the normal bookit flow — hence hard_block() always blocks booking.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\singleton_service;
use MoodleQuickForm;

/**
 * Condition that surfaces the self-service "move slot" entry for a booked user.
 */
class slotmove implements bo_condition {
    /** @var int hardcoded condition id (above alreadybooked) */
    public $id = MOD_BOOKING_BO_COND_SLOTMOVE;

    /** @var bool billboard override flag */
    public $overwrittenbybillboard = false;

    /**
     * Get condition id.
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Hardcoded condition (not stored in option JSON).
     *
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false;
    }

    /**
     * Not shown in the option condition form.
     *
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return false;
    }

    /**
     * Condition name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('bocondslotmove', 'mod_booking');
    }

    /**
     * Not skippable.
     *
     * @return bool
     */
    public function is_skippable(): bool {
        return false;
    }

    /**
     * Available (does not block) unless the user may self-rebook their own booked slot.
     *
     * @param booking_option_settings $settings option settings
     * @param int $userid user id
     * @param bool $not invert flag
     * @return bool
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {
        $answer = slot_mover::get_self_rebookable_answer((int)$settings->id, (int)$userid);

        // Block (= take over the button + move prepage) only when moving is the *only* action.
        // In a book-again (multiplebookings) state the normal booking flow owns the prepage and
        // the move is offered as a tab inside it (Fall 2), so slotmove must stay available there.
        $isavailable = $answer === null || slot_mover::book_again_active((int)$settings->id, $answer);

        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Optional SQL filter support.
     *
     * @param int $userid user id
     * @param array $params sql params
     * @return array
     */
    public function return_sql(int $userid = 0, &$params = []): array {
        return ['', '', '', [], ''];
    }

    /**
     * The move never books through the bookit flow; it commits via the move_slot
     * webservice and closes the prepage. Always block the normal booking action.
     *
     * @param booking_option_settings $settings option settings
     * @param int $userid user id
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {
        return true;
    }

    /**
     * Return condition description tuple.
     *
     * When the user may rebook, this condition owns the button (MYBUTTON) and a prebook
     * prepage (the move calendar). Otherwise it stays indifferent.
     *
     * @param booking_option_settings $settings option settings
     * @param int|null $userid user id
     * @param bool $full full mode
     * @param bool $not invert flag
     * @return array
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {
        $isavailable = $this->is_available($settings, (int)$userid, $not);

        if ($isavailable) {
            return [true, '', MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_INDIFFERENT];
        }

        return [$isavailable, '', MOD_BOOKING_BO_PREPAGE_PREBOOK, MOD_BOOKING_BO_BUTTON_MYBUTTON];
    }

    /**
     * No mform elements.
     *
     * @param MoodleQuickForm $mform form
     * @param int $optionid option id
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        return;
    }

    /**
     * Render the move-calendar prepage.
     *
     * @param int $optionid option id
     * @param int $userid user id
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0) {
        $answer = slot_mover::get_self_rebookable_answer($optionid, $userid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $dataarray['data'] = [
            'optionid' => $optionid,
            'userid' => $userid,
            'baid' => $answer ? (int)$answer->id : 0,
            'cmid' => (int)$settings->cmid,
        ];

        return [
            'data' => [$dataarray],
            'template' => 'mod_booking/condition/slotmove',
            'buttontype' => 1,
        ];
    }

    /**
     * Render the booked-state button: looks like "booked" with a smaller "move slot" hint.
     * The whole button-area opens the move prepage (per decision: clicking either part is fine).
     *
     * @param booking_option_settings $settings option settings
     * @param int $userid user id
     * @param bool $full full mode
     * @param bool $not invert mode
     * @param bool $fullwidth full width
     * @return array
     */
    public function render_button(
        booking_option_settings $settings,
        int $userid = 0,
        bool $full = false,
        bool $not = false,
        bool $fullwidth = true
    ): array {
        $label = get_string('slot_move_booked_label', 'mod_booking')
            . '<div class="booking-slot-move-hint small mt-1">'
            . get_string('slot_move_action', 'mod_booking') . '</div>';

        return bo_info::render_button(
            $settings,
            $userid,
            $label,
            'alert alert-success',
            false,
            $fullwidth,
            'button',
            'option',
            false,
            'noforward'
        );
    }
}

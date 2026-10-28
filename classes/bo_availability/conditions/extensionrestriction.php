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

namespace mod_booking\bo_availability\conditions;

use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\local\bookingworkflow\bookingrestriction;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A booking extension does not let the user book the option, for example because the trainer books for everybody.
 *
 * The extensions are asked by bookingrestriction. Their reason is shown instead of the booking button.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extensionrestriction implements bo_condition {
    /** @var int */
    public $id = MOD_BOOKING_BO_COND_EXTENSIONRESTRICTION;

    /** @var bool */
    public $overwrittenbybillboard = false;

    /**
     * Id of the condition.
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Hardcoded condition, not stored in the json of the option.
     *
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false;
    }

    /**
     * Not shown in the option form.
     *
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return false;
    }

    /**
     * Name of the condition.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('bocondextensionrestriction', 'mod_booking');
    }

    /**
     * The condition can not be skipped.
     *
     * @return bool
     */
    public function is_skippable(): bool {
        return false;
    }

    /**
     * Available if no booking extension stops the user.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param bool $not
     * @return bool
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {
        $isavailable = bookingrestriction::get_reason((int)$settings->id, $userid) === null;
        return $not ? !$isavailable : $isavailable;
    }

    /**
     * No SQL, the condition is checked for each option.
     *
     * @param int $userid
     * @param array $params
     * @return array
     */
    public function return_sql(int $userid = 0, &$params = []): array {
        return ['', '', '', [], ''];
    }

    /**
     * Blocks, unless the user may override the conditions.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {
        return !has_capability('mod/booking:overrideboconditions', context_system::instance());
    }

    /**
     * The reason of the extension, if it stops the user.
     *
     * @param booking_option_settings $settings
     * @param int|null $userid
     * @param bool $full
     * @param bool $not
     * @return array
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {
        $isavailable = $this->is_available($settings, (int)$userid, $not);
        $description = $isavailable ? '' : $this->get_description_string($settings, (int)$userid);
        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_MYALERT];
    }

    /**
     * Nothing to add to the option form.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
    }

    /**
     * No page in the booking process.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0) {
        return [];
    }

    /**
     * Shows the reason instead of the booking button.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param bool $full
     * @param bool $not
     * @param bool $fullwidth
     * @return array
     */
    public function render_button(
        booking_option_settings $settings,
        int $userid = 0,
        bool $full = false,
        bool $not = false,
        bool $fullwidth = true
    ): array {
        $label = $this->get_description_string($settings, $userid);
        return bo_info::render_button($settings, $userid, $label, 'alert alert-info', true, $fullwidth, 'alert', 'option');
    }

    /**
     * The reason given by the extension, or the billboard text if the option has one.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return string
     */
    private function get_description_string(booking_option_settings $settings, int $userid): string {
        if ($this->overwrittenbybillboard && !empty($text = bo_info::apply_billboard($this, $settings))) {
            return $text;
        }
        return bookingrestriction::get_reason((int)$settings->id, $userid)
            ?? get_string('bocondextensionrestriction', 'mod_booking');
    }
}

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
 * Slot booking prepage condition.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_feature;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_price;
use MoodleQuickForm;
use mod_booking\utils\wb_payment;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Condition that injects slot selection into existing prepage modal flow.
 */
class slotbooking implements bo_condition {
    /** @var int hardcoded condition id */
    public $id = MOD_BOOKING_BO_COND_SLOTBOOKING;

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
     * Hardcoded condition.
     *
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false;
    }

    /**
     * Not shown in option condition form.
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
        return get_string('bocondslotbooking', 'mod_booking');
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
     * Check if condition is currently available.
     *
     * @param booking_option_settings $settings option settings
     * @param int $userid user id
     * @param bool $not invert flag
     * @return bool
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {
        $isavailable = true;

        if ($this->is_slot_booking_enabled($settings)) {
            // Keep the prepage condition visible/stable throughout the booking flow.
            // The actual slot selection check is done in hard_block().
            $isavailable = false;
        }

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
     * Hard block if slot is required but not selected/available.
     *
     * @param booking_option_settings $settings option settings
     * @param int $userid user id
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {
        if (!$this->is_slot_booking_enabled($settings)) {
            return false;
        }

        if (!$this->is_slot_booking_available_in_license()) {
            return true;
        }

        $context = context_system::instance();
        if (has_capability('mod/booking:overrideboconditions', $context)) {
            return false;
        }

        $store = new slotbookingstore((int)$userid, (int)$settings->id);
        $data = $store->get_slotbooking_data();
        $ranges = $store->get_selected_ranges($data);
        if (empty($ranges)) {
            return true;
        }

        $maxslots = $this->get_max_slots_per_user((int)$settings->id);
        if (count($ranges) > $maxslots) {
            return true;
        }

        // A cached selection can already be the user's own persisted answer (e.g. once it was
        // added to the shopping cart) - "book again" (multiplebookings) can even leave more than
        // one active answer for this option, so exclude all of them, or a slot the user already
        // holds is counted as an occupant against itself and wrongly re-blocks the flow.
        $ownanswerids = slot_availability::get_active_answer_ids_for_user((int)$settings->id, (int)$userid);

        // Slot keys the user already holds for this option. The check above only catches "more
        // slots selected than max_slots_per_user allows in one submission" - it does not account for
        // slots already booked in an EARLIER submission. Re-submitting one of those (e.g. simply
        // stepping through a later prepage condition of an already-made selection) must keep
        // working even once capacity is fully used up, so this only ever blocks a genuinely NEW,
        // additional slot - evaluate_slot_for_user() below does not check capacity at all, so
        // without this, hard_block() would let a selection past that book_user_on_option()
        // (booking_option.php) then silently no-ops on instead of ever actually creating.
        $ownslotkeys = array_map(
            static fn(array $range): string => $range['start'] . ':' . $range['end'],
            slot_availability::get_booked_slot_ranges_for_user((int)$settings->id, (int)$userid)
        );
        $newrangecount = count(array_filter(
            $ranges,
            static fn(array $range): bool => !in_array($range[0] . ':' . $range[1], $ownslotkeys, true)
        ));
        if ($newrangecount > 0 && (count($ownslotkeys) + $newrangecount) > $maxslots) {
            return true;
        }

        $teachersrequired = slot_availability::get_teachers_required((int)$settings->id);
        $teacherselection = $store->get_selected_teachers_by_slot($data);

        foreach ($ranges as [$start, $end]) {
            $selectedteachers = [];
            if ($teachersrequired > 0) {
                $slotkey = $start . ':' . $end;
                $selectedteachers = $teacherselection[$slotkey] ?? [];
                if (count($selectedteachers) !== $teachersrequired) {
                    return true;
                }
            }

            $evaluation = slot_availability::evaluate_slot_for_user(
                (int)$settings->id,
                (int)$start,
                (int)$end,
                (int)$userid,
                $selectedteachers,
                excludeanswerids: $ownanswerids
            );
            if (empty($evaluation['bookable'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return condition description tuple.
     *
     * @param booking_option_settings $settings option settings
     * @param int|null $userid user id
     * @param bool $full full mode
     * @param bool $not invert flag
     * @return array
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {
        if (!$this->is_slot_booking_enabled($settings)) {
            return [true, '', MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_INDIFFERENT];
        }

        if (!$this->is_slot_booking_available_in_license()) {
            return [
                false,
                get_string('proversiononly', 'mod_booking'),
                MOD_BOOKING_BO_PREPAGE_NONE,
                MOD_BOOKING_BO_BUTTON_INDIFFERENT,
            ];
        }

        $isavailable = $this->is_available($settings, (int)$userid, $not);
        $description = $isavailable ? '' : get_string('slot_select_required', 'mod_booking');

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_PREBOOK, MOD_BOOKING_BO_BUTTON_INDIFFERENT];
    }

    /**
     * No mform elements.
     *
     * @param MoodleQuickForm $mform form
     * @param int $optionid option id
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
    }

    /**
     * Render prepage for slot selection.
     *
     * @param int $optionid option id
     * @param int $userid user id
     * @param array $additionaloptionids further option ids to merge into the calendar (sidebar filter)
     * @param bool $hidesidebar suppress the sidebar even when multiple option ids are given
     * @return array
     */
    public function render_page(
        int $optionid,
        int $userid = 0,
        array $additionaloptionids = [],
        bool $hidesidebar = false
    ) {
        $optionids = array_values(array_unique(array_merge([$optionid], $additionaloptionids)));
        $ismultioption = count($optionids) > 1;

        $data = [
            'optionid' => $optionid,
            'userid' => $userid,
            'multioption' => $ismultioption,
        ];

        if ($ismultioption) {
            $data['optionidsjson'] = json_encode($optionids);

            // Merged options can have entirely different prepage condition sequences (different
            // step counts, different conditions), so once the user actually books a slot from a
            // non-primary option, the JS hard-redirects to THAT option's own booking page instead
            // of trying to continue this page's wizard for it - see condition/slotBooking.js. Build
            // that URL for every merged option here (independent of hidesidebar - the sidebar can be
            // hidden while the merge/filter/booking plumbing still works).
            // Also carries whether each merged option actually has a price - addSwitchedOptionToCart
            // in the JS needs this to decide whether a completed add-to-cart call actually finished
            // the booking (free, no cart involved) or only added it to a PAID cart still awaiting
            // checkout. Reading it here (the option's own useprice) is the only reliable source -
            // reconstructing it from the selected slot's own data does not work for every slot type
            // (e.g. userdefined has no per-slot price at all, only a per-option one).
            $optionurls = [];
            $optionuseprices = [];
            foreach ($optionids as $urloptionid) {
                try {
                    $urlsettings = singleton_service::get_instance_of_booking_option_settings($urloptionid);
                } catch (\Throwable $e) {
                    continue;
                }
                if (empty($urlsettings->id)) {
                    continue;
                }
                $optionurls[$urlsettings->id] = (new moodle_url(
                    '/mod/booking/optionview.php',
                    ['cmid' => $urlsettings->cmid, 'optionid' => $urlsettings->id]
                ))->out(false);
                $optionuseprices[$urlsettings->id] = !empty($urlsettings->useprice);
            }
            $data['optionurlsjson'] = json_encode($optionurls);
            $data['optionpricesjson'] = json_encode($optionuseprices);
        }

        // Multi-option sidebar: only relevant once there's more than one option to choose from,
        // and only when the caller hasn't asked to hide it (see shortcodes::bookingoptionview()).
        $showsidebar = $ismultioption && !$hidesidebar;
        $data['showsidebar'] = $showsidebar;
        if ($showsidebar) {
            $sidebaroptions = [];
            foreach ($optionids as $sidebaroptionid) {
                try {
                    $sidebarsettings = singleton_service::get_instance_of_booking_option_settings($sidebaroptionid);
                } catch (\Throwable $e) {
                    continue;
                }
                if (empty($sidebarsettings->id)) {
                    continue;
                }
                $sidebaroptions[] = [
                    'optionid' => $sidebarsettings->id,
                    'name' => format_string($sidebarsettings->text),
                ];
            }
            $data['sidebaroptions'] = $sidebaroptions;
        }

        // Fall 2: in a book-again (multiplebookings) state, a booked user who may also self-rebook
        // is offered the move as a tab inside this same prepage. Deliver the hidden move-tab mount
        // data (gated by self_rebooking_allowed + book_again_active); the JS wires the tabs.
        $answer = slot_mover::get_self_rebookable_answer($optionid, $userid);
        if ($answer !== null && slot_mover::book_again_active($optionid, $answer)) {
            $data['showmovetab'] = true;
            $data['baid'] = (int)$answer->id;
        }

        $dataarray['data'] = $data;

        return [
            'data' => [$dataarray],
            'template' => 'mod_booking/condition/slotbooking',
            'buttontype' => 1,
        ];
    }

    /**
     * No dedicated button.
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
        return ['', ''];
    }

    /**
     * Persist selected slot values into booking answer JSON and date columns.
     *
     * @param stdClass $newanswer booking answer object to enrich
     * @param int $userid user id
     * @param int $excludeanswerid booking answer id to ignore in slot occupancy checks
     * @return void
     */
    public static function add_json_to_booking_answer(
        stdClass &$newanswer,
        int $userid,
        int $excludeanswerid = 0
    ): void {
        global $DB;

        $optionid = (int)($newanswer->optionid ?? 0);
        if (empty($optionid)) {
            return;
        }

        $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new self();
        if (!$condition->is_slot_booking_enabled($settings)) {
            return;
        }

        $store = new slotbookingstore($userid, $optionid);
        $cached = $store->get_slotbooking_data();
        $ranges = $store->get_selected_ranges($cached);
        if (empty($ranges)) {
            return;
        }

        $maxslots = $condition->get_max_slots_per_user($optionid);
        if (count($ranges) > $maxslots) {
            throw new moodle_exception('slot_error_selection_toomany', 'mod_booking');
        }

        // Each range is checked individually against the option's existing bookings below; that
        // does not catch two ranges from this same selection overlapping each other, since
        // neither is persisted yet. Reject that invalid combination before it is ever saved.
        if (slot_availability::ranges_overlap_internally($ranges)) {
            throw new moodle_exception('slot_error_selection_overlap', 'mod_booking');
        }

        $teachersrequired = slot_availability::get_teachers_required($optionid);
        $teacherselection = $store->get_selected_teachers_by_slot($cached);

        $slots = [];
        $minstart = 0;
        $maxend = 0;
        $allteacherids = [];
        $teachersperslot = [];

        foreach ($ranges as [$start, $end]) {
            $selectedteachers = [];
            if ($teachersrequired > 0) {
                $slotkey = $start . ':' . $end;
                $selectedteachers = array_values(array_unique(array_filter(
                    array_map('intval', $teacherselection[$slotkey] ?? []),
                    function ($id) {
                        return $id > 0;
                    }
                )));

                if (count($selectedteachers) !== $teachersrequired) {
                    throw new moodle_exception('slot_error_selection_required', 'mod_booking');
                }
            }

            $evaluation = slot_availability::evaluate_slot_for_user(
                $optionid,
                (int)$start,
                (int)$end,
                (int)$userid,
                $selectedteachers,
                $excludeanswerid,
                0,
                true
            );
            if (empty($evaluation['bookable'])) {
                throw new moodle_exception('slot_error_selected_unavailable', 'mod_booking');
            }

            $slots[] = [
                'start' => $start,
                'end' => $end,
            ];

            $teachersperslot[] = [
                'start' => $start,
                'end' => $end,
                'teachers' => $selectedteachers,
            ];
            $allteacherids = array_merge($allteacherids, $selectedteachers);

            if ($minstart === 0 || $start < $minstart) {
                $minstart = $start;
            }
            if ($end > $maxend) {
                $maxend = $end;
            }
        }

        if ($minstart <= 0 || $maxend <= $minstart) {
            return;
        }

        $newanswer->startdate = $minstart;
        $newanswer->enddate = $maxend;

        $slotdata = [
            'slots' => $slots,
            'num_slots' => count($slots),
            'price' => \mod_booking\local\slotbooking\slot_price::calculate_price(
                $optionid,
                count($slots),
                (int)$userid,
                $slots
            ),
            'teachers_per_slot' => $teachersperslot,
            'teachers' => array_values(array_unique($allteacherids)),
        ];

        slot_answer::set_slot_data($newanswer, $slotdata);

        if (
            isset($newanswer->waitinglist)
            && in_array(
                (int)$newanswer->waitinglist,
                [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED],
                true
            )
        ) {
            $store->delete_slotbooking_data();
        }
    }

    /**
     * Whether option uses slot booking.
     *
     * @param booking_option_settings $settings option settings
     * @return bool
     */
    private function is_slot_booking_enabled(booking_option_settings $settings): bool {
        return !empty($settings->slotconfig);
    }

    /**
     * Whether slot booking is available with current license.
     *
     * @return bool
     */
    private function is_slot_booking_available_in_license(): bool {
        return slot_feature::is_enabled();
    }

    /**
     * Returns max number of slots user can select for this option.
     *
     * @param int $optionid option id
     * @return int
     */
    private function get_max_slots_per_user(int $optionid): int {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $value = (int)($settings->slotconfig->max_slots_per_user ?? 1);
        return max(1, $value);
    }
}

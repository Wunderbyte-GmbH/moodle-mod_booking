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
 * Handling the booking process for subbookings.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\bo_availability\bo_subinfo;
use mod_booking\output\bookingoption_description;
use mod_booking\output\bookit_button;
use mod_booking\output\renderer;
use mod_booking\subbookings\subbookings_info;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for handling the booking process for subbookings.
 *
 * In the most simple case, this class provides a button for a user to book a subbooking option.
 * But this class handles the process, together with bo_conditions, prices and further functionalities...
 * ... as an integrative process.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_subbookit {

    /** @var booking_option_settings $settings */
    public $settings = null;

    /**
     * Renders the book it button for a given user and returns the rendered html as string.
     * This also includes a top and a bottom section which can be rendered seperately.
     *
     * @param booking_option_settings $settings
     * @param int $subbookingid
     * @param int $userid
     * @return string
     */
    public static function render_bookit_button(booking_option_settings $settings, int $subbookingid, int $userid = 0) {

        global $PAGE;
        $PAGE->set_context(context_system::instance());

        $output = $PAGE->get_renderer('mod_booking');

        list($templates, $datas) = self::render_bookit_template_data($settings, $subbookingid, $userid);

        $html = '';

        foreach ($templates as $template) {
            $data = array_shift($datas);
            $html .= $output->render_bookit_button($data, $template);
        }

        return $html;
    }

    /**
     * This is used to get template name & data as an array to render bookit-button (component).
     *
     * @param booking_option_settings $settings
     * @param int $subbookingid
     * @param int $userid
     * @param bool $renderprepagemodal
     * @return array
     */
    public static function render_bookit_template_data(
        booking_option_settings $settings,
        int $subbookingid,
        int $userid = 0,
        bool $renderprepagemodal = true) {

        // Get blocking conditions, including prepages$prepages etc.
        $results = bo_subinfo::get_subcondition_results($settings->id, $subbookingid, $userid);
        // Decide, wether to show the direct booking button or a modal.

        $showinmodalbutton = true;
        $extrabuttoncondition = '';
        $justmyalert = false;
        foreach ($results as $result) {

            switch ($result['button'] ) {
                case MOD_BOOKING_BO_BUTTON_MYBUTTON:
                    $buttoncondition = $result['classname'];
                    break;
                case MOD_BOOKING_BO_BUTTON_MYALERT;
                    // Here we could use a more sophisticated way of rights management.
                    // Right now, the logic is just linked to one right.
                    $context = context_module::instance(($settings->cmid));
                    if (has_capability('mod/booking:bookforothers', $context)) {
                        // We still render the alert, but just in supplement to the other butotn.
                        $extrabuttoncondition = $result['classname'];
                    } else {
                        $buttoncondition = $result['classname'];
                    }
                    break;
                case MOD_BOOKING_BO_BUTTON_JUSTMYALERT:
                    // The JUST MY ALERT prevents other buttons to be displayed.
                    $justmyalert = true;
                    $buttoncondition = $result['classname'];
                    break;
            }
        }

        $context = context_module::instance($settings->cmid);
        if (has_capability('mod/booking:bookforothers', $context)) {
            $full = true;
        } else {
            $full = false;
        }

        // The extra button condition is used to show Alert & Button, if this is allowed for a user.
        if (!$justmyalert && !empty($extrabuttoncondition)) {
            if (method_exists($extrabuttoncondition, 'instance')) {
                $condition = $extrabuttoncondition::instance();
            } else {
                $condition = new $extrabuttoncondition();
            }

            list($template, $data) = $condition->render_button($settings, $subbookingid, 0, $full);

            // This supports multiple templates as well.
            $datas[] = new bookit_button($data);

            $templates[] = $template;
        }

        $condition = new $buttoncondition();
        list($template, $data) = $condition->render_button($settings, $subbookingid, 0, $full);

        $datas[] = new bookit_button($data);
        $templates[] = $template;

        return [$templates, $datas];
    }

    /**
     * Handles booking via the webservice. Checks access and right area to execute functions.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function bookit(string $area, int $itemid, int $userid = 0) {

        global $USER;

        // Make sure the user has the right to book in principle.
        $context = context_system::instance();
        if (!empty($userid)
            && $userid != $USER->id
            && !has_capability('mod/booking:bookforothers', $context)) {
            throw new moodle_exception('norighttoaccess', 'mod_booking');
        }

        if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            return self::answer_subbooking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_BOOKED, $userid);
        } else {
            return [
                'status' => 0,
                'message' => 'novalidarea',
            ];
        }
    }

    /**
     * Helper function to create cartitem for optionid.
     *
     * @param string $area
     * @param int $itemid
     * @param int $status
     * @param int $userid
     * @return array
     */
    public static function answer_booking_option(string $area, int $itemid, int $status, int $userid = 0): array {

        global $PAGE, $USER;

        $bookingoption = booking_option::create_option_from_optionid($itemid);

        $settings = singleton_service::get_instance_of_booking_option_settings($itemid);

        // Make sure that we only buy from instance the user has access to.
        // This is just fraud prevention and can not happen ordinarily.
        // phpcs:ignore
        // $cm = get_coursemodule_from_instance('booking', $bookingoption->bookingid);

        // TODO: Find out if the executing user has the right to access this instance.
        // This can lead to problems, rights should be checked further up.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $context = context_module::instance($cm->id);
        if (!has_capability('mod/booking:choose', $context)) {
            return null;
        } */

        $user = price::return_user_to_buy_for($userid);

        if (!$user) {
            $user = $USER;
        }

        if (empty($userid)) {
            $userid = $user->id;
        }

        $price = price::get_price('option', $itemid, $user);

        // Now we reserve the place for the user.
        switch ($status) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                if (!$bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED)) {
                    return [];
                }
                break;
            case MOD_BOOKING_STATUSPARAM_RESERVED:
                if (!$bookingoption->user_submit_response($user, 0, 0, 1, MOD_BOOKING_VERIFIED)) {
                    return [];
                }
                break;
            case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                if (!$bookingoption->user_delete_response($user->id, true)) {
                    return [];
                }
                break;
            case MOD_BOOKING_STATUSPARAM_DELETED:
                if (!$bookingoption->user_delete_response($user->id)) {
                    return [];
                }
                break;
            case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                if (!$bookingoption::toggle_notify_user($user->id, $itemid)) {
                    return [];
                }
                break;
        }

        // We need to register this action as a booking answer, where we only reserve, not actually book.

        $user = singleton_service::get_instance_of_user($userid);
        $booking = singleton_service::get_instance_of_booking_by_bookingid($settings->bookingid);

        // With shortcodes & webservice we might not have a valid context object.
        booking_context_helper::fix_booking_page_context($PAGE, $booking->cmid);

        /** @var renderer $output */
        $output = $PAGE->get_renderer('mod_booking');
        $data = new bookingoption_description($itemid, null, MOD_BOOKING_DESCRIPTION_WEBSITE, false, null, $user);
        $description = $output->render_bookingoption_description_cartitem($data);

        $optiontitle = $bookingoption->option->text;
        if (!empty($bookingoption->option->titleprefix)) {
            $optiontitle = $bookingoption->option->titleprefix . ' - ' . $optiontitle;
        }

        $canceluntil = booking_option::return_cancel_until_date($itemid);

        $item = [
            'itemid' => $itemid,
            'title' => $optiontitle,
            'price' => $price['price'] ?? 0,
            'currency' => $price['currency'] ?? '',
            'description' => $description,
            'imageurl' => $settings->imageurl ?? '',
            'canceluntil' => $canceluntil,
            'coursestarttime' => $settings->coursestarttime ?? null,
            'courseendtime' => $settings->courseendtime ?? null,
        ];

        return $item;
    }

    /**
     * Helper function to create cartitem for subbooking.
     *
     * @param string $area
     * @param int $itemid
     * @param int $status
     * @param int $userid
     * @return array
     */
    public static function answer_subbooking_option(string $area, int $itemid, int $status, int $userid = 0): array {

        $subbooking = subbookings_info::get_subbooking_by_area_and_id($area, $itemid);

        // We reserve this subbooking option for a few minutes, during checkout.
        subbookings_info::save_response($area, $itemid, $status, $userid);

        $cartinformation = $subbooking->return_subbooking_information($itemid);
        $cartinformation['itemid'] = $itemid;

        return $cartinformation;
    }
}

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

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\bo_availability\bo_info;
use mod_booking\output\bookingoption_description;
use mod_booking\output\bookit_button;
use mod_booking\output\prepagemodal;
use mod_booking\subbookings\subbookings_info;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for handling the booking process.
 * In the most simple case, this class provides a button for a user to book a booking option.
 * But this class handles the process, together with bo_conditions, prices and further functionalities...
 * ... as an integrative process.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_bookit {

    /** @var booking_option_settings $settings */
    public $settings = null;

    /**
     * Renders the book it button for a given user and returns the rendered html as string.
     * This also includes a top and a bottom section which can be rendered seperately.
     *
     * @param booking_option_settings $settings
     * @param integer $userid
     * @return string
     */
    public static function render_bookit_button(booking_option_settings $settings, int $userid = 0) {

        global $PAGE;

        $output = $PAGE->get_renderer('mod_booking');
        list($templates, $datas) = self::render_bookit_template_data($settings, $userid);

        $html = '';

        foreach ($templates as $template) {
            $data = array_shift($datas);

            if ($template == 'mod_booking/prepagemodal') {
                $html .= $output->render_prepagemodal($data);
            } else {
                $html .= $output->render_bookit_button($data, $template);
            }
        }

        return $html;
    }

    /**
     * This is used to get template name & data as an array to render bookit-button (component).
     *
     * @param booking_option_settings $settings
     * @param integer $userid
     * @return array
     */
    public static function render_bookit_template_data(booking_option_settings $settings, int $userid = 0) {

        // Get blocking conditions, including prepages$prepages etc.
        $results = bo_info::get_condition_results($settings->id, $userid);
        // Decide, wether to show the direct booking button or a modal.

        $prepages = [];
        $showinmodalbutton = true;
        $extrabuttoncondition = '';
        $justmyalert = false;
        foreach ($results as $result) {

            // $prepages can pre pre- or postbent.

            self::sort_prepages($prepages, $result);

            switch ($result['button'] ) {
                case BO_BUTTON_MYBUTTON:
                    $buttoncondition = $result['classname'];
                    break;
                case BO_BUTTON_MYALERT;
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
                case BO_BUTTON_NOBUTTON:
                    // The no button marker can override all the other conditions.
                    // It is only relevant for the modal, not the rest.
                    $showinmodalbutton = false;
                    break;
                case BO_BUTTON_JUSTMYALERT:
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
            $condition = new $extrabuttoncondition();

            list($template, $data) = $condition->render_button($settings, 0, $full);

            // This supports multiple templates as well.
            $datas[] = new bookit_button($data);

            $templates[] = $template;
        }

        // Big decession: can we render the button right away, or do we need to introduce a modal?
        if (!$justmyalert && count($prepages) > 0) {

            // We render the button only from the highest relevant blocking condition.

            $datas[] = new prepagemodal(
                $settings, // We pass on the optionid.
                count($prepages), // The total number of pre booking pages.
                $buttoncondition,  // This is the button we need to render twice.;
                $showinmodalbutton, // This marker just suppresses the in modal button.
            );

            $templates[] = 'mod_booking/prepagemodal';

            return [$templates, $datas];
        } else {

            $condition = new $buttoncondition();
            list($template, $data) = $condition->render_button($settings, 0, $full);

            $datas[] = new bookit_button($data);
            $templates[] = $template;

            return [$templates, $datas];
        }
    }

    /**
     * Handles booking via the webservice. Checks access and right area to execute functions.
     *
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
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

        if ($area === 'option') {

            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            $boinfo = new bo_info($settings);
            if (!$boinfo->is_available($itemid, $userid)) {
                return [
                    'status' => 0,
                    'message' => 'notallowedtobook',
                ];
            }
            return self::answer_booking_option($area, $itemid, STATUSPARAM_BOOKED, $userid);
        } else if (str_starts_with($area, 'subbooking')) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            return self::answer_subbooking_option($area, $itemid, STATUSPARAM_BOOKED, $userid);
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
     * @param integer $itemid
     * @param integer $status
     * @param integer $userid
     * @return array
     */
    public static function answer_booking_option(string $area, int $itemid, int $status, int $userid = 0):array {

        global $PAGE, $USER;

        $bookingoption = booking_option::create_option_from_optionid($itemid);

        $settings = singleton_service::get_instance_of_booking_option_settings($itemid);

        // Make sure that we only buy from instance the user has access to.
        // This is just fraud prevention and can not happen ordinarily.
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
            case STATUSPARAM_BOOKED:
                if (!$bookingoption->user_submit_response($user, 0, 0, false, true)) {
                    return [];
                }
                break;
            case STATUSPARAM_RESERVED:
                if (!$bookingoption->user_submit_response($user, 0, 0, true, true)) {
                    return [];
                }
                break;
            case STATUSPARAM_NOTBOOKED:
                if (!$bookingoption->user_delete_response($user->id, true)) {
                    return [];
                }
                break;
            case STATUSPARAM_DELETED:
                if (!$bookingoption->user_delete_response($user->id)) {
                    return [];
                }
                break;
            case STATUSPARAM_NOTIFYMELIST:
                if (!$bookingoption::toggle_notify_user($user->id, $itemid)) {
                    return [];
                }
                break;
        }

        // We need to register this action as a booking answer, where we only reserve, not actually book.

        $user = singleton_service::get_instance_of_user($userid);
        $booking = singleton_service::get_instance_of_booking_by_optionid($itemid);

        if (!isset($PAGE->context)) {
            $PAGE->set_context(context_module::instance($booking->cmid));
        }

        $output = $PAGE->get_renderer('mod_booking');
        $data = new bookingoption_description($itemid, null, DESCRIPTION_WEBSITE, false, null, $user);
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
     * @param integer $itemid
     * @param integer $status
     * @param integer $userid
     * @return array
     */
    public static function answer_subbooking_option(string $area, int $itemid, int $status, int $userid = 0):array {

        $subbooking = subbookings_info::get_subbooking_by_area_and_id($area, $itemid);

        // We reserve this subbooking option for a few minutes, during checkout.
        subbookings_info::save_response($area, $itemid, $status, $userid);

        $cartinformation = $subbooking->return_subbooking_information($itemid);
        $cartinformation['itemid'] = $itemid;

        return $cartinformation;
    }

    /**
     * This function sorts the prepages according to their self defined priorities.
     *
     * @param array $prepages
     * @param array $result
     * @return void
     */
    private static function sort_prepages(array &$prepages, array $result) {

        // Make sure the keys are set.
        $prepages['pre'] = !isset($prepages['pre']) ? [] : $prepages['pre'];
        $prepages['post'] = !isset($prepages['post']) ? [] : $prepages['post'];
        $prepages['book'] = !isset($prepages['book']) ? null : $prepages['book'];

        $newpage = [
            'id' => $result['id'],
            'classname' => $result['classname']
        ];

        switch ($result['insertpage']) {
            case BO_PREPAGE_NONE:
                // Do nothing.
            break;
            case BO_PREPAGE_BOOK:
                $prepages['book'] = [
                    'id' => $result['id'],
                    'classname' => $result['classname']
                ];
            break;
            case BO_PREPAGE_PREBOOK:
                $prepages['pre'][] = [
                    'id' => $result['id'],
                    'classname' => $result['classname']
                ];
            break;
            case BO_PREPAGE_POSTBOOK:
                $prepages['post'][] = [
                    'id' => $result['id'],
                    'classname' => $result['classname']
                ];
            break;
        }

    }
}

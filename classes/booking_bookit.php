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
 * Handling the booking process.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use context_module;
use context_system;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\local\modechecker;
use mod_booking\output\bookingoption_description;
use mod_booking\output\bookit_button;
use mod_booking\output\prepagemodal;
use mod_booking\output\renderer;
use mod_booking\subbookings\subbookings_info;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for handling the booking process.
 *
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
     * @param int $userid
     * @return string
     */
    public static function render_bookit_button(booking_option_settings $settings, int $userid = 0) {

        global $PAGE;

        /** @var renderer $output */
        $output = $PAGE->get_renderer('mod_booking');
        list($templates, $datas) = self::render_bookit_template_data($settings, $userid);

        $html = '';

        foreach ($templates as $template) {
            $data = array_shift($datas);

            if ($template == 'mod_booking/bookingpage/prepagemodal') {

                $html .= $output->render_prepagemodal($data);

            } else if ($template == 'mod_booking/bookingpage/prepageinline') {

                $html .= $output->render_prepageinline($data);

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
     * @param int $userid
     * @param bool $renderprepagemodal
     * @return array
     */
    public static function render_bookit_template_data(
        booking_option_settings $settings,
        int $userid = 0,
        bool $renderprepagemodal = true) {

        // Get blocking conditions, including prepages$prepages etc.
        $results = bo_info::get_condition_results($settings->id, $userid);
        // Decide, wether to show the direct booking button or a modal.

        $datas = [];
        $showinmodalbutton = true;
        $extrabuttoncondition = '';
        $justmyalert = null;
        foreach ($results as $result) {
            switch ($result['button']) {
                case MOD_BOOKING_BO_BUTTON_MYBUTTON:
                    $buttoncondition = $result['classname'];
                    break;
                case MOD_BOOKING_BO_BUTTON_MYALERT;
                    // Here we could use a more sophisticated way of rights management.
                    // Right now, the logic is just linked to one right.
                    $context = context_module::instance(($settings->cmid));
                    if (has_capability('mod/booking:bookforothers', $context)) {
                        // We still render the alert, but just in supplement to the other button.
                        $extrabuttoncondition = $result['classname'];
                    } else {
                        $buttoncondition = $result['classname'];
                    }
                    break;
                case MOD_BOOKING_BO_BUTTON_NOBUTTON:
                    // The no button marker can override all the other conditions.
                    // It is only relevant for the modal, not the rest.
                    $showinmodalbutton = false;
                    break;
                case MOD_BOOKING_BO_BUTTON_JUSTMYALERT:
                    // The JUST MY ALERT prevents other buttons to be displayed.
                    if ($justmyalert === null) {
                        $justmyalert = true;
                    }
                    $buttoncondition = $result['classname'];
                    break;
                case MOD_BOOKING_BO_BUTTON_CANCEL:
                    if (modechecker::use_special_details_page_treatment()) {
                        $justmyalert = false;
                        $extrabuttoncondition = $result['classname'];
                        $renderprepagemodal = false;
                    }
                    break;
            }
        }

        $prepages = bo_info::return_sorted_conditions($results);

        $context = context_module::instance($settings->cmid);
        if (has_capability('mod/booking:bookforothers', $context)) {
            $full = true;
        } else {
            $full = false;
        }

        // Do we really want to render a modal?
        $showprepagemodal = (!$justmyalert && (count($prepages) > 0) && $renderprepagemodal);

        // Big decision: can we render the button right away, or do we need to introduce a modal.
        if ($showprepagemodal) {

            // We render the button only from the highest relevant blocking condition.

            $data = new prepagemodal(
                $settings, // We pass on the optionid.
                count($prepages), // The total number of pre booking pages.
                $buttoncondition,  // This is the button we need to render twice.
                !$justmyalert ? $extrabuttoncondition : '', // There might be a second button to render.
                $userid, // The userid for which all this will be rendered.
            );

            $datas[] = $data;

            $viewparam = booking::get_value_of_json_by_key($settings->bookingid, 'viewparam');
            $turnoffmodals = 0; // By default, we use modals.
            if ($viewparam == MOD_BOOKING_VIEW_PARAM_LIST) {
                // Only if we use list view, we can use inline modals.
                // So only in this case, we need to check the config setting.
                $turnoffmodals = get_config('booking', 'turnoffmodals');
            }

            if (empty($turnoffmodals)) {
                $templates[] = 'mod_booking/bookingpage/prepagemodal';
            } else {
                $templates[] = 'mod_booking/bookingpage/prepageinline';
            }

            return [$templates, $datas];
        } else {

            // The extra button condition is used to show Alert & Button, if this is allowed for a user.
            if (!$justmyalert && !empty($extrabuttoncondition)) {
                $condition = new $extrabuttoncondition();

                list($template, $data) = $condition->render_button($settings, $userid, $full, false, true);

                // This supports multiple templates as well.
                $datas[] = new bookit_button($data);

                $templates[] = $template;
            }

            $condition = new $buttoncondition();

            list($template, $data) = $condition->render_button($settings, $userid, $full, false, true);

            // If there is an extra button condition, we don't use two templates but one.
            // We just move the extra condition to a different area.
            if (!empty($extrabuttoncondition && !empty($datas) && isset($data['main']))) {
                $extrabutton = reset($datas);
                $extrabutton->data['top'] = $extrabutton->data["main"];
                $extrabutton->data['main'] = $data['main'];
                // Make sure that JS is turned on.
                $extrabutton->data['nojs'] = false;
                $datas = [$extrabutton];
                $templates = [$template];
            } else {
                $data['fullwidth'] = true;
                $datas[] = new bookit_button($data);
                $templates[] = $template;
            }

            return [$templates, $datas];
        }
    }

    /**
     * Handles booking via the webservice. Checks access and right area to execute functions.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @param string $data
     * @return array
     */
    public static function bookit(string $area, int $itemid, int $userid = 0, string $data = ''): array {

        global $USER, $CFG;

        // Make sure the user has the right to book in principle.
        $context = context_system::instance();

        if (!empty($userid)
            && $userid != $USER->id
            && !has_capability('mod/booking:bookforothers', $context)) {
            throw new moodle_exception('norighttoaccess', 'mod_booking');
        } else if (empty($userid)) {
            $userid = $USER->id;
        }

        if ($area === 'option') {

            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            $boinfo = new bo_info($settings);

            // There are two cases where we can actually book.
            // We call thefunction with hadblock set to true.
            // This means that we only get those blocks that actually should prevent booking.
            list($id, $isavailable, $description) = $boinfo->is_available($itemid, $userid, true);

            // If isavailable is true, there is actually no blocking condition at all.
            // This might never be the case, as we use this to introduce prepages and buttons (add to cart or bookit).
            // Therefore, we have to override it to make this functionality useful.
            // If the id is 1, this means that only the bookit button is blocking, this means we are allowed to book.

            /* TODO: Refactor this.
             First, we need a switch.
             Second the reaction code should be included in the condition classes themselves, to improve maintainability. */
            if ($id < MOD_BOOKING_BO_COND_BOOKITBUTTON) {
                $isavailable = true;
            } else if ($id === MOD_BOOKING_BO_COND_BOOKITBUTTON) {

                $cache = cache::make('mod_booking', 'confirmbooking');
                $cachekey = $userid . "_" . $settings->id . "_bookit";
                $now = time();
                $cache->set($cachekey, $now);

                $isavailable = false;

            } else if ($id === MOD_BOOKING_BO_COND_BOOKWITHCREDITS) {

                $cache = cache::make('mod_booking', 'confirmbooking');
                $cachekey = $userid . "_" . $settings->id . "_bookwithcredits";
                $now = time();
                $cache->set($cachekey, $now);

                $isavailable = false;

            } else if ($id === MOD_BOOKING_BO_COND_BOOKWITHSUBSCRIPTION) {

                $cache = cache::make('mod_booking', 'confirmbooking');
                $cachekey = $userid . "_" . $settings->id . "_bookwithsubscription";
                $now = time();
                $cache->set($cachekey, $now);

                $isavailable = false;

            } else if ($id === MOD_BOOKING_BO_COND_CONFIRMBOOKIT) {

                // Make sure cache is not blocking anymore.
                $cache = cache::make('mod_booking', 'confirmbooking');
                $cachekey = $userid . "_" . $settings->id . '_bookit';
                $cache->delete($cachekey);

                // This means we can actuall book.
                $isavailable = true;

            } else if ($id === MOD_BOOKING_BO_COND_CONFIRMBOOKWITHCREDITS) {

                 // Make sure cache is not blocking anymore.
                 $cache = cache::make('mod_booking', 'confirmbooking');
                 $cachekey = $userid . "_" . $settings->id . '_bookwithcredits';

                // Now, before actually booking, we also need to subtract the credit from the concerned user.
                // Get the used custom profile field.
                if (!$profilefield = get_config('booking', 'bookwithcreditsprofilefield')) {
                    $cache->delete($cachekey);
                    throw new moodle_exception('nocreditsfielddefined', 'mod_booking');
                }

                if ($USER->id != $userid) {
                    $user = singleton_service::get_instance_of_user($userid);
                } else {
                    $user = $USER;
                    profile_load_custom_fields($user);
                }

                if ($user->profile[$profilefield] < $settings->credits) {
                    $cache->delete($cachekey);
                    throw new moodle_exception('notenoughcredits', 'mod_booking');
                }

                $user->profile[$profilefield] = $user->profile[$profilefield] - $settings->credits;

                // We require this file only if we really do need it.
                require_once("$CFG->dirroot/user/profile/lib.php");
                profile_save_custom_fields($userid, [$profilefield => $user->profile[$profilefield]]);

                // This means we can actually book.
                $isavailable = true;
            } else if ($id === MOD_BOOKING_BO_COND_ASKFORCONFIRMATION) {
                $isavailable = true;
            } else if ($id === MOD_BOOKING_BO_COND_ALREADYBOOKED || $id === MOD_BOOKING_BO_COND_ONWAITINGLIST) {

                // Add a layer of security to not cancel just because of unintentional double click.
                if (!cancelmyself::apply_coolingoff_period($settings, $userid)) {
                     // If the cancel condition is blocking here, we can actually mark the option for cancelation.
                    $cache = cache::make('mod_booking', 'confirmbooking');
                    $cachekey = $userid . "_" . $settings->id . "_cancel";
                    $now = time();
                    $cache->set($cachekey, $now);
                }

            } else if ($id === MOD_BOOKING_BO_COND_CONFIRMCANCEL) {

                // Here we are already one step further and only confirm the cancelation.
                self::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);

                // Make sure cache is not blocking anymore.
                $cache = cache::make('mod_booking', 'confirmbooking');
                $cachekey = $userid . "_" . $settings->id . '_cancel';
                $cache->delete($cachekey);

                return [
                    'status' => 1,
                    'message' => 'cancelled',
                ];
            } else if ($id === MOD_BOOKING_BO_COND_ALREADYRESERVED) {

                // We only react on this if we are in cancelation.
                $booking = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);

                if (!empty($booking->iselective)) {
                    // Here we are already one step further and only confirm the cancelation.
                    self::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);

                    $cmid = (int)$booking->cmid;
                    $cache = cache::make('mod_booking', 'electivebookingorder');
                    if ($cachearray = $cache->get($cmid)) {

                        $list = [];
                        foreach ($cachearray['arrayofoptions'] as $item) {
                            if ($item == $itemid) {
                                continue;
                            }
                            array_push($list, $item);
                        }

                        if (count($list) == 0) {
                            $cachearray = false;
                        } else {
                            $cachearray['arrayofoptions'] = $list;
                            $cachearray['expirationtime'] = strtotime('+ 3 days', time());
                        }

                        $cache->set($cmid, $cachearray);
                    }

                    return [
                        'status' => 1,
                        'message' => 'notbooked',
                    ];
                }

            } else if ($id === MOD_BOOKING_BO_COND_ELECTIVEBOOKITBUTTON) {

                // Here we are already one step further and only confirm the cancelation.
                self::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_RESERVED, $userid);

                // For the elective, we need to record the booking order.

                $cache = cache::make('mod_booking', 'electivebookingorder');
                $cmid = (int)$settings->cmid;
                if ($cachearray = $cache->get($cmid)) {

                    $list = $cachearray['arrayofoptions'];
                    array_push($list, $itemid);
                } else {
                    $list = [$itemid];
                }

                $cachearray = [
                    'expirationtime' => strtotime('+ 3 days', time()),
                    'arrayofoptions' => $list,
                ];

                $cache->set($cmid, $cachearray);

                return [
                    'status' => 1,
                    'message' => 'reserved',
                ];
            }

            if (!$isavailable) {

                return [
                    'status' => 0,
                    'message' => 'notallowedtobook',
                ];
            }
            return array_merge(self::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_BOOKED, $userid),
                                ['status' => 1, 'message' => 'booked']);
        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            return array_merge(self::answer_subbooking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_BOOKED, $userid),
                                ['status' => 1, 'message' => 'booked']);
        } else if ($area === 'elective') {
            $jsonobject = json_decode($data);

            $list = $jsonobject->list ?? null;

            $cache = cache::make('mod_booking', 'electivebookingorder');

            // If there is no list, we just book in the currently saved order.
            $booking = singleton_service::get_instance_of_booking_settings_by_cmid($itemid);

            if (!empty($booking->enforceteacherorder)) {

                $arrayofoptions = elective::return_sorted_array_of_options_from_cache($itemid);
            } else if (!$list) {

                // We use itemid as cmid.
                $cachearray = $cache->get($itemid);
                $arrayofoptions = $cachearray['arrayofoptions'];

            } else {

                $list = json_decode($list);

                $arrayofoptions = $list;
            }

            foreach ($arrayofoptions as $item) {

                // We need to delete the previous entry.
                self::answer_booking_option('option', $item, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);

                // Book it again.
                self::answer_booking_option('option', $item, MOD_BOOKING_STATUSPARAM_BOOKED, $userid);

            }

            $cache->set($itemid, null);

            return [
                'status' => 0,
                'message' => 'novalidarea',
            ];

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
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $cm = get_coursemodule_from_instance('booking', $bookingoption->bookingid); */

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

        // Probably not necessary anymore, as we got the description from below.
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
        $booking = singleton_service::get_instance_of_booking_by_optionid($itemid);

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
            'costcenter' => $settings->costcenter ?? '',
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

        $settings = singleton_service::get_instance_of_booking_option_settings($subbooking->optionid);

        $cartinformation = $settings->return_subbooking_option_information($itemid);

        return $cartinformation;
    }
}

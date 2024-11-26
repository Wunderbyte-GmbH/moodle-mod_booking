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
 * This file contains the definition for the renderable classes for column 'action'.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_module;
use context_system;
use mod_booking\booking_answers;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'availableplaces'.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_availableplaces implements renderable, templatable {

    /** @var booking_answers $bookinganswers instance of class */
    private $bookinganswers = null;

    /** @var \stdClass $buyforuser user stdclass if we buy for user */
    private $buyforuser = null;

    /** @var bool $showmanageresponses */
    private $showmanageresponses = null;

    /** @var string $manageresponsesurl */
    private $manageresponsesurl = null;

    /** @var array $bookinginformation */
    private $bookinginformation = [];

    /** @var bool $showmaxanswers */
    public $showmaxanswers = true;

    /**
     * The constructor takes the values from db.
     *
     * @param mixed $values
     * @param booking_option_settings $settings
     * @param ?\stdClass $buyforuser
     */
    public function __construct($values, booking_option_settings $settings, ?\stdClass $buyforuser = null) {
        global $CFG;

        $this->buyforuser = $buyforuser;
        $this->bookinganswers = singleton_service::get_instance_of_booking_answers($settings);

        $cmid = $settings->cmid;
        $optionid = $settings->id;

        $syscontext = context_system::instance();
        $modcontext = context_module::instance($cmid);

        $canviewreport = (
            has_capability('mod/booking:viewreports', $syscontext)
            || has_capability('mod/booking:updatebooking', $modcontext)
            || has_capability('mod/booking:updatebooking', $syscontext)
            || booking_check_if_teacher($optionid)
        );

        if ($canviewreport) {

            $this->showmanageresponses = true;

            // Add a link to redirect to the booking option.
            $link = new moodle_url($CFG->wwwroot . '/mod/booking/report.php', [
                'id' => $cmid,
                'optionid' => $optionid,
            ]);
            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            if ($CFG->version >= 2023042400) {
                // Moodle 4.2 needs second param.
                $this->manageresponsesurl = html_entity_decode($link->out(), ENT_QUOTES);
            } else {
                // Moodle 4.1 and older.
                $this->manageresponsesurl = html_entity_decode($link->out(), ENT_COMPAT);
            }
        }

        if ($this->buyforuser) {
            $userid = $this->buyforuser->id;
        } else {
            $userid = 0;
        }

        // We got the array of all the booking information.
        $fullbookinginformation = $this->bookinganswers->return_all_booking_information($userid);
        // We need to pop out the first value which is by itself another array containing the information we need.
        $bookinginformation = array_pop($fullbookinginformation);

        // Get maxanswers from cache if exist.
        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
        // TODO: how about entire option settings?
        $cache = \cache::make('mod_booking', 'bookingoptionsettings');
        $cachedoption = $cache->get($settings->id);
        if (isset($cachedoption->maxanswers)) {
            $bookinginformation['maxanswers'] = $cachedoption->maxanswers;
        }

        // We need this to render a link to manage bookings in the template.
        if (!empty($this->showmanageresponses) && $this->showmanageresponses == true) {
            if (is_array($bookinginformation)) {
                $bookinginformation['showmanageresponses'] = true;
                $bookinginformation['manageresponsesurl'] = $this->manageresponsesurl;
            }
        }

        // Here we add the availability info texts to the $bookinginformation array.
        booking_answers::add_availability_info_texts_to_booking_information($bookinginformation);

        $this->bookinginformation = $bookinginformation;
    }

    /**
     * Get booking information.
     * @return array
     */
    public function get_bookinginformation() {
        return $this->bookinginformation;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->bookinginformation;
    }
}

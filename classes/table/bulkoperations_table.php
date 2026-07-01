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
 * Class bulkoperations_table.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;
use mod_booking\booking;
use mod_booking\singleton_service;
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

use local_wunderbyte_table\wunderbyte_table;

/**
 * Class to handle bulk operations table.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulkoperations_table extends wunderbyte_table {
    /**
     * Display the template name. Uses templatename from JSON if available, otherwise falls back to text.
     *
     * @param object $values
     * @return string
     */
    public function col_text($values) {
        if (empty($values->bookingid) && !empty($values->json)) {
            $jsonobj = json_decode($values->json);
            if (!empty($jsonobj->templatename)) {
                return format_string($jsonobj->templatename);
            }
        }
        if (!empty($values->text)) {
            return format_string($values->text);
        }
        return '-';
    }

    /**
     * Overrides the output for this column.
     * @param object $values
     * @return string
     */
    public function col_action($values) {
        global $PAGE, $OUTPUT;

        /* During AJAX table rendering (e.g. wunderbyte table via service.php), $PAGE->url is the
        AJAX service endpoint – not a valid returnurl. Use the HTTP Referer instead, which is
        the page containing the bulkoperations shortcode. Validate it as a local Moodle URL. */
        if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            // This is important to prevent open redirect vulnerabilities. We only want to allow local URLs.
            $returnurl = clean_param($referer, PARAM_LOCALURL) ?: null;
        } else {
            $returnurl = $PAGE->url->out(false);
        }

        if (empty($values->bookingid)) {
            /* Templates actually DO NOT HAVE a bookingid and cmid. But in order to load the form, we need one.
            So we just take the highest booking cmid available which is most likely the most recently created
            booking instance. */
            $allcmids = booking::get_all_cmids();
            if (empty($allcmids)) {
                return '';
            }
            $cmid = reset($allcmids);
            $urlparams = [
                'id' => $cmid,
                'optionid' => $values->id,
                'addastemplate' => '1',
            ];
            if ($returnurl !== null) {
                $urlparams['returnto'] = 'url';
                $urlparams['returnurl'] = $returnurl;
            }
        } else {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($values->bookingid);
            $urlparams = [
                'id' => $bookingsettings->cmid,
                'optionid' => $values->id,
            ];
            if ($returnurl !== null) {
                $urlparams['returnto'] = 'url';
                $urlparams['returnurl'] = $returnurl;
            }
        }

        $link = html_writer::link(
            new moodle_url('/mod/booking/editoptions.php', $urlparams),
            $OUTPUT->pix_icon('i/edit', get_string('editbookingoption', 'mod_booking')),
            [
                'target' => '_self',
                'class' => 'text-primary',
                'aria-label' => get_string('editbookingoption', 'mod_booking'),
            ]
        );

        return $link;
    }
}

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
use mod_booking\customfield\booking_handler;
use mod_booking\shortcodes;
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
     * Create a fully configured bulk operations table.
     *
     * Used by the [bulkoperations] shortcode (system context, all booking instances)
     * and by the bulk operations tab on view.php (module context, single instance).
     *
     * @param string $uniqueid unique id for the table
     * @param \context $context context used for the options filter sql (visibility checks)
     * @param array $args shortcode style args (customfields, columns, filter, download, ...)
     * @param int $bookingid if given, restrict the table to options of this booking instance
     * @param int $cmid if given, the dynamic forms triggered by the action buttons run in module context
     * @return self
     */
    public static function create_table(
        string $uniqueid,
        \context $context,
        array $args = [],
        int $bookingid = 0,
        int $cmid = 0
    ): self {

        \mod_booking\local\performance\performance_facade::start_measurement('Building table');

        $table = new self($uniqueid);
        $columns = [
            'id' => get_string('id', 'local_wunderbyte_table'),
            'text' => get_string('title', 'mod_booking'),
            'action' => get_string('edit'),
            'invisible' => get_string('invisible', 'mod_booking'),
        ];
        // Add defined customfields from args to columns.
        if (isset($args['customfields'])) {
            $customfieldnames = explode(",", $args['customfields']);
            $definedcustomfields = booking_handler::get_customfields();
            foreach ($definedcustomfields as $customfield) {
                if (!in_array($customfield->shortname, $customfieldnames)) {
                    continue;
                }
                $columns[$customfield->shortname] = $customfield->name;
            }
        }
        if (isset($args['columns'])) {
            $additionalcolumns = explode(",", $args['columns']);
            foreach ($additionalcolumns as $additionalcolumn) {
                if (in_array($additionalcolumn, $columns)) {
                    continue;
                }
                $columns[$additionalcolumn] = $additionalcolumn;
            }
        }
        if (!empty($args['download'])) {
            $table->showdownloadbutton = true;
        }

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));
        $table->addcheckboxes = true;

        \mod_booking\local\performance\performance_facade::end_measurement('Building table');

        // The booking instance filter makes no sense when the table is restricted to a single instance.
        $filtercolumns = shortcodes::apply_bulkoperations_filter($table, $columns, $args, $bookingid === 0);

        $table->showfilterontop = true;
        $table->filteronloadinactive = true;

        $table->define_fulltextsearchcolumns(array_keys($filtercolumns));
        $table->define_sortablecolumns(array_keys($filtercolumns));
        $table->sort_default_column = 'id';
        $table->sort_default_order = SORT_DESC;

        $wherearray = $bookingid > 0 ? ['bookingid' => $bookingid] : [];

        [$fields, $from, $where, $params, $filter] =
            booking::get_options_filter_sql(
                0,
                0,
                '',
                null,
                $context,
                [],
                $wherearray,
                null,
                [],
                '',
                '',
                $table
            );

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        $editbuttondata = [
            'title' => get_string('bulkoperationsheader', 'mod_booking'),
        ];
        $mailbuttondata = [
            'title' => get_string('sendmailheading', 'mod_booking'),
            'titlestring' => 'blabla',
            'bodystring' => 'adddatabody',
            'submitbuttonstring' => get_string('send', 'mod_booking'),
        ];
        if ($cmid > 0) {
            // With a cmid, the dynamic forms run in module context instead of system context.
            $editbuttondata['cmid'] = $cmid;
            $mailbuttondata['cmid'] = $cmid;
        }

        $table->actionbuttons[] = [
            'label' => get_string('editbookingoptions', 'mod_booking'),
            'class' => 'btn btn-warning',
            'href' => '#',
            'formname' => 'mod_booking\\form\\option_form_bulk',
            'nomodal' => false,
            'selectionmandatory' => true,
            'id' => '-1',
            'data' => $editbuttondata,
        ];
        $table->actionbuttons[] = [
            'label' => get_string('sendmailtoteachers', 'mod_booking'),
            'class' => 'btn btn-info',
            'href' => '#',
            'formname' => 'mod_booking\\form\\send_mail_to_teachers',
            'nomodal' => false,
            'selectionmandatory' => true,
            'id' => '-1',
            'data' => $mailbuttondata,
        ];
        $table->pageable(true);
        $table->stickyheader = true;
        $table->showcountlabel = true;
        $table->showrowcountselect = true;

        return $table;
    }

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

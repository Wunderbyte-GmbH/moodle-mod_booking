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
 * This file contains the definition for the renderable classes for bookingoption dates.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use moodle_exception;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for booked users.
 * It is used to display a slightly configurable list of booked users for a given booking option.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view implements renderable, templatable {

    /** @var int $cmid */
    private $cmid = null;

    /** @var context_system $context */
    private $context = null;

    /** @var string $renderedalloptionstable the rendered all options table */
    private $renderedalloptionstable = null;

    /** @var string $renderedmyoptionstable the rendered my options table */
    private $renderedmyoptionstable = null;

    /** @var string $whichview */
    private $whichview = null;

    /**
     * Constructor
     *
     * @param int $optionid
     */
    public function __construct(int $cmid, string $whichview = 'showall') {

        $this->cmid = $cmid;
        if (!$this->context = context_system::instance()) {
            throw new moodle_exception('badcontext');
        }
        $this->whichview = $whichview;

        // Verborgene immer mit lazyouthtml und für die jeweils aktuelle view outhtml!

        // Now create the tables.
        $this->renderedalloptionstable = $this->get_rendered_alloptions_table();

        // Now create the tables.
        $this->renderedmyoptionstable = $this->get_rendered_myoptions_table();
    }

    /**
     * Render table for all booking options.
     * @return string the rendered table
     */
    private function get_rendered_alloptions_table() {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $allbookingoptionstable = new bookingoptions_wbtable('allbookingoptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $allbookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($allbookingoptionstable);

        // Return table with lazy loading if $lazy is true.
        if ($this->whichview == 'showall') {
            $out = $allbookingoptionstable->outhtml(40, true);
        } else {
            list($idstring, $encodedtable, $out) = $allbookingoptionstable->lazyouthtml(40, true);
        }

        return $out;
    }

    /**
     * Render table for my own booked options.
     * @return string the rendered table
     */
    private function get_rendered_myoptions_table() {
        global $USER;

        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $mybookingoptionstable = new bookingoptions_wbtable('mybookingoptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray, $USER->id);
        $mybookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($mybookingoptionstable);

        // Return table with lazy loading if $lazy is true.
        if ($this->whichview == 'mybooking') {
            $out = $mybookingoptionstable->outhtml(40, true);
        } else {
            list($idstring, $encodedtable, $out) = $mybookingoptionstable->lazyouthtml(40, true);
        }

        return $out;
    }

    /**
     * Helper function to set the default layout for the table (list view).
     * @param wunderbyte_table $wbtable reference to the table class that should be initialized
     */
    private function wbtable_initialize_list_layout(wunderbyte_table &$wbtable) {
        $wbtable->add_subcolumns('leftside', ['text', 'action', 'teacher']);
        $wbtable->add_subcolumns('footer', ['dayofweektime', 'location', 'bookings']);
        $wbtable->add_subcolumns('rightside', ['booknow']);

        $wbtable->add_classes_to_subcolumns('leftside', ['columnkeyclass' => 'd-none']);
        $wbtable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left m-0 mb-1 h5'], ['text']);
        $wbtable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-right'], ['action']);
        $wbtable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left font-size-sm'], ['teacher']);
        $wbtable->add_classes_to_subcolumns('footer', ['columnkeyclass' => 'd-none']);
        $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
            ['dayofweektime']);
        $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-clock-o text-gray
            font-size-sm'], ['dayofweektime']);
        $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
            ['location']);
        $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-map-marker text-gray
            font-size-sm'], ['location']);
        $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
            ['bookings']);
        $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-ticket text-gray
            font-size-sm'], ['bookings']);
        $wbtable->add_classes_to_subcolumns('rightside', ['columnclass' => 'text-right'], ['booknow']);

        // Override naming for columns.
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_text', 'booking')],
            ['text']
        );
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_teacher', 'booking')],
            ['teacher']
        );
        $wbtable->is_downloading('', 'List of booking options');

        // Header column.
        $wbtable->define_header_column('text');

        $wbtable->pageable(true);
        $wbtable->stickyheader = true;
        $wbtable->showcountlabel = false;
        $wbtable->showdownloadbutton = false; // TODO.
        $wbtable->showreloadbutton = false;
        $wbtable->define_cache('mod_booking', 'bookingoptionstable');

        $wbtable->define_fulltextsearchcolumns(['titleprefix', 'text', 'description', 'location', 'teacherobjects']);

        $wbtable->define_sortablecolumns([
            'text' => get_string('bookingoption', 'mod_booking'),
            'location',
            'dayofweek'
        ]);

        // It's important to have the baseurl defined, we use it as a return url at one point.
        $baseurl = new moodle_url(
            $_SERVER['REQUEST_URI'],
            $_GET
        );
        $wbtable->define_baseurl($baseurl->out());

        // This allows us to use infinite scrolling, No pages will be used.
        $wbtable->infinitescroll = 40;

        $wbtable->tabletemplate = 'mod_booking/table_list';
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return [
            'alloptionstable' => $this->renderedalloptionstable,
            'myoptionstable' => $this->renderedmyoptionstable
        ];
    }
}

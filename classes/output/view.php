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

    /** @var string $whichview */
    private $whichview = null;

    /**
     * Constructor
     *
     * @param int $optionid
     */
    public function __construct(int $cmid, string $whichview = 'showall') {
        global $CFG;

        $this->cmid = $cmid;
        if (!$this->context = context_system::instance()) {
            throw new moodle_exception('badcontext');
        }
        $this->whichview = $whichview;

        // Verborgene immer mit lazyouthtml und für die jeweils aktuelle view outhtml!

        // Now create the tables.
        $this->renderedalloptionstable = $this->get_renderedalloptionstable();
    }

    /**
     * Render table for all booking options.
     * @param bool $lazy true if lazy loading should be used, false by default
     * @return string the rendered table
     */
    private function get_renderedalloptionstable(bool $lazy = false) {
        $cmid = $this->cmid;
        $context = $this->context;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $allbookingoptionstable = new bookingoptions_wbtable('allbookingoptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $allbookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Set the SQL for the table.
        // $allbookingoptionstable->set_sql('*', "{booking_options}", '1=1');

        $allbookingoptionstable->add_subcolumns('leftside', ['text', 'action', 'teacher']);
        $allbookingoptionstable->add_subcolumns('footer', ['dayofweektime', 'location', 'bookings']);
        $allbookingoptionstable->add_subcolumns('rightside', ['booknow']);

        $allbookingoptionstable->add_classes_to_subcolumns('leftside', ['columnkeyclass' => 'd-none']);
        $allbookingoptionstable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left m-0 mb-1 h5'], ['text']);
        $allbookingoptionstable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-right'], ['action']);
        $allbookingoptionstable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left font-size-sm'], ['teacher']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columnkeyclass' => 'd-none']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
            ['dayofweektime']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-clock-o text-gray
            font-size-sm'], ['dayofweektime']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
            ['location']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-map-marker text-gray
            font-size-sm'], ['location']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
            ['bookings']);
        $allbookingoptionstable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-ticket text-gray
            font-size-sm'], ['bookings']);
        $allbookingoptionstable->add_classes_to_subcolumns('rightside', ['columnclass' => 'text-right'], ['booknow']);

        // Override naming for columns.
        $allbookingoptionstable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_text', 'booking')],
            ['text']
        );
        $allbookingoptionstable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_teacher', 'booking')],
            ['teacher']
        );
        $allbookingoptionstable->is_downloading('', 'List of booking options');

        // Header column.
        $allbookingoptionstable->define_header_column('text');

        $allbookingoptionstable->pageable(true);
        $allbookingoptionstable->stickyheader = true;
        $allbookingoptionstable->showcountlabel = false;
        $allbookingoptionstable->showdownloadbutton = false; // TODO.
        $allbookingoptionstable->showreloadbutton = false;
        $allbookingoptionstable->define_cache('mod_booking', 'bookingoptionstable');

        $allbookingoptionstable->define_fulltextsearchcolumns(['titleprefix', 'text', 'description', 'location', 'teacherobjects']);

        $allbookingoptionstable->define_sortablecolumns([
            'text' => get_string('bookingoption', 'mod_booking'),
            'location',
            'dayofweek'
        ]);

        // It's important to have the baseurl defined, we use it as a return url at one point.
        $baseurl = new moodle_url(
            $_SERVER['REQUEST_URI'],
            $_GET
        );
        $allbookingoptionstable->define_baseurl($baseurl->out());

        // This allows us to use infinite scrolling, No pages will be used.
        $allbookingoptionstable->infinitescroll = 40;

        $allbookingoptionstable->tabletemplate = 'mod_booking/table_list';

        // Return table with lazy loading if $lazy is true.
        if ($this->whichview == 'showall') {
            $out = $allbookingoptionstable->outhtml(40, true);
        } else {
            list($idstring, $encodedtable, $out) = $allbookingoptionstable->lazyouthtml(40, true);
        }

        return $out;

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*
        Needed for the other tables:

        $wherearray = ['bookingid' => (int)$booking->id];

        // If we want to find only the teacher relevant options, we chose different sql.
        if (isset($args['teacherid']) && (is_int((int)$args['teacherid']))) {
            $wherearray['teacherobjects'] = '%"id":' . $args['teacherid'] . ',%';
            list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        } else {

            list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        }

        $table->set_filter_sql($fields, $from, $where, $filter, $params);
        */
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return [
            'alloptionstable' => $this->renderedalloptionstable
        ];
    }
}

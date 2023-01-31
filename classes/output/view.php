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
    public function get_rendered_alloptions_table() {
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

        $out = $allbookingoptionstable->outhtml(40, true);

        return $out;
    }

    /**
     * Render table for my own booked options.
     * @return string the rendered table
     */
    public function get_rendered_myoptions_table() {
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

        $out = $mybookingoptionstable->outhtml(40, true);

        return $out;
    }

    /**
     * Render table all options a specified teacher is teaching.
     * @param int $teacherid
     * @return string the rendered table
     */
    public function get_rendered_table_for_teacher(int $teacherid) {
        $cmid = $this->cmid;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $teacheroptionstable = new bookingoptions_wbtable('teacheroptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];

        $wherearray['teacherobjects'] = '%"id":' . $teacherid . ',%';
        list($fields, $from, $where, $params, $filter) =
            booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $teacheroptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($teacheroptionstable, false, false, false);

        $out = $teacheroptionstable->outhtml(40, true);

        return $out;
    }

    /**
     * Helper function to set the default layout for the table (list view).
     * @param wunderbyte_table $wbtable reference to the table class that should be initialized
     * @param bool $filter
     * @param bool $search
     * @param bool $sort
     */
    private function wbtable_initialize_list_layout(wunderbyte_table &$wbtable,
        bool $filter = true, bool $search = true, bool $sort = true) {
        $wbtable->add_subcolumns('leftside', ['text', 'action', 'teacher']);
        $wbtable->add_subcolumns('footer', ['dayofweektime', 'location', 'institution', 'bookings']);
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
        $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
            ['institution']);
        $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-building-o text-gray
            font-size-sm'], ['institution']);
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

        if ($search) {
            $wbtable->define_fulltextsearchcolumns(['titleprefix', 'text', 'description',
                'location', 'institution', 'teacherobjects']);
        }

        if ($filter) {
            $wbtable->define_filtercolumns([
                'location' => [
                    'localizedname' => get_string('location', 'mod_booking'),
                ],
                'institution' => [
                    'localizedname' => get_string('institution', 'mod_booking'),
                ],
                'dayofweek' => [
                    'localizedname' => get_string('dayofweek', 'mod_booking'),
                    'monday' => get_string('monday', 'mod_booking'),
                    'tuesday' => get_string('tuesday', 'mod_booking'),
                    'wednesday' => get_string('wednesday', 'mod_booking'),
                    'thursday' => get_string('thursday', 'mod_booking'),
                    'friday' => get_string('friday', 'mod_booking'),
                    'saturday' => get_string('saturday', 'mod_booking'),
                    'sunday' => get_string('sunday', 'mod_booking')
                ],
            ]);
        }

        if ($sort) {
            $wbtable->define_sortablecolumns([
                'text' => get_string('bookingoption', 'mod_booking'),
                'location',
                'institution',
                'dayofweek',
            ]);
        }

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

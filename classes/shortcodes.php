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
 * Shortcodes for mod booking
 *
 * @package mod_booking
 * @subpackage db
 * @since Moodle 3.11
 * @copyright 2021 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

use \mod_booking\table\bookingoptions_table;

/**
 * Deals with local_shortcodes regarding booking.
 */
class shortcodes {

    /**
     * Prints out list of bookingoptions.
     * Argumtents can be 'category' or 'perpage'.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return void
     */
    public static function listofbookingoptions($shortcode, $args, $content, $env, $next) {

        // TODO: Define capality.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* if (!has_capability('moodle/site:config', $env->context)) {
            return '';
        } */

        // If the id argument was not passed on, we have a fallback in the connfig.
        if (!isset($args['id'])) {
            $args['id'] = get_config('booking', 'shortcodessetinstance');
        }

        // To prevent misconfiguration, id has to be there and int.
        if (!(isset($args['id']) && $args['id'] && is_int((int)$args['id']))) {
            return 'Set id of booking instance';
        }

        if (!$booking = singleton_service::get_instance_of_booking_by_cmid($args['id'])) {
            return 'Couldn\'t find right booking instance ' . $args['id'];
        }

        if (!isset($args['category']) || !$category = ($args['category'])) {
            $category = '';
        }

        if (!isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])) {
            $perpage = 1000;
        }

        $tablename = bin2hex(random_bytes(12));

        $table = new bookingoptions_table($tablename, $booking);

        list($fields, $from, $where, $params) = $booking::get_all_options_sql(null, null, $category, null, $booking->context);

        $table->set_sql($fields, $from, $where, $params);

        $table->use_pages = false;

        $table->define_cache('mod_booking', 'bookingoptionstable');

        $table->add_subcolumns('cardbody', ['text', 'dayofweek', 'teacher', 'location', 'bookings', 'price']);

        // This avoids showing all keys in list view.
        $table->add_classes_to_subcolumns('cardbody', ['columnkeyclass' => 'd-md-none']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-md-3 col-sm-12'], ['text']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-sm-12 col-md-3 text-left'], ['dayofweek']);
        $table->add_classes_to_subcolumns('cardbody', ['columniclassbefore' => 'fa fa-clock-o'], ['dayofweek']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-sm-12 col-md-3'], ['teacher']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-sm-12 col-md-3'], ['location']);
        $table->add_classes_to_subcolumns('cardbody', ['columniclassbefore' => 'fa fa-map-marker'], ['location']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-sm-12 col-md-3 text-right'], ['bookings']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-sm-12 col-md-3 text-right'], ['price']);

        // Override naming for columns. one could use getstring for localisation here.
        $table->add_classes_to_subcolumns('cardbody',
            ['keystring' => get_string('tableheader_text', 'booking')], ['text']);
        $table->add_classes_to_subcolumns('cardbody',
            ['keystring' => get_string('tableheader_teacher', 'booking')], ['teacher']);
        $table->add_classes_to_subcolumns('cardbody',
            ['keystring' => get_string('tableheader_maxanswers', 'booking')], ['maxanswers']);
        $table->add_classes_to_subcolumns('cardbody',
            ['keystring' => get_string('tableheader_maxoverbooking', 'booking')], ['maxoverbooking']);
        $table->add_classes_to_subcolumns('cardbody',
            ['keystring' => get_string('tableheader_coursestarttime', 'booking')], ['coursestarttime']);
        $table->add_classes_to_subcolumns('cardbody',
            ['keystring' => get_string('tableheader_courseendtime', 'booking')], ['courseendtime']);

        $table->add_classes_to_subcolumns('cardbody', ['columnclass' => 'col-sm']);

        $table->set_tableclass('listheaderclass', 'card d-none d-md-block');

        $table->set_tableclass('cardbodyclass', 'list-group-item');

        $table->is_downloading('', 'List of booking options');

        $table->tabletemplate = 'mod_booking/shortcodes_table';

        ob_start();
        $out = $table->out($perpage, true);

        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }
}

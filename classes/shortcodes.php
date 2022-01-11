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

defined('MOODLE_INTERNAL') || die();

use \mod_booking\table\bookingoptions_table;

/**
 * Deals with local_shortcodes regarding booking.
 */
class shortcodes {

    /**
     * Prints out list of bookingoptions, filtered by special category.
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
        if (!has_capability('moodle/site:config', $env->context)) {
            return '';
        }

        // If the id Argument was not passed on, we have a fallback in the connfig.
        if (!isset($args['id'])) {
            $args['id'] = get_config('booking', 'shortcodessetinstance');
        }

        // To prevent misconfiguration, id has to be there and int.
        if (!(isset($args['id']) && $args['id'] && is_int((int)$args['id']))) {
            return 'Set id of booking instance';
        }

        if (!$booking = new booking($args['id'])) {
            return 'Couldn\'t find right booking instance ' . $args['id'];
        }

        if (!$category = ($args['category'])) {
            return 'No category defined ' . $args['id'];
        }

        $tablename = bin2hex(random_bytes(12));

        $table = new bookingoptions_table($tablename);

        $booking = new booking($args['id']);

        list($fields, $from, $where, $params) = $booking->get_all_options_sql(null, null, $category, 'bo.*');

        $table->set_sql($fields, $from, $where, $params);

        $table->add_subcolumns('cardbody', ['text', 'teacher', 'price', 'maxanswers', 'maxoverbooking',
            'coursestarttime', 'courseendtime', 'action']);

        // This avoids showing all keys in list view.
        $table->add_classes_to_subcolumns('cardbody', ['columnkeyclass' => 'd-md-none']);

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
        $table->set_tableclass('cardbodyclass', 'card-body row');

        $table->is_downloading('', 'List of booking options');

        ob_start();
        $out = $table->out(40, true);

        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }

}

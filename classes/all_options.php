<?php

/**
 * Display all options.
 *
 * @package    mod_booking
 * @copyright  2016 Andraž Prinčič <atletek@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

class all_options extends table_sql {

    var $booking = null;
    
    function __construct($uniqueid, $booking) {
        parent::__construct($uniqueid);
        
        $this->collapsible(true);
        $this->sortable(true);
        $this->pageable(true);        
        
        $this->booking = $booking;
    }

    function col_coursestarttime($values) {
        if ($values->coursestarttime == 0) {
            return get_string('datenotset', 'booking');
        } else {
            return userdate($values->coursestarttime) . " - " . userdate($values->courendtime);
        }
    }
    
    function col_id($values) {        
        return "<b>{$values->text}</b><br>{$values->address}<br>" . (empty($this->booking->booking->lblteachname) ? get_string('teachers', 'booking') : $this->booking->booking->lblteachname) . ": ";
    }
    
    /**
     * This function is called for each data row to allow processing of
     * columns which do not have a *_cols function.
     * @return string return processed value. Return NULL if no change has
     *     been made.
     */
    function other_cols($colname, $value) {

    }
    
    function wrap_html_start() {

    }

    function wrap_html_finish() {
        echo "<hr>";
    }
    
}

<?php

/**
 * Event observers.
 *
 * @package mod_booking
 * @copyright 2015 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_forum.
 */
class mod_booking_observer {

    /**
     * Observer for course_module_updated.
     *
     * @param  \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        global $CFG, $DB;

        $visible = $DB->get_record('course_modules', array('id' => $event->contextinstanceid), 'visible');
        
        $showHide = new stdClass();
        $showHide->id = $event->other['instanceid'];
        $showHide->showinapi = $visible->visible;
        
        $DB->update_record("booking", $showHide);

        return;
    }

}

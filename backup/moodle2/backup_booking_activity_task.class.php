<?php


/**
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/mod/booking/backup/moodle2/backup_booking_stepslib.php'); // Because it exists (must)
require_once($CFG->dirroot . '/mod/booking/backup/moodle2/backup_booking_settingslib.php'); // Because it exists (optional)

/**
 * booking backup task that provides all the settings and steps to perform one
 * complete backup of the activity
 */
class backup_booking_activity_task extends backup_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // booking only has one structure step
        $this->add_step(new backup_booking_activity_structure_step('booking_structure', 'booking.xml'));
    }

    /**
     * Code the transformations to perform in the activity in
     * order to get transportable (encoded) links
     */
    static public function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot,"/");

        // Link to the list of bookings
        $search="/(".$base."\/mod\/booking\/index.php\?id\=)([0-9]+)/";
        $content= preg_replace($search, '$@BOOKINGINDEX*$2@$', $content);

        // Link to booking view by moduleid
        $search="/(".$base."\/mod\/booking\/view.php\?id\=)([0-9]+)/";
        $content= preg_replace($search, '$@BOOKINGVIEWBYID*$2@$', $content);

        return $content;
    }
}

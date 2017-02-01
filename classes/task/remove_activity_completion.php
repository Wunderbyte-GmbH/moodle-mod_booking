<?php
namespace mod_booking\task;


class remove_activity_completion extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('modulename', 'mod_booking');
    }

    public function execute() {
        global $DB, $CFG;

        $result = $DB->get_records_sql(
                'SELECT ba.id, ba.bookingid, ba.optionid, ba.userid, b.course
            FROM {booking_answers} AS ba
            LEFT JOIN {booking_options} AS bo
            ON bo.id = ba.optionid
            LEFT JOIN {booking} AS b
            ON b.id = bo.bookingid
            WHERE bo.removeafterminutes > 0
            AND ba.completed = 1
            AND IF(ba.timemodified < (UNIX_TIMESTAMP() - (bo.removeafterminutes*60)), 1, 0) = 1;');

        require_once($CFG->libdir . '/completionlib.php');

        foreach ($result as $value) {
            $course = $DB->get_record('course', array('id' => $value->course));
            $completion = new \completion_info($course);
            $cm = get_coursemodule_from_instance('booking', $value->bookingid);

            $userData = $DB->get_record('booking_answers', array('id' => $value->id));
            $booking = $DB->get_record('booking', array('id' => $value->bookingid));

            $userData->completed = '0';
            $userData->timemodified = time();

            $DB->update_record('booking_answers', $userData);

            if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $userData->userid);
            }
        }
    }
}

<?php

require_once $CFG->libdir . '/formslib.php';

class mod_booking_manageusers_form extends moodleform {

    function definition() {
        global $CFG, $DB, $OUTPUT, $USER;
        $mform = & $this->_form;

        $cm = $this->_customdata['cm'];

        // visible elements
        // 
        //add all booked users to form
        $mform->addElement('html', '<h5>' . get_string('bookedusers', 'booking') . ':</h5>');

        if ($this->_customdata['bookedusers']) {

            foreach ($this->_customdata['bookedusers'] as $user) {
                if (empty($user->imagealt)) {
                    $user->imagealt = '';
                }

                $userData = $DB->get_record('booking_answers', array('optionid' => $this->_customdata['bookingdata']->id, 'userid' => $user->id));

                $checkMark = "&nbsp;";
                if ($userData->completed == '1') {
                    $checkMark = "&#x2713;";
                }

                $arrow = "&nbsp;";
                
                if (isset($user->usersOnList) && $user->usersOnList == '1') {
                    $arrow = "&#11014;";
                }                
                
                $mform->addElement('advcheckbox', "user[{$user->id}]", $arrow . $checkMark . " <a href=\"$CFG->wwwroot/user/view.php?id=$user->id\">" . fullname($user) . "</a>",  ($userData->timecreated > 0 ? ' ' . userdate($userData->timecreated, get_string('strftimedatefullshort')) : ''), array('group' => $this->_customdata['bookingdata']->id + 1));
            }

            $this->add_checkbox_controller($this->_customdata['bookingdata']->id + 1);
        } else {
            $mform->addElement('html', '<p>' . get_string('nousers', 'booking') . '</p>');
        }

        //add all waiting list users to form
        if (!empty($this->_customdata['waitinglistusers'])) {
            $mform->addElement('html', '<h5>' . get_string('waitinglistusers', 'booking') . ':</h5>');
            if ($this->_customdata['waitinglistusers']) {
                foreach ($this->_customdata['waitinglistusers'] as $user) {
                    if (empty($user->imagealt)) {
                        $user->imagealt = '';
                    }

                    $mform->addElement('advcheckbox', "user[$user->id]", "<a href=\"$CFG->wwwroot/user/view.php?id=$user->id\">" . fullname($user) . "</a>", '',  array('id' => 'budala', 'group' => $this->_customdata['bookingdata']->id));
                }

                $this->add_checkbox_controller($this->_customdata['bookingdata']->id);
            }

        }
        //-------------------------------------------------------------------------------
        // buttons

        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('static', 'onlylabel', '', '<span class="bookinglabelname">' . get_string('withselected', 'booking') . '</span>');
        if (!$this->_customdata['bookingdata']->autoenrol && has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
            $buttonarray[] = &$mform->createElement('submit', 'subscribetocourse', get_string('subscribetocourse', 'booking'));
        }

        if (has_capability('mod/booking:deleteresponses', context_module::instance($cm->id))) {
            $buttonarray[] = &$mform->createElement("submit", 'deleteusers', get_string('booking:deleteresponses', 'booking'));
        }

        if (has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
            $buttonarray[] = &$mform->createElement("submit", 'sendpollurl', get_string('booking:sendpollurl', 'booking'));
            $buttonarray[] = &$mform->createElement("submit", 'sendcustommessage', get_string('sendcustommessage', 'booking'));
        }

        if (booking_check_if_teacher($this->_customdata['bookingdata'], $USER) || has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
            $buttonarray[] = &$mform->createElement("submit", 'activitycompletion', get_string('confirmactivitycompletion', 'booking'));
            if ($this->_customdata['bookingdata']->conectedoption > 0) {
                $buttonarray[] = &$mform->createElement("submit", 'booktootherbooking', get_string('booktootherbooking', 'booking'));
            }
        }

        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);

        //hidden elements
        $mform->addElement('hidden', 'id', $this->_customdata['bookingdata']->cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'bookingid', $this->_customdata['bookingdata']->bookingid);
        $mform->setType('bookingid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $this->_customdata['bookingdata']->id);
        $mform->setType('optionid', PARAM_INT);
    }

    function data_preprocessing(&$default_values) {
        if (!isset($default_values['descriptionformat'])) {
            $default_values['descriptionformat'] = FORMAT_HTML;
        }
        if (!isset($default_values['description'])) {
            $default_values['description'] = '';
        }
    }

    function get_data() {
        $data = parent::get_data();

        if (isset($data->subscribetocourse) && !array_keys($data->user, 1)) {
            $data = false;
        }

        return $data;
    }

}

?>
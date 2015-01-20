<?php

require_once $CFG->libdir . '/formslib.php';

class mod_booking_teachers_form extends moodleform {
    
    function definition() {
        global $DB, $CFG;
        
        $mform = & $this->_form;
        
        $cm = $this->_customdata['cm'];
        
        if ($this->_customdata['teachers']) {

            foreach ($this->_customdata['teachers'] as $user) {
                if (empty($user->imagealt)) {
                    $user->imagealt = '';
                }

                $userData = $DB->get_record('booking_teachers', array('optionid' => $this->_customdata['option']->id, 'userid' => $user->id));

                $checkMark = "&nbsp;";
                if ($userData->completed == '1') {
                    $checkMark = "&#x2713;";
                }

                $arrow = "&nbsp;";           
                
                $mform->addElement('advcheckbox', "user[{$user->id}]", $checkMark . " <a href=\"$CFG->wwwroot/user/view.php?id=$user->id\">" . fullname($user) . "</a>",  '', array('group' => $this->_customdata['option']->id + 1));
            }

            $this->add_checkbox_controller($this->_customdata['option']->id + 1);
        } else {
            $mform->addElement('html', '<p>' . get_string('nousers', 'booking') . '</p>');
        }
        
        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('static', 'onlylabel', '', '<span class="bookinglabelname">' . get_string('withselected', 'booking') . '</span>');

        if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
            $buttonarray[] = &$mform->createElement("submit", 'activitycompletion', get_string('confirmactivitycompletion', 'booking'));
        }
        
        $buttonarray[] = &$mform->createElement('cancel');

        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
        
        //hidden elements
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $this->_customdata['optionid']);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'edit', $this->_customdata['edit']);
        $mform->setType('edit', PARAM_INT);
        
    }
    
}

?>
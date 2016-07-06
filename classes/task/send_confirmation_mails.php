<?php

namespace mod_booking\task;

class send_confirmation_mails extends \core\task\adhoc_task {
    
    /**
     * Data for sending mail
     * @var \stdClass
     */
    public function get_name() {
        return get_string('modulename', 'mod_booking');
    }

    public function execute() {
        $taskdata = $this->get_custom_data();
        
        if ($taskdata != null){
           if (!email_to_user($taskdata->userto, $taskdata->userfrom, $taskdata->subject, $taskdata->messagetext, $taskdata->messagehtml, $taskdata->attachment, $taskdata->attachname)){
               throw new \coding_exception('Confirmation email was not sent');
           }
        } else {
            throw new \coding_exception('Confirmation email was not sent due to lack of custom message data');
        }
    }
}

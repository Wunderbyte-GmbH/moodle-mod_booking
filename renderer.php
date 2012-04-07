<?php
/**
 * Moodle renderer used to display special elements
 *
 * @package   Booking
 * @copyright 2011 David Bogner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/
class mod_booking_renderer extends plugin_renderer_base {
	public function booking_show_results($booking, $course, $cm, $allresponses) {
		global $CFG, $COLUMN_HEIGHT, $DB, $OUTPUT;
		$header = html_writer::tag('h2',format_string(get_string("responses", "booking")));

		$context = get_context_instance(CONTEXT_MODULE, $cm->id);

		$hascapfullnames = has_capability('moodle/site:viewfullnames', $context);

		$viewresponses = has_capability('mod/booking:readresponses', $context);

		$count = 0;
		foreach ($booking->option as $optionid => $option) {

			// column 0 = bookingtitle and stats, column 1 = booked users and waitinglist users, columnn 2 = action options
			$tabledata[$count][0] = '<p style="font-weight:bold;">'.format_string($option->text).'</p>';
			if ($option->limitanswers) {
				$tabledata[$count][0] .= get_string("taken", "booking").": $option->taken / ".$option->maxanswers." ";
			} else {
				if (isset($allresponses[$optionid])) {
					$tabledata[$count][0] .= get_string("taken", "booking")." ".$option->taken;
				}
			}
			$tabledata[$count][1] = html_writer::start_tag('div', array('id' => 'tablecontainer'.$option->id));
			if ($viewresponses) {
				$attributes = array('id' => 'attemptsform'.$option->id, 'method' => 'post', 'action' =>  new moodle_url('report.php'));
				$tabledata[$count][1] .= html_writer::start_tag('form', $attributes);
				$tabledata[$count][1] .= html_writer::start_tag('div');
				$tabledata[$count][1] .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'id', 'value'=> $cm->id));
				$tabledata[$count][1] .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=> sesskey()));
				$tabledata[$count][1] .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'mode', 'value'=>'overview'));
				$tabledata[$count][1] .= html_writer::end_tag('div');

			}
			if (isset($allresponses[$optionid])) {
				$waitlistusers = array();
				$i=1;
				$tabledata[$count][1] .= html_writer::start_tag('div', array('style' => 'display:block'));
				foreach ($allresponses[$optionid] as $user) {
					if ($i <= $option->maxanswers || !$option->limitanswers){ //booked user
						$tabledata[$count][1] .= '<table class="mod-booking-inlinetable"><tr><td class="attemptcell">';
						if ($viewresponses and has_capability('mod/booking:deleteresponses',$context)) {
							$tabledata[$count][1] .= '<div class="attemptcell'.$option->id.'" id="attemptcell'.$option->id.'"><input type="checkbox" name="attemptid['.$optionid.']['.$i.']" value="'. $user->id. '" /></div>';
						}
						$tabledata[$count][1] .= '</td><td class="picture">';
						if (empty($user->imagealt)){
							$user->imagealt = '';
						}
						$tabledata[$count][1] .=  $OUTPUT->user_picture($user, array('courseid'=>$course->id));
						$tabledata[$count][1] .= '</td><td class="fullname">';
						$tabledata[$count][1] .= "<a href=\"$CFG->wwwroot/user/view.php?id=$user->id&amp;course=$course->id\">";
						$tabledata[$count][1] .= fullname($user, $hascapfullnames).'</a></td></tr></table>';
					} else if ($i <= $option->maxoverbooking + $option->maxanswers){ //waitlistusers;
						$waitlistusers[$i] = $user;
					}
					$i++;
				}
				$tabledata[$count][1] .= html_writer::end_tag('div');
				$tabledata[$count][1] .= html_writer::start_tag('div', array('class' => 'mod-booking-waitlist'));
				if($booking->limitanswers && ($option->maxoverbooking > 0)) {
					if (!empty($waitlistusers)){
						foreach ($waitlistusers as $user) {
							$tabledata[$count][1] .= '<table class="mod-booking-inlinetable"><tr><td class="attemptcell">';
							if ($viewresponses and has_capability('mod/booking:deleteresponses',$context)) {
								$tabledata[$count][1] .= '<input type="checkbox" name="attemptid['.$optionid.']['.$i++.']" value="'. $user->id. '" />';
							}
							$tabledata[$count][1] .= '</td><td class="picture">';
							if (empty($user->imagealt)){
								$user->imagealt = '';
							}
							$tabledata[$count][1] .=  $OUTPUT->user_picture($user, array('courseid'=>$course->id));
							$tabledata[$count][1] .= '</td><td class="fullname">';
							$tabledata[$count][1] .= "<a href=\"$CFG->wwwroot/user/view.php?id=$user->id&amp;course=$course->id\">";
							$tabledata[$count][1] .= fullname($user, $hascapfullnames);
							$tabledata[$count][1] .=  '</a></td></tr></table>';
						}
					}
				}
				$tabledata[$count][1] .= html_writer::end_tag('div');
				if ($option->limitanswers){
					$tabledata[$count][0] .= "<br />".get_string("waitinglisttaken", "booking").": ".count($waitlistusers)." / $option->maxoverbooking";
				}
			}

			//display on the side of each option
			$actiondata = '';
			if ($viewresponses and has_capability('mod/booking:deleteresponses',$context)) {
				 
				$actionurl = new moodle_url('report.php', array('sesskey'=>sesskey()));
				$selectoptions = array('delete'=>get_string('delete'));
				if ($option->courseid != 0) {
					$selectoptions['subscribe'] = get_string('subscribetocourse','booking');
				}
				$select = new single_select($actionurl, 'action', $selectoptions, null, array(''=>get_string('choose')), 'attemptsselect'.$option->id);

				$actiondata = html_writer::tag('label', ' ' . get_string('withselected', 'booking') . ' ', array('for'=>'menuaction'));
				$actiondata .= $this->output->render($select);
				$tabledata[$count][1] .= html_writer::tag('a', get_string('selectall', 'quiz'), array('href' => "javascript:select_all_in_element_with_id('tablecontainer$option->id', 'checked');"));
				$tabledata[$count][1] .= " / ";
				$tabledata[$count][1] .= html_writer::tag('a', get_string('selectnone', 'quiz'), array('href' => "javascript:select_all_in_element_with_id('tablecontainer$option->id', '');"));
				 
				$tabledata[$count][1] .=  html_writer::tag('div', $actiondata, array('class'=>'responseaction'));

				if ($viewresponses) {
					$tabledata[$count][1] .= html_writer::end_tag('form');
					$tabledata[$count][1] .= html_writer::end_tag('div');
				}
			}
			$tabledata[$count][2] = "";
			if (has_capability('mod/booking:updatebooking', $context)){
				$tabledata[$count][2] .= '<a href="editoptions.php?id='.$cm->id.'&optionid='.$option->id.'">'.get_string('updatebooking','booking').'</a><br />';
				$tabledata[$count][2] .= '<a href="report.php?id='.$cm->id.'&optionid='.$option->id.'&action=deletebookingoption&sesskey='.sesskey().'">'.get_string('deletebookingoption','booking').'</a><br />';
			}
			if (has_capability('mod/booking:downloadresponses', $context)){
				$downloadoptions = array('id' => $cm->id,'action' => $option->id,'download'=>'ods');
				$url = new moodle_url('report.php', $downloadoptions);
				$tabledata[$count][2] .= $OUTPUT->single_button($url, get_string('downloadusersforthisoptionods','booking'),'post');
				$downloadoptions['download'] ='xls';
				$url = new moodle_url('report.php', $downloadoptions);
				$tabledata[$count][2] .=$OUTPUT->single_button($url, get_string('downloadusersforthisoptionxls','booking'),'post');
			}
			$count++;
		}

		/// Print "Select all" etc.
		$table = new html_table();
		$bookingtitle =  get_string("booking", "booking");
		$strparticipants = get_string("participants");
		$strwaitinglist = get_string("onwaitinglist", "booking");
		$stroptions = get_string('managebooking', 'booking');
		$table->attributes['class'] = 'box generalbox boxaligncenter';
		$table->head = array($bookingtitle, $strparticipants, $stroptions);
		$table->data = $tabledata;
		echo (html_writer::table($table));
	}
}
?>

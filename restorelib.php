<?php //$Id: restorelib.php,v 1.30.6.1 2008/08/11 05:10:44 danmarsden Exp $
//This php script contains all the stuff to backup/restore
//booking mods

//This is the "graphical" structure of the booking mod:
//
//                      booking
//                    (CL,pk->id)----------|
//                        |                |
//                        |                |
//                        |                |
//                  booking_options         |
//             (UL,pk->id, fk->bookingid)   |
//                        |                |
//                        |                |
//                        |                |
//                   booking_answers        |
//        (UL,pk->id, fk->bookingid, fk->optionid)
//
// Meaning: pk->primary key field of the table
//          fk->foreign key to link with parent
//          nt->nested field (recursive data)
//          CL->course level info
//          UL->user level info
//          files->table may have files)
//
//-----------------------------------------------------------

//This function executes all the restore procedure about this mod
function booking_restore_mods($mod,$restore) {

	global $CFG;

	$status = true;

	//Get record from backup_ids
	$data = backup_getid($restore->backup_unique_code,$mod->modtype,$mod->id);

	if ($data) {
		//Now get completed xmlized object
		$info = $data->info;
		// if necessary, write to restorelog and adjust date/time fields
		if ($restore->course_startdateoffset) {
			restore_log_date_changes('Booking', $restore, $info['MOD']['#'], array('TIMEOPEN', 'TIMECLOSE'));
		}
		//traverse_xmlize($info);                                                                     //Debug
		//print_object ($GLOBALS['traverse_array']);                                                  //Debug
		//$GLOBALS['traverse_array']="";                                                              //Debug

		//Now, build the BOOKING record structure
		$booking->course = $restore->course_id;
		$booking->name = backup_todb($info['MOD']['#']['NAME']['0']['#']);
		$booking->text = backup_todb($info['MOD']['#']['TEXT']['0']['#']);
		$booking->format = backup_todb($info['MOD']['#']['FORMAT']['0']['#']);
		$booking->bookingmanager = backup_todb($info['MOD']['#']['BOOKINGMANAGER']['0']['#']);
		$booking->sendmail = isset($info['MOD']['#']['SHOWINFO']['0']['#'])?backup_todb($info['MOD']['#']['SENDMAIL']['0']['#']):'';
		$booking->copymail = backup_todb($info['MOD']['#']['COPYMAIL']['0']['#']);
		$booking->allowupdate = backup_todb($info['MOD']['#']['ALLOWUPDATE']['0']['#']);
		$booking->bookingpolicy = backup_todb($info['MOD']['#']['bookingpolicy']['0']['#']);
		$booking->timeopen = backup_todb($info['MOD']['#']['TIMEOPEN']['0']['#']);
		$booking->timeclose = backup_todb($info['MOD']['#']['TIMECLOSE']['0']['#']);
		$booking->limitanswers = backup_todb($info['MOD']['#']['LIMITANSWERS']['0']['#']);
		$booking->maxanswers = backup_todb($info['MOD']['#']['MAXANSWERS']['0']['#']);
		$booking->maxoverbooking = backup_todb($info['MOD']['#']['MAXOVERBOOKING']['0']['#']);
		$booking->timemodified = backup_todb($info['MOD']['#']['TIMEMODIFIED']['0']['#']);

		//The structure is equal to the db, so insert the booking
		$newid = insert_record ("booking",$booking);

		if ($newid) {
			//We have the newid, update backup_ids
			backup_putid($restore->backup_unique_code,$mod->modtype,
			$mod->id, $newid);

			//Check to see how answers (curently booking_options) are stored in the table
			//If answer1 - answer6 exist, this is a pre 1.5 version of booking
			if (isset($info['MOD']['#']['ANSWER1']['0']['#']) ||
			isset($info['MOD']['#']['ANSWER2']['0']['#']) ||
			isset($info['MOD']['#']['ANSWER3']['0']['#']) ||
			isset($info['MOD']['#']['ANSWER4']['0']['#']) ||
			isset($info['MOD']['#']['ANSWER5']['0']['#']) ||
			isset($info['MOD']['#']['ANSWER6']['0']['#']) ) {

				//This is a pre 1.5 booking backup, special work begins
				$options = array();
				$options[1] = backup_todb($info['MOD']['#']['ANSWER1']['0']['#']);
				$options[2] = backup_todb($info['MOD']['#']['ANSWER2']['0']['#']);
				$options[3] = backup_todb($info['MOD']['#']['ANSWER3']['0']['#']);
				$options[4] = backup_todb($info['MOD']['#']['ANSWER4']['0']['#']);
				$options[5] = backup_todb($info['MOD']['#']['ANSWER5']['0']['#']);
				$options[6] = backup_todb($info['MOD']['#']['ANSWER6']['0']['#']);

				for($i = 1; $i < 7; $i++) { //insert old answers (in 1.4)  as booking_options (1.5) to db.
					if (!empty($options[$i])) {  //make sure this option has something in it!
						$option->bookingid = $newid;
						$option->text = $options[$i];
						$option->timemodified = $booking->timemodified;
						$newoptionid = insert_record ("booking_options",$option);
						//Save this booking_option to backup_ids
						backup_putid($restore->backup_unique_code,"booking_options",$i,$newoptionid);
					}
				}
			} else { //Now we are in a "standard" 1.5 booking, so restore booking_options normally
				$status = booking_options_restore_mods($newid,$info,$restore);
			}

			//now restore the answers for this booking.
			if (restore_userdata_selected($restore,'booking',$mod->id)) {
				//Restore booking_answers
				$status = booking_answers_restore_mods($newid,$info,$restore);
			}
		} else {
			$status = false;
		}

		//Do some output
		if (!defined('RESTORE_SILENTLY')) {
			echo "<li>".get_string("modulename","booking")." \"".format_string(stripslashes($booking->name),true)."\"</li>";
		}
		backup_flush(300);

	} else {
		$status = false;
	}
	return $status;
}

function booking_options_restore_mods($bookingid,$info,$restore) {

	global $CFG;

	$status = true;

	$options = $info['MOD']['#']['OPTIONS']['0']['#']['OPTION'];

	//Iterate over options
	for($i = 0; $i < sizeof($options); $i++) {
		$opt_info = $options[$i];
		//traverse_xmlize($opt_info);                                                                 //Debug
		//print_object ($GLOBALS['traverse_array']);                                                  //Debug
		//$GLOBALS['traverse_array']="";                                                              //Debug

		//We'll need this later!!
		$oldid = backup_todb($opt_info['#']['ID']['0']['#']);
		$olduserid = isset($opt_info['#']['USERID']['0']['#'])?backup_todb($opt_info['#']['USERID']['0']['#']):'';

		//Now, build the BOOKING_OPTIONS record structure
		$option->bookingid = $bookingid;
		$option->text = backup_todb($opt_info['#']['TEXT']['0']['#']);
		$option->maxanswers = backup_todb($opt_info['#']['MAXANSWERS']['0']['#']);
		$option->maxoverbooking = backup_todb($opt_info['#']['MAXOVERBOOKING']['0']['#']);
		$option->bookingclosingtime = backup_todb($opt_info['#']['BOOKINGCLOSINGTIME']['0']['#']);
		$option->courseid = backup_todb($opt_info['#']['COURSEID']['0']['#']);
		$option->coursestarttime = backup_todb($opt_info['#']['COURSESTARTTIME']['0']['#']);
		$option->courseendtime = backup_todb($opt_info['#']['COURSEENDTIME']['0']['#']);
		$option->description = backup_todb($opt_info['#']['DESCRIPTION']['0']['#']);
		$option->limitanswers = backup_todb($opt_info['#']['LIMITANSWERS']['0']['#']);
		$option->timemodified = backup_todb($opt_info['#']['TIMEMODIFIED']['0']['#']);

		//The structure is equal to the db, so insert the booking_options
		$newid = insert_record ("booking_options",$option);

		//Do some output
		if (($i+1) % 50 == 0) {
			if (!defined('RESTORE_SILENTLY')) {
				echo ".";
				if (($i+1) % 1000 == 0) {
					echo "<br />";
				}
			}
			backup_flush(300);
		}

		if ($newid) {
			//We have the newid, update backup_ids
			backup_putid($restore->backup_unique_code,"booking_options",$oldid,
			$newid);
		} else {
			$status = false;
		}
	}

	return $status;
}

//This function restores the booking_answers
function booking_answers_restore_mods($bookingid,$info,$restore) {

	global $CFG;

	$status = true;
	if (isset($info['MOD']['#']['ANSWERS']['0']['#']['ANSWER'])) {
		$answers = $info['MOD']['#']['ANSWERS']['0']['#']['ANSWER'];

		//Iterate over answers
		for($i = 0; $i < sizeof($answers); $i++) {
			$ans_info = $answers[$i];
			//traverse_xmlize($sub_info);                                                                 //Debug
			//print_object ($GLOBALS['traverse_array']);                                                  //Debug
			//$GLOBALS['traverse_array']="";                                                              //Debug

			//We'll need this later!!
			$oldid = backup_todb($ans_info['#']['ID']['0']['#']);
			$olduserid = backup_todb($ans_info['#']['USERID']['0']['#']);

			//Now, build the BOOKING_ANSWERS record structure
			$answer->bookingid = $bookingid;
			$answer->userid = backup_todb($ans_info['#']['USERID']['0']['#']);
			$answer->optionid = backup_todb($ans_info['#']['OPTIONID']['0']['#']);
			$answer->timemodified = backup_todb($ans_info['#']['TIMEMODIFIED']['0']['#']);

			//If the answer contains BOOKING_ANSWER, it's a pre 1.5 backup
			if (!empty($ans_info['#']['BOOKING_ANSWER']['0']['#'])) {
				//optionid was, in pre 1.5 backups, booking_answer
				$answer->optionid = backup_todb($ans_info['#']['BOOKING_ANSWER']['0']['#']);
			}

			//We have to recode the optionid field
			$option = backup_getid($restore->backup_unique_code,"booking_options",$answer->optionid);
			if ($option) {
				$answer->optionid = $option->new_id;
			}

			//We have to recode the userid field
			$user = backup_getid($restore->backup_unique_code,"user",$answer->userid);
			if ($user) {
				$answer->userid = $user->new_id;
			}

			//The structure is equal to the db, so insert the booking_answers
			$newid = insert_record ("booking_answers",$answer);

			//Do some output
			if (($i+1) % 50 == 0) {
				if (!defined('RESTORE_SILENTLY')) {
					echo ".";
					if (($i+1) % 1000 == 0) {
						echo "<br />";
					}
				}
				backup_flush(300);
			}

			if ($newid) {
				//We have the newid, update backup_ids
				backup_putid($restore->backup_unique_code,"booking_answers",$oldid,
				$newid);
			} else {
				$status = false;
			}
		}
	}
	return $status;
}

//Return a content decoded to support interactivities linking. Every module
//should have its own. They are called automatically from
//booking_decode_content_links_caller() function in each module
//in the restore process
function booking_decode_content_links ($content,$restore) {

	global $CFG;

	$result = $content;

	//Link to the list of bookings

	$searchstring='/\$@(BOOKINGINDEX)\*([0-9]+)@\$/';
	//We look for it
	preg_match_all($searchstring,$content,$foundset);
	//If found, then we are going to look for its new id (in backup tables)
	if ($foundset[0]) {
		//print_object($foundset);                                     //Debug
		//Iterate over foundset[2]. They are the old_ids
		foreach($foundset[2] as $old_id) {
			//We get the needed variables here (course id)
			$rec = backup_getid($restore->backup_unique_code,"course",$old_id);
			//Personalize the searchstring
			$searchstring='/\$@(BOOKINGINDEX)\*('.$old_id.')@\$/';
			//If it is a link to this course, update the link to its new location
			if($rec->new_id) {
				//Now replace it
				$result= preg_replace($searchstring,$CFG->wwwroot.'/mod/booking/index.php?id='.$rec->new_id,$result);
			} else {
				//It's a foreign link so leave it as original
				$result= preg_replace($searchstring,$restore->original_wwwroot.'/mod/booking/index.php?id='.$old_id,$result);
			}
		}
	}

	//Link to booking view by moduleid

	$searchstring='/\$@(BOOKINGVIEWBYID)\*([0-9]+)@\$/';
	//We look for it
	preg_match_all($searchstring,$result,$foundset);
	//If found, then we are going to look for its new id (in backup tables)
	if ($foundset[0]) {
		//print_object($foundset);                                     //Debug
		//Iterate over foundset[2]. They are the old_ids
		foreach($foundset[2] as $old_id) {
			//We get the needed variables here (course_modules id)
			$rec = backup_getid($restore->backup_unique_code,"course_modules",$old_id);
			//Personalize the searchstring
			$searchstring='/\$@(BOOKINGVIEWBYID)\*('.$old_id.')@\$/';
			//If it is a link to this course, update the link to its new location
			if($rec->new_id) {
				//Now replace it
				$result= preg_replace($searchstring,$CFG->wwwroot.'/mod/booking/view.php?id='.$rec->new_id,$result);
			} else {
				//It's a foreign link so leave it as original
				$result= preg_replace($searchstring,$restore->original_wwwroot.'/mod/booking/view.php?id='.$old_id,$result);
			}
		}
	}

	return $result;
}

//This function makes all the necessary calls to xxxx_decode_content_links()
//function in each module, passing them the desired contents to be decoded
//from backup format to destination site/course in order to mantain inter-activities
//working in the backup/restore process. It's called from restore_decode_content_links()
//function in restore process
function booking_decode_content_links_caller($restore) {
	global $CFG;
	$status = true;

	if ($bookings = get_records_sql ("SELECT c.id, c.text
                                   FROM {$CFG->prefix}booking c
                                   WHERE c.course = $restore->course_id")) {
	//Iterate over each booking->text
	$i = 0;   //Counter to send some output to the browser to avoid timeouts
	foreach ($bookings as $booking) {
		//Increment counter
		$i++;
		$content = $booking->text;
		$result = restore_decode_content_links_worker($content,$restore);
		if ($result != $content) {
			//Update record
			$booking->text = addslashes($result);
			$status = update_record("booking",$booking);
			if (debugging()) {
				if (!defined('RESTORE_SILENTLY')) {
					echo '<br /><hr />'.s($content).'<br />changed to<br />'.s($result).'<hr /><br />';
				}
			}
		}
		//Do some output
		if (($i+1) % 5 == 0) {
			if (!defined('RESTORE_SILENTLY')) {
				echo ".";
				if (($i+1) % 100 == 0) {
					echo "<br />";
				}
			}
			backup_flush(300);
		}
	}
                                   }

                                   return $status;
}

//This function converts texts in FORMAT_WIKI to FORMAT_MARKDOWN for
//some texts in the module
function booking_restore_wiki2markdown ($restore) {

	global $CFG;

	$status = true;

	//Convert booking->text
	if ($records = get_records_sql ("SELECT c.id, c.text, c.format
                                         FROM {$CFG->prefix}booking c,
                                         {$CFG->prefix}backup_ids b
                                         WHERE c.course = $restore->course_id AND
                                               c.format = ".FORMAT_WIKI. " AND
                                               b.backup_code = $restore->backup_unique_code AND
                                               b.table_name = 'booking' AND
                                               b.new_id = c.id")) {
                                         foreach ($records as $record) {
                                         	//Rebuild wiki links
                                         	$record->text = restore_decode_wiki_content($record->text, $restore);
                                         	//Convert to Markdown
                                         	$wtm = new WikiToMarkdown();
                                         	$record->text = $wtm->convert($record->text, $restore->course_id);
                                         	$record->format = FORMAT_MARKDOWN;
                                         	$status = update_record('booking', addslashes_object($record));
                                         	//Do some output
                                         	$i++;
                                         	if (($i+1) % 1 == 0) {
                                         		if (!defined('RESTORE_SILENTLY')) {
                                         			echo ".";
                                         			if (($i+1) % 20 == 0) {
                                         				echo "<br />";
                                         			}
                                         		}
                                         		backup_flush(300);
                                         	}
                                         }

                                               }
                                               return $status;
}

//This function returns a log record with all the necessay transformations
//done. It's used by restore_log_module() to restore modules log.
function booking_restore_logs($restore,$log) {

	$status = false;

	//Depending of the action, we recode different things
	switch ($log->action) {
		case "add":
			if ($log->cmid) {
				//Get the new_id of the module (to recode the info field)
				$mod = backup_getid($restore->backup_unique_code,$log->module,$log->info);
				if ($mod) {
					$log->url = "view.php?id=".$log->cmid;
					$log->info = $mod->new_id;
					$status = true;
				}
			}
			break;
		case "update":
			if ($log->cmid) {
				//Get the new_id of the module (to recode the info field)
				$mod = backup_getid($restore->backup_unique_code,$log->module,$log->info);
				if ($mod) {
					$log->url = "view.php?id=".$log->cmid;
					$log->info = $mod->new_id;
					$status = true;
				}
			}
			break;
		case "choose":
			if ($log->cmid) {
				//Get the new_id of the module (to recode the info field)
				$mod = backup_getid($restore->backup_unique_code,$log->module,$log->info);
				if ($mod) {
					$log->url = "view.php?id=".$log->cmid;
					$log->info = $mod->new_id;
					$status = true;
				}
			}
			break;
		case "choose again":
			if ($log->cmid) {
				//Get the new_id of the module (to recode the info field)
				$mod = backup_getid($restore->backup_unique_code,$log->module,$log->info);
				if ($mod) {
					$log->url = "view.php?id=".$log->cmid;
					$log->info = $mod->new_id;
					$status = true;
				}
			}
			break;
		case "view":
			if ($log->cmid) {
				//Get the new_id of the module (to recode the info field)
				$mod = backup_getid($restore->backup_unique_code,$log->module,$log->info);
				if ($mod) {
					$log->url = "view.php?id=".$log->cmid;
					$log->info = $mod->new_id;
					$status = true;
				}
			}
			break;
		case "view all":
			$log->url = "index.php?id=".$log->course;
			$status = true;
			break;
		case "report":
			if ($log->cmid) {
				//Get the new_id of the module (to recode the info field)
				$mod = backup_getid($restore->backup_unique_code,$log->module,$log->info);
				if ($mod) {
					$log->url = "report.php?id=".$log->cmid;
					$log->info = $mod->new_id;
					$status = true;
				}
			}
			break;
		default:
			if (!defined('RESTORE_SILENTLY')) {
				echo "action (".$log->module."-".$log->action.") unknown. Not restored<br />";                 //Debug
			}
			break;
	}

	if ($status) {
		$status = $log;
	}
	return $status;
}
?>

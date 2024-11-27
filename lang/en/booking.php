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
 * English lang strings of the booking module
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;

$badgepro = '<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$badgeexp = '<span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Experimental</span>';
$badgedepr = '<span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';

// General strings.
$string['accept'] = 'Accept';
$string['aftersubmitaction'] = 'After saving...';
$string['age'] = 'Age';
$string['allowupdatedays'] = 'Days before reference date';
$string['areyousure:book'] = 'Click again to confirm booking';
$string['areyousure:cancel'] = 'Click again to confirm cancellation';
$string['assesstimestart'] = 'Start of the assessment period';
$string['assesstimefinish'] = 'End of the assessment period';
$string['assignteachers'] = 'Assign teachers:';
$string['alreadypassed'] = 'Already passed';
$string['bookingopeningtime'] = 'Bookable from';
$string['bookingclosingtime'] = 'Bookable until';
$string['bookingoption'] = 'Booking option';
$string['bookingoptionnamewithoutprefix'] = 'Name (without prefix)';
$string['bookings'] = 'Bookings';
$string['cancelallusers'] = 'Cancel booking for all users';
$string['cancelmyself'] = 'Undo my booking';
$string['cancelsign'] = '<i class="fa fa-ban" aria-hidden="true"></i>';
$string['canceluntil'] = 'Cancelling is only possible until certain date';
$string['close'] = 'Close';
$string['collapsedescriptionoff'] = 'Do not collapse descriptions';
$string['collapsedescriptionmaxlength'] = 'Collapse descriptions (max. length)';
$string['collapsedescriptionmaxlength_desc'] = 'Enter the maximum length of characters of a description. Descriptions having more characters will be collapsed.';
$string['confirmoptioncreation'] = 'Do you want to split this booking option so that a separate booking option is created
 from each individual date of this booking option?';
$string['createoptionsfromoptiondate'] = 'For each option date create a new option';
$string['customformnotchecked'] = 'You didn\'t accept yet.';
$string['customfieldsplaceholdertext'] = 'Custom fields';
$string['updatebooking'] = 'Update booking';
$string['booking:manageoptiontemplates'] = "Manage option templates";
$string['booking:editbookingrules'] = "Edit rules (Pro)";
$string['booking:overrideboconditions'] = 'User can book even when conditions return false.';
$string['cohort'] = 'Cohort';
$string['cohorts'] = 'Cohorts';
$string['cohort_s'] = 'Cohort(s)';
$string['confirmchangesemester'] = 'YES, I really want to delete all existing dates of the booking instance and generate new ones.';
$string['course'] = 'Moodle course';
$string['courseduplicating'] = 'DO NOT REMOVE this item. Moodle course is being copied with next run of CRON task.';
$string['courses'] = 'Courses';
$string['course_s'] = 'Kurs(e)';
$string['custom_bulk_message_sent'] = 'Custom bulk message sent (> 75% of booked users, min. 3)';
$string['custom_message_sent'] = 'Custom message sent';
$string['date_s'] = 'Date(s)';
$string['dayofweek'] = 'Weekday';
$string['deduction'] = 'Deduction';
$string['deductionreason'] = 'Reason for the deduction';
$string['deductionnotpossible'] = 'All teachers were present at this date. So no deduction can be logged.';
$string['defaultoptionsort'] = 'Default sorting by column';
$string['doyouwanttobook'] = 'Do you want to book <b>{$a}</b>?';
$string['from'] = 'From';
$string['generalsettings'] = 'General settings';
$string['global'] = 'global';
$string['gotomanageresponses'] = '&lt;&lt; Manage bookings';
$string['gotomoodlecourse'] = 'Go to Moodle course';
$string['limitfactor'] = 'Booking limit factor';
$string['maxperuserdontcountpassed'] = 'Max. number of bookings: Ignore courses passed';
$string['maxperuserdontcountpassed_desc'] = 'When calculating the maximum number of bookings per user per instance,
do not count booking options that have already passed';
$string['maxperuserdontcountcompleted'] = 'Max. number of bookings: Ignore completed';
$string['maxperuserdontcountcompleted_desc'] = 'Do not count bookings that have been marked as "completed" or that
have a presence status "Attending" or "Complete" when calculating the maximum number of bookings per user per instance';
$string['maxperuserdontcountnoshow'] = 'Max. number of bookings: Ignore users who did not show up';
$string['maxperuserdontcountnoshow_desc'] = 'Do not count bookings that have been marked as "No show"
when calculating the maximum number of bookings per user per instance';
$string['messageprovider:bookingconfirmation'] = "Booking confirmations";
$string['name'] = 'Name';
$string['noselection'] = 'No selection';
$string['optionsfield'] = 'Booking option field';
$string['optionsfields'] = 'Booking option fields';
$string['optionsiteach'] = 'Taught by me';
$string['placeholders'] = 'Placeholders';
$string['pricefactor'] = 'Price factor';
$string['profilepicture'] = 'Profile picture';
$string['responsesfields'] = 'Fields in participants list';
$string['responsible'] = 'Responsible';
$string['responsiblecontact'] = 'Responsible contact person';
$string['responsiblecontactcanedit'] = 'Allow responsible contacts to edit';
$string['responsiblecontactcanedit_desc'] = 'Activate this setting if you want to allow responsible contact persons
to edit their booking options and to see and edit the list of booked users.<br>
<b>Important:</b> The responsible contact person additionally needs the capability
<b>mod/booking:addeditownoption</b>.';
$string['responsiblecontact_help'] = 'Choose a person who is responsible for this booking option.
This is not supposed to be the teacher!';
$string['reviewed'] = 'Reviewed';
$string['rowupdated'] = 'Row was updated.';
$string['search'] = 'Search...';
$string['semesterid'] = 'SemesterID';
$string['nosemester'] = 'No semester chosen';
$string['sendmailtoallbookedusers'] = 'Send e-mail to all booked users';
$string['sortorder'] = 'Sort order';
$string['sortorder:asc'] = 'A&rarr;Z';
$string['sortorder:desc'] = 'Z&rarr;A';
$string['teachers'] = 'Teachers';
$string['teacher_s'] = 'Teacher(s)';
$string['assignteachers'] = 'Assign teachers:';
$string['thankyoubooked'] = '<i class="fa fa-3x fa-calendar-check-o text-success" aria-hidden="true"></i><br><br>
Thank you! You have successfully booked <b>{$a}</b>.';
$string['thankyoucheckout'] = '<i class="fa fa-3x fa-shopping-cart text-success" aria-hidden="true"></i><br><br>
Thank you! You have successfully put <b>{$a}</b> into the shopping cart. Now click on <b>"Proceed to checkout"</b>
 to continue.';
$string['thankyouwaitinglist'] = '<i class="fa fa-3x fa-clock-o text-primary" aria-hidden="true"></i><br><br>
 You were added to the waiting list for <b>{$a}</b>. You will automatically move up, in case someone drops out.';
$string['thankyouerror'] = '<i class="fa fa-3x fa-frown-o text-danger" aria-hidden="true"></i><br>
Unfortunately, there was an error when booking <b>{$a}</b>.';
$string['timefilter:coursetime'] = 'Course time';
$string['timefilter:bookingtime'] = 'Booking time';
$string['toomanytoshow'] = 'Too many records found...';
$string['unsubscribe:successnotificationlist'] = 'You were unsubscribed successfully from e-mail notifications for "{$a}".';
$string['unsubscribe:errorotheruser'] = 'You are not allowed to unsubscribe a different user than yourself!';
$string['unsubscribe:alreadyunsubscribed'] = 'You are already unsubscribed.';
$string['until'] = 'Until';
$string['userprofilefield'] = "Profile field";
$string['usersmatching'] = 'Matching users';
$string['allmoodleusers'] = 'All users of this site';
$string['enrolledusers'] = 'Users enrolled in course';
$string['nopriceisset'] = 'No price has been set for pricecategory {$a}';
$string['youareediting'] = 'You are editing "<b>{$a}</b>".';
$string['displayloginbuttonforbookingoptions'] = 'Display a button with redirect to login site for bookingoption';
$string['displayloginbuttonforbookingoptions_desc'] = 'Will be displayed for users not logged in only.';
$string['bookonlyondetailspage'] = 'Booking is only possible on dedicated booking details page';
$string['bookonlyondetailspage_desc'] = 'This means that booking is not possible from list or card view. To book, you need to be on the details page to see all the booking information.';
$string['loginbuttonforbookingoptionscoloroptions'] = 'Choose style (color) of displayed button';
$string['loginbuttonforbookingoptionscoloroptions_desc'] = 'Uses bootstrap 4 styles. Colors are for default application.';
$string['linktomoodlecourseonbookedbutton'] = 'Show Link to Moodle course directly on booked button';
$string['linktomoodlecourseonbookedbutton_desc'] = 'Instead of an extra link, this will transform the booked button the a link to the moodle course';


// Bootstrap 4 styles.
$string['cdo:buttoncolor:primary'] = 'Primary (blue)';
$string['cdo:buttoncolor:secondary'] = 'Secondary (grey)';
$string['cdo:buttoncolor:success'] = 'Success (green)';
$string['cdo:buttoncolor:warning'] = 'Warning (yellow)';
$string['cdo:buttoncolor:danger'] = 'Danger (red)';


// Badges.
$string['badge:pro'] = $badgepro;
$string['badge:exp'] = $badgeexp;

// Errors.
$string['error:ruleactionsendcopynotpossible'] = 'It\'s not possible to send an e-mail copy for the event you chose.';
$string['error:choosevalue'] = 'You have to choose a value here.';
$string['error:confirmthatyouaresure'] = 'Please confirm that you are sure.';
$string['error:taskalreadystarted'] = 'You have already started a task!';
$string['error:entervalue'] = 'You have to enter a value here.';
$string['error:negativevaluenotallowed'] = 'Please enter a positive value.';
$string['error:pricemissing'] = 'Please enter a price.';
$string['error:missingcapability'] = 'Necessary capability is missing. Please contact an administrator.';
$string['error:noendtagfound'] = 'End the placeholder section "{$a}" with backslash ("/").';

// Index.php.
$string['week'] = "Week";
$string['question'] = "Question";
$string['answer'] = "Answer";
$string['topic'] = "Topic";

// Teachers.
$string['teacher'] = 'Teacher';
$string['allteachers'] = 'All teachers';
$string['showallteachers'] = '&gt;&gt; Show all teachers';
$string['showcoursesofteacher'] = 'Courses';
$string['messagebutton'] = 'Message';
$string['messagingteacherimpossible'] = 'You cannot send messages to this teacher
 because you are not enrolled in any courses of her/him.';
$string['sendmail'] = 'Mail';
$string['teachernotfound'] = 'Teacher could not be found or does not exist.';
$string['notateacher'] = 'The user selected is not teaching any courses and is probably not a teacher.';
$string['showteachersmailinglist'] = 'Show a list of e-mails for all teachers...';

// Teacher_added.php.
$string['eventteacher_added'] = 'Teacher added';
$string['eventteacher_removed'] = 'Teacher removed';

// Renderer.php.
$string['myinstitution'] = 'My institution';
$string['visibleoptions'] = 'Visible booking options';
$string['invisibleoptions'] = 'Invisible booking options';
$string['addusertogroup'] = 'Add user to group: ';

// View.php.
$string['addmorebookings'] = 'Add more bookings';
$string['allowupdate'] = 'Allow booking to be updated';
$string['answered'] = 'Answered';
$string['dontaddpersonalevents'] = 'Dont add personal calendar events';
$string['dontaddpersonaleventsdesc'] = 'For each booked option and for all of its sessions, personal events are created in the moodle calendar. Suppressing them improves performance for heavy load sites.';
$string['attachicalfile'] = 'Attach iCal file';
$string['attachicalfile_desc'] = 'Attach iCal files containing the date(s) of the booking option to e-mails.';
$string['icalcancel'] = 'Include iCal event when booking is cancelled as cancelled event';
$string['icalcanceldesc'] = 'When a users cancels a booking or is removed from the booked users list, then attach an iCal attachment as cancelled event.';
$string['booking'] = 'Booking';
$string['bookinginstance'] = 'Booking instance';
$string['booking:addinstance'] = 'Add new booking';
$string['booking:choose'] = 'Book';
$string['booking:deleteresponses'] = 'Delete responses';
$string['booking:downloadresponses'] = 'Download responses';
$string['booking:readresponses'] = 'Read responses';
$string['booking:rate'] = 'Rate chosen booking options';
$string['booking:sendpollurl'] = 'Send poll url';
$string['booking:sendpollurltoteachers'] = 'Send poll url to teachers';
$string['booking:subscribeusers'] = 'Make bookings for other users';
$string['booking:updatebooking'] = 'Manage booking options';
$string['booking:viewallratings'] = 'View all raw ratings given by individuals';
$string['booking:viewanyrating'] = 'View total ratings that anyone received';
$string['booking:viewrating'] = 'View the total rating you received';
$string['booking:addeditownoption'] = 'Add new option and edit own options.';
$string['booking:canseeinvisibleoptions'] = 'View invisible options.';
$string['booking:changelockedcustomfields'] = 'Can change locked custom booking option fields.';

$string['booking:expertoptionform'] = "Expert option form";
$string['booking:reducedoptionform1'] = "1. Reduced option form for course category";
$string['booking:reducedoptionform2'] = "2. Reduced option form for course category";
$string['booking:reducedoptionform3'] = "3. Reduced option form for course category";
$string['booking:reducedoptionform4'] = "4. Reduced option form for course category";
$string['booking:reducedoptionform5'] = "5. Reduced option form for course category";


$string['booking:comment'] = 'Add comments';
$string['booking:managecomments'] = 'Manage comments';
$string['bookingfull'] = 'There are no available places';
$string['bookingname'] = 'Booking instance name';
$string['bookingoptionsmenu'] = 'Booking options';
$string['bookingopen'] = 'Open';
$string['bookingtext'] = 'Booking text';
$string['choose...'] = 'Choose...';
$string['datenotset'] = 'Date not set';
$string['daystonotify'] = 'Number of days in advance of the event-start to notify participants';
$string['daystonotify_help'] = "Will work only if start and end date of option are set! 0 for disabling this functionality.";
$string['daystonotify2'] = 'Second notification before start of event to notify participants.';
$string['daystonotifyteachers'] = 'Number of days in advance of the event-start to notify teachers';
$string['bookinganswer_cancelled'] = 'Booking option cancelled for/by user';

// Booking option events.
$string['bookingoption_cancelled'] = "Booking option cancelled for all";
$string['bookingoption_booked'] = 'Booking option booked';
$string['bookingoption_freetobookagain'] = 'Free places again';
$string['bookingoption_completed'] = 'Booking option completed';
$string['bookingoption_created'] = 'Booking option created';
$string['bookingoption_updated'] = 'Booking option updated';
$string['bookingoption_deleted'] = 'Booking option deleted';
$string['bookinginstance_updated'] = 'Booking instance updated';
$string['records_imported'] = 'Booking options imported via csv';
$string['records_imported_description'] = '{$a} booking options imported via csv';
$string['bookingoption_confirmed'] = 'Booking option confirmed';
$string['bookingoptionconfirmed:description'] = 'User with ID {$a->userid} enabled booking of bookingoption {$a->objectid} for user with ID {$a->relateduserid}.';

$string['eventreport_viewed'] = 'Report viewed';
$string['eventuserprofilefields_updated'] = 'Userprofile updated';
$string['existingsubscribers'] = 'Existing subscribers';
$string['expired'] = 'Sorry, this activity closed on {$a} and is no longer available';
$string['fillinatleastoneoption'] = 'You need to provide at least two possible answers.';
$string['full'] = 'Full';
$string['infonobookingoption'] = 'In order to add a booking option please use the settings block or the settings-icon on top of the page';
$string['infotext:prolicensenecessary'] = 'You need a Booking PRO license if you want to use this feature.
 <a href="https://wunderbyte.at/en/contact" target="_blank">Contact Wunderbyte</a> if you want to buy a PRO license.';
$string['limit'] = 'Limit';
$string['modulename'] = 'Booking';
$string['modulenameplural'] = 'Bookings';
$string['mustchooseone'] = 'You must choose an option before saving. Nothing was saved.';
$string['nofieldchosen'] = 'No field chosen';
$string['noguestchoose'] = 'Sorry, guests are not allowed to enter data';
$string['noresultsviewable'] = 'The results are not currently viewable.';
$string['nosubscribers'] = 'There are no teachers assigned!';
$string['notopenyet'] = 'Sorry, this activity is not available until {$a} ';
$string['pluginadministration'] = 'Booking administration';
$string['pluginname'] = 'Booking';
$string['potentialsubscribers'] = 'Potential subscribers';
$string['proversiononly'] = 'Upgrade to Booking PRO to use this feature.';
$string['proversion:cardsview'] = 'With Booking PRO you can also use cards view.';
$string['removeresponses'] = 'Remove all responses';
$string['responses'] = 'Responses';
$string['responsesto'] = 'Responses to {$a} ';
$string['spaceleft'] = 'space available';
$string['spacesleft'] = 'spaces available';
$string['subscribersto'] = 'Teachers for \'{$a}\'';
$string['taken'] = 'Taken';
$string['timerestrict'] = 'Restrict answering to this time period: This is deprecated and will be removed. Please use "Restrict Access" settings for making the booking activity available for a certain period';
$string['restrictanswerperiodopening'] = 'Booking is possible only after a certain date';
$string['restrictanswerperiodclosing'] = 'Booking is possible only until a certain date';
$string['to'] = 'to';
$string['viewallresponses'] = 'Manage {$a} responses';
$string['yourselection'] = 'Your selection';

// Subscribeusers.php.
$string['cannotremovesubscriber'] = 'You have to remove the activity completion prior to cancel the booking. Booking was not cancelled!';
$string['allchangessaved'] = 'All changes have been saved.';
$string['backtoresponses'] = '&lt;&lt; Back to responses';
$string['allusersbooked'] = 'All {$a} selected users have successfully been assigned to this booking option.';
$string['notallbooked'] = 'The following users could not be booked due to reaching the max number of bookings per user or lack of available places for the booking option: {$a}';
$string['enrolledinoptions'] = "already booked in booking options: ";
$string['onlyusersfrominstitution'] = 'You can only add users from this institution: {$a}';
$string['resultofcohortorgroupbooking'] = '<p>This is the result of your cohort booking:</p>
<ul>
<li>{$a->sumcohortmembers} users found in the selected cohorts</li>
<li>{$a->sumgroupmembers} users found in the selected groups</li>
<li><b>{$a->subscribedusers} users where booked for this option</b></li>
</ul>';
$string['problemsofcohortorgroupbooking'] = '<br><p>Not all users could be booked with cohort booking:</p>
<ul>
<li>{$a->notenrolledusers} users are not enrolled in the course</li>
<li>{$a->notsubscribedusers} users not booked for other reasons</li>
</ul>';
$string['nogrouporcohortselected'] = 'You need to select at least one group or cohort.';
$string['bookanyoneswitchon'] = '<i class="fa fa-user-plus" aria-hidden="true"></i> Allow booking of users who are not enrolled';
$string['bookanyoneswitchoff'] = '<i class="fa fa-user-times" aria-hidden="true"></i> Do not allow booking of users who are not enrolled (recommended)';
$string['bookanyonewarning'] = 'Be careful: You can now book any users you want. Only use this setting if you know what you are doing.
 To book users who are not enrolled into the course might cause problems.';

// Subscribe_cohort_or_group_form.php.
$string['scgfcohortheader'] = 'Cohort subscription';
$string['scgfgroupheader'] = 'Group subscription';
$string['scgfselectcohorts'] = 'Select cohort(s)';
$string['scgfbookgroupscohorts'] = 'Book cohort(s) or group(s)';
$string['scgfselectgroups'] = 'Select group(s)';

// Bookingform.
$string['address'] = 'Address';
$string['general'] = 'General';
$string['advancedoptions'] = 'Advanced options';
$string['btnbooknowname'] = 'Name of button: Book now';
$string['btncacname'] = 'Name of button: Confirm activity completion';
$string['btncancelname'] = 'Name of button: Cancel booking';
$string['courseurl'] = 'Course URL';
$string['description'] = 'Description';
$string['disablebookingusers'] = 'Disable booking of users - hide Book now button.';
$string['disablecancel'] = "Disable cancellation of this booking option";
$string['disablecancelforinstance'] = "Disable cancellation for the whole booking instance.
(If you activate this, then it won't be possible to cancel any booking within this instance.)";
$string['bookotheruserslimit'] = 'Max. number of users a teacher assigned to the option can book';
$string['department'] = 'Department';
$string['institution'] = 'Institution';
$string['institution_help'] = 'You can either enter the institution name manually or choose from a list of previous institutions.
                                    You can choose one institution only. Once you save, the institution will be added to the list.';
$string['lblsputtname'] = 'Name of label: Send poll url to teachers';
$string['lblteachname'] = 'Name of label: Teachers';
$string['limitanswers_help'] = 'If you change this option and you have booked people, you can remove them without notification!';
$string['location'] = 'Location';
$string['location_help'] = 'You can either enter the location name manually or choose from a list of previous locations.
                                    You can choose one location only. Once you save, the location will be added to the list.';
$string['removeafterminutes'] = 'Remove activity completion after N minutes';
$string['banusernames'] = 'Ban usernames';
$string['banusernames_help'] = 'To limit which usernames can`t apply just write in this field and separate with a coma. To ban usernames that end with gmail.com and yahoo.com just write: gmail.com or yahoo.com';
$string['completionmodule'] = 'Upon completion of the selected course activity, enable bulk deletion of user bookings';
$string['completionmodule_help'] = 'Display bulk deletion button for booking answers, if another course module has been completed. The bookings of users will be deleted with a click of a button on the report page! Only activities with completion enabled can be selected from the list.';
$string['teacherroleid'] = 'Subscribe teacher with that role to the course';
$string['bookingoptiontitle'] = 'Booking option title';
$string['addastemplate'] = 'Add as template';
$string['notemplate'] = 'Do not use as template';
$string['astemplate'] = 'Use as template in this course';
$string['asglobaltemplate'] = 'Use as global template';
$string['templatedeleted'] = 'Template was deleted!';
$string['bookingoptionname'] = 'Booking option name';
$string['recurringheader'] = 'Recurring options';
$string['repeatthisbooking'] = 'Repeat this option';
$string['howmanytimestorepeat'] = 'How many times to repeat?';
$string['howoftentorepeat'] = 'How often to repeat?';

// Categories.
$string['categoryheader'] = '[DEPRECATED] Category';
$string['category'] = 'Category';
$string['categories'] = 'Categories';
$string['addcategory'] = 'Edit categories';
$string['forcourse'] = 'for course';
$string['addnewcategory'] = 'Add new category';
$string['categoryname'] = 'Category name';
$string['rootcategory'] = 'Root';
$string['selectcategory'] = 'Select parent category';
$string['editcategory'] = 'Edit';
$string['deletecategory'] = 'Delete';
$string['deletesubcategory'] = 'Please, first delete all subcategories of this category!';
$string['usedinbooking'] = 'You can\'t delete this category, because you\'re using it in booking!';
$string['successfulldeleted'] = 'Category was deleted!';
$string['sqlfiltercheckstring'] = 'Hide bookingoption when condition not met';

// Events.
$string['bookingoptiondate_created'] = 'Booking option date created';
$string['bookingoptiondate_updated'] = 'Booking option date updated';
$string['bookingoptiondate_deleted'] = 'Booking option date deleted';
$string['custom_field_changed'] = 'Custom field changed';
$string['pricecategory_changed'] = 'Price category changed';
$string['reminder1_sent'] = 'First reminder sent';
$string['reminder2_sent'] = 'Second reminder sent';
$string['reminder_teacher_sent'] = 'Teacher reminder sent';
$string['optiondates_teacher_added'] = 'Substitution teacher was added';
$string['optiondates_teacher_deleted'] = 'Teacher deleted from teaching journal';
$string['booking_failed'] = 'Booking failed';
$string['rest_script_success'] = 'Rest script execution';
$string['rest_script_failed'] = 'Script execution has failed';
$string['booking_afteractionsfailed'] = 'Actions after booking failed';
$string['rest_script_executed'] = 'After the rest call has been executed';

// View.php.
$string['bookingpolicyagree'] = 'I have read, understood and agree to the booking policy.';
$string['bookingpolicynotchecked'] = 'You have not accepted the booking policy.';
$string['allbookingoptions'] = 'Download users for all booking options';
$string['attachedfiles'] = 'Attached files';
$string['availability'] = 'Still available';
$string['available'] = 'Places available';
$string['booked'] = 'Booked';
$string['always'] = 'Always';
$string['fullybooked'] = 'Fully booked';
$string['notfullybooked'] = 'Not fully booked';
$string['fullwaitinglist'] = 'Full waitinglist';
$string['notfullwaitinglist'] = 'Not full waitinglist';

$string['notifyme'] = 'Notify when available';
$string['alreadyonlist'] = 'You will be notified';
$string['bookedpast'] = 'Booked (course finished)';
$string['bookingdeleted'] = 'Your booking was cancelled';
$string['bookingmeanwhilefull'] = 'Meanwhile someone took already the last place';
$string['bookingsaved'] = 'Your booking was successfully saved. You can now proceed to book other courses.';
$string['booknow'] = 'Book now';
$string['bookotherusers'] = 'Book other users';
$string['cancelbooking'] = 'Cancel booking';
$string['executerestscript'] = 'Execute REST script';
$string['closed'] = 'Booking closed';
$string['confirmbookingoffollowing'] = 'Please confirm the booking of following course';
$string['confirmdeletebookingoption'] = 'Do you really want to delete this booking option?';
$string['coursedate'] = 'Date';
$string['createdbywunderbyte'] = 'Booking module created by Wunderbyte GmbH';
$string['deletebooking'] = 'Do you really want to unsubscribe from following course? <br /><br /> <b>{$a} </b>';
$string['deletethisbookingoption'] = 'Delete this booking option';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['download'] = 'Download';
$string['downloadusersforthisoptionods'] = 'Download users as .ods';
$string['downloadusersforthisoptionxls'] = 'Download users as .xls';
$string['endtimenotset'] = 'End date not set';
$string['mustfilloutuserinfobeforebooking'] = 'Before proceeding to the booking form, please fill in some personal booking information';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['showallbookingoptions'] = 'All booking options';
$string['showmybookingsonly'] = 'My booked options';
$string['showmyfieldofstudyonly'] = "My field of study";
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['confirmdeletebookingoption'] = 'Do you really want to delete this booking option?';
$string['norighttobook'] = 'Booking is not possible for your user role. Please contact the site administrator to give you the appropriate rights or enrol/sign in.';
$string['maxperuserwarning'] = 'You currently have used {$a->count} out of {$a->limit} maximum available bookings ({$a->eventtype}) for your user account';
$string['bookedpast'] = 'Booked (course terminated)';
$string['attachedfiles'] = 'Attached files';
$string['eventduration'] = 'Event duration';
$string['eventdesc:bookinganswercancelledself'] = 'The user "{$a->user}" cancelled "{$a->title}".';
$string['eventdesc:bookinganswercancelled'] = 'The user "{$a->user}" cancelled "{$a->relateduser}" from "{$a->title}".';
$string['eventpoints'] = 'Points';
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['managebooking'] = 'Manage';
$string['mustfilloutuserinfobeforebooking'] = 'Befor proceeding to the booking form, please fill in some personal booking information';
$string['nobookingselected'] = 'No booking option selected';
$string['notbooked'] = 'Not yet booked';
$string['onwaitinglist'] = 'You are on the waiting list';
$string['confirmed'] = 'Confirmed';
$string['organizatorname'] = 'Organizer name';
$string['organizatorname_help'] = 'You can either enter the organizer name manually or choose from a list of previous organizers.
                                    You can choose one organizer only. Once you save, the organizer will be added to the list.';
$string['availableplaces'] = 'Places available: {$a->available} of {$a->maxanswers}';
$string['pollurl'] = 'Poll url';
$string['pollurlteachers'] = 'Teachers poll url';
$string['feedbackurl'] = 'Poll url';
$string['feedbackurlteachers'] = 'Teachers poll url';
$string['select'] = 'Selection';
$string['activebookingoptions'] = 'Active booking options';
$string['starttimenotset'] = 'Start date not set';
$string['subscribetocourse'] = 'Enrol users in the course';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['tagtemplates'] = 'Tag templates';
$string['unlimitedplaces'] = 'Unlimited';
$string['userdownload'] = 'Download users';
$string['waitinglist'] = 'Waiting list';
$string['waitingplacesavailable'] = 'Waiting list places available: {$a->overbookingavailable} of {$a->maxoverbooking}';
$string['waitspaceavailable'] = 'Places on waiting list available';
$string['banusernameswarning'] = "Your username has been banned so you are unable book.";
$string['duplicatebooking'] = 'Duplicate this booking option';
$string['moveoptionto'] = 'Move booking option to other booking instance';

// Tag templates.
$string['cancel'] = 'Cancel';
$string['addnewtagtemplate'] = 'Add new';
$string['addnewtagtemplate'] = 'Add new tag template';
$string['savenewtagtemplate'] = 'Save';
$string['tagtag'] = 'Tag';
$string['tagtext'] = 'Text';
$string['wrongdataallfields'] = 'Please, fill out all fields!';
$string['tagsuccessfullysaved'] = 'Tag was saved.';
$string['edittag'] = 'Edit';
$string['tagdeleted'] = 'Tag template was deleted!';

// Mod_booking\all_options.
$string['showdescription'] = 'Show description';
$string['hidedescription'] = 'Hide description';
$string['cancelallusers'] = 'Cancel all booked users';

// Mod_form.
$string['signinlogoheader'] = 'Logo in header to display on the sign-in sheet';
$string['signinlogofooter'] = 'Logo in footer to display on the sign-in sheet';
$string['textdependingonstatus'] = "Text depending on booking status ";
$string['beforebookedtext'] = 'Before booked';
$string['beforebookedtext_help'] = 'Message shown before option being booked';
$string['beforecompletedtext'] = 'After booked';
$string['beforecompletedtext_help'] = 'Message shown after option become booked';
$string['aftercompletedtext'] = 'After activity completed';
$string['aftercompletedtext_help'] = 'Message shown after activity become completed';
$string['connectedbooking'] = '[DEPRECATED] Connected booking';
$string['errorpagination'] = 'Please enter a number bigger than 0';
$string['notconectedbooking'] = 'Not connected';
$string['connectedbooking_help'] = 'Booking instance eligible for transferring booked users. You can define from which option within the selected booking instance and how many users you will accept.';
$string['allowbookingafterstart'] = 'Allow booking after course start';
$string['cancancelbook'] = 'Allow users to cancel their booking themselves';
$string['cancancelbookdays'] = 'Disallow users to cancel their booking n days before start. Minus means, that users can still cancel n days AFTER course start.';
$string['cancancelbookdays:semesterstart'] = 'Disallow users to cancel their booking n days before <b>semester</b> start. Minus means, that users can still cancel n days AFTER semester start.';
$string['cancancelbookdays:bookingopeningtime'] = 'Disallow users to cancel their booking n days before <b>registration start</b> (booking opening time). Minus means, that users can still cancel n days AFTER registration start.';
$string['cancancelbookdays:bookingclosingtime'] = 'Disallow users to cancel their booking n days before <b>registration end</b> (booking closing time). Minus means, that users can still cancel n days AFTER registration end.';
$string['cancancelbookdaysno'] = "Don't limit";
$string['addtocalendar'] = 'Add to course calendar';
$string['caleventtype'] = 'Calendar event visibility';
$string['caldonotadd'] = 'Do not add to course calendar';
$string['caladdascourseevent'] = 'Add to calendar (visible only to course participants)';
$string['caladdassiteevent'] = 'Add to calendar (visible to all users)';
$string['limitanswers'] = 'Limit the number of participants';
$string['maxparticipantsnumber'] = 'Max. number of participants';
$string['maxoverbooking'] = 'Max. number of places on waiting list';
$string['minanswers'] = 'Min. number of participants';
$string['defaultbookingoption'] = 'Default booking options';
$string['activatemails'] = 'Activate e-mails (confirmations, notifications and more)';
$string['copymail'] = 'Send confirmation e-mail to booking manager';
$string['bookingpolicy'] = 'Booking policy';
$string['viewparam'] = 'View type';
$string['viewparam:list'] = 'List view';
$string['viewparam:cards'] = 'Cards view';

$string['eventslist'] = 'Recent updates';
$string['showrecentupdates'] = 'Show recent updates';
$string['showmessages'] = 'Show messages';

$string['error:semestermissingbutcanceldependentonsemester'] = 'The setting to calculate cancellation periods from semester start is active but semester is missing!';

$string['page:bookingpolicy'] = 'Booking policy';
$string['page:bookitbutton'] = 'Book';
$string['page:subbooking'] = 'Supplementary bookings';
$string['page:confirmation'] = 'Booking complete';
$string['page:checkout'] = 'Checkout';
$string['page:customform'] = 'Fill out form';

$string['confirmationmessagesettings'] = 'Confirmation e-mail settings';
$string['usernameofbookingmanager'] = 'Choose a booking manager';
$string['usernameofbookingmanager_help'] = 'Username of the user who will be displayed in the "From" field of the confirmation notifications. If the option "Send confirmation e-mail to booking manager" is enabled, this is the user who receives a copy of the confirmation notifications.';
$string['bookingmanagererror'] = 'The username entered is not valid. Either it does not exist or there are more then one users with this username (example: if you have mnet and local authentication enabled)';
$string['autoenrol'] = 'Automatically enrol users';
$string['autoenrol_help'] = 'If selected, users will be enrolled onto the relevant course as soon as they make the booking and unenrolled from that course as soon as the booking is cancelled.';
$string['bookedtext'] = 'Booking confirmation';
$string['userleave'] = 'User has cancelled his/her own booking (enter 0 to turn off)';
$string['waitingtext'] = 'Waiting list confirmation';
$string['statuschangetext'] = 'Status change message';
$string['deletedtext'] = 'Cancelled booking message (enter 0 to turn off)';
$string['bookingchangedtext'] = 'Message to be sent when a booking option changes (will only be sent to users who have already booked). Use the placeholder {changes} to show the changes. Enter 0 to turn off change notifications.';
$string['comments'] = 'Comments';
$string['nocomments'] = 'Commenting disabled';
$string['allcomments'] = 'Everybody can comment';
$string['enrolledcomments'] = 'Only enrolled';
$string['completedcomments'] = 'Only with completed activity';
$string['ratings'] = 'Bookingoption ratings';
$string['option_noimage'] = 'No image';
$string['noratings'] = 'Ratings disabled';
$string['allratings'] = 'Everybody can rate';
$string['enrolledratings'] = 'Only enrolled';
$string['completedratings'] = 'Only with completed activity';
$string['notes'] = 'Booking notes';
$string['uploadheaderimages'] = 'Header images for booking options';
$string['bookingimagescustomfield'] = 'Booking option custom field to match the header images with';
$string['bookingimages'] = 'Upload header images for booking options - they need to have the exact same name as the value of the selected customfield in each booking option.';
$string['emailsettings'] = 'E-mail settings '. $badgedepr;
$string['helptext:emailsettings'] = '<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span>&nbsp;Deprecated function, please migrate your templates & settings to <a href="{$a}">Booking Rules</a></span>!
</div>';

// Mail templates (instance specific or global).
$string['mailtemplatesadvanced'] = 'Activate advanced settings for e-mail templates';
$string['mailtemplatessource'] = 'Set source of mail templates';
$string['mailtemplatessource_help'] = '<b>Caution:</b> If you choose global e-mail templates, the instance-specific mail
templates won\'t be used. Instead the e-mail templates specified in the booking plugin settings will be used. <br><br>
Please make sure that there are existing e-mail templates in the booking settings for each e-mail type.';
$string['mailtemplatesinstance'] = 'Use mail templates from this booking instance (default)';
$string['mailtemplatesglobal'] = 'Use global mail templates from plugin settings';
$string['uselegacymailtemplates'] = 'Still use legacy mail templates';
$string['uselegacymailtemplates_desc'] = 'This function is deprecated and will be removed in the near future. We strongly encourage you to migrate your templates & settings to <a href="{$a}">Booking Rules</a>.
 <span class="text-danger"><b>Be careful:</b> If you uncheck this box, your email templates in your booking-instances won\'t be shown and used anymore.</span>';


$string['feedbackurl_help'] = 'Enter a link to a feedback form that should be sent to participants.
 It can be added to e-mails with the <b>{pollurl}</b> placeholder.';

$string['feedbackurlteachers_help'] = 'Enter a link to a feedback form that should be sent to teachers.
 It can be added to e-mails with the <b>{pollurlteachers}</b> placeholder.';

$string['bookingchangedtext_help'] = 'Enter 0 to turn change notifications off.';
$string['placeholders_help'] = 'Leave this blank to use the site default text.';
$string['helptext:placeholders'] = '<div class="alert alert-info" style="margin-left: 200px;">
<a data-toggle="collapse" href="#collapsePlaceholdersHelptext" role="button" aria-expanded="false" aria-controls="collapsePlaceholdersHelptext">
  <i class="fa fa-question-circle" aria-hidden="true"></i><span>&nbsp;Placeholders you can use in your emails.</span>
</a>
</div>
<div class="collapse" id="collapsePlaceholdersHelptext">
  <div class="card card-body">
    {$a}
  </div>
</div>';

// Placeholders.
$string['bookingdetails'] = "bookingdetails";
$string['gotobookingoption'] = "Go to booking option";
$string['bookinglink'] = "bookinglink";
$string['changes'] = "changes";
$string['coursecalendarurl'] = "coursecalendarurl";
$string['courselink'] = "courselink";
$string['duration'] = "duration";
$string['email'] = "email";
$string['enddate'] = "enddate";
$string['endtime'] = "endtime";
$string['firstname'] = "firstname";
$string['duration'] = "duration";
$string['journal'] = "journal";
$string['lastname'] = "lastname";
$string['numberparticipants'] = "numberparticipants";
$string['numberwaitinglist'] = "numberwaitinglist";
$string['participant'] = "participant";
$string['pollstartdate'] = "pollstartdate";
$string['qr_id'] = "qr_id";
$string['qr_username'] = "qr_username";
$string['startdate'] = "startdate";
$string['starttime'] = "starttime";
$string['rest_response'] = "rest_response";
$string['eventdescription'] = "eventdescription";
$string['customform'] = "customform";
$string['title'] = "title";
$string['usercalendarurl'] = "usercalendarurl";
$string['username'] = "username";
$string['loopprevention'] = 'The placeholder {$a} causes a loop. Please replace it.';
$string['duedate'] = 'duedate of installment';
$string['numberofinstallment'] = 'numberofinstallment';
$string['numberofinstallmentstring'] = 'installment number {$a}';
$string['installmentprice'] = 'installmentprice';
$string['sthwentwrongwithplaceholder'] = ''; // Returnvalue for failed placeholders. {$a} returns classname.

$string['userinfosasstring'] = '{$a->firstname} {$a->lastname} (ID:{$a->id})';

$string['configurefields'] = 'Configure fields and columns';
$string['manageresponsespagefields'] = 'Manage responses - Page';
$string['manageresponsesdownloadfields'] = 'Manage responses - Download (CSV, XLSX...)';
$string['optionspagefields'] = 'Booking options overview - Page';
$string['optionsdownloadfields'] = 'Booking options overview - Download (CSV, XLSX...)';
$string['signinsheetfields'] = 'Sign-in sheet fields (PDF)';
$string['signinonesession'] = 'Display date(s) in the header';
$string['signinaddemptyrows'] = 'Add empty rows';
$string['signinextrasessioncols'] = 'Add extra columns for dates';
$string['signinadddatemanually'] = 'Add date manually';
$string['signinhidedate'] = 'Hide dates';
$string['includeteachers'] = 'Include teachers in the sign-in sheet';
$string['choosepdftitle'] = 'Select a title for the sign-in sheet';
$string['addtogroup'] = 'Automatically enrol users in group';
$string['addtogroup_help'] = 'Automatically enrol users in group - group will be created automatically with name: Booking name - Option name';
$string['bookingattachment'] = 'Attachment';
$string['bookingcategory'] = 'Category';
$string['bookingduration'] = 'Duration';
$string['bookingorganizatorname'] = 'Organizer name';
$string['bookingpoints'] = 'Course points';
$string['bookingpollurl'] = 'Poll url';
$string['bookingpollurlteachers'] = 'Teachers poll url';
$string['bookingtags'] = 'Tags';
$string['customlabelsdeprecated'] = '[DEPRECATED] Custom labels';
$string['editinstitutions'] = 'Edit institutions';
$string['entervalidurl'] = 'Please, enter a valid URL!';
$string['eventtype'] = 'Event type';
$string['eventtype_help'] = 'You can either enter the event type manually or choose from a list of previous event types.
                             You can choose one event type only. Once you save, the event type will be added to the list.';
$string['groupname'] = 'Group name';
$string['lblacceptingfrom'] = 'Name of label: Accepting from';
$string['lblbooking'] = 'Name of label: Booking';
$string['lblinstitution'] = 'Name of label: Institution';
$string['lbllocation'] = 'Name of label: Location';
$string['lblname'] = 'Name of label: Name';
$string['lblnumofusers'] = 'Name of label: Num. of users';
$string['lblsurname'] = 'Name of label: Surname';
$string['maxperuser'] = 'Max current bookings per user';
$string['maxperuser_help'] = 'The maximum number of bookings an individual user can make in this activity at once.
<b>Attention:</b> In the Booking plugin settings, you can choose if users who completed or attended and booking options
that have already passed should be counted or not counted to determine the maximum number of bookings a user can book within this instance.';
$string['notificationtext'] = 'Notification message';
$string['numgenerator'] = 'Enable rec. number generation?';
$string['paginationnum'] = "N. of records - pagination";
$string['pollurlteacherstext'] = 'Message for the poll url sent to teachers';
$string['pollurltext'] = 'Message for sending poll url to booked users';
$string['reset'] = 'Reset';
$string['searchtag'] = 'Search tags';
$string['showinapi'] = 'Show in API?';
$string['whichview'] = 'Default view for booking options';
$string['whichviewerror'] = 'You have to include the default view in: Views to show in the booking options overview';
$string['showviews'] = 'Views to show in the booking options overview';
$string['enablepresence'] = 'Enable presence';
$string['removeuseronunenrol'] = 'Remove user from booking upon unenrolment from associated course?';

// Editoptions.php.
$string['editbookingoption'] = 'Edit booking option';
$string['createnewbookingoption'] = 'New booking option';
$string['createnewbookingoptionfromtemplate'] = 'Add a new booking option from template';
$string['connectedmoodlecourse'] = 'Connected Moodle course';
$string['connectedmoodlecourse_help'] = 'Choose "Create new course..." if you want a new Moodle course to be created for this booking option.';
$string['courseendtime'] = 'End time of the course';
$string['coursestarttime'] = 'Start time of the course';
$string['newcourse'] = 'Create new course...';
$string['nocourseselected'] = 'No course selected';
$string['noinstitutionselected'] = 'No institution selected';
$string['nolocationselected'] = 'No location selected';
$string['noeventtypeselected'] = 'No event type selected';
$string['importcsvbookingoption'] = 'Import CSV with booking options';
$string['importexcelbutton'] = 'Import activity completion';
$string['activitycompletiontext'] = 'Message to be sent to user when booking option is completed';
$string['activitycompletiontextsubject'] = 'Booking option completed';
$string['changesemester'] = 'Reset and create dates for semester';
$string['changesemester:warning'] = '<strong>Be careful:</strong> By clicking "Save changes" all dates will be deleted
and be replaced with dates of the chosen semester.';
$string['changesemesteradhoctaskstarted'] = 'Success. The dates will be re-generated the next time CRON is running. This may take several minutes.';
$string['activitycompletiontextmessage'] = 'You have completed the following booking option:

{$a->bookingdetails}

Go to course: {$a->courselink}
See all booking options: {$a->bookinglink}';
$string['sendmailtobooker'] = 'Book other users page: Send mail to user who books instead to users who are booked';
$string['sendmailtobooker_help'] = 'Activate this option in order to send booking confirmation mails to the user who books other users instead to users, who have been added to a booking option. This is only relevant for bookings made on the page "book other users".';
$string['submitandadd'] = 'Add a new booking option';
$string['submitandstay'] = 'Stay here';
$string['waitinglisttaken'] = 'On the waiting list';
$string['groupexists'] = 'The group already exists in the target course, please choose another name for the booking option';
$string['groupdeleted'] = 'This booking instance creates groups automatically in the target course. But the group has been manually deleted in the target course. Activate the following checkbox in order to recreate the group';
$string['recreategroup'] = 'Recreate group in the target course and enrol users in group';
$string['copy'] = 'copy';
$string['enrolmentstatus'] = 'Enrol users at course start time (Default: Not checked &rarr; enrol them immediately.)';
$string['enrolmentstatus_help'] = 'Notice: In order for automatic enrolment to work, you need to change the booking instance setting
 "Automatically enrol users" to "Yes".';
$string['duplicatename'] = 'This booking option name already exists. Please choose another one.';
$string['newtemplatesaved'] = 'New template for booking option was saved.';
$string['manageoptiontemplates'] = 'Manage booking option templates';
$string['usedinbookinginstances'] = 'Template is used in following booking instances';
$string['optiontemplatename'] = 'Option template name';
$string['option_template_not_saved_no_valid_license'] = 'Booking option template could not be saved as template.
                                                  Upgrade to PRO version to save an unlimited number of templates.';

// Option_form.php.
$string['bookingoptionimage'] = 'Header image';
$string['submitandgoback'] = 'Close this form';
$string['bookingoptionprice'] = 'Price';

// We removed this, but keep it for now as we might need these strings again.
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/*$string['er_saverelationsforoptiondates'] = 'Save entity for each date too';
$string['confirm:er_saverelationsforoptiondates'] = '<span class="text-danger">
<b>Be careful:</b> This booking option has dates with various entities.
Do you really want to set this entity for ALL dates?</span>';
$string['error:er_saverelationsforoptiondates'] = 'Please confirm that you want to overwrite deviating entities.'; */

$string['pricecategory'] = 'Price category';
$string['pricecurrency'] = 'Currency';
$string['optionvisibility'] = 'Visibility';
$string['optionvisibility_help'] = 'Here you can choose whether the option should be visible for everyone or if it should be hidden from normal users and be visible to entitled users only.';
$string['optionvisible'] = 'Visible to everyone (default)';
$string['optioninvisible'] = 'Hide from normal users (visible to entitled users only)';
$string['optionvisibledirectlink'] = 'Normal users can only see this option with a direct link';
$string['invisibleoption'] = '<i class="fa fa-eye-slash" aria-hidden="true"></i> Invisible';
$string['optionannotation'] = 'Internal annotation';
$string['optionannotation_help'] = 'Add internal remarks, annotations or anything you want. It will only be shown in this form and nowhere else.';
$string['optionidentifier'] = 'Unique identifier';
$string['optionidentifier_help'] = 'Add a unique identifier for this booking option.';
$string['titleprefix'] = 'Prefix';
$string['titleprefix_help'] = 'Add a prefix which will be shown before the option title, e.g. "BB42".';
$string['error:identifierexists'] = 'Choose another identifier. This one already exists.';

// Optionview.php.
$string['invisibleoption:notallowed'] = 'You are not allowed to see this booking option.';

// Importoptions.php.
$string['csvfile'] = 'CSV file';
$string['dateerror'] = 'Wrong date in line {$a}: ';
$string['dateparseformat'] = 'Date parse format';
$string['dateparseformat_help'] = 'Please, use date format like specified in CSV file. Help with <a href="http://php.net/manual/en/function.date.php">this</a> resource for options.';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['importcsvtitle'] = 'Import CSV';
$string['importfinished'] = 'Importing finished!';
$string['noteacherfound'] = 'The user specified as teacher on line {$a} does not exist on the platform.';
$string['nouserfound'] = 'No user found: ';
$string['import_failed'] = 'The import failed due to following reason: ';
$string['import_partial'] = 'The import was only partially completed. There were problems with following lines and they were not imported: ';
$string['importinfo'] = 'Import info: You can use the following columns in the csv upload (Explanation in parenthesis)';
$string['coursedoesnotexist'] = 'The Coursenumber {$a} does not exist';

// New importer.
// TODO: Translate.
$string['importcsv'] = 'CSV Importer';
$string['import_identifier'] = 'Unique identifier of booking option';
$string['import_tileprefix'] = 'Prefix (e.g., course number)';
$string['import_title'] = 'Title of booking option';
$string['import_text'] = 'Title of a booking option (synonym for text)';
$string['import_location'] = 'Location of a booking option. It is automatically linked to an "Entity" (local_entities) if there is a 100% match with the clear name. The ID number of an entity can also be entered here.';
$string['import_identifier'] = 'Unique identifier of booking option';
$string['import_maxanswers'] = 'Maximum number of bookings per booking option';
$string['import_maxoverbooking'] = 'Maximum number of waiting list spots per booking option';
$string['import_coursenumber'] = 'Moodle course ID number into which the bookers are enrolled';
$string['import_courseshortname'] = 'Short name of a Moodle course into which the bookers are enrolled';
$string['import_addtocalendar'] = 'Add to Moodle calendar';
$string['import_dayofweek'] = 'Weekday of a booking option, e.g., Monday';
$string['import_dayofweektime'] = 'Weekday and time of a booking option, e.g., Monday, 10:00 - 12:00';
$string['import_dayofweekstarttime'] = 'Start time of a course, e.g., 10:00';
$string['import_dayofweekendtime'] = 'End time of a course, e.g., 12:00';
$string['import_description'] = 'Description of the booking option';
$string['import_default'] = 'Standard price of a booking option. Additional prices can only be specified if the standard price is set. The columns must correspond to the short name of the booking categories.';
$string['import_teacheremail'] = 'Email addresses of users on the platform who can be set as teachers in the booking options. If there are multiple email addresses, use a comma as a separator (be careful with "escape" in comma-separated CSV!).';
$string['import_useremail'] = 'Email addresses of users on the platform who have booked this booking option. If there are multiple email addresses, use a comma as a separator (be careful with "escape" in comma-separated CSV!).';

$string['importsuccess'] = 'Import was successful. {$a} record(s) treated.';
$string['importfailed'] = 'Import failed';
$string['dateparseformat'] = 'Date parse format';
$string['dateparseformat_help'] = 'Please, use date format like specified in CSV file. Help with <a href="http://php.net/manual/en/function.date.php">this</a> resource for options.';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['importcolumnsinfos'] = 'Information about columns to be imported:';
$string['mandatory'] = 'mandatory';
$string['optional'] = 'optional';
$string['format'] = 'format';
$string['openformat'] = 'open format';
$string['downloaddemofile'] = 'Download demofile';
$string['updatedrecords'] = '{$a} record(s) updated.';
$string['addedrecords'] = '{$a} record(s) added.';
$string['callbackfunctionnotdefined'] = 'Callback function is not defined.';
$string['callbackfunctionnotapplied'] = 'Callback function could not be applied.';
$string['ifdefinedusedtomatch'] = 'If defined, will be used to match.';
$string['fieldnamesdontmatch'] = "The imported fieldnames don't match the defined fieldnames.";
$string['checkdelimiteroremptycontent'] = 'Check if data is given and separated via the selected symbol.';
$string['wronglabels'] = 'Imported CSV not containing the right labels. Column {$a} can not be imported.';
$string['missinglabel'] = 'Imported CSV does not contain mandatory column {$a}. Data can not be imported.';
$string['nolabels'] = 'No column labels defined in settings object.';
$string['checkdelimiter'] = 'Check if data is separated via the selected symbol.';
$string['dataincomplete'] = 'Record with componentid {$a->id} is incomplete and could not be treated entirely. Check field "{$a->field}".';

// Confirmation mail.
$string['days'] = '{$a} days';
$string['hours'] = '{$a} hours';
$string['minutes'] = '{$a} minutes';

$string['deletedtextsubject'] = 'Deleted booking: {$a->title} by {$a->participant}';
$string['deletedtextmessage'] = 'Booking option has been deleted: {$a->title}

User: {$a->participant}
Title: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Course: {$a->courselink}
Booking link: {$a->bookinglink}

';

$string['bookedtextsubject'] = 'Booking confirmation for {$a->title}';
$string['bookedtextsubjectbookingmanager'] = 'New booking for {$a->title} by {$a->participant}';
$string['bookedtextmessage'] = 'Your booking has been registered:

{$a->bookingdetails}
<p>##########################################</p>
Booking status: {$a->status}
Participant:   {$a->participant}

To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['waitingtextsubject'] = 'Booking status for {$a->title} has changed';
$string['waitingtextsubjectbookingmanager'] = 'Booking status for {$a->title} has changed';

$string['waitingtextmessage'] = 'You are now on the waiting list of:

{$a->bookingdetails}
<p>##########################################</p>
Booking status: {$a->status}
Participant:   {$a->participant}

To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';

$string['notifyemailsubject'] = 'Your booking will start soon';
$string['notifyemailmessage'] = 'Your booking will start soon:

Booking status: {$a->status}
Participant: {$a->participant}
Booking option: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['notifyemail'] = 'Participant notification before start';

$string['notifyemailteacherssubject'] = 'Your booking will start soon';
$string['notifyemailteachersmessage'] = 'Your booking will start soon:

{$a->bookingdetails}

You have <b>{$a->numberparticipants} booked participants</b> and <b>{$a->numberwaitinglist} people on the waiting list</b>.

To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['notifyemailteachers'] = 'Teacher notification before start';

$string['userleavesubject'] = 'You successfully unsubscribed from {$a->title}';
$string['userleavemessage'] = 'Hello {$a->participant},

You have been unsubscribed from {$a->title}.
';

$string['statuschangetextsubject'] = 'Booking status has changed for {$a->title}';
$string['statuschangetextmessage'] = 'Hello {$a->participant}!

Your booking status has changed.

Booking status: {$a->status}

Participant:   {$a->participant}
Booking option: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}

Go to the booking option: {$a->gotobookingoption}
';

$string['deletedbookingusersubject'] = 'Booking for {$a->title} cancelled';
$string['deletedbookingusermessage'] = 'Hello {$a->participant},

Your booking for {$a->title} ({$a->startdate} {$a->starttime}) has been cancelled.
';

$string['bookingchangedtextsubject'] = 'Change notification for {$a->title}';
$string['bookingchangedtextmessage'] = 'Your booking "{$a->title}" has changed.

Here\'s what\'s new:
{changes}

To view the change(s) and all your booked courses click on the following link: {$a->bookinglink}
';

$string['error:failedtosendconfirmation'] = 'The following user did not receive a confirmation mail

Booking status: {$a->status}
Participant: {$a->participant}
Booking option: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Link: {$a->bookinglink}
Associated course: {$a->courselink}

';

$string['pollurltextsubject'] = 'Please take the survey';
$string['pollurltextmessage'] = 'Please take the survey:

Survey URL: <a href="{pollurl}" target="_blank">{pollurl}</a>
';

$string['pollurlteacherstextsubject'] = 'Please take the survey';
$string['pollurlteacherstextmessage'] = 'Please take the survey:

Survey URL: <a href="{pollurlteachers}" target="_blank">{pollurlteachers}</a>
';

$string['reportremindersubject'] = 'Reminder: Your booked course';
$string['reportremindermessage'] = '{$a->bookingdetails}';
$string['changesinentity'] = '{$a->name} (ID: {$a->id})';
$string['entitydeleted'] = 'Location has been deleted';

// Report.php.
$string['allmailssend'] = 'All e-mails to the users have been sent!';
$string['associatedcourse'] = 'Associated course';
$string['bookedusers'] = 'Booked users';
$string['deletedusers'] = 'Deleted users';
$string['reservedusers'] = 'Users with shortterm reservations';
$string['bookingfulldidntregister'] = 'Option is full, so I didn\'t transfer all users!';
$string['booktootherbooking'] = 'Book users to other booking option';
$string['downloadallresponses'] = 'Download all responses for all booking options';
$string['copyonlythisbookingurl'] = 'Copy this booking URL';
$string['copypollurl'] = 'Copy poll URL';
$string['copytoclipboard'] = 'Copy to clipboard: Ctrl+C, Enter';
$string['editotherbooking'] = 'Other booking rules';
$string['editteachers'] = 'Edit';
$string['generaterecnum'] = "Generate numbers";
$string['generaterecnumareyousure'] = "This will generate new numbers and permanently delete the old one!";
$string['generaterecnumnotification'] = "New numbers have been generated.";
$string['gotobooking'] = '&lt;&lt; Bookings';
$string['lblbooktootherbooking'] = 'Name of button: Book users to other booking';
$string['no'] = 'No';
$string['nocourse'] = 'No course selected for this booking option';
$string['nodateset'] = 'Course date not set';
$string['nousers'] = 'No users!';
$string['numrec'] = "Rec. num.";
$string['onlythisbookingoption'] = 'Only this booking option';
$string['optiondatesmanager'] = 'Manage option dates';
$string['optionid'] = 'Option ID';
$string['optionmenu'] = 'This booking option';
$string['searchdate'] = 'Date';
$string['searchname'] = 'First name';
$string['searchsurname'] = 'Last name';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['copypollurl'] = 'Copy poll URL';
$string['gotobooking'] = '&lt;&lt; Bookings';
$string['nousers'] = 'No users!';
$string['booktootherbooking'] = 'Book users to other booking';
$string['lblbooktootherbooking'] = 'Name of button: Book users to other booking option';
$string['toomuchusersbooked'] = 'The max number of users you can book is: {$a}';
$string['transfer'] = 'Transfer';
$string['transferheading'] = 'Transfer selected users to the selected booking option';
$string['transfersuccess'] = 'The users have successfully been transferred to the new booking option';
$string['transferoptionsuccess'] = 'The booking option and the users have successfully been transferred.';
$string['transferproblem'] = 'The following could not be transferred due to booking option limitation or user limitation: {$a}';
$string['searchwaitinglist'] = 'On waiting list';
$string['selectatleastoneuser'] = 'Please, select at least 1 user!';
$string['selectanoption'] = 'Please, select a booking option';
$string['delnotification'] = 'You deleted {$a->del} of {$a->all} users. Users, that have completed activity, can\'t be deleted!';
$string['delnotificationactivitycompletion'] = 'You deleted {$a->del} of {$a->all} users. Users, that have completed activity, can\'t be deleted!';
$string['selectoptionid'] = 'Please, select option!';
$string['sendcustommsg'] = 'Send custom message';
$string['sendpollurltoteachers'] = 'Send poll url';
$string['toomuchusersbooked'] = 'The max number of users you can book is: {$a}';
$string['userid'] = 'UserID';
$string['userssuccessfullenrolled'] = 'All users have been enrolled!';
$string['userssuccessfullybooked'] = 'All users have been booked to the other booking option.';
$string['sucessfullybooked'] = 'Sucessfully booked';
$string['waitinglistusers'] = 'Users on waiting list';
$string['withselected'] = 'With selected users:';
$string['editotherbooking'] = 'Other booking rules';
$string['bookingfulldidntregister'] = 'Option is full, so I didn\'t transfer all users!';
$string['numrec'] = "Rec. num.";
$string['generaterecnum'] = "Generate numbers";
$string['generaterecnumareyousure'] = "This will generate new numbers and permanently delete the old one!";
$string['generaterecnumnotification'] = "New numbers have been generated.";
$string['searchwaitinglist'] = 'On waiting list';
$string['ratingsuccessful'] = 'The ratings were successfully updated';
$string['userid'] = 'UserID';
$string['nodateset'] = 'Course date not set';
$string['editteachers'] = 'Edit';
$string['sendpollurltoteachers'] = 'Send poll url';
$string['copytoclipboard'] = 'Copy to clipboard: Ctrl+C, Enter';
$string['yes'] = 'Yes';
$string['sendreminderemailsuccess'] = 'Notification e-mail has been sent!';
$string['sign_in_sheet_download'] = 'Download sign-in sheet';
$string['sign_in_sheet_configure'] = 'Configure sign-in sheet';
$string['status_complete'] = "Complete";
$string['status_incomplete'] = "Incomplete";
$string['status_noshow'] = "No show";
$string['status_failed'] = "Failed";
$string['status_unknown'] = "Unknown";
$string['status_attending'] = "Attending";
$string['presence'] = "Presence";
$string['confirmpresence'] = "Confirm presence";
$string['selectpresencestatus'] = "Choose presence status";
$string['userssuccessfullygetnewpresencestatus'] = 'All users have a new presence status.';
$string['deleteresponsesactivitycompletion'] = 'Delete all users with completed activity: {$a}';
$string['signature'] = 'Signature';
$string['userssucesfullygetnewpresencestatus'] = 'Presence status for selected users successfully updated';
$string['copytotemplate'] = 'Save booking option as template';
$string['copytotemplatesucesfull'] = 'Booking option was sucessfully saved as template.';
$string['successfullybooked'] = 'Successfully booked';
$string['indexnumber'] = 'Numbering';
$string['bookingdate'] = 'Booking date';

// Send message.
$string['booking:cansendmessages'] = 'Can send messages';
$string['messageprovider:sendmessages'] = 'Can send messages';
$string['activitycompletionsuccess'] = 'All selected users have been marked for activity completion';
$string['booking:communicate'] = 'Can communicate';
$string['confirmoptioncompletion'] = '(Un)confirm completion status';
$string['enablecompletion'] = 'At least one of the booked options has to be marked as completed';
$string['enablecompletiongroup'] = 'Require entries';
$string['messagesend'] = 'Your message has been sent.';
$string['messagesubject'] = 'Subject';
$string['messagetext'] = 'Message';
$string['sendmessage'] = 'Send message';

// Teachers_handler.php.
$string['teachersforoption'] = 'Teachers';
$string['teachersforoption_help'] = '<b>BE CAREFUL: </b>When adding teachers here, they will also be <b>added to each date in the future</b> in the teaching report.
When deleting teachers here, they will be <b>removed from each date in the future</b> in the teaching report!';
$string['info:teachersforoptiondates'] = 'Go to the <a href="{$a}" target="_self">teaching journal</a>, to manage teachers for specific dates.';

// Lib.php.
$string['pollstrftimedate'] = '%Y-%m-%d';
$string['mybookingoptions'] = 'My booked options';
$string['bookuserswithoutcompletedactivity'] = "Book users without completed activity";
$string['sessionremindermailsubject'] = 'Reminder: You have an upcoming session';
$string['sessionremindermailmessage'] = '<p>Keep in mind: You are booked for the following session:</p>
<p>{$a->optiontimes}</p>
<p>##########################################</p>
<p>{$a->sessiondescription}</p>
<p>##########################################</p>
<p>Booking status: {$a->status}</p>
<p>Participant: {$a->participant}</p>
';

// All_users.php.
$string['completed'] = 'Completed';
$string['usersonlist'] = 'User on list';
$string['fullname'] = 'Full name';
$string['timecreated'] = 'Time created';
$string['sendreminderemail'] = "Send reminder e-mail";

// Importexcel.php.
$string['importexceltitle'] = 'Import activity completion';

// Importexcel_file.php.
$string['excelfile'] = 'CSV file with activity completion';

// Institutions.php.
$string['institutions'] = 'Institutions';

// Otherbooking.php.
$string['otherbookingoptions'] = 'Accepting from';
$string['otherbookingnumber'] = 'Num. of users';
$string['otherbookingaddrule'] = 'Add new rule';
$string['editrule'] = "Edit";
$string['deleterule'] = 'Delete';
$string['deletedrule'] = 'Rule deleted.';

// Otherbookingaddrule_form.php.
$string['selectoptioninotherbooking'] = "Option";
$string['otherbookinglimit'] = "Limit";
$string['otherbookinglimit_help'] = "How many users you accept from option. If 0, you can accept unlimited users.";
$string['otherbookingsuccessfullysaved'] = 'Rule saved!';

// Optiondates.php.
$string['optiondatestime'] = 'Session time';
$string['optiondatesmessage'] = 'Session {$a->number}: {$a->date} <br> From: {$a->starttime} <br> To: {$a->endtime}';
$string['optiondatessuccessfullysaved'] = "Session time was saved.";
$string['optiondatessuccessfullydelete'] = "Session time was deleted.";
$string['leftandrightdate'] = '{$a->leftdate} to {$a->righttdate}';
$string['editingoptiondate'] = 'You are currently editing this session';
$string['newoptiondate'] = 'Create a new session...';

// Optiondatesadd_form.php.
$string['dateandtime'] = 'Date and time';
$string['sessionnotifications'] = 'E-mail notifications for each session';
$string['customfields'] = 'Custom fields';
$string['addcustomfieldorcomment'] = 'Add a comment or custom field';
$string['customfieldname'] = 'Field name';
$string['customfieldname_help'] = 'You can enter any field name you want. The special fieldnames
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> in combination with a link in the value field will render buttons and links
                                    which are only accessible during (and shortly before) the actual meetings.';
$string['customfieldvalue'] = 'Value';
$string['customfieldvalue_help'] = 'You can enter any value you want (text, number or HTML).<br>
                                    If you have used one of the special field names
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> then enter the complete link to the meeting session starting with https:// or http://';
$string['deletecustomfield'] = 'Delete custom field?';
$string['deletecustomfield_help'] = 'Caution: Setting this checkbox will delete the associated custom field when saving.';
$string['erroremptycustomfieldname'] = 'Custom field name is not allowed to be empty.';
$string['erroremptycustomfieldvalue'] = 'Custom field value is not allowed to be empty.';
$string['daystonotifysession'] = 'Notification n days before start';
$string['daystonotifysession_help'] = "Number of days in advance of the session start to notify participants.
Enter 0 to deactivate the e-mail notification for this session.";
$string['nocfnameselected'] = "Nothing selected. Either type new name or select one from the list.";
$string['bigbluebuttonmeeting'] = 'BigBlueButton meeting';
$string['zoommeeting'] = 'Zoom meeting';
$string['teamsmeeting'] = 'Teams meeting';
$string['addcomment'] = 'Add a comment...';

// File: locallib.php.
$string['signinsheetdate'] = 'Date(s): ';
$string['signinsheetaddress'] = 'Address: ';
$string['signinsheetlocation'] = 'Location: ';
$string['signinsheetdatetofillin'] = 'Date: ';
$string['booking:readallinstitutionusers'] = 'Show all users';
$string['manageoptiontemplates'] = 'Manage option templates';
$string['linkgotobookingoption'] = 'Go to booked option: {$a}</a>';

// File: settings.php.
$string['bookingsettings'] = 'Booking: Main settings';
$string['bookingdebugmode'] = 'Booking debug mode';
$string['bookingdebugmode_desc'] = 'Booking debug mode should only be activated by developers.';
$string['globalcurrency'] = 'Currency';
$string['globalcurrencydesc'] = 'Choose the currency for booking option prices';
$string['globalmailtemplates'] = 'Legacy mail templates ' . $badgedepr;
$string['globalmailtemplates_desc'] = 'After activation, you can go to the settings of a booking instance and set the source of mail templates to global.';
$string['globalbookedtext'] = 'Booking confirmation (global template) ' . $badgedepr;
$string['globalwaitingtext'] = 'Waiting list confirmation (global template) ' . $badgedepr;
$string['globalnotifyemail'] = 'Participant notification before start (global template) ' . $badgedepr;
$string['globalnotifyemailteachers'] = 'Teacher notification before start (global template) ' . $badgedepr;
$string['globalstatuschangetext'] = 'Status change message (global template) ' . $badgedepr;
$string['globaluserleave'] = 'User has cancelled his/her own booking (global template) ' . $badgedepr;
$string['globaldeletedtext'] = 'Cancelled booking message (global template) ' . $badgedepr;
$string['globalbookingchangedtext'] = 'Message to be sent when a booking option changes (will only be sent to users who have already booked). Use the placeholder {changes} to show the changes. Enter 0 to turn off change notifications. (Global template) ' . $badgedepr;
$string['globalpollurltext'] = 'Message for sending poll url to booked users (global template) ' . $badgedepr;
$string['globalpollurlteacherstext'] = 'Message for the poll url sent to teachers (global template) ' . $badgedepr;
$string['globalactivitycompletiontext'] = 'Message to be sent to user when booking option is completed (global template) ' . $badgedepr;
$string['licensekeycfg'] = 'Activate PRO version';
$string['licensekeycfgdesc'] = 'With a PRO license you can create as many booking templates as you like and use PRO features such as global mail templates, waiting list info texts or teacher notifications.';
$string['licensekey'] = 'PRO license key';
$string['licensekeydesc'] = 'Upload a valid license key to activate the PRO version.';
$string['license_activated'] = 'PRO version activated successfully.<br>(Expires: ';
$string['license_invalid'] = 'Invalid license key';
$string['icalcfg'] = 'Calendar settings and configuration of iCal attachements';
$string['icalcfgdesc'] = 'Configure calendar settings and the iCal (*.ics) files that are attached to e-mail messages. These files alow adding the booking dates to the personal calendar.';
$string['icalfieldlocation'] = 'Text to display in iCal field location';
$string['icalfieldlocationdesc'] = 'Choose from the dropdown list what what text should be used for the calendar field location';
$string['customfield'] = 'Custom field to be set in the booking option settings. It will then be shown in the booking option overview.';
$string['customfielddesc'] = 'After adding a custom field, you can define the value for the field in the booking option settings. The value will be shown in the booking option description.';
$string['customfieldconfigure'] = 'Booking: Custom booking option fields';
$string['customfielddef'] = 'Custom booking option field';
$string['customfieldtype'] = 'Field type';
$string['textfield'] = 'Single line text input';
$string['selectfield'] = 'Drop-down list';
$string['multiselect'] = 'Multiple selection';
$string['customfieldoptions'] = 'List of possible values';
$string['delcustfield'] = 'Delete this field and all associated field settings in the booking options';
$string['signinlogo'] = 'Logo to display on the sign-in sheet';
$string['cfgsignin'] = 'Sign-In Sheet Configuration';
$string['cfgsignin_desc'] = 'Configure the sign-in sheet settings';
$string['pdfportrait'] = 'Portrait';
$string['pdflandscape'] = 'Landscape';
$string['signincustfields'] = 'Custom profile fields';
$string['signincustfields_desc'] = 'Select the custom profiles fields to be shown on the sign-in sheet';
$string['showcustomfields'] = 'Custom booking option fields';
$string['showcustomfields_desc'] = 'Select the custom booking option fields to be shown on the sign-in sheet';
$string['alloptionsinreport'] = 'One report for a booking activity ' . $badgepro;
$string['alloptionsinreportdesc'] = 'The report of one booking option will include all the bookings of all booking options within this instance.';

$string['showlistoncoursepage'] = 'Show extra information on course page';
$string['showlistoncoursepage_help'] = 'If you activate this setting, the course name, a short info and a button
                                            redirecting to the available booking options will be shown.';
$string['hidelistoncoursepage'] = 'Hide extra information on course page (default)';
$string['showcoursenameandbutton'] = 'Show course name, short info and a button redirecting to the available booking options';

$string['coursepageshortinfolbl'] = 'Short info';
$string['coursepageshortinfolbl_help'] = 'Choose a short info text to show on the course page.';
$string['coursepageshortinfo'] = 'If you want to book yourself for this course; Click on "View available options", choose a booking option and then click on "Book now".';

$string['btnviewavailable'] = "View available options";

$string['signinextracols_heading'] = 'Additional columns on the sign-in sheet';
$string['signinextracols'] = 'Additional column';
$string['signinextracols_desc'] = 'You can print up to 3 additional columns on the sign-in sheet. Fill in the column title or leave it blank for no additional column';
$string['numberrows'] = 'Number rows';
$string['numberrowsdesc'] = 'Number each row of the sign-in sheet. Number will be displayed left of the name in the same column';

$string['availabilityinfotexts_heading'] = 'Availability info texts for booking places and waiting list ' . $badgepro;
$string['bookingplacesinfotexts'] = 'Show availability info texts for booking places';
$string['bookingplacesinfotexts_info'] = 'Show short info messages instead of the number of available booking places.';
$string['waitinglistinfotexts'] = 'Show availability info texts for waiting list';
$string['waitinglistinfotexts_info'] = 'Show short info messages instead of the number of available waiting list places.';
$string['bookingplaceslowpercentage'] = 'Percentage for booking places low message';
$string['bookingplaceslowpercentagedesc'] = 'If the available booking places reach or get below this percentage a booking places low message will be shown.';
$string['waitinglistlowpercentage'] = 'Percentage for waiting list low message';
$string['waitinglistlowpercentagedesc'] = 'If the available places on the waiting list reach or get below this percentage a waiting list low message will be shown.';

$string['waitinglistshowplaceonwaitinglist'] = 'Show place on waitinglist.';
$string['waitinglistshowplaceonwaitinglist_info'] = 'Waitinglist: Shows the exact place of the user on the waitinglist.';

$string['yourplaceonwaitinglist'] = 'You are on place {$a} on the waitinglist';

$string['waitinglistlowmessage'] = 'Only a few waiting list places left!';
$string['waitinglistenoughmessage'] = 'Still enough waiting list places.';
$string['waitinglistfullmessage'] = 'Waiting list full.';
$string['bookingplaceslowmessage'] = 'Only a few places left!';
$string['bookingplacesenoughmessage'] = 'Still enough places available.';
$string['bookingplacesfullmessage'] = 'Fully booked.';
$string['eventalreadyover'] = 'This event is already over.';
$string['nobookingpossible'] = 'No booking possible.';
$string['availplaces_full'] = 'Full';

$string['pricecategories'] = 'Booking: Price categories';

$string['bookingpricesettings'] = 'Price settings';
$string['bookingpricesettings_desc'] = 'Here you can customize booking prices.';

$string['bookwithcreditsactive'] = "Book with credits";
$string['bookwithcreditsactive_desc'] = "Users with credits can book directly without paying a price.";

$string['bookwithcreditsprofilefieldoff'] = 'Do not show';
$string['bookwithcreditsprofilefield'] = "User profile field for credits";
$string['bookwithcreditsprofilefield_desc'] = "To use this functionality, please define a user profile field where credits are stored.
<span class='text-danger'><b>Be careful:</b> You should create this field in a way that your users can't set a credit themselves.</span>";

$string['cfcostcenter'] = "Custom booking option field for cost center";
$string['cfcostcenter_desc'] = "If you use cost centers, you have to specify which custom
booking option field is used to store the cost center.";

$string['priceisalwayson'] = 'Prices always active';
$string['priceisalwayson_desc'] = 'If you activate this checkbox, you cannot deactive prices for individual booking options.
 However, you can still set a price of 0 EUR.';

$string['bookingpricecategory'] = 'Price category';
$string['bookingpricecategory_info'] = 'Define the name of the category, eg "students"';

$string['addpricecategory'] = 'Add price category';
$string['addpricecategory_info'] = 'You can add another price category';

$string['userprofilefieldoff'] = 'Do not show';
$string['pricecategoryfield'] = 'User profile field for price category';
$string['pricecategoryfielddesc'] = 'Choose the user profile field, which stores the price category identifier for each user.';

$string['useprice'] = 'Only book with price';

$string['teachingreportfortrainer'] = 'Report of performed teaching units for trainer';
$string['educationalunitinminutes'] = 'Length of an educational unit (minutes)';
$string['educationalunitinminutes_desc'] = 'Enter the length of an educational unit in minutes. It will be used to calculate the performed teaching units.';

$string['duplicationrestore'] = 'Booking instances: Duplication, backup and restore';
$string['duplicationrestoredesc'] = 'Here you can set which information you want to include when duplicating or backing up / restoring booking instances.';
$string['duplicationrestoreteachers'] = 'Include teachers';
$string['duplicationrestoreprices'] = 'Include prices';
$string['duplicationrestoreentities'] = 'Include entities';
$string['duplicationrestoresubbookings'] = 'Include subbookings ' . $badgepro;

$string['duplicationrestoreoption'] = 'Booking options: Duplication settings ' . $badgepro;
$string['duplicationrestoreoption_desc'] = 'Special settings for the duplication of booking options.';

$string['coursesheader'] = 'Moodle Courses';
$string['nomoodlecourseconnection'] = 'No connection to Moodle course';
$string['createnewmoodlecourse'] = 'Create new empty Moodle course';
$string['createnewmoodlecoursefromtemplate'] = 'Create new Moodle course from template';
$string['createnewmoodlecoursefromtemplate_help'] = 'Templates need to be tagged with the tag defined in settings and the current user needs to have the following capabilities on the source course:
<br>
Easiest way to achieve is to be inscribed in the template course as teacher.
<br>
moodle/course:view
moodle/backup:backupcourse
moodle/restore:restorecourse
moodle/question:add
';

$string['waitinglistheader'] = 'Waiting list';
$string['waitinglistheader_desc'] = 'Here you can set how the booking waiting list should behave.';
$string['turnoffwaitinglist'] = 'Turn off waiting list globally';
$string['turnoffwaitinglist_desc'] = 'Activate this setting, if you do not want to use the waiting list
 feature on this site (e.g. because you only want to use the notification list).';
$string['turnoffwaitinglistaftercoursestart'] = 'Turn off automatic moving up from waiting list after a booking option has started.';
$string['keepusersbookedonreducingmaxanswers'] = 'Keep users booked on limit reduction';
$string['keepusersbookedonreducingmaxanswers_desc'] = 'Keep users booked even when the limit of bookable places (maxanswers) is reduced.
Example: A booking option has 5 spots. The limit is reduced to 3. The 5 users who have already booked will still remain booked.';

$string['notificationlist'] = 'Notification list';
$string['notificationlistdesc'] = 'When no place is available anymore, users can still register to be notified when there is an opening';
$string['usenotificationlist'] = 'Use notification list';

$string['subbookings'] = 'Subbookings ' . $badgepro;
$string['subbookings_desc'] = 'Activate subbookings in order to enable the booking of additional items or time slots (e.g. for tennis courts).';
$string['showsubbookings'] = 'Activate subbookings';

$string['progressbars'] = 'Progress bars of time passed ' . $badgepro;
$string['progressbars_desc'] = 'Get a visual representation of the time which has already passed for a booking option.';
$string['showprogressbars'] = 'Show progress bars of time passed';
$string['progressbarscollapsible'] = 'Make progress bars collapsible';

$string['bookingoptiondefaults'] = 'Default settings for booking options';
$string['bookingoptiondefaultsdesc'] = 'Here you can set default settings for the creation of booking options and lock them if needed.';
$string['addtocalendardesc'] = 'Course calendar events are visible to ALL users within a course. If you do not want them to be created at all,
you can turn this setting off and lock it by default. Don\'t worry: user calendar events for booked options will still be created anyways.';

$string['automaticcoursecreation'] = 'Automatic creation of Moodle courses ' . $badgepro;
$string['newcoursecategorycfield'] = 'Booking option custom field to be used as course category';
$string['newcoursecategorycfielddesc'] = 'Choose a booking option custom field which will be used as course category for automatically created
 courses using the dropdown entry "Create new course..." in the form for creating new booking options.';

$string['allowoverbooking'] = 'Allow overbooking';
$string['allowoverbookingheader'] = 'Overbooking of booking options ' . $badgepro;
$string['allowoverbookingheader_desc'] = 'Allow administrators and entitled users to overbook booking options.
  (Be careful: This can lead to unexpected behavior. Only activate this if you really need it.)';
$string['definedteacherrole'] = 'Teachers of booking option are assigned to this role';
$string['definedteacherrole_desc'] = 'When a teacher is added to a bookingoption, he/she will be assigned to this role in the corresponding course.';

$string['appearancesettings'] = 'Appearance ' . $badgepro;
$string['appearancesettings_desc'] = 'Configure the appearance of the booking plugin.';
$string['turnoffwunderbytelogo'] = 'Do not show Wunderbyte logo und link';
$string['turnoffwunderbytelogo_desc'] = 'If you activate this setting, the Wunderbyte logo and the link to the Wunderbyte website won\'t be shown.';

$string['turnoffmodals'] = "Turn off modals";
$string['turnoffmodals_desc'] = "Some steps during the booking process will open modals. This settings will show the information inline, no modals will open.
<b>Please note:</b> If you use the Booking <b>cards view</b>, then modals will still be used. You can <b>only turn them off for list view</b>.";

$string['collapseshowsettings'] = "Collapse 'show dates' with more than x dates.";
$string['collapseshowsettings_desc'] = "To avoid a messy view with too many dates, a lower limit for collapsed dates can be defined here.";

$string['teachersettings'] = 'Teachers ' . $badgepro;
$string['teachersettings_desc'] = 'Teacher-specific settings.';

$string['teacherslinkonteacher'] = 'Add links to teacher pages';
$string['teacherslinkonteacher_desc'] = 'When there are teachers added to booking options, this setting will add a link to an overview page for each teacher.';

$string['teachersnologinrequired'] = 'Login for teacher pages not necessary';
$string['teachersnologinrequired_desc'] = 'If you activate this setting, everyone can access the teacher pages, regardless if logged in or not.';
$string['teachersshowemails'] = 'Always show teacher\'s email addresses to everyone';
$string['teachersshowemails_desc'] = 'If you activate this setting, every user can see
    the e-mail address of any teacher - even if they are not logged in. <span class="text-danger"><b>Be careful:</b> This might be
    a privacy issue. Only activate this, if you are sure it corresponds with your organization\'s privacy policy.</span>';
$string['bookedteachersshowemails'] = 'Show teacher\'s email addresses to booked users';
$string['bookedteachersshowemails_desc'] = 'If you activate this setting, booked users can see
the e-mail address of their teacher.';
$string['teachersallowmailtobookedusers'] = 'Allow teachers to send an e-mail to all booked users using their own mail client';
$string['teachersallowmailtobookedusers_desc'] = 'If you activate this setting, teachers can click a button to send an e-mail
    to all booked users using their own mail client - the e-mail-addresses of all users will be visible.
    <span class="text-danger"><b>Be careful:</b> This might be a privacy issue. Only activate this,
    if you are sure it corresponds with your organization\'s privacy policy.</span>';
$string['teachersalwaysenablemessaging'] = 'Allow users to send message all teachers';
$string['teachersalwaysenablemessaging_desc'] = 'If you activate this setting, users can send messages to teachers even if they aren\'t enroled in any of their courses.';

$string['cancellationsettings'] = 'Cancellation settings ' . $badgepro;
$string['canceldependenton'] = 'Cancellation period dependent on';
$string['canceldependenton_desc'] = 'Choose the date that should be used as "start" for the setting
"Disallow users to cancel their booking n days before start. Minus means, that users can still cancel n
days AFTER course start.".<br>
This will also set the <i>service period</i> of courses in shopping cart accordingly (if shopping cart is installed).';
$string['cdo:coursestarttime'] = 'Start of the booking option (coursestarttime)';
$string['cdo:semesterstart'] = 'Semester start';
$string['cdo:bookingopeningtime'] = 'Booking registration start (bookingopeningtime)';
$string['cdo:bookingclosingtime'] = 'Booking registration end (bookingclosingtime)';

$string['duplicatemoodlecourses'] = 'Duplicate Moodle course';
$string['duplicatemoodlecourses_desc'] = 'When this setting is active and you duplicate a booking option,
then the connected Moodle course will also be duplicated. This will be done with an adhoc task,
so be sure that CRON runs regularly.';

$string['choosetags'] = 'Choose tags';
$string['choosetags_desc'] = 'Courses marked with these tags can be used as templates. If a booking option is linked to such a template, a copy of the template course will be automatically created upon first saving.';

$string['templatecategoryname'] = 'Short name of the course category where the template courses are located.';
$string['templatecategoryname_desc'] = 'Booking options can be linked to Moodle courses. This feature allows the courses to be created upon the first saving of the booking option.';

$string['usecoursecategorytemplates'] = 'Use templates for newly created Moodle courses';
$string['usecoursecategorytemplates_desc'] = '';

$string['coolingoffperiod'] = 'Cancellation possible after x seconds';
$string['coolingoffperiod_desc'] = 'To prevent users from canceling due to, for example accidentally double clicking the booking button, a cooling off period can be set in seconds. During this time cancellation is not possible. Do not set more than a few seconds as the waiting time is not explicitly shown to users.';

// Privacy API.
$string['privacy:metadata:booking_answers'] = 'Represents a booking of an event';
$string['privacy:metadata:booking_answers:userid'] = 'User that is booked for this event';
$string['privacy:metadata:booking_answers:bookingid'] = 'ID of the booking instance';
$string['privacy:metadata:booking_answers:optionid'] = 'ID of the booking option';
$string['privacy:metadata:booking_answers:timemodified'] = 'Timestamp when booking was last modified';
$string['privacy:metadata:booking_answers:timecreated'] = 'Timestamp when booking was created';
$string['privacy:metadata:booking_answers:waitinglist'] = 'True if user is on the waitinglist';
$string['privacy:metadata:booking_answers:status'] = 'Status info for this booking';
$string['privacy:metadata:booking_answers:notes'] = 'Additional notes';
$string['privacy:metadata:booking_ratings'] = 'Your rating of an event';
$string['privacy:metadata:booking_ratings:userid'] = 'User that rated this event';
$string['privacy:metadata:booking_ratings:optionid'] = 'ID of the rated booking option';
$string['privacy:metadata:booking_ratings:rate'] = 'Rate that was assigned';
$string['privacy:metadata:booking_teachers'] = 'Teacher(s) of an event';
$string['privacy:metadata:booking_teachers:userid'] = 'User that is teaching this event';
$string['privacy:metadata:booking_teachers:optionid'] = 'ID of the booking option which is taught';
$string['privacy:metadata:booking_teachers:completed'] = 'If task is completed for the teacher';
$string['privacy:metadata:booking_answers:completed'] = 'User that booked has completed the task';
$string['privacy:metadata:booking_answers:frombookingid'] = 'ID of connected booking';
$string['privacy:metadata:booking_answers:numrec'] = 'Record number';
$string['privacy:metadata:booking_icalsequence'] = 'Ical sequence';
$string['privacy:metadata:booking_icalsequence:userid'] = 'User ID for ical';
$string['privacy:metadata:booking_icalsequence:optionid'] = 'Booking option ID for ical';
$string['privacy:metadata:booking_icalsequence:sequencevalue'] = 'Ical sequence value';
$string['privacy:metadata:booking_teachers:bookingid'] = 'ID of booking instance for teacher';
$string['privacy:metadata:booking_teachers:calendarid'] = 'ID of calendar event for teacher';
$string['privacy:metadata:booking_userevents'] = 'User events in calendar';
$string['privacy:metadata:booking_userevents:userid'] = 'User ID for user event';
$string['privacy:metadata:booking_userevents:optionid'] = 'ID of booking option for user event';
$string['privacy:metadata:booking_userevents:optiondateid'] = 'ID of optiondate (session) for user event';
$string['privacy:metadata:booking_userevents:eventid'] = 'ID of event in events table';

$string['privacy:metadata:booking_optiondates_teachers'] = 'Track teachers for each session.';
$string['privacy:metadata:booking_optiondates_teachers:optiondateid'] = 'ID of the option date';
$string['privacy:metadata:booking_optiondates_teachers:userid'] = 'The userid of the teacher.';

$string['privacy:metadata:booking_subbooking_answers'] = 'Stores the anwers (the bookings) of a user for a particular subbooking.';
$string['privacy:metadata:booking_subbooking_answers:itemid'] = 'itemid can be the same as sboptionid, but there are some types (eg. timeslots which provide slots) where one sboptionid provides a lot of itemids.';
$string['privacy:metadata:booking_subbooking_answers:optionid'] = 'The option ID';
$string['privacy:metadata:booking_subbooking_answers:sboptionid'] = 'id of the booked subbooking';
$string['privacy:metadata:booking_subbooking_answers:userid'] = 'Userid of the booked user.';
$string['privacy:metadata:booking_subbooking_answers:usermodified'] = 'The user that modified';
$string['privacy:metadata:booking_subbooking_answers:json'] = 'supplementary data if necessary';
$string['privacy:metadata:booking_subbooking_answers:timestart'] = 'Timestamp for start time of this booking';
$string['privacy:metadata:booking_subbooking_answers:timeend'] = 'Timestamp for end time of this booking';
$string['privacy:metadata:booking_subbooking_answers:status'] = 'The bookings status, as in booked, waiting list, in the shopping cart, on a notify list or deleted';
$string['privacy:metadata:booking_subbooking_answers:timecreated'] = 'The time created';
$string['privacy:metadata:booking_subbooking_answers:timemodified'] = 'The time last modified';

$string['privacy:metadata:booking_odt_deductions'] = 'This table is used to log if we want to deduct a part of a teachers salary if (s)he has missing hours.';
$string['privacy:metadata:booking_odt_deductions:optiondateid'] = 'The option date ID';
$string['privacy:metadata:booking_odt_deductions:userid'] = 'Userid of the teacher who gets a deduction for this option date.';
$string['privacy:metadata:booking_odt_deductions:reason'] = 'Reason for the deduction.';
$string['privacy:metadata:booking_odt_deductions:usermodified'] = 'The user that modified';
$string['privacy:metadata:booking_odt_deductions:timecreated'] = 'The time created';
$string['privacy:metadata:booking_odt_deductions:timemodified'] = 'The time last modified';

// Calendar.php.
$string['usercalendarentry'] = 'You are booked for <a href="{$a}">this session</a>.';
$string['bookingoptioncalendarentry'] = '<a href="{$a}" class="btn btn-primary">Book now...</a>';

// Mybookings.php.
$string['status'] = 'Status';
$string['active'] = "Active";
$string['terminated'] = "Terminated";
$string['notstarted'] = "Not yet started";

// Subscribeusersactivity.php.
$string['transefusers'] = "Transfer users";
$string['transferhelp'] = 'Transfer users, that have not completed activity from selected option to {$a}.';
$string['sucesfullytransfered'] = 'Users were sucesfully transfered.';

$string['confirmactivtyfrom'] = 'Confirm users activity from';
$string['sucesfullcompleted'] = 'Activity was sucesfully completed for users.';
$string['enablecompletion'] = 'Count of entries';
$string['confirmuserswith'] = 'Confirm users who completed activity or received badge';
$string['confirmusers'] = 'Confirm users activity';

$string['nodirectbookingbecauseofprice'] = 'Booking for others is only partially possible with this booking option. The reasons for this are as follows:
    <ul>
    <li>a price is entered</li>
    <li>the Shopping Cart module is installed</li>
    <li>the waiting list is not globally deactivated</li>
    </ul>
    The intention of this behaviour is to prevent "mixed" bookings with and without shopping cart. Please use shopping cart cashier function to book users.';

// Optiontemplatessettings.php.
$string['optiontemplatessettings'] = 'Booking option templates';
$string['defaulttemplate'] = 'Default template';
$string['defaulttemplatedesc'] = 'Default booking option template when creating a new booking option.';
$string['dontuse'] = 'Don\'t use template';

// Instancetemplateadd.php.
$string['saveinstanceastemplate'] = 'Add booking instance to template';
$string['thisinstance'] = 'This booking instance';
$string['instancetemplate'] = 'Instance template';
$string['instancesuccessfullysaved'] = 'This booking instance was sucesfully saved as template.';
$string['instance_not_saved_no_valid_license'] = 'Booking instance could not be saved as template.
                                                  Upgrade to PRO version to save an unlimited number of templates.';
$string['bookinginstancetemplatessettings'] = 'Booking: Instance templates';
$string['bookinginstancetemplatename'] = 'Booking instance template name';
$string['managebookinginstancetemplates'] = 'Manage booking instance templates';
$string['populatefromtemplate'] = 'Populate from template';

// Mybookings.
$string['mybookingsbooking'] = 'Booking (Course)';
$string['mybookingsoption'] = 'Option';

// Custom report templates.
$string['managecustomreporttemplates'] = 'Manage custom report templates';
$string['customreporttemplates'] = 'Custom report templates';
$string['customreporttemplate'] = 'Custom report template';
$string['addnewreporttemplate'] = 'Add new report template';
$string['templatefile'] = 'Template file';
$string['templatesuccessfullysaved'] = 'Template was saved.';
$string['customdownloadreport'] = 'Download report';
$string['bookingoptionsfromtemplatemenu'] = 'New booking option from template';

// Automatic option creation.
$string['autcrheader'] = '[DEPRECATED] Automatic booking option creation';
$string['autcrwhatitis'] = 'If this option is enabled it automatically creates a new booking option and assigns
 a user as booking manager / teacher to it. Users are selected based on a custom user profile field value.';
$string['enable'] = 'Enable';
$string['customprofilefield'] = 'Custom profile field to check';
$string['customprofilefieldvalue'] = 'Custom profile field value to check';
$string['optiontemplate'] = 'Option template';

// Link.php.
$string['bookingnotopenyet'] = 'Your event starts in {$a} minutes. The link you used will redirect you if you click it again within 15 minutes before.';
$string['bookingpassed'] = 'Your event has ended.';
$string['linknotvalid'] = 'This link or meeting is not accessible.
If it is a meeting you have booked, please check again, shortly before start.';

// Booking_utils.php.
$string['linknotavailableyet'] = "The link to access the meeting is available only 15 minutes before the start
until the end of the session.";
$string['changeinfochanged'] = ' has changed:';
$string['changeinfoadded'] = ' has been added:';
$string['changeinfodeleted'] = ' has been deleted:';
$string['changeinfocfchanged'] = 'A field has changed:';
$string['changeinfocfadded'] = 'A field has been added:';
$string['changeinfocfdeleted'] = 'A field has been deleted:';
$string['changeinfosessionadded'] = 'A session has been added:';
$string['changeinfosessiondeleted'] = 'A session has been deleted:';

// Bookingoption_changes.mustache.
$string['changeold'] = '[DELETED] ';
$string['changenew'] = '[NEW] ';

// Bookingoption_description.php.
$string['gotobookingoption'] = 'Go to booking option';
$string['dayofweektime'] = 'Day & Time';
$string['showdates'] = 'Show dates';

// Bookingoptions_simple_table.php.
$string['bsttext'] = 'Booking option';
$string['bstcoursestarttime'] = 'Date / Time';
$string['bstlocation'] = 'Location';
$string['bstinstitution'] = 'Institution';
$string['bstparticipants'] = 'Participants';
$string['bstteacher'] = 'Teacher(s)';
$string['bstwaitinglist'] = 'On waiting list';
$string['bstmanageresponses'] = 'Manage bookings';
$string['bstcourse'] = 'Course';
$string['bstlink'] = 'Show';

// All_options.php.
$string['infoalreadybooked'] = '<div class="infoalreadybooked"><i>You are already booked for this option.</i></div>';
$string['infowaitinglist'] = '<div class="infowaitinglist"><i>You are on the waiting list for this option.</i></div>';

$string['tableheader_text'] = 'Course name';
$string['tableheader_teacher'] = 'Teacher(s)';
$string['tableheader_maxanswers'] = 'Available places';
$string['tableheader_maxoverbooking'] = 'Waiting list places';
$string['tableheader_minanswers'] = 'Min. number of participants';
$string['tableheader_coursestarttime'] = 'Course start';
$string['tableheader_courseendtime'] = 'Course end';
$string['course_start'] = 'Start';

// Customfields.
$string['booking_customfield'] = 'Booking customfields for booking options';

// Optiondates_only.mustache.
$string['sessions'] = 'Session(s)';

// Message_sent.php.
$string['message_sent'] = 'Message sent';

// Price.php.
$string['nopricecategoriesyet'] = 'No price categories have been created yet.';
$string['priceformulaisactive'] = 'On saving, calculate prices with price formula (this will overwrite current prices).';
$string['priceformulainfo'] = '<a data-toggle="collapse" href="#priceformula" role="button" aria-expanded="false" aria-controls="priceformula">
<i class="fa fa-code"></i> Show JSON for price formula...
</a>
<div class="collapse" id="priceformula">
<samp>{$a->formula}</samp>
</div><br>
<a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank"><i class="fa fa-edit"></i> Edit formula...</a><br><br>
Below, you can additionally add a manual factor (multiplication) and an absolute value (addition) to be added to the formula.';
$string['priceformulamultiply'] = 'Manual factor';
$string['priceformulamultiply_help'] = 'Additional value to <strong>multiply</strong> the result with.';
$string['priceformulaadd'] = 'Absolute value';
$string['priceformulaadd_help'] = 'Additional value to <strong>add</strong> to the result.';
$string['priceformulaoff'] = 'Prevent recalculation of prices';
$string['priceformulaoff_help'] = 'Activate this option in order to prevent the function "Calculate all prices from
 instance with formula" from recalculating the prices for this booking option.';

// Pricecategories_form.php.
$string['price'] = 'Price';
$string['additionalpricecategories'] = 'Add or edit price categories';
$string['defaultpricecategoryname'] = 'Default price category name';
$string['nopricecategoryselected'] = 'Enter the name of a new price category';
$string['pricecategoryidentifier'] = 'Price category identifier';
$string['pricecategoryidentifier_help'] = 'Enter a short text to identify the category, e.g. "stud" or "acad".';
$string['pricecategoryname'] = 'Price category name';
$string['pricecategoryname_help'] = 'Enter the full name of the price category to be shown in booking options, e.g. "Student price".';
$string['defaultvalue'] = 'Default price value';
$string['defaultvalue_help'] = 'Enter a default value for every price in this category. Of course, this value can be overwritten later.';
$string['pricecatsortorder'] = 'Sort order (number)';
$string['pricecatsortorder_help'] = 'Enter a full number. "1" means that the price category will be shown at first place, "2" at second place etc.';
$string['disablepricecategory'] = 'Disable price category';
$string['disablepricecategory_help'] = 'When you disable a price category, you will not be able to use it anymore.';
$string['addpricecategory'] = 'Add price category';
$string['erroremptypricecategoryname'] = 'Price category name is not allowed to be empty.';
$string['erroremptypricecategoryidentifier'] = 'Price category identifier is not allowed to be empty.';
$string['errorduplicatepricecategoryidentifier'] = 'Price category identifiers need to be unique.';
$string['errorduplicatepricecategoryname'] = 'Price category names need to be unique.';
$string['errortoomanydecimals'] = 'Only 2 decimals are allowed.';
$string['pricecategoriessaved'] = 'Price categories were saved';
$string['pricecategoriessubtitle'] = '<p>Here you can define different price categories, e.g.
    special price categories for students, employees or externals.
    <b>Be careful:</b> Once you have added a category, you cannot delete it.
    Only disable or rename it.</p>';

// Price formula.
$string['defaultpriceformula'] = "Price formula";
$string['priceformulaheader'] = 'Price formula ' . $badgepro;
$string['priceformulaheader_desc'] = "Use a price formula to automatically calculate prices for booking options.";
$string['defaultpriceformuladesc'] = "The JSON object permits the configuration of the automatic price calculation with a booking option.";

// Semesters.
$string['booking:semesters'] = 'Booking: Semesters';
$string['semester'] = 'Semester';
$string['semesters'] = 'Semesters';
$string['semesterssaved'] = 'Semesters have been saved';
$string['semesterssubtitle'] = 'Here you can add, change or delete <strong>semesters and holidays</strong>.
    After saving, the entries will be ordered by their <strong>start date in descending order</strong>.';
$string['addsemester'] = 'Add semester';
$string['semesteridentifier'] = 'Identifier';
$string['semesteridentifier_help'] = 'Short text to identify the semester, e.g. "ws22".';
$string['semestername'] = 'Name';
$string['semestername_help'] = 'Enter the full name of the semester, e.g. "Semester of Winter 2021/22"';
$string['semesterstart'] = 'First day of semester';
$string['semesterstart_help'] = 'The day the semester starts.';
$string['semesterend'] = 'Last day of semester';
$string['semesterend_help'] = 'The day the semester ends';
$string['deletesemester'] = 'Delete semester';
$string['erroremptysemesteridentifier'] = 'Semester identifier is needed!';
$string['erroremptysemestername'] = 'Semester name is not allowed to be empty';
$string['errorduplicatesemesteridentifier'] = 'Semester identifiers need to be unique.';
$string['errorduplicatesemestername'] = 'Semester names need to be unique.';
$string['errorsemesterstart'] = 'Semester start needs to be before semester end.';
$string['errorsemesterend'] = 'Semester end needs to be after semester start.';
$string['choosesemester'] = "Choose semester";
$string['choosesemester_help'] = "Choose the semester for which the holiday(s) should be created.";
$string['holidays'] = "Holidays";
$string['holiday'] = "Holiday";
$string['holidayname'] = "Holiday name (optional)";
$string['holidaystart'] = 'Holiday (start)';
$string['holidayend'] = 'End';
$string['holidayendactive'] = 'End is not on the same day';
$string['addholiday'] = 'Add holiday(s)';
$string['errorholidaystart'] = 'Holiday is not allowed to start after the end date.';
$string['errorholidayend'] = 'Holiday is not allowed to end before the start date.';
$string['deleteholiday'] = 'Delete holiday';

// Cache.
$string['cachedef_bookedusertable'] = 'Booked users table (cache)';
$string['cachedef_bookingoptions'] = 'Booking options (cache)';
$string['cachedef_bookingoptionsanswers'] = 'Booking options answers (cache)';
$string['cachedef_bookingoptionstable'] = 'Tables of booking options with hashed sql queries (cache)';
$string['cachedef_cachedpricecategories'] = 'Booking price categories (cache)';
$string['cachedef_cachedprices'] = 'Prices in booking (cache)';
$string['cachedef_cachedbookinginstances'] = 'Booking instances (cache)';
$string['cachedef_bookingoptionsettings'] = 'Booking option settings (cache)';
$string['cachedef_cachedsemesters'] = 'Semesters (cache)';
$string['cachedef_cachedteachersjournal'] = 'Teaches journal (Cache)';
$string['cachedef_subbookingforms'] = 'Subbooking Forms (Cache)';
$string['cachedef_conditionforms'] = 'Condition Forms (Cache)';
$string['cachedef_confirmbooking'] = 'Booking confirmed (Cache)';
$string['cachedef_electivebookingorder'] = 'Elective booking order (Cache)';
$string['cachedef_customformuserdata'] = 'Custom form user data (Cache)';
$string['cachedef_eventlogtable'] = 'Event log table (Cache)';

// Dates_handler.php.
$string['chooseperiod'] = 'Select time period';
$string['chooseperiod_help'] = 'Select a time period within to create the date series.';
$string['dates'] = 'Dates';
$string['reoccurringdatestring'] = 'Weekday, start and end time (Day, HH:MM - HH:MM)';
$string['reoccurringdatestring_help'] = 'Enter a text in the following format:
    "Day, HH:MM - HH:MM", e.g. "Monday, 10:00 - 11:00" or "Sun 09:00-10:00" or "block" for blocked events.';

// Weekdays.
$string['monday'] = 'Monday';
$string['tuesday'] = 'Tuesday';
$string['wednesday'] = 'Wednesday';
$string['thursday'] = 'Thursday';
$string['friday'] = 'Friday';
$string['saturday'] = 'Saturday';
$string['sunday'] = 'Sunday';

// Dynamicoptiondateform.php.
$string['add_optiondate_series'] = 'Create date series';
$string['reoccurringdatestringerror'] = 'Enter a text in the following format:
    Day, HH:MM - HH:MM or "block" for blocked events.';
$string['customdatesbtn'] = '<i class="fa fa-plus-square"></i> Custom dates...';
$string['aboutmodaloptiondateform'] = 'Create custom dates
(e.g. for blocked events or for single dates that differ from the date series).';
$string['modaloptiondateformtitle'] = 'Custom dates';
$string['optiondate'] = 'Date';
$string['addoptiondate'] = 'Add date';
$string['deleteoptiondate'] = 'Remove date';
$string['optiondatestart'] = 'Start';
$string['optiondateend'] = 'End';
$string['erroroptiondatestart'] = 'Date start needs to be before date end.';
$string['erroroptiondateend'] = 'Date end needs to be after date start.';

// Optiondates_teachers_report.php & optiondates_teachers_table.php.
$string['accessdenied'] = 'Access denied';
$string['nopermissiontoaccesspage'] = '<div class="alert alert-danger" role="alert">You have no permission to access this page.</div>';
$string['optiondatesteachersreport'] = 'Substitutions / Cancelled dates';
$string['optiondatesteachersreport_desc'] = 'This report gives an overview of which teacher was present at which specific date.<br>
By default, every date will be filled in with the option\'s teacher. You can overwrite specific dates with replacement teachers.';
$string['linktoteachersinstancereport'] = '<p><a href="{$a}" target="_self">&gt;&gt; Go to teachers report for booking instance</a></p>';
$string['noteacherset'] = 'No teacher';
$string['reason'] = 'Reason';
$string['error:reasonfornoteacher'] = 'Enter a reason why no teachers were present at this date.';
$string['error:reasontoolong'] = 'Reason is too long, enter a shorter text.';
$string['error:reasonforsubstituteteacher'] = 'Enter a reason for the substitute teacher(s).';
$string['error:reasonfordeduction'] = 'Enter a reason for the deduction.';

$string['confirmbooking'] = 'Confirmation of this booking';
$string['confirmbookinglong'] = 'Do you really want to confirm this booking?';
$string['unconfirm'] = 'Delete confirmation';
$string['unconfirmbooking'] = 'Delete confirmation of this booking';
$string['unconfirmbookinglong'] = 'Do you really want to delete the confirmation of this booking?';

$string['deletebooking'] = 'Delete this booking';
$string['deletebookinglong'] = 'Do you really want to delete this booking?';

$string['successfullysorted'] = 'Successfully sorted';

// Teachers_instance_report.php.
$string['teachers_instance_report'] = 'Teachers report';
$string['error:invalidcmid'] = 'The report cannot be opened because no valid course module ID (cmid) was provided. It needs to be the cmid of a booking instance!';
$string['teachingreportforinstance'] = 'Teaching overview report for ';
$string['teachersinstancereport:subtitle'] = '<strong>Hint:</strong> The number of units of a course (booking option) is calculated by the duration of an educational unit
 which you can <a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank">set in the booking settings</a> and the specified date series string (e.g. "Tue, 16:00-17:30").
 For blocked events or booking options missing this string, the number of units cannot be calculated!';
$string['units'] = 'Units';
$string['sum_units'] = 'Sum of units';
$string['units_courses'] = 'Courses / Units';
$string['units_unknown'] = 'Number of units unknown';
$string['missinghours'] = 'Missing hours';
$string['substitutions'] = 'Substitution(s)';

// Teachers_instance_config.php.
$string['teachers_instance_config'] = 'Edit booking option form';
$string['teachingconfigforinstance'] = 'Edit booking option form for ';
$string['dashboard_summary'] = 'General';
$string['dashboard_summary_desc'] = 'Contains the settings and stats for the whole Moodle site';

// Optionformconfig.php / optionformconfig_form.php.
$string['optionformconfig'] = 'Configure booking option forms (PRO)';
$string['optionformconfig_infotext'] = 'With this PRO feature, you can create your individual booking option forms by using drag & drop
and the checkboxes. The forms are stored on a specific context level (e.g. booking instance, system-wide...). Users can only access the forms
if they have the appropriate capabilities.';
$string['optionformconfig_getpro'] = ' With Booking ' . $badgepro . ' you have the possibility to create individual forms with drag and drop
for specific user roles and contexts (e.g. for a specific booking instance or system wide).';
$string['optionformconfigsaved'] = 'Configuration for the booking option form saved.';
$string['optionformconfigsubtitle'] = '<p>Turn off features you do not need, in order to make the booking option form more compact for your administrators.</p>
<p><strong>BE CAREFUL:</strong> Only deactivate fields if you are completely sure that you won\'t need them!</p>';
$string['optionformconfig:nobooking'] = 'You need to create at least one booking instance before you can use this form!';

$string['optionformconfigsavedsystem'] = 'Your form definition was saved on context level system';
$string['optionformconfigsavedcoursecat'] = 'Your form definition was saved on context level course category';
$string['optionformconfigsavedmodule'] = 'Your form definition was saved on context level module';
$string['optionformconfigsavedcourse'] = 'Your form definition was saved on context level course';
$string['optionformconfigsavedother'] = 'Your form definition was saved on context level {$a}';

$string['optionformconfignotsaved'] = 'No special configuration was saved for your form';

$string['prepare_import'] = "Prepare Import";
$string['id'] = "Id";
$string['json'] = "Stores supplementary information";
$string['returnurl'] = "Url to return to";
$string['youareusingconfig'] = 'Your are using the following form configuration: {$a}';
$string['formconfig'] = 'Show which form is used.';
$string['template'] = 'Templates';
$string['moveoption'] = 'Move booking option';
$string['dontmove'] = 'Nicht verschieben';
$string['moveoption_help'] = 'Move booking option to different booking instance';
$string['text'] = 'Titel';
$string['maxanswers'] = 'Limit for answers';
$string['identifier'] = 'Identification';
$string['easy_text'] = 'Easy, not changeable text';
$string['easy_bookingopeningtime'] = 'Easy booking opening time';
$string['easy_bookingclosingtime'] = 'Easy booking closing time';
$string['easy_availability_selectusers'] = 'Easy selected users condition';
$string['easy_availability_previouslybooked'] = 'Easy already booked condition';
$string['invisible'] = 'Invisible';
$string['annotation'] = 'Internal annotation';
$string['courseid'] = 'Course to subscribe to';
$string['entities'] = 'Choose places with entities plugin';
$string['entitiesfieldname'] = 'Place(s)';
$string['shoppingcart'] = 'Set payment options with shopping cart plugin';
$string['optiondates'] = 'Dates';
$string['actions'] = 'Booking actions';
$string['attachment'] = 'Attachments';
$string['howmanyusers'] = 'Book other users limit';
$string['recurringoptions'] = 'Recurring booking options';
$string['bookusers'] = 'For Import, to book users directly';
$string['timemodified'] = 'Time modified';
$string['waitforconfirmation'] = 'Book only after confirmation';

// Tasks.
$string['task_adhoc_reset_optiondates_for_semester'] = 'Adhoc task: Reset and generate new optiondates for semester';
$string['task_remove_activity_completion'] = 'Booking: Remove activity completion';
$string['task_enrol_bookedusers_tocourse'] = 'Booking: Enrol booked users to course';
$string['task_send_completion_mails'] = 'Booking: Send completion mails';
$string['task_send_confirmation_mails'] = 'Booking: Send confirmation mails';
$string['task_send_notification_mails'] = 'Booking: Send notification mails';
$string['task_send_reminder_mails'] = 'Booking: Send reminder mails';
$string['task_send_mail_by_rule_adhoc'] = 'Booking: Send mail by rule (adhoc task)';
$string['task_clean_booking_db'] = 'Booking: Clean database';
$string['task_purge_campaign_caches'] = 'Booking: Clean caches for booking campaigns';
$string['optionbookabletitle'] = '{$a->title} is available again';
$string['optionbookablebody'] = '{$a->title} is now available again. <a href="{$a->url}">Click here</a> to directly go there.<br><br>
(You receive this mail because you have clicked on the notification button for this option.)<br><br>
<a href="{$a->unsubscribelink}">Unsubscribe from notification e-mails for "{$a->title}".</a>';

// Calculate prices.
$string['recalculateprices'] = 'Calculate all prices from instance with formula';
$string['recalculateall'] = 'Calculate all prices';
$string['alertrecalculate'] = '<b>Caution!</b> All prices will be recalculated and all old prices will be overwritten.';
$string['nopriceformulaset'] = 'No formula set on setting page. <a href="{$a->url}" target="_blank">Set it here.</a>';
$string['successfulcalculation'] = 'Price calculation successful!';
$string['applyunitfactor'] = 'Apply unit factor';
$string['applyunitfactor_desc'] = 'If this setting is active, the educational unit length (e.g. 45 min) set above will be used
 to calculate the number of educational units. This number will be used as factor for the price formula.
 Example: A booking option has a date series like "Mon, 15:00 - 16:30". So it lasts 2 educational units (45 min each).
 So a unit factor of 2 will be applied to the price formula. (Unit factor will only be applied if a price formula is present.)';
$string['roundpricesafterformula'] = 'Round prices (price formula)';
$string['roundpricesafterformula_desc'] = 'If active, prices will be rounded to full numbers (no decimals) after the <strong>price formula</strong> has been applied.';

// Col_availableplaces.mustache.
$string['manageresponses'] = 'Manage bookings';

// Bo conditions.
$string['availabilityconditions'] = 'Availability conditions';
$string['availabilityconditionsheader'] = '<i class="fa fa-fw fa-key" aria-hidden="true"></i>&nbsp;Availability conditions';
$string['apply'] = 'Apply';
$string['delete'] = 'Delete';

$string['bo_cond_alreadybooked'] = 'alreadybooked: Is already booked by this user';
$string['bo_cond_alreadyreserved'] = 'alreadyreserved: Has already been added to cart by this user';
$string['bo_cond_selectusers'] = 'Only selected users can book';
$string['bo_cond_booking_time'] = 'Only bookable within a certain time';
$string['bo_cond_fullybooked'] = 'Fully booked';
$string['bo_cond_bookingpolicy'] = 'Booking policy';
$string['bo_cond_notifymelist'] = 'Notify list';
$string['bo_cond_max_number_of_bookings'] = 'max_number_of_bookings: Maximum number of bookings per user reached';
$string['bo_cond_onwaitinglist'] = 'onwaitinglist: User is on waiting list';
$string['bo_cond_askforconfirmation'] = 'askforconfirmation: Manually confirm booking';
$string['bo_cond_previouslybooked'] = 'User has previously booked a certain option';
$string['bo_cond_enrolledincourse'] = 'User is enrolled in certain course(s)';
$string['bo_cond_enrolledincohorts'] = 'User is enrolled in certain cohort(s)';
$string['bo_cond_priceisset'] = 'priceisset: Price is set';
$string['bo_cond_userprofilefield_1_default'] = 'User profile field has a certain value';
$string['bo_cond_userprofilefield_2_custom'] = 'Custom user profile field has a certain value';
$string['bo_cond_isbookable'] = 'isbookable: Booking is allowed';
$string['bo_cond_isloggedin'] = 'isloggedin: User is logged in';
$string['bo_cond_fullybookedoverride'] = 'fullybookedoverride: Can be overbooked by staff';
$string['bo_cond_iscancelled'] = 'iscancelled: Booking option cancelled';
$string['bo_cond_subbooking_blocks'] = 'Subbooking blocks this booking option';
$string['bo_cond_subbooking'] = 'Subbbookings exist';
$string['bo_cond_bookitbutton'] = 'bookitbutton: Show the normal booking button.';
$string['bo_cond_isloggedinprice'] = 'isloggedinprice: Show all prices when not logged in.';
$string['bo_cond_optionhasstarted'] = 'Has already started';
$string['bo_cond_customform'] = 'Fill out form';

$string['bo_cond_booking_time_available'] = 'Within normal booking times.';
$string['bo_cond_booking_time_not_available'] = 'Not within normal booking times.';
$string['bo_cond_booking_opening_time_not_available'] = 'Can be booked from {$a}.';
$string['bo_cond_booking_opening_time_full_not_available'] = 'Can be booked from {$a}.';
$string['bo_cond_booking_closing_time_not_available'] = 'Cannot be booked anymore (ended on {$a}).';
$string['bo_cond_booking_closing_time_full_not_available'] = 'Cannot be booked anymore (ended on {$a}).';

$string['bo_cond_alreadybooked_available'] = 'Not yet booked';
$string['bo_cond_alreadybooked_full_available'] = 'The user has not yet booked';
$string['bo_cond_alreadybooked_not_available'] = 'Booked';
$string['bo_cond_alreadybooked_full_not_available'] = 'Booked';

$string['bo_cond_alreadyreserved_available'] = 'Not yet added to cart';
$string['bo_cond_alreadyreserved_full_available'] = 'Not yet added to cart';
$string['bo_cond_alreadyreserved_not_available'] = 'Added to cart';
$string['bo_cond_alreadyreserved_full_not_available'] = 'Added to cart';

$string['bo_cond_fullybooked_available'] = 'Book it';
$string['bo_cond_fullybooked_full_available'] = 'Booking is possible';
$string['bo_cond_fullybooked_not_available'] = 'Fully booked';
$string['bo_cond_fullybooked_full_not_available'] = 'Fully booked';

$string['bo_cond_allowedtobookininstance_available'] = 'Book it';
$string['bo_cond_allowedtobookininstance_full_available'] = 'Booking is possible';
$string['bo_cond_allowedtobookininstance_not_available'] = 'No right to book';
$string['bo_cond_allowedtobookininstance_full_not_available'] = 'No right to book on this booking instance';

$string['bo_cond_fullybookedoverride_available'] = 'Book it';
$string['bo_cond_fullybookedoverride_full_available'] = 'Booking is possible';
$string['bo_cond_fullybookedoverride_not_available'] = 'Fully booked';
$string['bo_cond_fullybookedoverride_full_not_available'] = 'Fully booked - but you have the right to book a user anyways.';

$string['bo_cond_max_number_of_bookings_available'] = 'Book it';
$string['bo_cond_max_number_of_bookings_full_available'] = 'Booking is possible';
$string['bo_cond_max_number_of_bookings_not_available'] = 'Max. number of bookings reached';
$string['bo_cond_max_number_of_bookings_full_not_available'] = 'User has reached the max number of bookings';

$string['bo_cond_onnotifylist_available'] = 'Book it';
$string['bo_cond_onnotifylist_full_available'] = 'Booking is possible';
$string['bo_cond_onnotifylist_not_available'] = 'Max number of bookings reached';
$string['bo_cond_onnotifylist_full_not_available'] = 'User has reached the max number of bookings';

$string['bo_cond_askforconfirmation_available'] = 'Book it';
$string['bo_cond_askforconfirmation_full_available'] = 'Booking is possible';
$string['bo_cond_askforconfirmation_not_available'] = 'Book it - on waitinglist';
$string['bo_cond_askforconfirmation_full_not_available'] = 'Book it - on waitinglist';

$string['bo_cond_onwaitinglist_available'] = 'Book it';
$string['bo_cond_onwaitinglist_full_available'] = 'Booking is possible';
$string['bo_cond_onwaitinglist_not_available'] = 'You are on the waiting list';
$string['bo_cond_onwaitinglist_full_not_available'] = 'User is on the waiting list';

$string['bo_cond_priceisset_available'] = 'Book it';
$string['bo_cond_priceisset_full_available'] = 'Booking is possible';
$string['bo_cond_priceisset_not_available'] = 'You need to pay';
$string['bo_cond_priceisset_full_not_available'] = 'A price is set, payment required';

$string['bo_cond_userprofilefield_available'] = 'Book it';
$string['bo_cond_userprofilefield_full_available'] = 'Booking is possible';
$string['bo_cond_userprofilefield_not_available'] = 'Not allowed to book';
$string['bo_cond_userprofilefield_full_not_available'] = 'Only users with user profile field {$a->profilefield} set to value {$a->value} are allowed to book.
    <br>But you have the right to book a user anyways.';

$string['bo_cond_customuserprofilefield_available'] = 'Book it';
$string['bo_cond_customuserprofilefield_full_available'] = 'Booking is possible';
$string['bo_cond_customuserprofilefield_not_available'] = 'Not allowed to book';
$string['bo_cond_customuserprofilefield_full_not_available'] = 'Only users with custom user profile field {$a->profilefield} set to value {$a->value} are allowed to book.
    <br>But you have the right to book a user anyways.';

$string['bo_cond_previouslybooked_available'] = 'Book it';
$string['bo_cond_previouslybooked_full_available'] = 'Booking is possible';
$string['bo_cond_previouslybooked_not_available'] = 'Only users who have previously booked <a href="{$a}">this option</a> are allowed to book.';
$string['bo_cond_previouslybooked_full_not_available'] = 'Only users who have previously booked <a href="{$a}">this option</a> are allowed to book.
    <br>But you have the right to book a user anyways.';

$string['bo_cond_enrolledincohorts_available'] = 'Book it';
$string['bo_cond_enrolledincohorts_full_available'] = 'Booking is possible';
$string['bo_cond_enrolledincohorts_not_available'] = 'Booking not allowed because you are not enrolled in at least one of the following cohort(s): {$a}';
$string['bo_cond_enrolledincohorts_full_not_available'] = 'Only users who are enrolled in at least one of the following cohort(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bo_cond_enrolledincohorts_not_available_and'] = 'Booking not allowed because you are not enrolled in all of the following cohort(s): {$a}';
$string['bo_cond_enrolledincohorts_full_not_available_and'] = 'Only users who are enrolled in all of the following cohort(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bo_cond_enrolledincohorts_warning'] = 'You have a very high number of cohorts on your system. Not all of them will be available here. If that is a problem for you, please contact <a mailto="info@wunderyte.at">Wunderbyte</a>';


$string['bo_cond_enrolledincourse_available'] = 'Book it';
$string['bo_cond_enrolledincourse_full_available'] = 'Booking is possible';
$string['bo_cond_enrolledincourse_not_available'] = 'Booking not allowed because you are not enrolled in at least one of the following course(s): {$a}';
$string['bo_cond_enrolledincourse_full_not_available'] = 'Only users who are enrolled in at least one of the following course(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bo_cond_enrolledincourse_not_available_and'] = 'Booking not allowed because you are not enrolled in all of the following course(s): {$a}';
$string['bo_cond_enrolledincourse_full_not_available_and'] = 'Only users who are enrolled in all of the following course(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';

$string['bo_cond_isbookable_available'] = 'Book it';
$string['bo_cond_isbookable_full_available'] = 'Booking is possible';
$string['bo_cond_isbookable_not_available'] = 'Not allowed to book';
$string['bo_cond_isbookable_full_not_available'] = 'Booking is forbidden for this booking option.
    <br>But you have the right to book a user anyways.';

$string['bo_cond_subisbookable_available'] = 'Book it';
$string['bo_cond_subisbookable_full_available'] = 'Booking is possible';
$string['bo_cond_subisbookable_not_available'] = 'Book option first';
$string['bo_cond_subisbookable_full_not_available'] = 'Booking is not possible for this subbooking as the corresponding option is not booked.';

$string['bo_cond_iscancelled_available'] = 'Book it';
$string['bo_cond_iscancelled_full_available'] = 'Booking is possible';
$string['bo_cond_iscancelled_not_available'] = 'Cancelled';
$string['bo_cond_iscancelled_full_not_available'] = 'Cancelled';

$string['bo_cond_isloggedin_available'] = 'Book it';
$string['bo_cond_isloggedin_full_available'] = 'Booking is possible';
$string['bo_cond_isloggedin_not_available'] = 'Log in to book this option.';
$string['bo_cond_isloggedin_full_not_available'] = 'User is not logged in.';

$string['bo_cond_optionhasstarted_available'] = 'Book it';
$string['bo_cond_optionhasstarted_full_available'] = 'Booking is possible';
$string['bo_cond_optionhasstarted_not_available'] = 'Already started - booking is not possible anymore';
$string['bo_cond_optionhasstarted_full_not_available'] = 'Already started - booking for users is not possible anymore';

$string['bo_cond_selectusers_available'] = 'Book it';
$string['bo_cond_selectusers_full_available'] = 'Booking is possible';
$string['bo_cond_selectusers_not_available'] = 'Booking not allowed';
$string['bo_cond_selectusers_full_not_available'] = 'Only the following users are allowed to book:<br>{$a}';

$string['bo_cond_subbookingblocks_available'] = 'Book it';
$string['bo_cond_subbookingblocks_full_available'] = 'Booking is possible';
$string['bo_cond_subbookingblocks_not_available'] = 'Not allowed to book.';
$string['bo_cond_subbookingblocks_full_not_available'] = 'Subbooking blocks this booking option.';

// This does not really block, it just handles available subbookings.
$string['bo_cond_subbooking_available'] = 'Book it';
$string['bo_cond_subbooking_full_available'] = 'Booking is possible';
$string['bo_cond_subbooking_not_available'] = 'Book it';
$string['bo_cond_subbooking_full_not_available'] = 'Booking is possible';

$string['bo_cond_customform_restrict'] = 'Form needs to be filled out before booking';
$string['bo_cond_customform_available'] = 'Book it';
$string['bo_cond_customform_full_available'] = 'Booking is possible';
$string['bo_cond_customform_not_available'] = 'Book it';
$string['bo_cond_customform_full_not_available'] = 'Booking is possible';

// BO conditions in mform.
$string['bo_cond_selectusers_restrict'] = 'Only specific user(s) are allowed to book';
$string['bo_cond_selectusers_userids'] = 'User(s) allowed to book';
$string['bo_cond_selectusers_userids_help'] = '<p>If you use this condition, only selected people will be able to book this event.</p>
<p>However, you can also use this condition to allow certain people to bypass other restrictions:</p>
<p>(1) To do this, click the "Has relation to other condition" checkbox.<br>
(2) Make sure that the "OR" operator is selected.<br>
(3) Choose all conditions to be bypassed.</p>
<p>Examples:<br>
"Fully booked" => The selected person is allowed to book even if the event is already fully booked.<br>
"Only bookable within a certain time" => The selected person is allowed to book also outside the normal booking times.</p>';

$string['userinfofieldoff'] = 'No user profile field selected';
$string['bo_cond_userprofilefield_1_default_restrict'] = 'A chosen user profile field should have a certain value';
$string['bo_cond_previouslybooked_restrict'] = 'User has previously booked a certain option';
$string['bo_cond_userprofilefield_field'] = 'Profile field';
$string['bo_cond_userprofilefield_value'] = 'Value';
$string['bo_cond_userprofilefield_operator'] = 'Operator';

$string['bo_cond_userprofilefield_2_custom_restrict'] = 'A custom user profile field should have a certain value';
$string['bo_cond_customuserprofilefield_field'] = 'Profile field';
$string['bo_cond_customuserprofilefield_value'] = 'Value';
$string['bo_cond_customuserprofilefield_operator'] = 'Operator';

$string['equals'] = 'has exactly this value (text or number)';
$string['contains'] = 'contains (text)';
$string['lowerthan'] = 'is lower than (number)';
$string['biggerthan'] = 'is bigger than (number)';
$string['equalsnot'] = 'has not exactly this value (text or number)';
$string['containsnot'] = 'does not contain (text)';
$string['inarray'] = 'user has one of these comma separated values';
$string['notinarray'] = 'user has none of these comma separated values';
$string['isempty'] = 'field is empty';
$string['isnotempty'] = 'field is not empty';

$string['overrideconditioncheckbox'] = 'Has relation to other condition';
$string['overridecondition'] = 'Condition';
$string['overrideoperator'] = 'Operator';
$string['overrideoperator:and'] = 'AND';
$string['overrideoperator:or'] = 'OR';
$string['bo_cond_previouslybooked_optionid'] = 'Must be already booked';
$string['allcohortsmustbefound'] = 'User has to be member of all cohorts';
$string['allcoursesmustbefound'] = 'User has to be subscribed to all courses';
$string['onecohortmustbefound'] = 'User has to be member to at least one of these cohorts';
$string['onecoursemustbefound'] = 'User has to be subscribed to at least only one of these courses';


$string['noelement'] = "No Element";
$string['checkbox'] = "Checkbox";
$string['displaytext'] = "Display text";
$string['textarea'] = "Textarea";
$string['shorttext'] = "Shorttext";
$string['bo_cond_customform_url'] = "Url";
$string['bo_cond_customform_url_error'] = "The URL is not valid or does not start with http or https.";
$string['bo_cond_customform_numbers_error'] = "Please insert a valid number of days.";
$string['bo_cond_customform_mail'] = "E-Mail";
$string['bo_cond_customform_mail_error'] = "The email address is invalid.";
$string['bo_cond_customform_fully_booked'] = 'The option "{$a}" is already fully booked.';
$string['formtype'] = "Type of form";
$string['bo_cond_customform_label'] = "Label";
$string['bo_cond_customform_notempty'] = 'Must not be empty';
$string['bo_cond_customform_value'] = 'Value';
$string['bo_cond_customform_value_help'] = 'When a dropdown menu is selected, please enter one value per line. The values and displayed values can be entered separately, for example, "1 => My first value => number_of_availability" etc.';
$string['bo_cond_customform_available'] = 'available';

// Teacher_performed_units_report.php.
$string['error:wrongteacherid'] = 'Error: No user could be found for the provided "teacherid".';
$string['duration:minutes'] = 'Duration (minutes)';
$string['duration:units'] = 'Units ({$a} min)';
$string['teachingreportfortrainer:subtitle'] = '<strong>Hint:</strong> You can change the duration of
an educational unit in the plugin settings (e.g. 45 instead of 60 minutes).<br/>
<a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank">
&gt;&gt; Go to plugin settings...
</a>';
$string['error:missingteacherid'] = 'Error: Report cannot be loaded because of missing teacherid.';

// Teacher_performed_units_report_form.php.
$string['filterstartdate'] = 'From';
$string['filterenddate'] = 'Until';
$string['filterbtn'] = 'Filter';

// Booking campaigns.
$string['bookingcampaignswithbadge'] = 'Booking: Campaigns ' . $badgepro;
$string['bookingcampaigns'] = 'Booking: Campaigns (PRO)';
$string['bookingcampaign'] = 'Campaign';
$string['bookingcampaignssubtitle'] = 'Campaigns allow you to discount the prices of selected booking options
 for a specified period of time and increase the booking limit for that period. For campaigns to work, the
 Moodle cron job must run regularly.<br>
 Overlapping campaigns will add up. Two matching 50% price campaigns will result in a 25% price.';
$string['campaigntype'] = 'Campaign type';
$string['editcampaign'] = 'Edit campaign';
$string['addbookingcampaign'] = 'Add campaign';
$string['deletebookingcampaign'] = 'Delete campaign';
$string['deletebookingcampaign_confirmtext'] = 'Do you really want to delete the following campaign?';
$string['campaign_name'] = 'Custom name for the campaign';
$string['campaign_customfield'] = 'Change price or booking limit';
$string['campaign_customfield_descriptiontext'] = 'Affects: Booking option custom field "{$a->fieldname}"
 having the value "{$a->fieldvalue}".';
$string['campaignfieldname'] = 'Booking option field';
$string['campaignfieldvalue'] = 'Value';
$string['campaignstart'] = 'Start of campaign';
$string['campaignend'] = 'End of campaign';

$string['campaign_blockbooking'] = 'Block certain booking options';
$string['campaign_blockbooking_descriptiontext'] = 'Affects: Booking option custom field "{$a->fieldname}"
having the value "{$a->fieldvalue}".';

$string['optionspecificcampaignwarning'] = "If you choose a Booking option field specific field here beneath, the price and limit part of the campaign will apply only for booking options that fulfill the requirements.<br><br>If you choose a Booking option field AND a Custom user profile field, both requirements have to be fulfilled.";
$string['userspecificcampaignwarning'] = "If you choose a Custom user profile field here beneath, the price part of the campaign will only be effective for users with the defined value in the custom user profile field.";
$string['customuserprofilefield'] = "Custom user profile field";
$string['customuserprofilefield_help'] = "If you choose a value here, the price part of the camapaign will only be valid for users with the defined value in the defined custom field.";


$string['blockoperator'] = 'Operator';
$string['blockoperator_help'] = '<b>Block above</b> ... Online booking will be blocked once the given percentage
of bookings is reached. Booking will only be possible for a cashier or admin afterwards.<br>
<b>Block below</b> ... Online booking will be blocked until the given percentage
of bookings is reached. Before that happens, booking is only possible for cashier or admin.';
$string['blockabove'] = 'Block above';
$string['blockbelow'] = 'Block below';
$string['percentageavailableplaces'] = 'Percentage of available places';
$string['percentageavailableplaces_help'] = 'You need to enter a valid percentage beween 0 and 100 (without %-sign!).';
$string['hascapability'] = 'Except has capability';
$string['blockinglabel'] = 'Message when blocking';
$string['blockinglabel_help'] = 'Enter the message that should be shown when the booking is blocked.
If you want to localize this message, you can use
<a href="https://docs.moodle.org/403/en/Multi-language_content_filter" target="_blank">language filters</a>.';

// Booking campaign help buttons.
$string['campaign_name_help'] = 'Specify any name for the campaign - for example, "Christmas Campaign 2023" or "Easter Discount 2023".';
$string['campaignfieldname_help'] = 'Select the custom booking option field whose value is to be compared.';
$string['campaignfieldvalue_help'] = 'Select the value of the field. The campaign applies to all booking options that have this value in the selected field.';
$string['campaignstart_help'] = 'When does the campaign start?';
$string['campaignend_help'] = 'When does the campaign end?';
$string['pricefactor_help'] = 'Specify a value by which to multiply the price. For example to discount the prices by 20%, enter the value <b>0.8</b>.';
$string['limitfactor_help'] = 'Specify a value by which to multiply the booking limit. For example to increase the booking limit by 20%, enter the value <b>1.2</b>.';

// Booking campaign errors.
$string['error:pricefactornotbetween0and1'] = 'You need to enter a value between 0 and 1, e.g. 0.9 to reduce prices by 10%.';
$string['error:limitfactornotbetween1and2'] = 'You need to enter a value between 0 and 2, e.g. 1.2 to add 20% more bookable places.';
$string['error:missingblockinglabel'] = 'Please enter the message to show when booking is blocked.';
$string['error:percentageavailableplaces'] = 'You need to enter a valid percentage bewteen 0 and 100 (without %-sign!).';
$string['error:campaignstart'] = 'Campaign start has to be before campaign end.';
$string['error:campaignend'] = 'Campaign end has to be after campaign start.';

// Booking rules.
$string['bookingruleswithbadge'] = 'Booking: Rules ' . $badgepro;
$string['bookingrules'] = 'Booking: Rules (PRO)';
$string['bookingrule'] = 'Rule';
$string['addbookingrule'] = 'Add rule';
$string['deletebookingrule'] = 'Delete rule';
$string['deletebookingrule_confirmtext'] = 'Do you really want to delete the following rule?';
$string['bookingruletemplates'] = 'Load a template rule';
$string['bookinguseastemplate'] = 'Set this rule as template';
$string['bookingdefaulttemplate'] = 'Choose template...';

$string['rule_event'] = 'Event';
$string['rule_event_condition'] = 'Execute when...';
$string['rule_mailtemplate'] = 'Mail template';
$string['rule_datefield'] = 'Date field';
$string['rule_customprofilefield'] = 'Custom user profile field';
$string['rule_operator'] = 'Operator';
$string['rule_value'] = 'Value';
$string['rule_days'] = 'Number of days';

$string['rule_optionfield'] = 'Option field to compare';
$string['rule_optionfield_coursestarttime'] = 'Begin (coursestarttime)';
$string['rule_optionfield_courseendtime'] = 'End (courseendtime)';
$string['rule_optionfield_bookingopeningtime'] = 'Start of allowed booking period (bookingopeningtime)';
$string['rule_optionfield_bookingclosingtime'] = 'End of allowed booking period (bookingclosingtime)';
$string['rule_optionfield_text'] = 'Name of the booking option (text)';
$string['rule_optionfield_location'] = 'Location (location)';
$string['rule_optionfield_address'] = 'Address (address)';

$string['rule_sendmail_cpf'] = '[Preview] Send an e-mail to user with custom profile field';
$string['rule_sendmail_cpf_desc'] = 'Choose an event that should trigger the "Send an e-mail" rule. Enter an e-mail template
 (you can use placeholders like {bookingdetails}) and define to which users the e-mail should be sent.
  Example: All users having the value "Vienna center" in a custom user profile field called "Study center".';

$string['rule_daysbefore'] = 'Trigger n days in relation to a certain date';
$string['rule_daysbefore_desc'] = 'Choose a date field of booking options and the number of days in relation to that date.';
$string['rule_react_on_event'] = "React on event";
$string['rule_react_on_event_desc'] = "Choose an event that should trigger the rule.<br>
<b>Hint:</b> You can use the placeholder <code>{eventdescription}</code> to show a description of the event.";
$string['rule_react_on_event_after_completion'] = "Number of days after end of booking option, where rule still applies";
$string['rule_react_on_event_after_completion_help'] = "Leave this field empty or set to 0 if you want to keep executing the action. You can use negative numbers if the rule should be suspended before the specified courseend.";

$string['error:nofieldchosen'] = 'You have to choose a field.';
$string['error:mustnotbeempty'] = 'Must not be empty.';

// Booking rules conditions.
$string['rule_name'] = "Custom name for the rule";
$string['bookingrulecondition'] = "Condition of the rule";
$string['bookingruleaction'] = "Action of the rule";
$string['enter_userprofilefield'] = "Select users by entering a value for custom user profile field.";
$string['condition_textfield'] = 'Value';
$string['match_userprofilefield'] = "Select users by matching field in booking option and user profile field.";
$string['select_users'] = "Directly select users without connection to the booking option";
$string['select_student_in_bo'] = "Select users of a booking option";
$string['select_teacher_in_bo'] = "Select teachers of a booking option";
$string['select_user_from_event'] = "Select user from event";
$string['send_mail'] = 'Send email';
$string['bookingcondition'] = 'Condition';
$string['condition_select_teacher_in_bo_desc'] = 'Select the teachers of the booking option (affected by the rule).';
$string['condition_select_student_in_bo_desc'] = 'Select all students of the booking option (affected by the rule) having a certain role.';
$string['condition_select_student_in_bo_roles'] = 'Choose role';
$string['condition_select_users_userids'] = "Select the users you want to target";
$string['condition_select_user_from_event_desc'] = 'Choose a user who is somehow connected to the event';
$string['studentbooked'] = 'Users who booked';
$string['studentwaitinglist'] = 'Users on the waiting list';
$string['studentnotificationlist'] = 'Users on the notification list';
$string['studentdeleted'] = 'Users who were already deleted';
$string['useraffectedbyevent'] = 'User affected by the event';
$string['userwhotriggeredevent'] = 'User who triggered the event';
$string['condition_select_user_from_event_type'] = 'Choose role';

$string['select_user_shopping_cart'] = "Choose user who has to pay installments";
$string['condition_select_user_shopping_cart_desc'] = "User with payment obligation is chosen";

// Booking rules actions.
$string['bookingaction'] = "Action";
$string['sendcopyofmailsubjectprefix'] = 'Subject prefix for the copy';
$string['sendcopyofmailmessageprefix'] = 'Message prefix for the copy';
$string['send_copy_of_mail'] = 'Send an email copy';
$string['send_mail_interval'] = 'Send a message to multiple users with a time delay';
$string['interval'] = "Delay";
$string['interval_help'] = "In minutes. 1440 for 24 hours.";

// Cancel booking option.
$string['canceloption'] = "Cancel boooking option";
$string['canceloption_desc'] = "Canceling a booking option means that it is no longer bookable, but it is still shown in list.";
$string['confirmcanceloption'] = "Confirm cancellation of booking option";
$string['confirmcanceloptiontitle'] = "Change the status of the booking option";
$string['cancelthisbookingoption'] = "Cancel this booking option";
$string['usergavereason'] = '{$a} gave the following reason for cancellation:';
$string['undocancelthisbookingoption'] = "Undo cancelling of this booking option";
$string['cancelreason'] = "Reason for cancelation of this booking option";
$string['undocancelreason'] = "Do you really want to undo the cancellation of this booking option?";
$string['nocancelreason'] = "You need to give a reason for canceling this booking option";

// Access.php.
$string['booking:bookforothers'] = "Book for others";
$string['booking:canoverbook'] = "Has permission to overbook";
$string['booking:canreviewsubstitutions'] = "Allowed to review teacher substitutions (control checkbox)";
$string['booking:conditionforms'] = "Submit condition forms like booking policy or subbookings";
$string['booking:view'] = 'View booking instances';
$string['booking:viewreports'] = 'Allow access for viewing reports';
$string['booking:manageoptiondates'] = 'Manage option dates';
$string['booking:limitededitownoption'] = 'Less than addeditownoption, only allows very limited actions';
$string['booking:editoptionformconfig'] = 'Edit option config form';
$string['booking:bookanyone'] = 'Allowed to book anyone';

// Booking_handler.php.
$string['error:newcoursecategorycfieldmissing'] = 'You need to create a <a href="{$a->bookingcustomfieldsurl}"
 target="_blank">booking custom field</a> for new course categories first. After you have created one, make sure
 it is selected in the <a href="{$a->settingsurl}" target="_blank">Booking plugin settings</a>.';
$string['error:coursecategoryvaluemissing'] = 'You need to choose a value here as it is needed as course category
 for the automatically created Moodle course.';

// Subbookings.
$string['bookingsubbookingsheader'] = "Subbookings";
$string['bookingsubbooking'] = "Subbooking";
$string['subbooking_name'] = "Name of the subbooking";
$string['bookingsubbookingadd'] = 'Add a subbooking';
$string['bookingsubbookingedit'] = 'Edit';
$string['editsubbooking'] = 'Edit subbooking';
$string['bookingsubbookingdelete'] = 'Delete subbooking';

$string['onlyaddsubbookingsonsavedoption'] = "You need to save this booking option before you can add subbookings.";
$string['onlyaddentitiesonsavedsubbooking'] = "You need to save this subbooking before you can add an entity.";

$string['subbooking_timeslot'] = "Timeslot booking";
$string['subbooking_timeslot_desc'] = "This opens timeslots for every booking date with a set duration.";
$string['subbooking_duration'] = "Duration in minutes";

$string['subbooking_additionalitem'] = "Additional item booking";
$string['subbooking_additionalitem_desc'] = "This permits you to add optinally bookable items to this booking option,
 eg. you can book a better special seat etc. or breakfast to your hotel room.";
$string['subbooking_additionalitem_description'] = "Describe the additionally bookable item:";

$string['subbooking_additionalperson'] = "Additional person booking";
$string['subbooking_additionalperson_desc'] = "This permits you to add other persons to this booking option,
 e.g. to book places for your family members.";
$string['subbooking_additionalperson_description'] = "Describe the additional person booking option";

$string['subbooking_addpersons'] = "Add additional person(s)";
$string['subbooking_bookedpersons'] = "The following person(s) are added:";
$string['personnr'] = 'Person n° {$a}';

// Shortcodes.
$string['recommendedin'] = "Shortcode to show a list of booking options which should be recommended in a given course.
 To use this, add a booking customfield with the shortname 'recommendedin' and comma separated values with the shortnames
 of the courses you want to show this recommendations. So: When you want recommend option1 to the participants enroled in
 Course 1 (course1), then you need to set the customfield 'recommendedin' from within the booking option to 'course1'.";
$string['fieldofstudyoptions'] = "Shortcode to show all booking options of a field of study.
 They are defined by a common cohort sync enrolement & the booking availabilty condition of
 having to be inscribed in one of these courses.";
$string['fieldofstudycohortoptions'] = "Shortcode to show all booking options of a field of study.
 They are defined by a course group with the same name. Booking options are defined by having comma
 separated shortnames of at least one of theses courses in the recommendedin custom booking options field.";
$string['nofieldofstudyfound'] = "No field of study could be determined via cohorts";
$string['shortcodenotsupportedonyourdb'] = "This shortcode is not supported on your DB. It only works on postgres & mariadb";
$string['definefieldofstudy'] = 'You can show here all booking options from the whole field fo study. To make this work,
 use groups with the name of your field of study. In a course which is used in "Psychology" and "Philosophy",
 you will have two groups, named like these fields of study. Follow this scheme for all your courses.
 Now add the custom booking field with the shortname "recommendedin", where you add the comma separated
 shortcodes of those courses, in which a booking option should be recommended. If a user is subscribed
 to "philosophy", she will see all the booking options in which at least one of the "philosohpy"-courses is recommended.';
$string['definecmidforshortcode'] = "To use this shortcode, enter the id of a booking instance like this: [courselist cmid=23]";
$string['courselist'] = 'Show all booking options of a booking instance.';

// Elective.
$string['elective'] = "Elective";
$string['selected'] = 'Selected';
$string['bookelectivesbtn'] = 'Book selected electives';
$string['electivesbookedsuccess'] = 'Your selected electives have been booked successfully.';
$string['errormultibooking'] = 'There was an ERROR when booking the electives.';
$string['selectelective'] = 'Select elective for {$a} credits';
$string['electivedeselectbtn'] = 'Deselect elective';
$string['confirmbookingtitle'] = "Confirm booking";
$string['sortbookingoptions'] = "Please sort your bookings in the right order. You will only be able to access the associated courses one after the other. Top comes first.";
$string['selectoptionsfirst'] = "Please select booking options first.";
$string['electivesettings'] = 'Elective Settings';
$string['iselective'] = 'Use instance as elective';
$string['iselective_help'] = 'This allows you to force users to book several booking options at once in a specific order
 or in specific relations to each other. Additionally, you can force the use of credits.';
$string['maxcredits'] = 'Max credits to use';
$string['maxcredits_help'] = 'You can define the max amount of credits users can or must use when booking options. You can define in every booking option how many credits it is worth.';
$string['unlimitedcredits'] = 'Don\'t use credits';
$string['enforceorder'] = 'Enforce booking order';
$string['enforceorder_help'] = 'Users will be inscribed only once they have completed the previous booking option';
$string['consumeatonce'] = 'All credits have to be consumed at once';
$string['consumeatonce_help'] = 'Uses can only book once, and they have to book all options in one step.';
$string['credits'] = 'Credits';
$string['bookwithcredits'] = '{$a} credits';
$string['bookwithcredit'] = '{$a} credit';
$string['notenoughcreditstobook'] = 'Not enough credit to book';
$string['electivenotbookable'] = 'Not bookable';
$string['credits_help'] = 'The number of credits which will be used by booking this option.';
$string['mustcombine'] = 'Necessary booking options';
$string['mustcombine_help'] = 'Booking options which have to be combined with this option';
$string['mustnotcombine'] = 'Excluded booking options';
$string['mustnotcombine_help'] = 'Booking options which can\'t be  combined with this option';
$string['nooptionselected'] = 'No booking option selected';
$string['creditsmessage'] = 'You have {$a->creditsleft} of {$a->maxcredits} credits left.';
$string['notemplateyet'] = 'No template yet';
$string['electiveforcesortorder'] = 'Teacher can force sort order';
$string['enforceteacherorder'] = 'Enforce teachers order';
$string['enforceteacherorder_help'] = 'Users will not be able to define order of selected options but they will be determined by teacher';
$string['notbookablecombiantion'] = 'This combination of electives is not allowed';

// Booking Actions.
$string['bookingactionsheader'] = 'Actions after booking [EXPERIMENTAL]';
$string['selectboactiontype'] = 'Select action after booking';
$string['bookingactionadd'] = "Add action";
$string['boactions_desc'] = "Booking actions after booking are still an EXPERIMENTAL feature. You can try them if you want.
But do not use them in a productive environment yet!";
$string['boactions'] = 'Actions after booking ' . $badgepro . ' ' . $badgeexp;
$string['onlyaddactionsonsavedoption'] = "Actions after booking can only be added once the booking option is saved.";
$string['boactionname'] = "Name of action";
$string['bonumberofdays'] = "Number of days";
$string['bosecrettoken'] = "Secret token";
$string['bopathtoscript'] = "Path to rest script";
$string['showboactions'] = "Activate actions after booking";
$string['boactionselectuserprofilefield'] = "Choose profile field";
$string['boactioncancelbookingvalue'] = "Activate immediate cancellation";
$string['boactioncancelbooking_desc'] = "Used when an option should be purchasable multiple times.";
$string['boactionuserprofilefieldvalue'] = 'Value';
$string['actionoperator:set'] = 'Replace';
$string['actionoperator:subtract'] = 'Subtract';
$string['actionoperator'] = 'Action';
$string['actionoperator:adddate'] = 'Add date';
$string['customformparams_value'] = "Customform parameter";
$string['customformparams_desc'] = "Use parameter that are set in the customform.";
$string['adminparameter_value'] = "Admin parameter";
$string['adminparameter_desc'] = "Use parameter that are set in the admin settings.";
$string['userparameter_value'] = "User parameter";
$string['userparameter_desc'] = "Use user parameter.";
$string['editaction'] = "Edit Action";


// Dates class.
$string['adddatebutton'] = "Add date";
$string['nodatesstring'] = "There are currently no dates associated with this booking option";
$string['nodatesstring_desc'] = "no dates";

// Access.
$string['mod/booking:expertoptionform'] = 'Bookingoption for experts';
$string['mod/booking:reducedoptionform1'] = 'Reduced booking option 1';
$string['mod/booking:reducedoptionform2'] = 'Reduced booking option 2';
$string['mod/booking:reducedoptionform3'] = 'Reduced booking option 3';
$string['mod/booking:reducedoptionform4'] = 'Reduced booking option 4';
$string['mod/booking:reducedoptionform5'] = 'Reduced booking option 5';
$string['mod/booking:bookanyone'] = 'Book anyone';
$string['mod/booking:seepersonalteacherinformation'] = 'See personal teacher information';

// Vue strings.
$string['vue_dashboard_checked'] = 'Default Checked';
$string['vue_dashboard_name'] = 'Name';
$string['vue_dashboard_course_count'] = 'Course Count';
$string['vue_dashboard_path'] = 'Path';
$string['vue_dashboard_create_oe'] = 'Create new OE';
$string['vue_dashboard_assign_role'] = 'Assign Roles';
$string['vue_dashboard_new_course'] = 'Create new course';
$string['vue_not_found_route_not_found'] = 'Route not found';
$string['vue_not_found_try_again'] = 'Please try later again';
$string['vue_booking_stats_capability'] = 'Capability';
$string['vue_booking_stats_back'] = 'Back';
$string['vue_booking_stats_save'] = 'Save';
$string['vue_booking_stats_restore'] = 'Restore';
$string['vue_booking_stats_select_all'] = 'Select all';
$string['vue_booking_stats_booking_options'] = 'Booking Options';
$string['vue_booking_stats_booked'] = 'Booked';
$string['vue_booking_stats_waiting'] = 'Waiting List';
$string['vue_booking_stats_reserved'] = 'Reserved';
$string['vue_capability_options_cap_config'] = 'Capability Configuration';
$string['vue_capability_options_necessary'] = 'necessary';
$string['vue_capability_unsaved_changes'] = 'There are unsaved changes';
$string['vue_capability_unsaved_continue'] = 'You really want to reset this configuration?';
$string['vue_booking_stats_restore_confirmation'] = 'You really want to reset this configuration?';
$string['vue_booking_stats_yes'] = 'Yes';
$string['vue_booking_stats_no'] = 'No';
$string['vue_confirm_modal'] = 'Are you sure you want to go back?';
$string['vue_heading_modal'] = 'Confirmation';
$string['vue_notification_title_unsave'] = 'No unsaved changes detected';
$string['vue_notification_text_unsave'] = 'There were no unsaved changes detected.';
$string['vue_notification_title_action_success'] = 'Configuration was {$a}';
$string['vue_notification_text_action_success'] = 'Configuration was {$a} successfully.';
$string['vue_notification_title_action_fail'] = 'Configuration was not  {$a}';
$string['vue_notification_text_action_fail'] = 'Something went wrong while saving. The changes have not been made.';
$string['vue_dashboard_goto_category'] = 'Go to category';

// Mobile.
$string['mobileapp_heading'] = "Mobile App";
$string['mobileapp_heading_desc'] = "Choose your booking instance to display on the connected Moodle Mobile Apps.";
$string['mobileappnobookinginstance'] = "No booking instance on your plattform";
$string['mobileappnobookinginstance_desc'] = "You need to create at least one booking instance";
$string['mobileappsetinstance'] = "Booking instance";
$string['mobileappsetinstancedesc'] = "Choose the Booking instance which should be shown on the mobile app.";
$string['details'] = 'Details';
$string['notbookable'] = "Not bookable";
$string['next'] = 'Next';
$string['previous'] = 'Previous';
$string['show_dates'] = 'Show dates';
$string['mobileapp_price'] = 'Price';
$string['mobile_notification'] = 'Form has been submitted';
$string['mobile_submitted_success'] = 'You can continue and book the course';
$string['mobile_reset_submission'] = 'Reset Submission form';
$string['mobile_set_submission'] = 'Submit';
$string['mobile_field_required'] = 'This field is required';
// Days before or after.
$string['choose...'] = 'Choose...';
$string['daysbefore'] = '{$a} day(s) before';
$string['daysafter'] = '{$a} day(s) after';
$string['sameday'] = 'same day';

$string['reportfields'] = 'Report fields';


$string['bookinganswerupdated'] = 'Booking Answer Udated';

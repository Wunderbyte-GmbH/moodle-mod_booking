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

// General strings.
$string['areyousure:book'] = 'Do you really want to book?';
$string['areyousure:cancel'] = 'Do you really want to cancel?';
$string['assignteachers'] = 'Assign teachers:';
$string['alreadypassed'] = 'Already passed';
$string['bookingoption'] = 'Booking option';
$string['bookingoptionnamewithoutprefix'] = 'Name (without prefix)';
$string['bookings'] = 'Bookings';
$string['updatebooking'] = 'Update booking';
$string['booking:manageoptiontemplates'] = "Manage option templates";
$string['booking:cantoggleformmode'] = 'User can edit all settings';
$string['booking:overrideboconditions'] = 'User can book even when conditions return false.';
$string['courses'] = 'Courses';
$string['date_s'] = 'Date(s)';
$string['dayofweek'] = 'Weekday';
$string['doyouwanttobook'] = 'Do you want to book <b>{$a}</b>?';
$string['gotomanageresponses'] = '&lt;&lt; Manage bookings';
$string['gotomoodlecourse'] = 'Go to Moodle course';
$string['messageprovider:bookingconfirmation'] = "Booking confirmations";
$string['optionsiteach'] = 'Teached by me';
$string['placeholders'] = 'Placeholders';
$string['search'] = 'Search...';
$string['teachers'] = 'Teachers';
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
$string['usersmatching'] = 'Gefundene Nutzer:innen';
$string['allmoodleusers'] = 'Alle Nutzer:innen dieser Website';
$string['enrolledusers'] = 'In den Kurs eingeschriebene Nutzer:innen';
$string['nopriceisset'] = 'Kein Preis vorhanden';

// General errors.
$string['error:choosevalue'] = 'You have to choose a value here.';
$string['error:entervalue'] = 'You have to enter a value here.';

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
$string['addusertogroup'] = 'Add user to group: ';

// View.php.
$string['addmorebookings'] = 'Add more bookings';
$string['allowupdate'] = 'Allow booking to be updated';
$string['answered'] = 'Answered';
$string['dontaddpersonalevents'] = 'Dont add personal calendar events';
$string['dontaddpersonaleventsdesc'] = 'For each booked option and for all of its sessions, personal events are created in the moodle calendar. Suppressing them improves performance for heavy load sites.';
$string['attachical'] = 'Attach single iCal event per booking';
$string['attachicaldesc'] = 'Email notifications will include an attached iCal event, if this is enabled. The iCal will include only one start time and one end time either defined
in the booking option settings or start time of the first session to end time of the last session';
$string['attachicalsess'] = 'Attach all session dates as iCal events';
$string['attachicalsessdesc'] = 'Email notifications will include all session dates defined for a booking option as iCal attachment.';
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
$string['booking:comment'] = 'Add comments';
$string['booking:managecomments'] = 'Manage comments';
$string['bookingopeningtime'] = 'From';
$string['bookingclosingtime'] = 'Until';
$string['bookingfull'] = 'There are no available places';
$string['bookingname'] = 'Booking name';
$string['bookingoptionsmenu'] = 'Booking options';
$string['bookingopen'] = 'Open';
$string['bookingtext'] = 'Booking text';
$string['choose...'] = 'Choose...';
$string['datenotset'] = 'Date not set';
$string['daystonotify'] = 'Number of days in advance of the event-start to notify participants';
$string['daystonotify_help'] = "Will work only if start and end date of option are set! 0 for disabling this functionality.";
$string['daystonotify2'] = 'Second notification before start of event to notify participants.';
$string['daystonotifyteachers'] = 'Number of days in advance of the event-start to notify teachers (PRO)';
$string['bookinganswer_cancelled'] = 'Booking option cancelled for/by user';

// Booking option events.
$string['bookingoption_cancelled'] = "Booking option cancelled";
$string['bookingoption_booked'] = 'Booking option booked';
$string['bookingoption_completed'] = 'Booking option completed';
$string['bookingoption_created'] = 'Booking option created';
$string['bookingoption_updated'] = 'Booking option updated';
$string['bookingoption_deleted'] = 'Booking option deleted';

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
$string['removeresponses'] = 'Remove all responses';
$string['responses'] = 'Responses';
$string['responsesto'] = 'Responses to {$a} ';
$string['spaceleft'] = 'space available';
$string['spacesleft'] = 'spaces available';
$string['subscribersto'] = 'Teachers for \'{$a}\'';
$string['taken'] = 'Taken';
$string['timerestrict'] = 'Restrict answering to this time period: This is deprecated and will be removed. Please use "Restrict Access" settings for making the booking activity available for a certain period';
$string['restrictanswerperiodopening'] = 'Booking option is availably only after a certain date';
$string['restrictanswerperiodclosing'] = 'Booking option is available until a certain date';
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
$string['advancedoptions'] = 'Advanced options';
$string['btnbooknowname'] = 'Name of button: Book now';
$string['btncacname'] = 'Name of button: Confirm activity completion';
$string['btncancelname'] = 'Name of button: Cancel booking';
$string['courseurl'] = 'Course URL';
$string['description'] = 'Description';
$string['disablebookingusers'] = 'Disable booking of users - hide Book now button.';
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
$string['banusernames_help'] = 'To limit which usernames can`t apply just write in this field, and separate with coma. To ban usernames, that end with gmail.com and yahoo.com just write: gmail.com, yahoo.com';
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

// Events.
$string['bookingoptiondate_created'] = 'Booking option date created';
$string['bookingoptiondate_updated'] = 'Booking option date updated';
$string['bookingoptiondate_deleted'] = 'Booking option date deleted';
$string['custom_field_changed'] = 'Custom field changed';
$string['pricecategory_changed'] = 'Price category changed';
$string['reminder1_sent'] = 'First reminder sent';
$string['reminder2_sent'] = 'Second reminder sent';
$string['reminder_teacher_sent'] = 'Teacher reminder sent';

// View.php.
$string['bookingpolicyagree'] = 'I have read, understood and agree to the booking policy.';
$string['bookingpolicynotchecked'] = 'You have not accepted the booking policy.';
$string['allbookingoptions'] = 'Download users for all booking options';
$string['attachedfiles'] = 'Attached files';
$string['availability'] = 'Still available';
$string['available'] = 'Places available';
$string['booked'] = 'Booked';
$string['fullybooked'] = 'Fully booked';
$string['notifyme'] = 'Notify when available';
$string['alreadyonlist'] = 'You will be notified';
$string['bookedpast'] = 'Booked (course finished)';
$string['bookingdeleted'] = 'Your booking was cancelled';
$string['bookingmeanwhilefull'] = 'Meanwhile someone took already the last place';
$string['bookingsaved'] = 'Your booking was successfully saved. You can now proceed to book other courses.';
$string['booknow'] = 'Book now';
$string['bookotherusers'] = 'Book other users';
$string['cancelbooking'] = 'Cancel booking';
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
$string['mustfilloutuserinfobeforebooking'] = 'Befor proceeding to the booking form, please fill in some personal booking information';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['showallbookingoptions'] = 'All booking options';
$string['showmybookingsonly'] = 'My booked options';
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['confirmdeletebookingoption'] = 'Do you really want to delete this booking option?';
$string['norighttobook'] = 'Booking is not possible for your user role. Please contact the site administrator to give you the appropriate rights or enrol/sign in.';
$string['maxperuserwarning'] = 'You currently have used {$a->count} out of {$a->limit} maximum available bookings ({$a->eventtype}) for your user account';
$string['bookedpast'] = 'Booked (course terminated)';
$string['bookotherusers'] = 'Book other users';
$string['attachedfiles'] = 'Attached files';
$string['eventduration'] = 'Event duration';
$string['eventpoints'] = 'Points';
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['managebooking'] = 'Manage';
$string['mustfilloutuserinfobeforebooking'] = 'Befor proceeding to the booking form, please fill in some personal booking information';
$string['nobookingselected'] = 'No booking option selected';
$string['notbooked'] = 'Not yet booked';
$string['onwaitinglist'] = 'You are on the waiting list';
$string['organizatorname'] = 'Organizer name';
$string['organizatorname_help'] = 'You can either enter the organizer name manually or choose from a list of previous organizers.
                                    You can choose one organizer only. Once you save, the organizer will be added to the list.';
$string['availableplaces'] = 'Places available: {$a->available} of {$a->maxanswers}';
$string['pollurl'] = 'Poll url';
$string['pollurlteachers'] = 'Teachers poll url';
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
$string['banusernameswarning'] = "Your username is banned so you can't book.";
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
$string['textdependingonstatus'] = "Text depending on booking status";
$string['beforebookedtext'] = 'Before booked';
$string['beforecompletedtext'] = 'After booked';
$string['aftercompletedtext'] = 'After activity completed';
$string['connectedbooking'] = '[DEPRECATED] Connected booking';
$string['errorpagination'] = 'Please enter a number bigger than 0';
$string['notconectedbooking'] = 'Not connected';
$string['connectedbooking_help'] = 'Booking instance eligible for transferring booked users. You can define from which option within the selected booking instance and how many users you will accept.';
$string['allowbookingafterstart'] = 'Allow booking after course start';
$string['cancancelmyself'] = 'Allow users to cancel their booking themselves';
$string['cancancelbookdays'] = 'Disallow users to cancel their booking n days before start. Minus means, that users can still cancel n days AFTER course start.';
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
$string['sendcopytobookingmanger'] = 'Send confirmation e-mail to booking manager';
$string['bookingpolicy'] = 'Booking policy';

$string['page:bookingpolicy'] = 'Booking policy';
$string['page:bookitbutton'] = 'Book';
$string['page:subbooking'] = 'Supplementary bookings';
$string['page:confirmation'] = 'Booking complete';
$string['page:checkout'] = 'Checkout';

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
$string['noratings'] = 'Ratings disabled';
$string['allratings'] = 'Everybody can rate';
$string['enrolledratings'] = 'Only enrolled';
$string['completedratings'] = 'Only with completed activity';
$string['shorturl'] = 'Short URL of this option';
$string['generatenewurl'] = 'Generate new short url';
$string['notes'] = 'Booking notes';
$string['uploadheaderimages'] = 'Header images for booking options';
$string['bookingimagescustomfield'] = 'Booking option custom field to match the header images with';
$string['bookingimages'] = 'Upload header images for booking options - they need to have the exact same name as the value of the selected customfield in each booking option.';
$string['emailsettings'] = 'E-mail settings';

// Mail templates (instance specific or global).
$string['mailtemplatesadvanced'] = 'Activate advanced settings for e-mail templates';
$string['mailtemplatessource'] = 'Set source of mail templates (PRO)';
$string['mailtemplatessource_help'] = '<b>Caution:</b> If you choose global e-mail templates, the instance-specific mail
templates won\'t be used. Instead the e-mail templates specified in the booking plugin settings will be used. <br><br>
Please make sure that there are existing e-mail templates in the booking settings for each e-mail type.';
$string['mailtemplatesinstance'] = 'Use mail templates from this booking instance (default)';
$string['mailtemplatesglobal'] = 'Use global mail templates from plugin settings';

$string['addnewlocation'] = "Add new location";

$string['pollurlteachers_help'] = 'You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{numberparticipants} - Number of participants (without waiting list)</li>
<li>{numberwaitinglist} - Number of participants on the waiting list</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['pollurl_help'] = 'You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['bookedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['userleave_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['waitingtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['notifyemail_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['notifyemailteachers_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['statuschangetext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['deletedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['bookingchangedtext_help'] = 'Enter 0 to turn change notifications off.

You can use any of the following placeholders in the text:
<ul>
<li>{changes} - What has changed?</li>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['pollurltext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['pollurlteacherstext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['activitycompletiontext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['notificationtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['beforebookedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['beforecompletedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['aftercompletedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{email} - User email</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['placeholders_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status} - Booking status</li>
<li>{participant}</li>
<li>{profilepicture} - User\'s profile picture</li>
<li>{email} - User email</li>
<li>{title} - The title of the booking option</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
<li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{dates} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['helptext:placeholders'] = '<p>
<a data-toggle="collapse" href="#collapsePlaceholdersHelptext" role="button" aria-expanded="false" aria-controls="collapsePlaceholdersHelptext">
  <i class="fa fa-question-circle" aria-hidden="true"></i><span>&nbsp;You can use the following placeholders...</span>
</a>
</p>
<div class="collapse" id="collapsePlaceholdersHelptext">
  <div class="card card-body">
    <ul>
        <li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
        <li>{gotobookingoption} - Link to booking option</li>
        <li>{status} - Booking status</li>
        <li>{participant}</li>
        <li>{email} - User email</li>
        <li>{title}</li>
        <li>{duration}</li>
        <li>{starttime}</li>
        <li>{endtime}</li>
        <li>{startdate}</li>
        <li>{enddate}</li>
        <li>{courselink}</li>
        <li>{bookinglink}</li>
        <li>{pollurl}</li>
        <li>{pollurlteachers}</li>
        <li>{location}</li>
        <li>{institution}</li>
        <li>{address}</li>
        <li>{eventtype}</li>
        <li>{teacher} - Name of first teacher</li>
<li>{teachers} - List of all teachers</li>
        <li>{teacherN} - Name of specific teacher, e.g. {teacher1}</li>
        <li>{pollstartdate}</li>
        <li>{qr_id} - Insert QR code with user id</li>
        <li>{qr_username} - Insert QR code with user username</li>
        <li>{dates} - Session times</li>
        <li>{shorturl} - Short URL of option</li>
        <li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
        <li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
    </ul>
  </div>
</div>';

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
$string['signinhidedate'] = 'Hide date';
$string['includeteachers'] = 'Include teachers in the sign-in sheet';
$string['choosepdftitle'] = 'Select a title for the sign-in sheet';
$string['addtogroup'] = 'Automatically enrol users in group';
$string['addtogroup_help'] = 'Automatically enrol users in group - group will be created automatically with name: Bookin name - Option name';
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
$string['maxperuser_help'] = 'The maximum number of bookings an individual user can make in this activity at once. After an event end time has passed, it is no longer counted against this limit.';
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
$string['choosecourse'] = 'Choose a course';
$string['choosecourse_help'] = 'Choose "New course" if you want a new Moodle course to be created for this booking option.';
$string['courseendtime'] = 'End time of the course';
$string['coursestarttime'] = 'Start time of the course';
$string['newcourse'] = 'New course';
$string['donotselectcourse'] = 'No course selected';
$string['donotselectinstitution'] = 'No institution selected';
$string['donotselectlocation'] = 'No location selected';
$string['donotselecteventtype'] = 'No event type selected';
$string['importcsvbookingoption'] = 'Import CSV with booking options';
$string['importexcelbutton'] = 'Import activity completion';
$string['activitycompletiontext'] = 'Message to be sent to user when booking option is completed';
$string['activitycompletiontextsubject'] = 'Booking option completed';
$string['changesemester'] = 'Change Semester';
$string['changesemester:warning'] = '<strong>Be careful:</strong> By clicking "Save changes" all dates will be deleted
 and be replaced with dates of the chosen semester.';
$string['choosesemester'] = 'Choose Semester';
$string['activitycompletiontextmessage'] = 'You have completed the following booking option:

{$a->bookingdetails}

Go to course: {$a->courselink}
See all booking options: {$a->bookinglink}';
$string['sendmailtobooker'] = 'Book other users page: Send mail to user who books instead to users who are booked';
$string['sendmailtobooker_help'] = 'Activate this option in order to send booking confirmation mails to the user who books other users instead to users, who have been added to a booking option. This is only relevant for bookings made on the page "book other users".';
$string['startendtimeknown'] = 'Start and end time of course are known';
$string['submitandaddnew'] = 'Save and add new';
$string['waitinglisttaken'] = 'On the waiting list';
$string['groupexists'] = 'The group already exists in the target course, please choose another name for the booking option';
$string['groupdeleted'] = 'This booking instance creates groups automatically in the target course. But the group has been manually deleted in the target course. Activate the following checkbox in order to recreate the group';
$string['recreategroup'] = 'Recreate group in the target course and enrol users in group';
$string['copy'] = ' - Copy';
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
$string['toggleformmode_simple'] = '<i class="fa fa-compress" aria-hidden="true"></i> Switch to simple mode';
$string['toggleformmode_expert'] = '<i class="fa fa-expand" aria-hidden="true"></i> Switch to expert mode';

// Option_form.php.
$string['bookingoptionimage'] = 'Upload an image';
$string['submitandgoback'] = 'Save and go back';
$string['bookingoptionprice'] = 'Price';
$string['pricecategory'] = 'Price category';
$string['pricecurrency'] = 'Currency';
$string['optionvisibility'] = 'Visibility';
$string['optionvisibility_help'] = 'Here you can choose whether the option should be visible for everyone or if it should be hidden from normal users and be visible to entitled users only.';
$string['optionvisible'] = 'Visible to everyone (default)';
$string['optioninvisible'] = 'Hide from normal users (visible to entitled users only)';
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
$string['notifyemailteachers'] = 'Teacher notification before start (PRO)';

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
$string['sendcustommessage'] = 'Send custom message';
$string['sendpollurltoteachers'] = 'Send poll url';
$string['toomuchusersbooked'] = 'The max number of users you can book is: {$a}';
$string['userid'] = 'UserID';
$string['userssuccessfullenrolled'] = 'All users have been enrolled!';
$string['userssuccessfullybooked'] = 'All users have been booked to the other booking option.';
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
$string['copytotemplatesucesfull'] = 'Booking option was sucesfully saved as template.';


// Send message.
$string['booking:cansendmessages'] = 'Can send messages';
$string['activitycompletionsuccess'] = 'All selected users have been marked for activity completion';
$string['booking:communicate'] = 'Can communicate';
$string['confirmoptioncompletion'] = '(Un)confirm completion status';
$string['enablecompletion'] = 'At least one of the booked options has to be marked as completed';
$string['enablecompletiongroup'] = 'Require entries';
$string['messagesend'] = 'Your message has been sent.';
$string['messagesubject'] = 'Subject';
$string['messagetext'] = 'Message';

// Teachers_handler.php.
$string['teachersforoption'] = 'Teachers';
$string['teachersforoption_help'] = '<b>BE CAREFUL: </b>When adding teachers here, they will also be <b>added to EACH date</b> in the teaching report.
When deleting teachers here, they will be <b>removed from EACH date</b> in the teaching report!';
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
$string['addnewinstitution'] = 'Add new institution';

// Institutionform.class.php.
$string['institutionname'] = 'Institution name';
$string['addnewinstitution'] = 'Add new institution';
$string['successfulldeletedinstitution'] = 'Institution was deleted';
$string['csvfile_help'] = 'CSV file must contain only one column named Institution.';

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
$string['addcustomfield'] = 'Add custom field';
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
$string['daystonotifysession'] = 'Number of days in advance of the session start to notify participants';
$string['daystonotifysession_help'] = "Enter 0 to deactivate the e-mail notification for this session.";
$string['nocfnameselected'] = "Nothing selected. Either type new name or select one from the list.";

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
$string['globalcurrency'] = 'Currency';
$string['globalcurrencydesc'] = 'Choose the currency for booking option prices';
$string['globalmailtemplates'] = 'Global mail templates';
$string['globalmailtemplates_desc'] = 'Only available in the PRO version. After activation, you can go to the settings of a booking instance and set the source of mail templates to global.';
$string['globalbookedtext'] = 'Booking confirmation (global template)';
$string['globalwaitingtext'] = 'Waiting list confirmation (global template)';
$string['globalnotifyemail'] = 'Participant notification before start (global template)';
$string['globalnotifyemailteachers'] = 'Teacher notification before start (global template)';
$string['globalstatuschangetext'] = 'Status change message (global template)';
$string['globaluserleave'] = 'User has cancelled his/her own booking (global template)';
$string['globaldeletedtext'] = 'Cancelled booking message (global template)';
$string['globalbookingchangedtext'] = 'Message to be sent when a booking option changes (will only be sent to users who have already booked). Use the placeholder {changes} to show the changes. Enter 0 to turn off change notifications. (Global template)';
$string['globalpollurltext'] = 'Message for sending poll url to booked users (global template)';
$string['globalpollurlteacherstext'] = 'Message for the poll url sent to teachers (global template)';
$string['globalactivitycompletiontext'] = 'Message to be sent to user when booking option is completed (global template)';
$string['licensekeycfg'] = 'Activate PRO version';
$string['licensekeycfgdesc'] = 'With a PRO license you can create as many booking templates as you like and use PRO features such as global mail templates, waiting list info texts or teacher notifications.';
$string['licensekey'] = 'PRO license key';
$string['licensekeydesc'] = 'Upload a valid license key to activate the PRO version.';
$string['license_activated'] = 'PRO version activated successfully.<br>(Expires: ';
$string['license_invalid'] = 'Invalid license key';
$string['icalcfg'] = 'Configuration of the iCal attachements';
$string['icalcfgdesc'] = 'Configure the iCal.ics files that are attached to e-mail messages. These files alow adding the booking dates to the personal calendar.';
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

$string['showlistoncoursepagelbl'] = 'Show extra information on course page';
$string['showlistoncoursepagelbl_help'] = 'If you activate this setting, the course name, a short info and a button
                                            redirecting to the available booking options will be shown.';
$string['hidelistoncoursepage'] = 'Hide extra information on course page (default)';
$string['showcoursenameandbutton'] = 'Show course name, short info and a button redirecting to the available booking options';

$string['coursepageshortinfolbl'] = 'Short info';
$string['coursepageshortinfolbl_help'] = 'Choose a short info text to show on the course page.';
$string['coursepageshortinfo'] = 'If you want to book yourself for this course, click on "View available options", choose a booking option and then click on "Book now".';

$string['btnviewavailable'] = "View available options";

$string['signinextracols_heading'] = 'Additional columns on the sign-in sheet';
$string['signinextracols'] = 'Additional column';
$string['signinextracols_desc'] = 'You can print up to 3 additional columns on the sign-in sheet. Fill in the column title or leave it blank for no additional column';
$string['googleapikey'] = 'Google API key';
$string['googleapikey_desc'] = 'API key for Google URL Shortener. Get it here: https://developers.google.com/url-shortener/v1/getting_started#APIKey';
$string['numberrows'] = 'Number rows';
$string['numberrowsdesc'] = 'Number each row of the sign-in sheet. Number will be displayed left of the name in the same column';
$string['multiicalfiles'] = 'Attach one iCal file per date for MS Outlook 2010 compatibility';
$string['multiicalfilesdesc'] = 'Only MS Outlook 2010 does not support multiple dates within one iCal file. Previous and later version do support it (Ex. Outlook365). If you want to send MS Outlook compatible dates, then activate this option in order to attach multiple iCal files (one per date/event)';

$string['availabilityinfotexts_heading'] = 'Availability info texts for booking places and waiting list';
$string['availabilityinfotexts_desc'] = 'Only available in the PRO version.';
$string['bookingplacesinfotexts'] = 'Show availability info texts for booking places';
$string['bookingplacesinfotexts_info'] = 'Show short info messages instead of the number of available booking places.';
$string['waitinglistinfotexts'] = 'Show availability info texts for waiting list';
$string['waitinglistinfotexts_info'] = 'Show short info messages instead of the number of available waiting list places.';
$string['bookingplaceslowpercentage'] = 'Percentage for booking places low message';
$string['bookingplaceslowpercentagedesc'] = 'If the available booking places reach or get below this percentage a booking places low message will be shown.';
$string['waitinglistlowpercentage'] = 'Percentage for waiting list low message';
$string['waitinglistlowpercentagedesc'] = 'If the available places on the waiting list reach or get below this percentage a waiting list low message will be shown.';
$string['waitinglistlowmessage'] = 'Only a few waiting list places left!';
$string['waitinglistenoughmessage'] = 'Still enough waiting list places.';
$string['waitinglistfullmessage'] = 'Waiting list full.';
$string['bookingplaceslowmessage'] = 'Only a few places left!';
$string['bookingplacesenoughmessage'] = 'Still enough places available.';
$string['bookingplacesfullmessage'] = 'Fully booked.';
$string['eventalreadyover'] = 'This event is already over.';
$string['nobookingpossible'] = 'No booking possible.';

$string['pricecategories'] = 'Booking: Price categories';

$string['bookingpricesettings'] = 'Price settings';
$string['bookingpricesettings_desc'] = 'Here you can customize booking prices.';

$string['bookingpricecategory'] = 'Price category';
$string['bookingpricecategory_info'] = 'Define the name of the category, eg "students"';

$string['addpricecategory'] = 'Add price category';
$string['addpricecategory_info'] = 'You can add another price category';

$string['pricecategoryfieldoff'] = 'Do not show';
$string['pricecategoryfield'] = 'User profile field for price category';
$string['pricecategoryfielddesc'] = 'Choose the user profile field, which stores the price category identifier for each user.';

$string['useprice'] = 'Only book with price';

$string['teachingreportfortrainer'] = 'Report of performed teaching units for trainer';
$string['educationalunitinminutes'] = 'Length of an educational unit (minutes)';
$string['educationalunitinminutes_desc'] = 'Enter the length of an educational unit in minutes. It will be used to calculate the performed teaching units.';

$string['duplicationrestore'] = 'Duplication, backup and restore';
$string['duplicationrestoredesc'] = 'Here you can set which information you want to include when duplicating or backing up / restoring booking instances.';
$string['duplicationrestoreteachers'] = 'Include teachers';
$string['duplicationrestoreprices'] = 'Include prices';
$string['duplicationrestoreentities'] = 'Include entities';

$string['waitinglistheader'] = 'Waiting list';
$string['waitinglistheader_desc'] = 'Here you can set how the booking waiting list should behave.';
$string['turnoffwaitinglistaftercoursestart'] = 'Turn off automatic moving up from waiting list after a booking option has started.';

$string['notificationlist'] = 'Notification list';
$string['notificationlistdesc'] = 'When no place is available anymore, users can still register to be notified when there is an opening';
$string['usenotificationlist'] = 'Use notification list';

$string['subbookings'] = 'Subbookings (PRO)';
$string['subbookings_desc'] = 'Activate subbookings in order to enable the booking of additional items or time slots (e.g. for tennis courts).';
$string['showsubbookings'] = 'Activate subbookings';

$string['progressbars'] = 'Progress bars of time passed (PRO)';
$string['progressbars_desc'] = 'Get a visual representation of the time which has already passed for a booking option.';
$string['showprogressbars'] = 'Show progress bars of time passed';
$string['progressbarscollapsible'] = 'Make progress bars collapsible';

$string['bookingoptiondefaults'] = 'Default settings for booking options';
$string['bookingoptiondefaultsdesc'] = 'Here you can set default settings for the creation of booking options and lock them if needed.';
$string['addtocalendardesc'] = 'Course calendar events are visible to ALL users within a course. If you do not want them to be created at all,
you can turn this setting off and lock it by default. Don\'t worry: user calendar events for booked options will still be created anyways.';

$string['automaticcoursecreation'] = 'Automatic creation of Moodle courses (PRO)';
$string['newcoursecategorycfield'] = 'Booking option custom field to be used as course category';
$string['newcoursecategorycfielddesc'] = 'Choose a booking option custom field which will be used as course category for automatically created
 courses using the dropdown entry "New course" in the form for creating new booking options.';

// Mobile.
$string['next'] = 'Next';
$string['previous'] = 'Previous';

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
$string['linknotavailableyet'] = "The link to access the meeting is available only 15 minutes before the start until the end of the session.";
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
<a href="/admin/settings.php?section=modsettingbooking" target="_blank"><i class="fa fa-edit"></i> Edit formula...</a><br><br>
Below, you can additionally add a manual factor (multiplication) and an absolute value (addition) to be added to the formula.';
$string['priceformulamultiply'] = 'Manual factor';
$string['priceformulamultiply_help'] = 'Additional value to <strong>multiply</strong> the result with.';
$string['priceformulaadd'] = 'Absolute value';
$string['priceformulaadd_help'] = 'Additional value to <strong>add</strong> to the result.';
$string['priceformulaoff'] = 'Prevent recalculation of prices';
$string['priceformulaoff_help'] = 'Activate this option, in order to prevent the function "Calculate all prices from
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
$string['cachedef_bookingoptions'] = 'Booking options (cache)';
$string['cachedef_bookingoptionsanswers'] = 'Booking options answers (cache)';
$string['cachedef_bookingoptionstable'] = 'Tables of booking options with hashed sql queries (cache)';
$string['cachedef_cachedpricecategories'] = 'Booking price categories (cache)';
$string['cachedef_cachedprices'] = 'Prices in booking (cache)';
$string['cachedef_cachedbookinginstances'] = 'Booking instances (cache)';
$string['cachedef_bookingoptionsettings'] = 'Booking option settings (cache)';
$string['cachedef_cachedsemesters'] = 'Semesters (cache)';

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

// Teachers_instance_report.php.
$string['teachers_instance_report'] = 'Teachers report';
$string['error:invalidcmid'] = 'The report cannot be opened because no valid course module ID (cmid) was provided. It needs to be the cmid of a booking instance!';
$string['teachingreportforinstance'] = 'Teaching overview report for ';
$string['teachersinstancereport:subtitle'] = '<strong>Hint:</strong> The number of units of a course (booking option) is calculated by the duration of an educational unit
 which you can <a href="/admin/settings.php?section=modsettingbooking" target="_blank">set in the booking settings</a> and the specified date series string (e.g. "Tue, 16:00-17:30").
 For blocked events or booking options missing this string, the number of units cannot be calculated!';
$string['units'] = 'Units';
$string['sum_units'] = 'Sum of units';
$string['units_courses'] = 'Courses / Units';
$string['units_unknown'] = 'Number of units unknown';
$string['missinghours'] = 'Missing hours';
$string['substitutions'] = 'Substitution(s)';

// Optionformconfig.php / optionformconfig_form.php.
$string['optionformconfig'] = 'Booking: Configure booking option form';
$string['optionformconfigsaved'] = 'Configuration for the booking option form saved.';
$string['optionformconfigsubtitle'] = '<p>Turn off features you do not need, in order to make the booking option form more compact for your administrators.</p>
<p><strong>BE CAREFUL:</strong> Only deactivate fields if you are completely sure that you won\'t need them!</p>';
$string['optionformconfig:nobooking'] = 'You need to create at least one booking instance before you can use this form!';

// Tasks.
$string['task_remove_activity_completion'] = 'Booking: Remove activity completion';
$string['task_enrol_bookedusers_tocourse'] = 'Booking: Enrol booked users to course';
$string['task_send_completion_mails'] = 'Booking: Send completion mails';
$string['task_send_confirmation_mails'] = 'Booking: Send confirmation mails';
$string['task_send_notification_mails'] = 'Booking: Send notification mails';
$string['task_send_reminder_mails'] = 'Booking: Send reminder mails';
$string['task_send_mail_by_rule_adhoc'] = 'Booking: Send mail by rule (adhoc task)';
$string['task_clean_booking_db'] = 'Booking: Clean database';
$string['optionbookabletitle'] = '{$a->title} is available again';
$string['optionbookablebody'] = '{$a->title} is now available again. <a href="{$a->url}">Click here</a> to directly go there.<br><br>
(You receive this mail because you have clicked on the notification button for this option.)';

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
 So a unit factor of 2 will be applied to the price formula.';
$string['roundpricesafterformula'] = 'Round prices (price formula)';
$string['roundpricesafterformula_desc'] = 'If active, prices will be rounded to full numbers (no decimals) after the <strong>price formula</strong> has been applied.';

// Col_text_link.mustache.
$string['cancelallusers'] = 'Cancel booking for all users';

// Col_availableplaces.mustache.
$string['manageresponses'] = 'Manage bookings';

// Bo conditions.
$string['availabilityconditions'] = 'Availability conditions';

$string['bo_cond_alreadybooked'] = 'alreadybooked: Is already booked by this user';
$string['bo_cond_alreadyreserved'] = 'alreadyreserved: Has already been added to cart by this user';
$string['bo_cond_booking_time'] = 'Only bookable within a certain time';
$string['bo_cond_fullybooked'] = 'fullybooked: Fully booked';
$string['bo_cond_bookingpolicy'] = 'bookingpolicy: Confirm booking policy';
$string['bo_cond_notifymelist'] = 'notifymelist: Put me on a notify list';
$string['bo_cond_max_number_of_bookings'] = 'max_number_of_bookings: Maximum number of bookings per user reached';
$string['bo_cond_onwaitinglist'] = 'onwaitinglist: User is on waiting list';
$string['bo_cond_previouslybooked'] = 'User has previously booked a certain option';
$string['bo_cond_priceisset'] = 'priceisset: Price is set';
$string['bo_cond_userprofilefield_1_default'] = 'User profile field has a certain value';
$string['bo_cond_userprofilefield_2_custom'] = 'Custom user profile field has a certain value';
$string['bo_cond_isbookable'] = 'isbookable: Booking is allowed';
$string['bo_cond_isloggedin'] = 'isloggedin: User is logged in';
$string['bo_cond_fullybookedoverride'] = 'fullybookedoverride: Can be overbooked by staff';
$string['bo_cond_iscancelled'] = 'iscancelled: Booking option cancelled';
$string['bo_cond_subbooking_blocks'] = 'subbookingblocks: Subbooking blocks this Booking option';
$string['bo_cond_subbooking'] = 'subbooking: Subbooking adds additonal options to this Booking option';
$string['bo_cond_bookitbutton'] = 'bookitbutton: Show the normal booking button.';
$string['bo_cond_isloggedinprice'] = 'isloggedinprice: Show all prices when not logged in.';

$string['bo_cond_booking_time_available'] = 'Within normal booking times.';
$string['bo_cond_booking_time_not_available'] = 'Not within normal booking times.';
$string['bo_cond_booking_opening_time_not_available'] = 'Cannot be booked yet.';
$string['bo_cond_booking_opening_time_full_not_available'] = 'Can be booked from<br>{$a}.';
$string['bo_cond_booking_closing_time_not_available'] = 'Cannot be booked anymore.';
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
$string['bo_cond_fullybooked_full_not_available'] = 'Fully booked. Booking not possible anymore.';

$string['bo_cond_fullybookedoverride_available'] = 'Book it';
$string['bo_cond_fullybookedoverride_full_available'] = 'Booking is possible';
$string['bo_cond_fullybookedoverride_not_available'] = 'Fully booked';
$string['bo_cond_fullybookedoverride_full_not_available'] = 'Already fully booked, but you have the right to book a user anyways.';

$string['bo_cond_max_number_of_bookings_available'] = 'Book it';
$string['bo_cond_max_number_of_bookings_full_available'] = 'Booking is possible';
$string['bo_cond_max_number_of_bookings_not_available'] = 'Max number of bookings reached';
$string['bo_cond_max_number_of_bookings_full_not_available'] = 'User has reached the max number of bookings';

$string['bo_cond_onnotifylist_available'] = 'Book it';
$string['bo_cond_onnotifylist_full_available'] = 'Booking is possible';
$string['bo_cond_onnotifylist_not_available'] = 'Max number of bookings reached';
$string['bo_cond_onnotifylist_full_not_available'] = 'User has reached the max number of bookings';

$string['bo_cond_onwaitinglist_available'] = 'Book it';
$string['bo_cond_onwaitinglist_full_available'] = 'Booking is possible';
$string['bo_cond_onwaitinglist_not_available'] = 'Fully booked - You are on the waiting list';
$string['bo_cond_onwaitinglist_full_not_available'] = 'User is on waitinglist';

$string['bo_cond_priceisset_available'] = 'Book it';
$string['bo_cond_priceisset_full_available'] = 'Booking is possible';
$string['bo_cond_priceisset_not_available'] = 'You need to pay';
$string['bo_cond_priceisset_full_not_available'] = 'A price is set, payment required';

$string['bo_cond_userprofilefield_available'] = 'Book it';
$string['bo_cond_userprofilefield_full_available'] = 'Booking is possible';
$string['bo_cond_userprofilefield_not_available'] = 'Not allowed to book';
$string['bo_cond_userprofilefield_full_not_available'] = 'Only user with customfield {$a->profilefield} set to value {$a->value} are allowed to book.
    <br>But you have the right to book a user anyways.';

$string['bo_cond_customuserprofilefield_available'] = 'Book it';
$string['bo_cond_customuserprofilefield_full_available'] = 'Booking is possible';
$string['bo_cond_customuserprofilefield_not_available'] = 'Not allowed to book';
$string['bo_cond_customuserprofilefield_full_not_available'] = 'Only user with customfield {$a->profilefield} set to value {$a->value} are allowed to book.
    <br>But you have the right to book a user anyways.';

$string['bo_cond_previouslybooked_available'] = 'Book it';
$string['bo_cond_previouslybooked_full_available'] = 'Booking is possible';
$string['bo_cond_previouslybooked_not_available'] = 'Not allowed to book';
$string['bo_cond_previouslybooked_full_not_available'] = 'Only user who have previously booked this <a href="{$a}">option</a> are allowed to book.
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
$string['bo_cond_iscancelled_full_not_available'] = 'Cancelled - booking not possible';

$string['bo_cond_isloggedin_available'] = 'Book it';
$string['bo_cond_isloggedin_full_available'] = 'Booking is possible';
$string['bo_cond_isloggedin_not_available'] = 'Log in to book this option.';
$string['bo_cond_isloggedin_full_not_available'] = 'User is not logged in.';

$string['bo_cond_optionhasstarted_available'] = 'Book it';
$string['bo_cond_optionhasstarted_full_available'] = 'Booking is possible';
$string['bo_cond_optionhasstarted_not_available'] = 'Already started - booking is not possible anymore';
$string['bo_cond_optionhasstarted_full_not_available'] = 'Already started - booking for users not possible anymore';

$string['bo_cond_subbookingblocks_available'] = 'Book it';
$string['bo_cond_subbookingblocks_full_available'] = 'Booking is possible';
$string['bo_cond_subbookingblocks_not_available'] = 'Not allowed to book.';
$string['bo_cond_subbookingblocks_full_not_available'] = 'Subbooking blocks this booking option.';

// This does not really block, it just handels available subbookings.
$string['bo_cond_subbooking_available'] = 'Book it';
$string['bo_cond_subbooking_full_available'] = 'Booking is possible';
$string['bo_cond_subbooking_not_available'] = 'Book it';
$string['bo_cond_subbooking_full_not_available'] = 'Booking is possible';

// BO conditions in mform.
$string['userinfofieldoff'] = 'No user profile field selected';
$string['restrictwithuserprofilefield'] = 'A chosen user profile field should have a certain value';
$string['restrictwithpreviouslybooked'] = 'User has previously booked a certain option';
$string['bo_cond_userprofilefield_field'] = 'Profile field';
$string['bo_cond_userprofilefield_value'] = 'Value';
$string['bo_cond_userprofilefield_operator'] = 'Operator';

$string['restrictwithcustomuserprofilefield'] = 'A custom user profile field should have a certain value';
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

// Teacher_performed_units_report.php.
$string['error:wrongteacherid'] = 'Error: No user could be found for the provided "teacherid".';
$string['duration:minutes'] = 'Duration (minutes)';
$string['duration:units'] = 'Units ({$a} min)';
$string['teachingreportfortrainer:subtitle'] = '<strong>Hint:</strong> You can change the duration of
an educational unit in the plugin settings (e.g. 45 instead of 60 minutes).<br/>
<a href="/admin/settings.php?section=modsettingbooking" target="_blank">
&gt;&gt; Go to plugin settings...
</a>';
$string['error:missingteacherid'] = 'Error: Report cannot be loaded because of missing teacherid.';

// Teacher_performed_units_report_form.php.
$string['filterstartdate'] = 'From';
$string['filterenddate'] = 'Until';
$string['filterbtn'] = 'Filter';

// Booking rules.
$string['bookingrules'] = 'Booking: Define global rules (PRO)';
$string['bookingrule'] = 'Rule';
$string['addbookingrule'] = 'Add rule';
$string['deletebookingrule'] = 'Delete rule';
$string['deletebookingrule_confirmtext'] = 'Do you really want to delete the following rule?';

$string['rule_event'] = 'Event';
$string['rule_mailtemplate'] = 'Mail template';
$string['rule_datefield'] = 'Date field';
$string['rule_customprofilefield'] = 'Custom user profile field';
$string['rule_operator'] = 'Operator';
$string['rule_value'] = 'Value';
$string['rule_days'] = 'Number of days before';

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

$string['rule_daysbefore'] = 'Trigger n days before a certain date';
$string['rule_daysbefore_desc'] = 'Choose a date field of booking options and the number of days BEFORE that date.';
$string['rule_react_on_event'] = "React on event";
$string['rule_react_on_event_desc'] = "Choose an event that should trigger the rule.";

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

// Booking rules actions.
$string['bookingaction'] = "Action";

// Cancel booking option.
$string['canceloption'] = "Cancel boooking option";
$string['canceloption_desc'] = "Canceling a boooking option means that it is no longer bookabel, but it is still shown in list.";
$string['confirmcanceloption'] = "Confirm cancelation of booking option";
$string['confirmcanceloptiontitle'] = "Change the status of the booking option";
$string['cancelthisbookingoption'] = "Cancel this booking option";
$string['usergavereason'] = '{$a} gave the following reason for cancellation:';
$string['undocancelthisbookingoption'] = "Undo cancelling of this booking option";
$string['cancelreason'] = "Reason for cancelation of this booking option";
$string['undocancelreason'] = "Do you really want to undo the cancellation of this booking option?";
$string['nocancelreason'] = "You need to give a reason for canceling this booking option";

// Access.php.
$string['booking:bookforothers'] = "Book for others";

// Booking_handler.php.
$string['error:newcoursecategorycfieldmissing'] = 'You need to create a <a href="{$a->bookingcustomfieldsurl}" target="_blank">booking
 custom field</a> for new course categories first. After you have created one, make sure it is selected in the
 <a href="{$a->settingsurl}" target="_blank">Booking plugin settings</a>.';
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
$string['subbooking_additionalitem_desc'] = "This permits you to add optinally bookable items to this booking option, eg. you can book a better special seat etc. or breakfast to your hotel room.";
$string['subbooking_additionalitem_description'] = "Describe the additionally bookable item:";

$string['subbooking_additionalperson'] = "Additional person booking";
$string['subbooking_additionalperson_desc'] = "This permits you to add other persons to this booking option, e.g. to book a place for your family.";
$string['subbooking_additionalperson_description'] = "Describe the additional person booking option";

$string['subbooking_addpersons'] = "Add an additional person";
$string['subbooking_bookedpersons'] = "The following persons are added:";
$string['personnr'] = 'Person n° {$a}';
$string['age'] = 'Age';

$string['accept'] = "Accept";
$string['close'] = "Close";

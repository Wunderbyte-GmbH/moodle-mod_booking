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
$string['messageprovider:bookingconfirmation'] = "Booking confirmations";
$string['booking:manageoptiontemplates'] = "Manage option templates";

// Index.php.
$string['week'] = "Week";
$string['question'] = "Question";
$string['answer'] = "Answer";
$string['topic'] = "Topic";

// Teacher_added.php.
$string['eventteacher_added'] = 'Teacher added';
$string['eventteacher_removed'] = 'Teacher removed';

// Renderer.php.
$string['showonlymyinstitutions'] = "My institution";
$string['addusertogroup'] = 'Add user to group: ';

// View.php.
$string['addmorebookings'] = 'Add more bookings';
$string['addmorebookings'] = 'Add more bookings';
$string['allowupdate'] = 'Allow booking to be updated';
$string['answered'] = 'Answered';
$string['attachical'] = 'Attach single iCal event per booking';
$string['attachicaldesc'] = 'Email notifications will include an attached iCal event, if this is enabled. The iCal will include only one start time and one end time either defined
in the booking option settings or start time of the first session to end time of the last session';
$string['attachicalsess'] = 'Attach all session dates as iCal events';
$string['attachicalsessdesc'] = 'Email notifications will include all session dates defined for a booking option as iCal attachment.';
$string['icalcancel'] = 'Include iCal event when booking is cancelled as cancelled event';
$string['icalcanceldesc'] = 'When a users cancels a booking or is removed from the booked users list, then attach an iCal attachment as cancelled event.';
$string['booking'] = 'Booking';
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
$string['booking:comment'] = 'Add comments';
$string['booking:managecomments'] = 'Manage comments';
$string['bookingclose'] = 'Until';
$string['bookingfull'] = 'There are no available places';
$string['bookingname'] = 'Booking name';
$string['bookingoptionsmenu'] = 'Booking options';
$string['bookingopen'] = 'Open';
$string['bookingtext'] = 'Booking text';
$string['datenotset'] = 'Date not set';
$string['daystonotify'] = 'Number of days in advance of the event-start to notify participants';
$string['daystonotify_help'] = "Will work only if start and end date of option are set! 0 for disabling this functionality.";
$string['daystonotify2'] = 'Second notification before start of event to notify participants.';
$string['daystonotifyteachers'] = 'Number of days in advance of the event-start to notify teachers (PRO)';
$string['eventbooking_cancelled'] = 'Booking cancelled';
$string['eventbookingoption_booked'] = 'Booking option booked';
$string['eventbookingoption_completed'] = 'Booking option completed';
$string['bookingoption_created'] = 'Booking option created';
$string['bookingoption_updated'] = 'Booking option updated';
$string['bookingoption_deleted'] = 'Booking option deleted';
$string['eventreport_viewed'] = 'Report viewed';
$string['eventuserprofilefields_updated'] = 'Userprofile updated';
$string['existingsubscribers'] = 'Existing subscribers';
$string['expired'] = 'Sorry, this activity closed on {$a} and is no longer available';
$string['fillinatleastoneoption'] = 'You need to provide at least two possible answers.';
$string['full'] = 'Full';
$string['goenrol'] = 'Go to registration';
$string['gotop'] = 'Go to top';
$string['infonobookingoption'] = 'In order to add a booking option please use the settings block or the settings-icon on top of the page';
$string['limit'] = 'Limit';
$string['modulename'] = 'Booking';
$string['modulenameplural'] = 'Bookings';
$string['mustchooseone'] = 'You must choose an option before saving. Nothing was saved.';
$string['myoptions'] = 'Options I manage';
$string['noguestchoose'] = 'Sorry, guests are not allowed to enter data';
$string['noresultsviewable'] = 'The results are not currently viewable.';
$string['nosubscribers'] = 'There are no teachers assigned!';
$string['notopenyet'] = 'Sorry, this activity is not available until {$a} ';
$string['pluginadministration'] = 'Booking administration';
$string['pluginname'] = 'Booking';
$string['potentialsubscribers'] = 'Potential subscribers';
$string['removeresponses'] = 'Remove all responses';
$string['responses'] = 'Responses';
$string['responsesto'] = 'Responses to {$a} ';
$string['spaceleft'] = 'space available';
$string['spacesleft'] = 'spaces available';
$string['subscribersto'] = 'Teachers for \'{$a}\'';
$string['taken'] = 'Taken';
$string['teachers'] = 'Teachers';
$string['timerestrict'] = 'Restrict answering to this time period: This is deprecated and will be removed. Please use "Restrict Access" settings for making the booking activity available for a certain period';
$string['timecloseoption'] = 'Limit the availability of this booking option until a certain date';
$string['to'] = 'to';
$string['viewallresponses'] = 'Manage {$a} responses';
$string['yourselection'] = 'Your selection';

// Subscribeusers.php.
$string['cannotremovesubscriber'] = 'You have to remove the activity completion prior to cancel the booking. Booking was not cancelled!';
$string['allchangessave'] = 'All changes have been saved.';
$string['backtoresponses'] = '<< Back to responses';
$string['allusersbooked'] = 'All {$a} selected users have successfully been assigned to this booking option.';
$string['notallbooked'] = 'The following users could not be booked due to reaching the max number of bookings per user or lack of available places for the booking option: {$a}';
$string['enrolledinoptions'] = "alredy booked in booking options: ";

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
$string['howmanyusers'] = 'Max. number of users a teacher assigned to the option can book';
$string['howmanyusers_help'] = '';
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
$string['showhelpfullnavigationlinks'] = 'Show navigation links.';
$string['showhelpfullnavigationlinks_help'] = 'Show \'Go to registration\' and \'Go to top\' links.';
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

// View.php.
$string['agreetobookingpolicy'] = 'I have read and agree to the following booking policies';
$string['allbookingoptions'] = 'Download users for all booking options';
$string['attachedfiles'] = 'Attached files';
$string['availability'] = 'Still available';
$string['available'] = 'Places available';
$string['booked'] = 'Booked';
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
$string['createdby'] = 'Booking module created by Wunderbyte GmbH';
$string['deletebooking'] = 'Do you really want to unsubscribe from following course? <br /><br /> <b>{$a} </b>';
$string['deletebookingoption'] = 'Delete this booking option';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['download'] = 'Download';
$string['downloadusersforthisoptionods'] = 'Download users as .ods';
$string['downloadusersforthisoptionxls'] = 'Download users as .xls';
$string['endtimenotset'] = 'End date not set';
$string['mustfilloutuserinfobeforebooking'] = 'Befor proceeding to the booking form, please fill in some personal booking information';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['showallbookings'] = 'All bookings';
$string['showmybookingsonly'] = 'My bookings';
$string['showactive'] = 'Active bookings';
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['confirmdeletebookingoption'] = 'Do you really want to delete this booking option?';
$string['norighttobook'] = 'Booking is not possible for your user role. Please contact the site administrator to give you the appropriate rights or enrol/sign in.';
$string['createdby'] = 'Booking module created by Wunderbyte GmbH';
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
$string['showactive'] = 'Active bookings';
$string['showallbookings'] = 'All bookings';
$string['starttimenotset'] = 'Start date not set';
$string['subscribetocourse'] = 'Enrol users in the course';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['tagtemplates'] = 'Tag templates';
$string['unlimited'] = 'Number of available places is not limited';
$string['updatebooking'] = 'Edit this booking option';
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
$string['editteacherslink'] = 'Edit teachers';

// Mod_form.
$string['signinlogoheader'] = 'Logo in header to display on the sign-in sheet';
$string['signinlogofooter'] = 'Logo in footer to display on the sign-in sheet';
$string['bookingoptiontext'] = "Booking option text depending on booking status";
$string['beforebookedtext'] = 'Before booked';
$string['beforecompletedtext'] = 'After booked';
$string['aftercompletedtext'] = 'After activity completed';
$string['conectedbooking'] = 'Connected booking';
$string['errorpagination'] = 'Please enter a number bigger than 0';
$string['notconectedbooking'] = 'Not connected';
$string['conectedbooking_help'] = 'Booking instance eligible for transferring booked users. You can define from which option within the selected booking instance and how many users you will accept.';
$string['cancancelbook'] = 'Allow user to cancel the booking during the booking period?';
$string['cancancelbookdays'] = 'Disallow users to cancel their booking n days before start';
$string['cancancelbookdaysno'] = "Don't limit";
$string['addtocalendar'] = 'Add to calendar';
$string['caleventtype'] = 'Calendar event visibility';
$string['caldonotadd'] = 'Do not add to calendar';
$string['caladdascourseevent'] = 'Add to calendar (visible only to course participants)';
$string['caladdassiteevent'] = 'Add to calendar (visible to all users)';
$string['limitanswers'] = 'Limit the number of participants';
$string['maxparticipantsnumber'] = 'Max. number of participants';
$string['maxoverbooking'] = 'Max. number of places on waiting list';
$string['defaultbookingoption'] = 'Default booking options';
$string['activatemails'] = 'Activate e-mails (confirmations, notifications and more)';
$string['sendcopytobookingmanger'] = 'Send confirmation e-mail to booking manager';
$string['allowdelete'] = 'Allow users to cancel their booking themselves';
$string['bookingpolicy'] = 'Booking policy';
$string['confirmationmessagesettings'] = 'Confirmation e-mail settings';
$string['usernameofbookingmanager'] = 'Choose a booking manager';
$string['usernameofbookingmanager_help'] = 'Username of the user who will be displayed in the "From" field of the confirmation notifications. If the option "Send confirmation e-mail to booking manager" is enabled, this is the user who receives a copy of the confirmation notifications.';
$string['bookingmanagererror'] = 'The username entered is not valid. Either it does not exist or there are more then one users with this username (example: if you have mnet and local authentication enabled)';
$string['autoenrol'] = 'Automatically enrol users';
$string['autoenrol_help'] = 'If selected, users will be enrolled onto the relevant course as soon as they make the booking and unenrolled from that course as soon as the booking is cancelled.';
$string['bookedtext'] = 'Booking confirmation';
$string['userleave'] = 'User has cancelled his/her own booking';
$string['waitingtext'] = 'Waiting list confirmation';
$string['statuschangetext'] = 'Status change message';
$string['deletedtext'] = 'Cancelled booking message';
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
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['pollurl_help'] = 'You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['bookedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['userleave_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['waitingtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['notifyemail_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['notifyemailteachers_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['statuschangetext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['deletedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
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
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['pollurltext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['pollurlteacherstext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['activitycompletiontext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['notificationtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['beforebookedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['beforecompletedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['aftercompletedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option)</li>
<li>{gotobookingoption} - Link to booking option</li>
<li>{status}</li>
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
<li>{teacher}</li>
<li>{teacherN} - N is number of teacher ex. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - Insert QR code with user id</li>
<li>{qr_username} - Insert QR code with user username</li>
<li>{times} - Session times</li>
<li>{shorturl} - Short URL of option</li>
<li>{usercalendarurl} - Link to subscribe to user calendar (personal events)</li>
<li>{coursecalendarurl} - Link to subscribe to course calendar (course events)</li>
</ul>';

$string['fields'] = 'Fields to display in different contexts';
$string['reportfields'] = 'Downlodable responses fields (csv, xls-Download)';
$string['responsesfields'] = 'Fields on the manage responses page';
$string['optionsfields'] = 'Fields on the booking options overview page';
$string['signinsheetfields'] = 'Sign-in sheet fields (PDF)';
$string['signinonesession'] = 'Display selected session time on the sign-in sheet';
$string['signinaddemptyrows'] = 'Number of empty rows to add for people who did not sign up';
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
$string['customlabels'] = 'Custom labels';
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
$string['addeditbooking'] = 'Edit booking option';
$string['addnewbookingoption'] = 'Add a new booking option';
$string['addnewbookingoptionfromtemplate'] = 'Add a new booking option from template';
$string['choosecourse'] = 'Choose a course';
$string['courseendtime'] = 'End time of the course';
$string['coursestarttime'] = 'Start time of the course';
$string['donotselectcourse'] = 'No course selected';
$string['donotselectinstitution'] = 'No institution selected';
$string['donotselectlocation'] = 'No location selected';
$string['donotselecteventtype'] = 'No event type selected';
$string['importcsvbookingoption'] = 'Import CSV with booking options';
$string['importexcelbutton'] = 'Import activity completion';
$string['activitycompletiontext'] = 'Message to be sent to user when booking option is completed';
$string['activitycompletiontextsubject'] = 'Booking option completed';
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
$string['enrolmentstatus'] = 'Do not enrol users immediately but only at course start time';
$string['duplicatename'] = 'This booking option name already exists. Please choose another one.';
$string['newtemplatesaved'] = 'New template for booking option was saved.';
$string['manageoptiontemplates'] = 'Manage booking option templates';
$string['usedinbookinginstances'] = 'Template is used in following booking instances';
$string['optiontemplatename'] = 'Option template name';
$string['option_template_not_saved_no_valid_license'] = 'Booking option template could not be saved as template.
                                                  Upgrade to PRO version to save an unlimited number of templates.';

// Option_form.php.
$string['submitandgoback'] = 'Save and go back';
$string['bookingoptionprice'] = 'Price';
$string['pricecategory'] = 'Price category';
$string['pricecurrency'] = 'Currency';

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
$string['addteachers'] = 'Add teachers';
$string['allmailssend'] = 'All e-mails to the users have been sent!';
$string['associatedcourse'] = 'Associated course';
$string['bookedusers'] = 'Booked users';
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
$string['gotobooking'] = '<< Bookings';
$string['lblbooktootherbooking'] = 'Name of button: Book users to other booking';
$string['no'] = 'No';
$string['nocourse'] = 'No course selected for this booking option';
$string['nodateset'] = 'Course date not set';
$string['nousers'] = 'No users!';
$string['numrec'] = "Rec. num.";
$string['onlythisbookingurl'] = 'Link to this booking URL';
$string['optiondates'] = 'Multiple dates session';
$string['optionid'] = 'Option ID';
$string['optionmenu'] = 'This booking option';
$string['searchdate'] = 'Date';
$string['searchname'] = 'First name';
$string['searchsurname'] = 'Last name';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['copypollurl'] = 'Copy poll URL';
$string['gotobooking'] = '<< Bookings';
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
$string['waitinglist'] = 'Waiting list';
$string['searchwaitinglist'] = 'On waiting list';
$string['ratingsuccess'] = 'The ratings were successfully updated';
$string['userid'] = 'UserID';
$string['nodateset'] = 'Course date not set';
$string['editteachers'] = 'Edit';
$string['sendpollurltoteachers'] = 'Send poll url';
$string['copytoclipboard'] = 'Copy to clipboard: Ctrl+C, Enter';
$string['yes'] = 'Yes';
$string['sendreminderemailsuccess'] = 'Notification e-mail has been sent!';
$string['sign_in_sheet_download'] = 'Download Sign in Sheet';
$string['sign_in_sheet_download_show'] = 'Show Sign in sheet download form';
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
$string['copytotemplate'] = 'Copy to template';
$string['copytotemplatesucesfull'] = 'Booking option was sucesfully copied to template.';


// Send message.
$string['activitycompletionsuccess'] = 'All selected users have been marked for activity completion';
$string['booking:communicate'] = 'Can communicate';
$string['confirmoptioncompletion'] = '(Un)confirm completion status';
$string['enablecompletion'] = 'At least one of the booked options has to be marked as completed';
$string['enablecompletiongroup'] = 'Require entries';
$string['messagesend'] = 'Your message has been sent.';
$string['messagesubject'] = 'Subject';
$string['messagetext'] = 'Message';

// Teachers.php.
$string['users'] = '<< Manage responses';

// Lib.php.
$string['pollstrftimedate'] = '%Y-%m-%d';
$string['mybookings'] = 'My bookings';
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

// Optiondatesadd_form.php.
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
$string['pdfdate'] = 'Booking Date(s): ';
$string['pdflocation'] = 'Location: ';
$string['pdfroom'] = 'Room: ';
$string['pdfstudentname'] = "Student Name";
$string['pdfsignature'] = "Signature";
$string['pdftodaydate'] = 'Date: ';
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
$string['uniqueoptionnameheading'] = 'Unique option names';
$string['uniqueoptionnameheadingdesc'] = 'When using CSV import for booking options, option names need to be unique.
If there are multiple options with the same name, a unique key will be added internally to the option name.
Here you can define the separator between the option name and the key.

Example: Option name = "Option A", Separator = "#?#", idnumber = "00313" => Internal option name: "Option A#?#00313"';
$string['uniqueoptionnameseparator'] = 'Separator for unique option names';
$string['uniqueoptionnameseparatordesc'] = 'The separator must not contain blanks or be part of any existing booking option name.';
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

$string['showdescriptionmode'] = 'Choose description mode';
$string['showdescriptionmode_help'] = 'You can choose how to show descriptions: You can show them only after clicking on info links or inline - right inside the table.';
$string['showdescriptioninline'] = 'Show full descriptions inline (right inside the table)';
$string['showdescriptionmodal'] = 'Show info links (default)';

$string['showlistoncoursepagelbl'] = 'Show available booking options on course page';
$string['showlistoncoursepagelbl_help'] = 'If you activate this setting, a list of available booking options will be
                                            shown right on the course page below the link of the booking instance.
                                            You can also choose to show only the course name, a short info and a button
                                            redirecting to the available booking options.';
$string['showlistoncoursepage'] = 'Show list on course page (default)';
$string['hidelistoncoursepage'] = 'Hide list on course page';
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
$string['waitinglistlowmessage'] = 'Only a few places on the waiting list are left.';
$string['waitinglistenoughmessage'] = 'Still enough places on the waiting list available.';
$string['waitinglistfullmessage'] = 'No places available on the waiting list.';
$string['bookingplaceslowmessage'] = 'Only a few booking places are left.';
$string['bookingplacesenoughmessage'] = 'Still enough booking places available.';
$string['bookingplacesfullmessage'] = 'No booking places available anymore.';
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

// Mobile.
$string['next'] = 'Next';
$string['previous'] = 'Previous';
// Privacy API.
$string['privacy:metadata:booking_answers'] = 'Represents a booking to an event';
$string['privacy:metadata:booking_answers:userid'] = 'User that is booked for this event';
$string['privacy:metadata:booking_answers:bookingid'] = 'ID of the event';
$string['privacy:metadata:booking_answers:optionid'] = 'Specifies which version of an event, eg summerterm or winterterm';
$string['privacy:metadata:booking_answers:timemodified'] = 'Timestamp when booking was last modified';
$string['privacy:metadata:booking_answers:timecreated'] = 'Timestamp when booking was created';
$string['privacy:metadata:booking_answers:waitinglist'] = 'If this user is on a waitinglist';
$string['privacy:metadata:booking_answers:status'] = 'Statusinfo for this booking';
$string['privacy:metadata:booking_answers:notes'] = 'Additional notes';
$string['privacy:metadata:booking_ratings'] = 'Represents your rating of an event';
$string['privacy:metadata:booking_ratings:userid'] = 'User that rated this event';
$string['privacy:metadata:booking_ratings:optionid'] = 'Which version of an event was rated';
$string['privacy:metadata:booking_ratings:rate'] = 'Rate that was assigned';
$string['privacy:metadata:booking_teachers'] = 'Represents the teacher of an event';
$string['privacy:metadata:booking_teachers:userid'] = 'User that is teaching this event';
$string['privacy:metadata:booking_teachers:optionid'] = 'Which version of an event is taught';
$string['privacy:metadata:booking_teachers:completed'] = 'If task is completed';

// Calendar.php.
$string['usercalendarentry'] = 'You are booked for <a href="{$a}">this session</a>.';
$string['bookingoptioncalendarentry'] = '<a href="{$a}" class="btn btn-primary">Book now...</a>';

// Mybookings.php.
$string['status'] = 'Status';
$string['active'] = "Active";
$string['terminated'] = "Terminated";
$string['notstarted'] = "Not yet started";

// Subscribeusersctivity.php.
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
$string['bookinginstancetemplatessettings'] = 'Booking instance templates';
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
$string['autcrheader'] = 'Automatic booking option creation';
$string['autcrwhatitis'] = 'If this option is enabled it automatically creates a new booking option and assigns a user as booking manager / teacher to it. Users are selected based on a custom user profile field value.';
$string['enable'] = 'Enable';
$string['customprofilefield'] = 'Custom profile field to check';
$string['customprofilefieldvalue'] = 'Custom profile field value to check';
$string['optiontemplate'] = 'Option template';

// Link.php.
$string['bookingnotopenyet'] = 'Your event starts in {$a} minutes. The link you used will redirect you if you click it again within 15 minutes before.';
$string['bookingpassed'] = 'Your event has ended.';
$string['linknotvalid'] = 'You don\'t seem to be booked for this meeting';

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

// Bookingoptions_simple_table.php.
$string['bsttext'] = 'Booking option';
$string['bstcoursestarttime'] = 'Start time';
$string['bstcourseendtime'] = 'End time';
$string['bstlocation'] = 'Location';
$string['bstinstitution'] = 'Institution';
$string['bstparticipants'] = 'Participants';
$string['bstteacher'] = 'Teacher(s)';
$string['bstwaitinglist'] = 'On waiting list';
$string['bstmanageresponses'] = 'Manage responses';
$string['bstcourse'] = 'Course';
$string['bstlink'] = 'Show';

// All_options.php.
$string['infoalreadybooked'] = '<div class="infoalreadybooked"><i>You are already booked for this option.</i></div>';
$string['infowaitinglist'] = '<div class="infowaitinglist"><i>You are on the waiting list for this option.</i></div>';

// Shortcodes.
$string['shortcodeslistofbookingoptions'] = 'List of booking options';
$string['shortcodessetdefaultinstance'] = 'Set default instance for shortcodes implementation';
$string['shortcodessetdefaultinstancedesc'] = 'This allows you to change instances quickly when you want to change
a lot of them at once. One example would be that you have a lot of teaching categories and they are listed on different
pages, but you need to change the booking options form one semester to the next.';
$string['shortcodessetinstance'] = 'Set the moodle ID of the booking instance which should be used by default';
$string['shortcodessetinstancedesc'] = 'If you use this setting, you can use the shortcode like this: [listofbookings category="philosophy"]
So no need to specify the ID';

$string['tableheader_text'] = 'Course name';
$string['tableheader_teacher'] = 'Teacher(s)';
$string['tableheader_maxanswers'] = 'Available places';
$string['tableheader_maxoverbooking'] = 'Waiting list places';
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

// Semesters.php.
$string['booking:semesters'] = 'Booking: Semesters';
$string['semesters'] = 'Semesters';
$string['semesterssaved'] = 'Semesters have been saved';
$string['semesterssubtitle'] = '<p>Here you can define different semesters.</p>';
$string['addsemester'] = 'Add a new semester';
$string['semesteridentifier'] = 'Semester identifier';
$string['semesteridentifier_help'] = 'Short text to identify the semester, e.g. "ws22". You cannot change a semester identifier once it is created.
However, you can just delete the semester and create a new one if necessary.';
$string['semestername'] = 'Semester name';
$string['semestername_help'] = 'Enter the full name of the semester, e.g. "Semester of Winter 2021/22"';
$string['semesterstart'] = 'First day of semester';
$string['semesterstart_help'] = 'The day the semester starts.';
$string['semesterend'] = 'Last day of semester';
$string['semesterend_help'] = 'The day the semester ends';
$string['deletesemester'] = 'Delete this semester entry';
$string['deletesemester_help'] = 'This will only delete this semester entry, it will not affect any booking options.';
$string['erroremptysemesteridentifier'] = 'Semester identifier is needed!';
$string['erroremptysemestername'] = 'Semester name is not allowed to be empty';
$string['errorduplicatesemesteridentifier'] = 'Semester identifiers need to be unique.';
$string['errorduplicatesemestername'] = 'Semester names need to be unique.';
$string['errorsemesterstart'] = 'Semester start needs to be before semester end.';
$string['errorsemesterend'] = 'Semester end needs to be after semester start.';

// Cache.
$string['cachedef_bookingoptions'] = 'General information of booking options';
$string['cachedef_bookingoptionsanswers'] = 'Booking status of single options';
$string['cachedef_bookingoptionstable'] = 'Tables of booking options with hashed sql queries';
$string['cachedef_cachedpricecategories'] = 'Price categories Booking';
$string['cachedef_cachedprices'] = 'Standard prices in Booking';
$string['cachedef_cachedbookinginstances'] = 'Booking instances cache';
$string['cachedef_bookingoptionsettings'] = 'Booking option settings cache';

// Shortcodes.
$string['search'] = 'Search...';

// Optiondates_handler.php.
$string['datesforsemester'] = 'Create semester dates';
$string['reocurringdatestring'] = 'Reocurring dates';

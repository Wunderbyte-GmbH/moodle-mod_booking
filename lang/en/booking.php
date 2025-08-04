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

$string['aboutmodaloptiondateform'] = 'Create custom dates
(e.g. for blocked events or for single dates that differ from the date series).';
$string['accept'] = 'Accept';
$string['accessdenied'] = 'Access denied';
$string['actionoperator'] = 'Action';
$string['actionoperator:adddate'] = 'Add date';
$string['actionoperator:set'] = 'Replace';
$string['actionoperator:subtract'] = 'Subtract';
$string['actions'] = 'Actions';
$string['activatemails'] = 'Activate e-mails (confirmations, notifications and more)';
$string['active'] = "Active";
$string['activebookingoptions'] = 'Active booking options';
$string['activitycompletionsuccess'] = 'All selected users have been marked for activity completion';
$string['activitycompletiontext'] = 'Message to be sent to user when booking option is completed';
$string['activitycompletiontextmessage'] = 'You have completed the following booking option:
{$a->bookingdetails}
Go to course: {$a->courselink}
See all booking options: {$a->bookinglink}';
$string['activitycompletiontextsubject'] = 'Booking option completed';
$string['addastemplate'] = 'Add as template';
$string['addbookingcampaign'] = 'Add campaign';
$string['addbookingrule'] = 'Add rule';
$string['addcategory'] = 'Edit categories';
$string['addcomment'] = 'Add a comment...';
$string['addcustomfieldorcomment'] = 'Add a comment or custom field';
$string['adddatebutton'] = "Add date";
$string['addedrecords'] = '{$a} record(s) added.';
$string['addholiday'] = 'Add holiday(s)';
$string['additionalpricecategories'] = 'Add or edit price categories';
$string['addmorebookings'] = 'Add more bookings';
$string['addnewcategory'] = 'Add new category';
$string['addnewreporttemplate'] = 'Add new report template';
$string['addnewtagtemplate'] = 'Add new tag template';
$string['addoptiondate'] = 'Add date';
$string['addoptiondateseries'] = 'Create date series';
$string['addpricecategory'] = 'Add price category';
$string['addpricecategoryinfo'] = 'You can add another price category';
$string['address'] = 'Address';
$string['addsemester'] = 'Add semester';
$string['addtocalendar'] = 'Add to course calendar';
$string['addtocalendardesc'] = 'Course calendar events are visible to ALL users within a course. If you do not want them to be created at all,
you can turn this setting off and lock it by default. Don\'t worry: user calendar events for booked options will still be created anyways.';
$string['addtogroup'] = 'Automatically enrol users in group of linked course';
$string['addtogroup_help'] = 'Automatically enrol users in group that is linked in settings of bookingoption. Group will be created automatically with name: Booking name - Option name';
$string['addtogroupofcurrentcourse'] = 'Automatically enrol users to group(s) of current course';
$string['addtogroupofcurrentcourse_help'] = "Choose group(s) of current course the users should be enroled to, once they booked at least one of the bookingoptions in this instance. Groups need to be created first within is course.</br>
It's also possible to enrol users into a specific group for each bookingoption the have booked. These groups will are named after the bookingoption";
$string['addtogroupofcurrentcoursebookingoption'] = "Enrol in specific group for each booked option";
$string['addusertogroup'] = 'Add user to group: ';
$string['adminparameter_desc'] = "Use parameter that are set in the admin settings.";
$string['adminparametervalue'] = "Admin parameter";
$string['advancedoptions'] = 'Advanced options';
$string['aftercompletedtext'] = 'After activity completed';
$string['aftercompletedtext_help'] = 'Message shown after activity was completed';
$string['aftersubmitaction'] = 'After saving...';
$string['age'] = 'Age';
$string['alertrecalculate'] = '<b>Caution!</b> All prices will be recalculated and all old prices will be overwritten.';
$string['allbookingoptions'] = 'Download users for all booking options';
$string['allchangessaved'] = 'All changes have been saved.';
$string['allcohortsmustbefound'] = 'User has to be member of all cohorts';
$string['allcomments'] = 'Everybody can comment';
$string['allcoursesmustbefound'] = 'User has to be subscribed to all courses';
$string['allmailssend'] = 'All e-mails to the users have been sent!';
$string['allmoodleusers'] = 'All users of this site';
$string['alloptionsinreport'] = 'One report for a booking activity <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['alloptionsinreportdesc'] = 'The report of one booking option will include all the bookings of all booking options within this instance.';
$string['allowbookingafterstart'] = 'Allow booking after course start';
$string['allowoverbooking'] = 'Allow overbooking';
$string['allowoverbookingheader'] = 'Overbooking of booking options <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['allowoverbookingheader_desc'] = 'Allow administrators and entitled users to overbook booking options.
  (Be careful: This can lead to unexpected behavior. Only activate this if you really need it.)';
$string['allowupdate'] = 'Allow booking to be updated';
$string['allowupdatedays'] = 'Days before reference date';
$string['allratings'] = 'Everybody can rate';
$string['allteachers'] = 'All teachers';
$string['allteacherspagebookinginstances'] = 'On "All teachers" page, show only teachers who are teaching in one of these selected Booking instances (Choose "No selection" to show ALL teachers).';
$string['allusercertificates'] = 'Certificates of a user';
$string['allusersbooked'] = 'All {$a} selected users have successfully been assigned to this booking option.';
$string['alreadybooked'] = 'Already booked';
$string['alreadyonlist'] = 'You will be notified';
$string['alreadypassed'] = 'Already passed';
$string['always'] = 'Always';
$string['alwaysshowlinkondetailspage'] = 'Always show link on details page';
$string['alwaysshowlinkondetailspage_desc'] = 'Details page can be reached via the link on the title or the header image. But this will add an additional button.';
$string['andotherfield'] = "AND additional field";
$string['annotation'] = 'Internal annotation';
$string['answer'] = "Answer";
$string['answered'] = 'Answered';
$string['answerscount'] = "Number of bookings";
$string['appearancesettings'] = 'Appearance <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['appearancesettings_desc'] = 'Configure the appearance of the booking plugin.';
$string['apply'] = 'Apply';
$string['applybookingrules'] = 'Apply booking rules';
$string['applyunitfactor'] = 'Apply unit factor';
$string['applyunitfactor_desc'] = 'If this setting is active, the educational unit length (e.g. 45 min) set above will be used
 to calculate the number of educational units. This number will be used as factor for the price formula.
 Example: A booking option has a date series like "Mon, 15:00 - 16:30". So it lasts 2 educational units (45 min each).
 So a unit factor of 2 will be applied to the price formula. (Unit factor will only be applied if a price formula is present.)';
$string['applyuserwhobookedcheckbox'] = 'Yes, I am booking the training also for myself.';
$string['areyousure:book'] = 'Click again to confirm booking';
$string['areyousure:cancel'] = 'Click again to confirm cancellation';
$string['asglobaltemplate'] = 'Use as global template';
$string['assesstimefinish'] = 'End of the assessment period';
$string['assesstimestart'] = 'Start of the assessment period';
$string['assignteachers'] = 'Assign teachers:';
$string['associatedcourse'] = 'Associated course';
$string['astemplate'] = 'Use as template in this course';
$string['attachedfiles'] = 'Attached files';
$string['attachicalfile'] = 'Attach iCal file';
$string['attachicalfile_desc'] = 'Attach iCal files containing the date(s) of the booking option to e-mails.';
$string['attachment'] = 'Attachments';
$string['autcrheader'] = '[DEPRECATED] Automatic booking option creation';
$string['autcrwhatitis'] = 'If this option is enabled it automatically creates a new booking option and assigns
 a user as booking manager / teacher to it. Users are selected based on a custom user profile field value.';
$string['autoenrol'] = 'Automatically enrol users in connected course';
$string['autoenrol_help'] = 'If selected, users will be enrolled onto the relevant course as soon as they make the booking and unenrolled from that course as soon as the booking is cancelled.';
$string['automaticbookingoptioncompletion'] = 'Bookingoption completed when course is completed';
$string['automaticbookingoptioncompletion_desc'] = 'If enabled the bookingoption is set to completed when linked moodle course is set completed.';
$string['automaticcoursecreation'] = 'Automatic creation of Moodle courses <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['availability'] = 'Availability';
$string['availabilityconditions'] = 'Availability conditions';
$string['availabilityconditionsheader'] = '<i class="fa fa-fw fa-key" aria-hidden="true"></i>&nbsp;Availability conditions';
$string['availabilityinfotextsheading'] = 'Availability info texts for booking places and waiting list <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['available'] = 'Places available';
$string['availableplaces'] = 'Places available: {$a->available} of {$a->maxanswers}';
$string['availplacesfull'] = 'Full';
$string['back'] = 'Back';
$string['backtoresponses'] = '&lt;&lt; Back to responses';
$string['badge:exp'] = '<span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Experimental</span>';
$string['badge:pro'] = '<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['banusernames'] = 'Ban usernames';
$string['banusernames_help'] = 'To limit which usernames can`t apply just write in this field, and separate with coma. To ban usernames, that end with gmail.com and yahoo.com just write: gmail.com, yahoo.com';
$string['banusernameswarning'] = "Your username is banned so you can't book.";
$string['beforebookedtext'] = 'Before booked';
$string['beforebookedtext_help'] = 'Message shown before option being booked';
$string['beforecompletedtext'] = 'After booked';
$string['beforecompletedtext_help'] = 'Message shown after option become booked';
$string['bigbluebuttonmeeting'] = 'BigBlueButton meeting';
$string['biggerthan'] = 'is bigger than (number)';
$string['billboardtext'] = 'Text to be displayed instead of blocking alert text';
$string['blockabove'] = 'Block above';
$string['blockalways'] = 'Block regardless of places';
$string['blockbelow'] = 'Block below';
$string['blockinglabel'] = 'Message when blocking';
$string['blockinglabel_help'] = 'Enter the message that should be shown, when booking is blocked.
If you want to localize this message, you can use
<a href="https://docs.moodle.org/403/en/Multi-language_content_filter" target="_blank">language filters</a>.';
$string['blockoperator'] = 'Operator';
$string['blockoperator_help'] = '<b>Block above</b> ... Online booking will be blocked once the given percentage
of bookings is reached. Booking will only be possible for a cashier or admin afterwards.<br>
<b>Block below</b> ... Online booking will be blocked until the given percentage
of bookings is reached. Before that happens, booking is only possible for cashier or admin.';
$string['boactioncancelbookingdesc'] = "Used for options which can be bought multiple times";
$string['boactioncancelbookingvalue'] = "Activate immediate cancellation";
$string['boactionname'] = "Name of action";
$string['boactions'] = 'Actions after booking <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span> <span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Experimental</span>';
$string['boactions_desc'] = "Booking actions after booking are still an EXPERIMENTAL feature. You can try them if you want.
But do not use them in a productive environment yet!";
$string['boactionselectuserprofilefield'] = "Choose profile field";
$string['boactionuserprofilefieldvalue'] = 'Value';
$string['bocondallowedtobookininstance'] = 'Booking should be possible without special capability';
$string['bocondallowedtobookininstanceanyways'] = "Users are allowed to book without the capability '<b>mod/booking:choose</b>'.<br>
<div class='text-danger'>Note: Both this checkbox and the upper checkbox need to be checked if you want to activate this.</div>";
$string['bocondallowedtobookininstanceavailable'] = 'Book it';
$string['bocondallowedtobookininstancefullavailable'] = 'Booking is possible';
$string['bocondallowedtobookininstancefullnotavailable'] = 'No right to book on this booking instance';
$string['bocondallowedtobookininstancenotavailable'] = 'No right to book';
$string['bocondalreadybooked'] = 'alreadybooked: Is already booked by this user';
$string['bocondalreadybookedavailable'] = 'Not yet booked';
$string['bocondalreadybookedfullavailable'] = 'The user has not yet booked';
$string['bocondalreadybookedfullnotavailable'] = 'Booked';
$string['bocondalreadybookednotavailable'] = 'Booked';
$string['bocondalreadyreserved'] = 'alreadyreserved: Has already been added to cart by this user';
$string['bocondalreadyreservedavailable'] = 'Not yet added to cart';
$string['bocondalreadyreservedfullavailable'] = 'Not yet added to cart';
$string['bocondalreadyreservedfullnotavailable'] = 'Added to cart';
$string['bocondalreadyreservednotavailable'] = 'Added to cart';
$string['bocondaskforconfirmation'] = 'askforconfirmation: Manually confirm booking';
$string['bocondaskforconfirmationavailable'] = 'Book it';
$string['bocondaskforconfirmationfullavailable'] = 'Booking is possible';
$string['bocondaskforconfirmationfullnotavailable'] = 'Book it - on waitinglist';
$string['bocondaskforconfirmationnotavailable'] = 'Book it - on waitinglist';
$string['bocondbookingclosingtimefullnotavailable'] = 'Cannot be booked anymore (ended on {$a}).';
$string['bocondbookingclosingtimenotavailable'] = 'Cannot be booked anymore (ended on {$a}).';
$string['bocondbookingopeningtimefullnotavailable'] = 'Can be booked from {$a}.';
$string['bocondbookingopeningtimenotavailable'] = 'Can be booked from {$a}.';
$string['bocondbookingpolicy'] = 'Booking policy';
$string['bocondbookingtime'] = 'Only bookable within a certain time';
$string['bocondbookingtimeavailable'] = 'Within normal booking times.';
$string['bocondbookingtimenotavailable'] = 'Not within normal booking times.';
$string['bocondbookitbutton'] = 'bookitbutton: Show the normal booking button.';
$string['bocondcapbookingchooseavailable'] = 'Right to book';
$string['bocondcapbookingchoosefullavailable'] = 'Right to book on this booking instance';
$string['bocondcapbookingchoosefullnotavailable'] = 'No right to book on this booking instance';
$string['bocondcapbookingchoosenotavailable'] = 'No right to book';
$string['bocondcustomform'] = 'Fill out form';
$string['bocondcustomformavailable'] = 'Book it';
$string['bocondcustomformdeleteinfoscheckboxuser'] = 'Checkbox to delete answers';
$string['bocondcustomformdeleteinfoscheckboxusertext'] = 'Would you like the information provided here to be deleted after the event is over?';
$string['bocondcustomformfullavailable'] = 'Booking is possible';
$string['bocondcustomformfullnotavailable'] = 'Booking is not possible';
$string['bocondcustomformfullybooked'] = 'The option "{$a}" is already fully booked.';
$string['bocondcustomformlabel'] = "Label";
$string['bocondcustomformmail'] = "E-Mail";
$string['bocondcustomformmailerror'] = "The email address is invalid.";
$string['bocondcustomformnotavailable'] = 'Book it';
$string['bocondcustomformnotempty'] = 'Must not be empty';
$string['bocondcustomformnumberserror'] = "Please insert a valid number of days.";
$string['bocondcustomformrestrict'] = 'Form needs to be filled out before booking';
$string['bocondcustomformstillavailable'] = "still available";
$string['bocondcustomformurl'] = "Url";
$string['bocondcustomformurlerror'] = "The URL is not valid or does not start with http or https.";
$string['bocondcustomformvalue'] = 'Value';
$string['bocondcustomformvalue_help'] = 'When a dropdown menu is selected, please enter one value per line. The values and displayed values can be entered separately, for example, "1 => My first value => number_of_availability" etc.';
$string['bocondcustomuserprofilefieldavailable'] = 'Book it';
$string['bocondcustomuserprofilefieldconnectsecondfield'] = 'Connect with other profile field';
$string['bocondcustomuserprofilefieldfield'] = 'Profile field';
$string['bocondcustomuserprofilefieldfield2'] = 'Second profile field';
$string['bocondcustomuserprofilefieldfullavailable'] = 'Booking is possible';
$string['bocondcustomuserprofilefieldfullnotavailable'] = 'Only users with custom user profile field {$a->profilefield} set to value {$a->value} are allowed to book.
    <br>But you have the right to book a user anyways.';
$string['bocondcustomuserprofilefieldnotavailable'] = 'Not allowed to book';
$string['bocondcustomuserprofilefieldoperator'] = 'Operator';
$string['bocondcustomuserprofilefieldoperator2'] = 'Operator (2nd field)';
$string['bocondcustomuserprofilefieldvalue'] = 'Value';
$string['bocondcustomuserprofilefieldvalue2'] = 'Value (2nd field)';
$string['bocondenrolledincohorts'] = 'User is enrolled in certain cohort(s)';
$string['bocondenrolledincohortsavailable'] = 'Book it';
$string['bocondenrolledincohortsfullavailable'] = 'Booking is possible';
$string['bocondenrolledincohortsfullnotavailable'] = 'Only users who are enrolled in at least one of the following cohort(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bocondenrolledincohortsfullnotavailableand'] = 'Only users who are enrolled in all of the following cohort(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bocondenrolledincohortsnotavailable'] = 'Booking not allowed because you are not enrolled in at least one of the following cohort(s): {$a}';
$string['bocondenrolledincohortsnotavailableand'] = 'Booking not allowed because you are not enrolled in all of the following cohort(s): {$a}';
$string['bocondenrolledincohortswarning'] = 'You have a very high number of cohorts on your system. Not all of them will be available here. If that is a problem for you, please contact <a mailto="info@wunderyte.at">Wunderbyte</a>';
$string['bocondenrolledincourse'] = 'User is enrolled in certain course(s)';
$string['bocondenrolledincourseavailable'] = 'Book it';
$string['bocondenrolledincoursefullavailable'] = 'Booking is possible';
$string['bocondenrolledincoursefullnotavailable'] = 'Only users who are enrolled in at least one of the following course(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bocondenrolledincoursefullnotavailableand'] = 'Only users who are enrolled in all of the following course(s) are allowed to book: {$a}
    <br>But you have the right to book a user anyways.';
$string['bocondenrolledincoursenotavailable'] = 'Booking not allowed because you are not enrolled in at least one of the following course(s): {$a}';
$string['bocondenrolledincoursenotavailableand'] = 'Booking not allowed because you are not enrolled in all of the following course(s): {$a}';
$string['bocondfullybooked'] = 'Fully booked';
$string['bocondfullybookedavailable'] = 'Book it';
$string['bocondfullybookedfullavailable'] = 'Booking is possible';
$string['bocondfullybookedfullnotavailable'] = 'Fully booked';
$string['bocondfullybookednotavailable'] = 'Fully booked';
$string['bocondfullybookedoverride'] = 'fullybookedoverride: Can be overbooked by staff';
$string['bocondfullybookedoverrideavailable'] = 'Book it';
$string['bocondfullybookedoverridefullavailable'] = 'Booking is possible';
$string['bocondfullybookedoverridefullnotavailable'] = 'Fully booked - but you have the right to book a user anyways.';
$string['bocondfullybookedoverridenotavailable'] = 'Fully booked';
$string['bocondinstanceavailability'] = 'instanceavailability: Access restrictions';
$string['bocondinstanceavailabilityavailable'] = 'Book it';
$string['bocondinstanceavailabilityfullavailable'] = 'Booking is possible';
$string['bocondinstanceavailabilityfullnotavailable'] = '<a href="{$a}" target="_blank">Booking instance access restrictions</a> not met.<br>
But you have the right to book a user anyways.';
$string['bocondinstanceavailabilitynotavailable'] = 'Booking not possible';
$string['bocondisbookable'] = 'isbookable: Booking is allowed';
$string['bocondisbookableavailable'] = 'Book it';
$string['bocondisbookablefullavailable'] = 'Booking is possible';
$string['bocondisbookablefullnotavailable'] = 'Booking is forbidden for this booking option.
    <br>But you have the right to book a user anyways.';
$string['bocondisbookablenotavailable'] = 'Not allowed to book';
$string['bocondiscancelled'] = 'iscancelled: Booking option cancelled';
$string['bocondiscancelledavailable'] = 'Book it';
$string['bocondiscancelledfullavailable'] = 'Booking is possible';
$string['bocondiscancelledfullnotavailable'] = 'Cancelled';
$string['bocondiscancellednotavailable'] = 'Cancelled';
$string['bocondisloggedin'] = 'isloggedin: User is logged in';
$string['bocondisloggedinavailable'] = 'Book it';
$string['bocondisloggedinfullavailable'] = 'Booking is possible';
$string['bocondisloggedinfullnotavailable'] = 'User is not logged in.';
$string['bocondisloggedinnotavailable'] = 'Log in to book this option.';
$string['bocondisloggedinprice'] = 'isloggedinprice: Show all prices when not logged in.';
$string['bocondmaxnumberofbookings'] = 'max_number_of_bookings: Maximum number of bookings per user reached';
$string['bocondmaxnumberofbookingsavailable'] = 'Book it';
$string['bocondmaxnumberofbookingsfullavailable'] = 'Booking is possible';
$string['bocondmaxnumberofbookingsfullnotavailable'] = 'User has reached the max number of bookings';
$string['bocondmaxnumberofbookingsnotavailable'] = 'Max. number of bookings reached';
$string['bocondnotifymelist'] = 'Notify list';
$string['bocondonnotifylistavailable'] = 'Book it';
$string['bocondonnotifylistfullavailable'] = 'Booking is possible';
$string['bocondonnotifylistfullnotavailable'] = 'User has reached the max number of bookings';
$string['bocondonnotifylistnotavailable'] = 'Max number of bookings reached';
$string['bocondonwaitinglist'] = 'onwaitinglist: User is on waiting list';
$string['bocondonwaitinglistavailable'] = 'Book it';
$string['bocondonwaitinglistfullavailable'] = 'Booking is possible';
$string['bocondonwaitinglistfullnotavailable'] = 'User is on the waiting list';
$string['bocondonwaitinglistnotavailable'] = 'You are on the waiting list';
$string['bocondoptionhasstarted'] = 'Has already started';
$string['bocondoptionhasstartedavailable'] = 'Book it';
$string['bocondoptionhasstartedfullavailable'] = 'Booking is possible';
$string['bocondoptionhasstartedfullnotavailable'] = 'Already started - booking for users is not possible anymore';
$string['bocondoptionhasstartednotavailable'] = 'Already started - booking is not possible anymore';
$string['bocondotheroptionsavailable'] = 'Linked options not available';
$string['bocondpreviouslybooked'] = 'User has previously booked a certain option';
$string['bocondpreviouslybookedavailable'] = 'Book it';
$string['bocondpreviouslybookedfullavailable'] = 'Booking is possible';
$string['bocondpreviouslybookedfullnotavailable'] = 'Only users who have previously booked <a href="{$a}">this option</a> are allowed to book.
    <br>But you have the right to book a user anyways.';
$string['bocondpreviouslybookednotavailable'] = 'Only users who have previously booked <a href="{$a}">this option</a> are allowed to book.';
$string['bocondpreviouslybookedoptionid'] = 'Must be already booked';
$string['bocondpreviouslybookedrestrict'] = 'User has previously booked a certain option';
$string['bocondpriceisset'] = 'priceisset: Price is set';
$string['bocondpriceissetavailable'] = 'Book it';
$string['bocondpriceissetfullavailable'] = 'Booking is possible';
$string['bocondpriceissetfullnotavailable'] = 'A price is set, payment required';
$string['bocondpriceissetnotavailable'] = 'You need to pay';
$string['bocondselectusers'] = 'Only selected users can book';
$string['bocondselectusersavailable'] = 'Book it';
$string['bocondselectusersfullavailable'] = 'Booking is possible';
$string['bocondselectusersfullnotavailable'] = 'Only the following users are allowed to book:<br>{$a}';
$string['bocondselectusersnotavailable'] = 'Booking not allowed';
$string['bocondselectusersrestrict'] = 'Only specific user(s) are allowed to book';
$string['bocondselectusersuserids'] = 'User(s) allowed to book';
$string['bocondselectusersuserids_help'] = '<p>If you use this condition, only selected people will be able to book this event.</p>
<p>However, you can also use this condition to allow certain people to bypass other restrictions:</p>
<p>(1) To do this, click the "Has relation to other condition" checkbox.<br>
(2) Make sure that the "OR" operator is selected.<br>
(3) Choose all conditions to be bypassed.</p>
<p>Examples:<br>
"Fully booked" => The selected person is allowed to book even if the event is already fully booked.<br>
"Only bookable within a certain time" => The selected person is allowed to book also outside the normal booking times.</p>';
$string['bocondsubbooking'] = 'Subbbookings exist';
$string['bocondsubbookingavailable'] = 'Book it';
$string['bocondsubbookingblocks'] = 'Subbooking blocks this booking option';
$string['bocondsubbookingblocksavailable'] = 'Book it';
$string['bocondsubbookingblocksfullavailable'] = 'Booking is possible';
$string['bocondsubbookingblocksfullnotavailable'] = 'Subbooking blocks this booking option.';
$string['bocondsubbookingblocksnotavailable'] = 'Not allowed to book.';
$string['bocondsubbookingfullavailable'] = 'Booking is possible';
$string['bocondsubbookingfullnotavailable'] = 'Booking is possible';
$string['bocondsubbookingnotavailable'] = 'Book it';
$string['bocondsubisbookableavailable'] = 'Book it';
$string['bocondsubisbookablefullavailable'] = 'Booking is possible';
$string['bocondsubisbookablefullnotavailable'] = 'Booking is not possible for this subbooking as the corresponding option is not booked.';
$string['bocondsubisbookablenotavailable'] = 'Book option first';
$string['boconduserprofilefield1default'] = 'User profile field has a certain value';
$string['boconduserprofilefield1defaultrestrict'] = 'A chosen user profile field should have a certain value';
$string['boconduserprofilefield2custom'] = 'Custom user profile field has a certain value';
$string['boconduserprofilefield2customrestrict'] = 'A custom user profile field should have a certain value';
$string['boconduserprofilefieldavailable'] = 'Book it';
$string['boconduserprofilefieldfield'] = 'Profile field';
$string['boconduserprofilefieldfullavailable'] = 'Booking is possible';
$string['boconduserprofilefieldfullnotavailable'] = 'Only users with user profile field {$a->profilefield} set to value {$a->value} are allowed to book.
    <br>But you have the right to book a user anyways.';
$string['boconduserprofilefieldnotavailable'] = 'Not allowed to book';
$string['boconduserprofilefieldoperator'] = 'Operator';
$string['boconduserprofilefieldvalue'] = 'Value';
$string['bonumberofdays'] = "Number of days";
$string['bookanyoneswitchoff'] = '<i class="fa fa-user-times" aria-hidden="true"></i> Do not allow booking of users who are not enrolled (recommended)';
$string['bookanyoneswitchon'] = '<i class="fa fa-user-plus" aria-hidden="true"></i> Allow booking of users who are not enrolled';
$string['bookanyonewarning'] = 'Be careful: You can now book any users you want. Only use this setting if you know what you are doing.
 To book users who are not enrolled into the course might cause problems.';
$string['booked'] = 'Booked';
$string['bookeddeleted'] = 'Booking removed';
$string['bookedpast'] = 'Booked (course finished)';
$string['bookedteachersshowemails'] = 'Show teacher\'s email addresses to booked users';
$string['bookedteachersshowemails_desc'] = 'If you activate this setting, booked users can see
the e-mail address of their teacher.';
$string['bookedtext'] = 'Booking confirmation';
$string['bookedtextmessage'] = 'Your booking has been registered:
{$a->bookingdetails}
<p>##########################################</p>
Booking status: {$a->status}
Participant:   {$a->participant}
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['bookedtextsubject'] = 'Booking confirmation for {$a->title}';
$string['bookedtextsubjectbookingmanager'] = 'New booking for {$a->title} by {$a->participant}';
$string['bookedusers'] = 'Booked users';
$string['bookelectivesbtn'] = 'Book selected electives';
$string['booking'] = 'Booking';
$string['booking:addeditownoption'] = 'Add new option and edit own options.';
$string['booking:addinstance'] = 'Add new booking';
$string['booking:bookanyone'] = 'Allowed to book anyone';
$string['booking:bookforothers'] = "Book for others";
$string['booking:canoverbook'] = "Has permission to overbook";
$string['booking:canreviewsubstitutions'] = "Allowed to review teacher substitutions (control checkbox)";
$string['booking:canseeinvisibleoptions'] = 'View invisible options.';
$string['booking:cansendmessages'] = 'Can send messages';
$string['booking:changelockedcustomfields'] = 'Can change locked custom booking option fields.';
$string['booking:choose'] = 'Book';
$string['booking:comment'] = 'Add comments';
$string['booking:communicate'] = 'Can communicate';
$string['booking:conditionforms'] = "Submit condition forms like booking policy or subbookings";
$string['booking:deleteresponses'] = 'Delete responses';
$string['booking:downloadresponses'] = 'Download responses';
$string['booking:editbookingrules'] = "Edit rules (Pro)";
$string['booking:editoptionformconfig'] = 'Edit option config form';
$string['booking:editteacherdescription'] = 'Edit teacher description';
$string['booking:expertoptionform'] = "Expert option form";
$string['booking:limitededitownoption'] = 'Less than addeditownoption, only allows very limited actions';
$string['booking:managebookedusers'] = 'Manage user bookings (booking answers)';
$string['booking:managecomments'] = 'Manage comments';
$string['booking:manageoptiondates'] = 'Manage option dates';
$string['booking:manageoptiontemplates'] = "Manage option templates";
$string['booking:overrideboconditions'] = 'User can book even when conditions return false.';
$string['booking:rate'] = 'Rate chosen booking options';
$string['booking:readallinstitutionusers'] = 'Show all users';
$string['booking:readresponses'] = 'Read responses';
$string['booking:reducedoptionform1'] = "1. Reduced option form for course category";
$string['booking:reducedoptionform2'] = "2. Reduced option form for course category";
$string['booking:reducedoptionform3'] = "3. Reduced option form for course category";
$string['booking:reducedoptionform4'] = "4. Reduced option form for course category";
$string['booking:reducedoptionform5'] = "5. Reduced option form for course category";
$string['booking:seepersonalteacherinformation'] = 'See personal teacher information';
$string['booking:semesters'] = 'Booking: Semesters';
$string['booking:sendpollurl'] = 'Send poll url';
$string['booking:sendpollurltoteachers'] = 'Send poll url to teachers';
$string['booking:subscribeusers'] = 'Make bookings for other users';
$string['booking:updatebooking'] = 'Manage booking options';
$string['booking:view'] = 'View booking instances';
$string['booking:viewallratings'] = 'View all raw ratings given by individuals';
$string['booking:viewanyrating'] = 'View total ratings that anyone received';
$string['booking:viewrating'] = 'View the total rating you received';
$string['booking:viewreports'] = 'Allow access for viewing reports';
$string['bookingaction'] = "Action";
$string['bookingactionadd'] = "Add action";
$string['bookingactionsheader'] = 'Actions after booking [EXPERIMENTAL]';
$string['bookingafteractionsfailed'] = 'Actions after booking failed';
$string['bookingandcancelling'] = 'Booking und Cancelling';
$string['bookinganswercancelled'] = 'Booking option cancelled for/by user';
$string['bookinganswerwaitingforconfirmation'] = 'Pre-registration for booking option received';
$string['bookinganswerwaitingforconfirmationdesc'] = 'User with id {$a->relateduserid} has registered for bookingoption with id {$a->objectid}.';
$string['bookingattachment'] = 'Attachment';
$string['bookingcampaign'] = 'Campaign';
$string['bookingcampaigns'] = 'Booking: Campaigns (PRO)';
$string['bookingcampaignssubtitle'] = 'Campaigns allow you to discount the prices of selected booking options
 for a specified period of time and increase the booking limit for that period. For campaigns to work, the
 Moodle cron job must run regularly.<br>
 Overlapping campaigns will add up. Two matching 50% price campaigns will result in a 25% price.';
$string['bookingcampaignswithbadge'] = 'Booking: Campaigns <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['bookingcategory'] = 'Category';
$string['bookingchangedtext'] = 'Message to be sent when a booking option changes (will only be sent to users who have already booked). Use the placeholder {changes} to show the changes. Enter 0 to turn off change notifications.';
$string['bookingchangedtext_help'] = 'Enter 0 to turn change notifications off.';
$string['bookingchangedtextmessage'] = 'Your booking "{$a->title}" has changed.
Here\'s what\'s new:
{changes}
To view the change(s) and all your booked courses click on the following link: {$a->bookinglink}
';
$string['bookingchangedtextsubject'] = 'Change notification for {$a->title}';
$string['bookingclosingtime'] = 'Bookable until';
$string['bookingcondition'] = 'Condition';
$string['bookingconfirmationlink'] = 'Link to booking receipt';
$string['bookingcustomfield'] = 'Booking customfields for booking options';
$string['bookingdate'] = 'Booking date';
$string['bookingdebugmode'] = 'Booking debug mode';
$string['bookingdebugmode_desc'] = 'Booking debug mode should only be activated by developers.';
$string['bookingdefaulttemplate'] = 'Choose template...';
$string['bookingdeleted'] = 'Your booking was cancelled';
$string['bookingdetails'] = "Details of the booking";
$string['bookingduration'] = 'Duration';
$string['bookingfailed'] = 'Booking failed';
$string['bookingfull'] = 'There are no available places';
$string['bookingfulldidntregister'] = 'Option is full, so I didn\'t transfer all users!';
$string['bookinghistory'] = 'Booking history';
$string['bookingidfilter'] = 'Booking Instance';
$string['bookingimages'] = 'Upload header images for booking options - they need to have the exact same name as the value of the selected customfield in each booking option.';
$string['bookingimagescustomfield'] = 'Booking option custom field to match the header images with';
$string['bookinginstance'] = 'Booking instance';
$string['bookinginstancetemplatename'] = 'Booking instance template name';
$string['bookinginstancetemplatessettings'] = 'Booking: Instance templates';
$string['bookinginstanceupdated'] = 'Booking instance updated';
$string['bookinglink'] = "Link to bookingoption";
$string['bookingmanagererror'] = 'The username entered is not valid. Either it does not exist or there are more then one users with this username (example: if you have mnet and local authentication enabled)';
$string['bookingmeanwhilefull'] = 'Meanwhile someone took already the last place';
$string['bookingname'] = 'Booking instance name';
$string['bookingnotopenyet'] = 'Your event starts in {$a} minutes. The link you used will redirect you if you click it again within 15 minutes before.';
$string['bookingopen'] = 'Open';
$string['bookingopeningtime'] = 'Bookable from';
$string['bookingoption'] = 'Booking option';
$string['bookingoptionbooked'] = 'Booking option booked';
$string['bookingoptionbookedotheruserdesc'] = 'The user with id {$a->userid} booked the user with id {$a->relateduserid} to the option with id  {$a->objectid}.';
$string['bookingoptionbookedotheruserwaitinglistdesc'] = 'The user with id {$a->userid} booked the user with id {$a->relateduserid} to the option with id {$a->objectid} on the waitinglist.';
$string['bookingoptionbookedsameuserdesc'] = 'The user with id {$a->userid} booked the booking option with id {$a->objectid}.';
$string['bookingoptionbookedsameuserwaitinglistdesc'] = 'The user with id {$a->userid} booked the booking option with id {$a->objectid} on the waitinglist.';
$string['bookingoptionbookedviaautoenrol'] = 'Booking option booked automatically via autoenrol';
$string['bookingoptionbookedviaautoenroldesc'] = 'User with ID {$a->userid} was enrolled in bookingoption with ID {$a->objectid} via enrolment link.';
$string['bookingoptioncalendarentry'] = '<a href="{$a}" class="btn btn-primary">Book now...</a>';
$string['bookingoptioncanbecancelleduntil'] = 'You can cancel until {$a}.';
$string['bookingoptioncancelled'] = "Booking option cancelled for all";
$string['bookingoptioncantbecancelledanymore'] = 'Cancelling was possible until {$a}.';
$string['bookingoptioncompleted'] = 'Booking option completed';
$string['bookingoptionconfirmed'] = 'Booking option confirmed';
$string['bookingoptionconfirmed:description'] = 'User with ID {$a->userid} enabled booking of booking option {$a->objectid} for user with ID {$a->relateduserid}.';
$string['bookingoptioncreated'] = 'Booking option created';
$string['bookingoptiondatecreated'] = 'Booking option date created';
$string['bookingoptiondatedeleted'] = 'Booking option date deleted';
$string['bookingoptiondateupdated'] = 'Booking option date updated';
$string['bookingoptiondefaults'] = 'Default settings for booking options';
$string['bookingoptiondefaultsdesc'] = 'Here you can set default settings for the creation of booking options and lock them if needed.';
$string['bookingoptiondeleted'] = 'Booking option deleted';
$string['bookingoptiondescription'] = 'Description of the booking option.';
$string['bookingoptiondetaillink'] = 'bookingoptiondetaillink';
$string['bookingoptionfreetobookagain'] = 'Free places again';
$string['bookingoptionimage'] = 'Header image';
$string['bookingoptionname'] = 'Booking option name';
$string['bookingoptionnamewithoutprefix'] = 'Name (without prefix)';
$string['bookingoptionprice'] = 'Price';
$string['bookingoptionsall'] = 'Shortcode to show all bookingoptions.';
$string['bookingoptionsfromtemplatemenu'] = 'New booking option from template';
$string['bookingoptionsmenu'] = 'Booking options';
$string['bookingoptiontitle'] = 'Booking option title';
$string['bookingoptionupdated'] = 'Booking option updated';
$string['bookingoptionupdateddesc'] = 'User with id "{$a->userid}" updated bookingoption with id "{$a->objectid}".';
$string['bookingoptionwaitinglistbooked'] = 'Booked on waitinglist';
$string['bookingorganizatorname'] = 'Organizer name';
$string['bookingpassed'] = 'Your event has ended.';
$string['bookingplacesenoughmessage'] = 'Still enough places available.';
$string['bookingplacesfullmessage'] = 'Fully booked.';
$string['bookingplacesinfotexts'] = 'Place availability display';
$string['bookingplacesinfotextsinfo'] = 'Select how place availability should be displayed to users.';
$string['bookingplaceslowmessage'] = 'Only a few places left!';
$string['bookingplaceslowpercentage'] = 'Percentage for booking places low message';
$string['bookingplaceslowpercentagedesc'] = 'If the available booking places reach or get below this percentage a booking places low message will be shown.';
$string['bookingplacesplacesleft'] = '{$a} places left';
$string['bookingplacesunlimitedmessage'] = 'Availble immediately';
$string['bookingpoints'] = 'Course points';
$string['bookingpolicy'] = 'Booking policy';
$string['bookingpolicyagree'] = 'I have read, understood and agree to the booking policy.';
$string['bookingpolicynotchecked'] = 'You have not accepted the booking policy.';
$string['bookingpollurl'] = 'Poll url';
$string['bookingpollurlteachers'] = 'Teachers poll url';
$string['bookingpricecategory'] = 'Price category';
$string['bookingpricecategoryinfo'] = 'Define the name of the category, eg "students"';
$string['bookingpricesettings'] = 'Price settings';
$string['bookingpricesettings_desc'] = 'Here you can customize booking prices.';
$string['bookingreportlink'] = 'Link to report of bookingoption';
$string['bookingrule'] = 'Rule';
$string['bookingruleaction'] = "Action of the rule";
$string['bookingruleapply'] = "Apply rule";
$string['bookingruleapplydesc'] = "Uncheck if you want to deactivate this rule.";
$string['bookingrulecondition'] = "Condition of the rule";
$string['bookingruledeactivate'] = "<b>Deactivate</b> booking rule";
$string['bookingruleisactive'] = "Rule is active and applied";
$string['bookingruleisnotactive'] = "Rule is not active und will not be applied";
$string['bookingrules'] = 'Booking Rules (PRO)';
$string['bookingrulesnootherfound'] = 'No other rules found';
$string['bookingrulesothercontextheading'] = 'Links to rules in other contexts:';
$string['bookingruleswithbadge'] = 'Booking: Rules <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['bookingruletemplate'] = 'Deactivate preconfigured templates for rules.';
$string['bookingruletemplates'] = 'Load a template rule';
$string['bookingruletemplatesactive'] = 'Activate preconfigured templates for rules.';
$string['bookings'] = 'Bookings';
$string['bookingsaved'] = 'Your booking was successfully saved. You can now proceed to book other courses.';
$string['bookingsettings'] = 'Booking: Main settings';
$string['bookingstracker'] = "Bookings tracker";
$string['bookingstracker_desc'] = "Here you can activate the bookings tracker.
It allows authorized users to manage bookings for the entire site at various hierarchical booking levels
(optiondates, booking options, booking instance, Moodle course, site-wide) and change the presence status
booked users.";
$string['bookingstrackerdelete'] = 'Unsubscribe';
$string['bookingstrackerpresencecounter'] = 'Count presences';
$string['bookingstrackerpresencecounter_desc'] = 'Display a counter that shows the total number of presences.
Define in the next setting which presence status should be counted.';
$string['bookingstrackerpresencecountervaluetocount'] = 'Presence status to count';
$string['bookingstrackerpresencecountervaluetocount_desc'] = 'This presence status will be counted and displayed in the booking tracker.';
$string['bookingsubbooking'] = "Subbooking";
$string['bookingsubbookingadd'] = 'Add a subbooking';
$string['bookingsubbookingdelete'] = 'Delete subbooking';
$string['bookingsubbookingedit'] = 'Edit';
$string['bookingsubbookingsheader'] = "Subbookings";
$string['bookingtags'] = 'Tags';
$string['bookingtext'] = 'Booking text';
$string['bookinguseastemplate'] = 'Set this rule as template';
$string['booknow'] = 'Book now';
$string['bookondetail'] = 'More information';
$string['bookonlyondetailspage'] = 'Booking is only possible on dedicated booking details page';
$string['bookonlyondetailspage_desc'] = 'This means that booking is not possible from list or card view. To book, you need to be on the details page to see all the booking information.';
$string['bookotheroptions'] = 'Book options';
$string['bookotheroptionsconditionsblock'] = "Only book if all conditions of option are met";
$string['bookotheroptionsforce'] = 'Handle restrictions of these options';
$string['bookotheroptionsforcebooking'] = 'Always book';
$string['bookotheroptionsnooverbooking'] = 'Only book if seats are available';
$string['bookotheroptionsselect'] = 'Book into other booking options as well';
$string['bookotherusers'] = 'Book other users';
$string['bookotheruserslimit'] = 'Max. number of users a teacher assigned to the option can book';
$string['booktootherbooking'] = 'Book users to other booking option';
$string['bookusers'] = 'For Import, to book users directly';
$string['bookuserswithoutcompletedactivity'] = "Book users without completed activity";
$string['bookwithcredit'] = '{$a} credit';
$string['bookwithcredits'] = '{$a} credits';
$string['bookwithcreditsactive'] = "Book with credits";
$string['bookwithcreditsactive_desc'] = "Users with credits can book directly without paying a price.";
$string['bookwithcreditsprofilefield'] = "User profile field for credits";
$string['bookwithcreditsprofilefield_desc'] = "To use this functionality, please define a user profile field where credits are stored.
<span class='text-danger'><b>Be careful:</b> You should create this field in a way that your users can't set a credit themselves.</span>";
$string['bookwithcreditsprofilefieldoff'] = 'Do not show';
$string['bopathtoscript'] = "Path to rest script";
$string['bosecrettoken'] = "Secret token";
$string['bstcourse'] = 'Course';
$string['bstcoursestarttime'] = 'Date / Time';
$string['bstinstitution'] = 'Institution';
$string['bstlink'] = 'Show';
$string['bstlocation'] = 'Location';
$string['bstmanageresponses'] = 'Manage bookings';
$string['bstparticipants'] = 'Participants';
$string['bstteacher'] = 'Teacher(s)';
$string['bsttext'] = 'Booking option';
$string['bstwaitinglist'] = 'On waiting list';
$string['btnbooknowname'] = 'Name of button: Book now';
$string['btncacname'] = 'Name of button: Confirm activity completion';
$string['btncancelname'] = 'Name of button: Cancel booking';
$string['btnviewavailable'] = "View available options";
$string['bulkoperations'] = 'Zeige Liste von Buchungsoptionen um Massenoperationen zu ermöglichen';
$string['bulkoperationsheader'] = 'Update data for selected bookingoption(s)';
$string['cachedef_bookedusertable'] = 'Booked users table (cache)';
$string['cachedef_bookforuser'] = 'Book for user (Cache)';
$string['cachedef_bookinganswers'] = 'Bookinganswers (Cache)';
$string['cachedef_bookinghistorytable'] = 'Booking history table (Cache)';
$string['cachedef_bookingoptions'] = 'Booking options (cache)';
$string['cachedef_bookingoptionsanswers'] = 'Booking options answers (cache)';
$string['cachedef_bookingoptionsettings'] = 'Booking option settings (cache)';
$string['cachedef_bookingoptionstable'] = 'Tables of booking options with hashed sql queries (cache)';
$string['cachedef_cachedbookinginstances'] = 'Booking instances (cache)';
$string['cachedef_cachedpricecategories'] = 'Booking price categories (cache)';
$string['cachedef_cachedprices'] = 'Prices in booking (cache)';
$string['cachedef_cachedsemesters'] = 'Semesters (cache)';
$string['cachedef_cachedteachersjournal'] = 'Teaches journal (Cache)';
$string['cachedef_conditionforms'] = 'Condition Forms (Cache)';
$string['cachedef_confirmbooking'] = 'Booking confirmed (Cache)';
$string['cachedef_customformuserdata'] = 'Custom form user data (Cache)';
$string['cachedef_electivebookingorder'] = 'Elective booking order (Cache)';
$string['cachedef_eventlogtable'] = 'Event log table (Cache)';
$string['cachedef_mybookingoptionstable'] = 'My bookingoptions table (Cache)';
$string['cachedef_subbookingforms'] = 'Subbooking Forms (Cache)';
$string['cachesettings'] = 'Cache Settings';
$string['cachesettings_desc'] = 'These changes have a significant impact on performance. Please only make changes here if you know exactly what you are doing.';
$string['cacheturnoffforbookinganswers'] = 'Turn off caching of responses (user bookings)';
$string['cacheturnoffforbookinganswers_desc'] = 'This setting significantly increases the load on the database. However, if there are massive problems with the cache configuration, this setting can still be advantageous.';
$string['cacheturnoffforbookingsettings'] = 'Turn off caching of booking option settings';
$string['cacheturnoffforbookingsettings_desc'] = 'This setting significantly increases the load on the database. However, if there are massive problems with the cache configuration, this setting can still be advantageous.';
$string['caladdascourseevent'] = 'Add to calendar (visible only to course participants)';
$string['caladdassiteevent'] = 'Add to calendar (visible to all users)';
$string['caldonotadd'] = 'Do not add to course calendar';
$string['caleventtype'] = 'Calendar event visibility';
$string['callbackfunctionnotapplied'] = 'Callback function could not be applied.';
$string['callbackfunctionnotdefined'] = 'Callback function is not defined.';
$string['campaignblockbooking'] = 'Block certain booking options';
$string['campaigncustomfield'] = 'Change price or booking limit';
$string['campaigndescriptioncpvalue'] = 'Custom user profile field "{$a->cpfield}" {$a->cpoperator} "{$a->cpvalue}"';
$string['campaigndescriptionfieldvalue'] = 'Booking option field "{$a->bofieldname}" {$a->campaignfieldnameoperator} "{$a->fieldvalue}"';
$string['campaignend'] = 'End of campaign';
$string['campaignend_help'] = 'When does the campaign end?';
$string['campaignfieldname'] = 'Booking option field';
$string['campaignfieldname_help'] = 'Select the custom booking option field whose value is to be compared.';
$string['campaignfieldvalue'] = 'Value';
$string['campaignfieldvalue_help'] = 'Select the value of the field. The campaign applies to all booking options that have this value in the selected field.';
$string['campaignname'] = 'Custom name for the campaign';
$string['campaignname_help'] = 'Specify any name for the campaign - for example, "Christmas Campaign 2023" or "Easter Discount 2023".';
$string['campaignstart'] = 'Start of campaign';
$string['campaignstart_help'] = 'When does the campaign start?';
$string['campaigntype'] = 'Campaign type';
$string['cancancelbookabsolute'] = 'Set cancellation date with a fixed date';
$string['cancancelbookallow'] = 'Allow users to cancel their booking themselves';
$string['cancancelbookdays'] = 'Disallow users to cancel their booking n days before start. Minus means, that users can still cancel n days AFTER course start.';
$string['cancancelbookdays:bookingclosingtime'] = 'Disallow users to cancel their booking n days before <b>registration end</b> (booking closing time). Minus means, that users can still cancel n days AFTER registration end.';
$string['cancancelbookdays:bookingopeningtime'] = 'Disallow users to cancel their booking n days before <b>registration start</b> (booking opening time). Minus means, that users can still cancel n days AFTER registration start.';
$string['cancancelbookdays:coursestarttime'] = 'Disallow users to cancel their booking n days before <b>start of booking option</b> (course start time). Minus means, that users can still cancel n days AFTER registration start.';
$string['cancancelbookdays:semesterstart'] = 'Disallow users to cancel their booking n days before <b>semester</b> start. Minus means, that users can still cancel n days AFTER semester start.';
$string['cancancelbookdaysno'] = "Don't limit";
$string['cancancelbookrelative'] = 'Set cancellation date <b>relative to {$a}</b>';
$string['cancancelbooksetting'] = 'Define cancellation conditions';
$string['cancancelbooksetting_help'] = 'These settings can be overriden by more special settings in the booking options';
$string['cancancelbookunlimited'] = 'Cancellation without limits is possible.';
$string['cancel'] = 'Cancel';
$string['cancelallusers'] = 'Cancel all booked users';
$string['cancelbooking'] = 'Cancel booking';
$string['canceldateabsolute'] = 'Cancelling possible until';
$string['canceldependenton'] = 'Cancellation period dependent on';
$string['canceldependenton_desc'] = 'Choose the date that should be used as "start" for the setting
"Disallow users to cancel their booking n days before start. Minus means, that users can still cancel n
days AFTER course start.".<br>
This will also set the <i>service period</i> of courses in shopping cart accordingly (if shopping cart is installed). This also affects installment payments. You can take out the semester in the Dates section of a booking option, if you want to use the coursestart instead of the semesterstart.';
$string['cancelical'] = 'Cancel calendar event(s)';
$string['cancellation'] = 'Cancellation';
$string['cancellationsettings'] = 'Cancellation settings <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['cancelmyself'] = 'Undo my booking';
$string['canceloption'] = "Cancel boooking option";
$string['canceloption_desc'] = "Canceling a boooking option means that it is no longer bookabel, but it is still shown in list.";
$string['cancelreason'] = "Reason for cancellation of this booking option";
$string['cancelsign'] = '<i class="fa fa-ban" aria-hidden="true"></i>';
$string['cancelthisbookingoption'] = "Cancel this booking option";
$string['canceluntil'] = 'Cancelling is only possible until certain date';
$string['cannotremovesubscriber'] = 'You have to remove the activity completion prior to cancel the booking. Booking was not cancelled!';
$string['categories'] = 'Categories';
$string['category'] = 'Category';
$string['categoryheader'] = '[DEPRECATED] Category';
$string['categoryname'] = 'Category name';
$string['cdo:bookingclosingtime'] = 'Booking registration end (bookingclosingtime)';
$string['cdo:bookingopeningtime'] = 'Booking registration start (bookingopeningtime)';
$string['cdo:buttoncolor:danger'] = 'Danger (red)';
$string['cdo:buttoncolor:primary'] = 'Primary (blue)';
$string['cdo:buttoncolor:secondary'] = 'Secondary (grey)';
$string['cdo:buttoncolor:success'] = 'Success (green)';
$string['cdo:buttoncolor:warning'] = 'Warning (yellow)';
$string['cdo:coursestarttime'] = 'Start of the booking option (coursestarttime)';
$string['cdo:semesterstart'] = 'Semester start';
$string['certificate'] = 'Certificate';
$string['certificatecode'] = 'Code of certificate';
$string['certificatecolheader'] = 'Latest certificate';
$string['certificateexpirationdate'] = 'Expiration date';
$string['certificateheader'] = 'Moodle certificate';
$string['certificateissuedate'] = 'Issue date';
$string['certificatemodalheader'] = 'Certificates of {$a}';
$string['certificateon'] = 'Activate certificate creation <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['certificateon_desc'] = 'Enable this setting if you want to issue certificates for the completion of booking options.';
$string['certificateurl'] = 'Certificate URL';
$string['certificatewithexpiration'] = 'Expires: {$a}';
$string['certificatewithoutexpiration'] = 'No expiration date';
$string['cfcostcenter'] = "Custom booking option field for cost center";
$string['cfcostcenter_desc'] = "If you use cost centers, you have to specify which custom
booking option field is used to store the cost center.";
$string['cfgsignin'] = 'Sign-In Sheet Configuration';
$string['cfgsignin_desc'] = 'Configure the sign-in sheet settings';
$string['changeinfoadded'] = ' has been added:';
$string['changeinfocfadded'] = 'A field has been added:';
$string['changeinfocfchanged'] = 'A field has changed:';
$string['changeinfocfdeleted'] = 'A field has been deleted:';
$string['changeinfochanged'] = '{$a} has/have changed';
$string['changeinfodeleted'] = ' has been deleted:';
$string['changeinfosessionadded'] = 'A session has been added:';
$string['changeinfosessiondeleted'] = 'A session has been deleted:';
$string['changenew'] = '[NEW] ';
$string['changeold'] = '[DELETED] ';
$string['changepresencestatus'] = 'Change presence status';
$string['changes'] = "Changes made in bookingoption (for rule listening to bookingoption_updated)";
$string['changesemester'] = 'Reset and create dates for semester';
$string['changesemester:warning'] = '<strong>Be careful:</strong> By clicking "Save changes" all dates will be deleted
and be replaced with dates of the chosen semester.';
$string['changesemesteradhoctaskstarted'] = 'Success. The dates will be re-generated the next time CRON is running. This may take several minutes.';
$string['changesinentity'] = '{$a->name} (ID: {$a->id})';
$string['changesinexpirydateabsolute'] = 'Certificate: an absolute value of expiration date';
$string['changesinexpirydaterelative'] = 'Certificate: a relative value of expiration date';
$string['changesinexpirydatetype'] = 'Certificate: the type of expiration date';
$string['checkbox'] = "Checkbox";
$string['checkdelimiter'] = 'Check if data is separated via the selected symbol.';
$string['checkdelimiteroremptycontent'] = 'Check if data is given and separated via the selected symbol.';
$string['checkedanswersdeleted'] = 'The selected bookings were deleted successfully.';
$string['checkoutidentifier'] = "Ordernumber";
$string['choose...'] = 'Choose...';
$string['choosedifferentvalue'] = 'Choose different value than in first field';
$string['choosepdftitle'] = 'Select a title for the sign-in sheet';
$string['chooseperiod'] = 'Select time period';
$string['chooseperiod_help'] = 'Select a time period within to create the date series.';
$string['choosesemester'] = "Choose semester";
$string['choosesemester_help'] = "Choose the semester for which the holiday(s) should be created.";
$string['choosesession'] = 'Choose date (session)...';
$string['choosetags'] = 'Choose tags';
$string['choosetags_desc'] = 'Courses marked with these tags can be used as templates. If a booking option is linked to such a template, a copy of the template course will be automatically created upon first saving.';
$string['circumventavailabilityconditions'] = 'Circumvent availabilty restrictions';
$string['circumventavailabilityconditions_desc'] = 'If this setting is enabled, restrictions of booking options that depend on user profile fields can be bypassed.
When users visit the "optionview.php" page once with the correct parameters, the booking option can become bookable for them despite these restrictions.
Required parameter is <b>cvfield=userprofileshortname_desiredvalue</b>, and optionally <b>cvpwd=password</b>.
The bypass of the restriction is specific to each booking instance and only applies to the instance for which "optionview" was last called with the "cvfield" parameter.';
$string['circumventpassword'] = 'Password to circumvent the restrictions. Leave empty if no password should be required.';
$string['close'] = 'Close';
$string['closed'] = 'Booking closed';
$string['cohort'] = 'Cohort';
$string['cohorts'] = 'Cohort(s)';
$string['collapsedescriptionmaxlength'] = 'Collapse descriptions (max. length)';
$string['collapsedescriptionmaxlength_desc'] = 'Enter the maximum length of characters of a description. Descriptions having more characters will be collapsed.';
$string['collapsedescriptionoff'] = 'Do not collapse descriptions';
$string['collapseshowsettings'] = "Collapse 'show dates' with more than x dates.";
$string['collapseshowsettings_desc'] = "To avoid a messy view with too many dates, a lower limit for collapsed dates can be defined here.";
$string['comments'] = 'Comments';
$string['competencies'] = 'Competencies';
$string['competenciesheader'] = ' <i class="fa fa-line-chart" aria-hidden="true"></i>&nbsp;Competencies';
$string['competencychoose'] = 'Choose competencies of this booking option';
$string['competencynonefound'] = 'No competencies defined yet';
$string['completed'] = 'Completed';
$string['completedcomments'] = 'Only with completed activity';
$string['completedratings'] = 'Only with completed activity';
$string['completionchanged'] = 'Update of completion status';
$string['completionchangedhistory'] = 'The completion status was changed from "{$a->completionold}" zu "{$a->completionnew}" geändert';
$string['completionmodule'] = 'Upon completion of the selected course activity, enable bulk deletion of user bookings';
$string['completionmodule_help'] = 'Display bulk deletion button for booking answers, if another course module has been completed. The bookings of users will be deleted with a click of a button on the report page! Only activities with completion enabled can be selected from the list.';
$string['completionoptioncompletedcminfo'] = 'Get the following number of booked options marked as completed by teachers, managers or admin: {$a}';
$string['completionoptioncompletedform'] = 'Given number of the booked options has to be marked as completed. This has to be done by teacher, manager or admin.';
$string['conditionselectstudentinbo_desc'] = 'Select all students of the booking option (affected by the rule) having a certain role.';
$string['conditionselectstudentinboroles'] = 'Choose role';
$string['conditionselectteacherinbo_desc'] = 'Select the teachers of the booking option (affected by the rule).';
$string['conditionselectuserfromevent_desc'] = 'Choose a user who is somehow connected to the event';
$string['conditionselectuserfromeventtype'] = 'Choose role';
$string['conditionselectusershoppingcart_desc'] = "User with payment obligation is chosen";
$string['conditionselectusersuserids'] = "Select the users you want to target";
$string['conditionsoverwritingbillboard'] = 'Overwrite messages about blocking reasons of bookings';
$string['conditionsoverwritingbillboard_desc'] = 'A text can be entered in the settings of the booking instance that is displayed instead of other messages on blocking reasons of bookings.';
$string['conditiontextfield'] = 'Value';
$string['configurefields'] = 'Configure fields and columns';
$string['confirmactivtyfrom'] = 'Confirm users activity from';
$string['confirmationmessagesettings'] = 'Confirmation e-mail settings';
$string['confirmationonnotification'] = 'Open booking for notified user?';
$string['confirmationonnotificationnoopen'] = 'No, keep it open for all users';
$string['confirmationonnotificationoneatatime'] = 'Only the <b>one </b> person who was last notified should be able to book';
$string['confirmationonnotificationwarning'] = '<div class="alert alert-warning" role="alert">Please note that you must configure a corresponding rule for this function to work.</div>';
$string['confirmationonnotificationyesforall'] = 'Yes, for all notified users';
$string['confirmationonnotificationyesoneatatime'] = 'Yes, confirmation only for one user at a time';
$string['confirmbooking'] = 'Confirmation of this booking';
$string['confirmbookinganswer'] = 'Confirm booking answer when user notification is enabled.';
$string['confirmbookinglong'] = 'Do you really want to confirm this booking?';
$string['confirmbookingoffollowing'] = 'Please confirm the booking of following course';
$string['confirmbookingtitle'] = "Confirm booking";
$string['confirmcanceloption'] = "Confirm cancelation of booking option";
$string['confirmcanceloptiontitle'] = "Change the status of the booking option";
$string['confirmchangesemester'] = 'YES, I really want to delete all existing dates of the booking instance and generate new ones.';
$string['confirmdeletebookingoption'] = 'Do you really want to delete this booking option <b>{$a}</b>?';
$string['confirmed'] = 'Confirmed';
$string['confirmoptioncompletion'] = '(Un)confirm completion status';
$string['confirmoptioncreation'] = 'Do you want to split this booking option so that a separate booking option is created
 from each individual date of this booking option?';
$string['confirmpresence'] = "Confirm presence";
$string['confirmrecurringoption'] = 'Apply these changes to all children of this bookingoptions as well?';
$string['confirmrecurringoptionapplychanges'] = 'Apply current changes';
$string['confirmrecurringoptionerror'] = 'You can proceed with any of these options';
$string['confirmrecurringoptionoverwrite'] = 'Overwrite all settings';
$string['confirmusers'] = 'Confirm users activity';
$string['confirmuserswith'] = 'Confirm users who completed activity or received badge';
$string['connectedbooking'] = '[DEPRECATED] Connected booking';
$string['connectedbooking_help'] = 'Booking instance eligible for transferring booked users. You can define from which option within the selected booking instance and how many users you will accept.';
$string['connectedmoodlecourse'] = 'Connected Moodle course';
$string['connectedmoodlecourse_help'] = 'Choose "Create new course..." if you want a new Moodle course to be created for this booking option.';
$string['consumeatonce'] = 'All credits have to be consumed at once';
$string['consumeatonce_help'] = 'Uses can only book once, and they have to book all options in one step.';
$string['contains'] = 'contains (text)';
$string['containsinarray'] = 'user has one of these comma separated values at least partly';
$string['containsnot'] = 'does not contain (text)';
$string['containsnotinarray'] = 'user has one of these comma separated values, not even partly';
$string['containsnotplain'] = 'does not contain';
$string['containsplain'] = 'contains';
$string['coolingoffperiod'] = 'Cancellation possible after x seconds';
$string['coolingoffperiod_desc'] = 'To prevent users from canceling due to, for example, accidentally clicking the booking button too quickly, a cooling off period can be set in seconds. During this time, cancellation is not possible. Do not set more than a few seconds, as the waiting time is not explicitly shown to users.';
$string['copy'] = 'copy';
$string['copycircumventlink'] = 'Copy access link for externals';
$string['copymail'] = 'Send confirmation e-mail to booking manager';
$string['copyonlythisbookingurl'] = 'Copy this booking URL';
$string['copypollurl'] = 'Copy poll URL';
$string['copytoclipboard'] = 'Copy to clipboard: Ctrl+C, Enter';
$string['copytotemplate'] = 'Save booking option as template';
$string['copytotemplatesucesfull'] = 'Booking option was successfully saved as template.';
$string['course'] = 'Moodle course';
$string['coursecalendarurl'] = "URL for course calendar";
$string['coursedate'] = 'Date';
$string['coursedoesnotexist'] = 'The Coursenumber {$a} does not exist';
$string['courseduplicating'] = 'DO NOT REMOVE this item. Moodle course is being copied with next run of CRON task.';
$string['courseendtime'] = 'End time of the course';
$string['courseid'] = 'Course to subscribe to';
$string['courselink'] = "Link to course related to bookingoption";
$string['courselist'] = 'Show all the booking options of a booking instance';
$string['coursepageshortinfo'] = 'If you want to book yourself for this course, click on "View available options", choose a booking option and then click on "Book now".';
$string['coursepageshortinfolbl'] = 'Short info';
$string['coursepageshortinfolbl_help'] = 'Choose a short info text to show on the course page.';
$string['courses'] = 'Courses';
$string['coursesheader'] = 'Moodle course';
$string['courseshortname'] = 'Shortname of the course';
$string['coursestart'] = 'Start';
$string['coursestarttime'] = 'Start time of the course';
$string['courseurl'] = 'Course URL';
$string['createcompetencylink'] = '<a href="{$a}" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">
Create new competency (in framework) </a>';
$string['createdbywunderbyte'] = 'Booking module created by Wunderbyte GmbH';
$string['createical'] = 'Create calendar event(s)';
$string['createnewbookingoption'] = 'New booking option';
$string['createnewbookingoptionfromtemplate'] = 'Add a new booking option from template';
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
$string['createnewmoodlecoursefromtemplatewithusers'] = 'Transfer the users from the template course to the new course.';
$string['createoptionsfromoptiondate'] = 'For each option date create a new option';
$string['credits'] = 'Credits';
$string['credits_help'] = 'The number of credits which will be used by booking this option.';
$string['creditsmessage'] = 'You have {$a->creditsleft} of {$a->maxcredits} credits left.';
$string['csvfile'] = 'CSV file';
$string['custombulkmessagesent'] = 'Custom bulk message sent (> 75% of booked users, min. 3)';
$string['customdatesbtn'] = '<i class="fa fa-plus-square"></i> Custom dates...';
$string['customdownloadreport'] = 'Download report';
$string['customfield'] = 'Custom field to be set in the booking option settings. It will then be shown in the booking option overview.';
$string['customfieldchanged'] = 'Custom field changed';
$string['customfieldconfigure'] = 'Booking: Custom booking option fields';
$string['customfielddef'] = 'Custom booking option field';
$string['customfielddesc'] = 'After adding a custom field, you can define the value for the field in the booking option settings. The value will be shown in the booking option description.';
$string['customfieldname'] = 'Field name';
$string['customfieldname_help'] = 'You can enter any field name you want. The special fieldnames
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> in combination with a link in the value field will render buttons and links
                                    which are only accessible during (and shortly before) the actual meetings.';
$string['customfieldoptions'] = 'List of possible values';
$string['customfields'] = 'Custom fields';
$string['customfieldsforfilter'] = 'Custom fields that are to be displayed as filter options';
$string['customfieldsplaceholdertext'] = 'Custom user profile fields & custom booking option fields can be referenced using their shortname.';
$string['customfieldtype'] = 'Field type';
$string['customfieldvalue'] = 'Value';
$string['customfieldvalue_help'] = 'You can enter any value you want (text, number or HTML).<br>
                                    If you have used one of the special field names
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> then enter the complete link to the meeting session starting with https:// or http://';
$string['customform'] = "Customform";
$string['customformnotchecked'] = 'You didn\'t accept yet.';
$string['customformparams_desc'] = "Use parameter that are set in the customform.";
$string['customformparamsvalue'] = "Customform parameter";
$string['customformselectoptions'] = '<div class="alert alert-info" role="alert">
<i class="fa fa-info-circle"></i>
<span><b>Values for selection can be specified like:</b> <br>
key => Display name <br>
Details and additional optional values: <br>
key (<i>Should contain no spaces or special characters</i>) => <br>
Display name (<i>As displayed to users</i>) => <br>
Maximum number of bookings (<i>Total availability shared across all users, displayed to users</i>) => <br>
Price (<i>Can be modified with the defined price category field, displayed to users</i>) => <br>
Allowed users (<i>IDs of users who have access to this option</i>) <br>
<b>Example:</b> <br>
choose => Select... <br>
singleroom => Single Room => 10 => 100 => 1,2,3,4,5 <br>
doubleroom => Double Room => 5 => student:100,expert:200,default:150 => 1,2,3,4,5
</span>
</div>';
$string['customlabelsdeprecated'] = '[DEPRECATED] Custom labels';
$string['custommessagesent'] = 'Custom message sent';
$string['customprofilefield'] = 'Custom profile field to check';
$string['customprofilefieldvalue'] = 'Custom profile field value to check';
$string['customreporttemplate'] = 'Custom report template';
$string['customreporttemplates'] = 'Custom report templates';
$string['customuserprofilefield'] = "Custom user profile field";
$string['customuserprofilefield_help'] = "If you choose a value here, the price part of the camapaign will only be valid for users with the defined value in the defined custom field.";
$string['dashboardsummary'] = 'General';
$string['dashboardsummary_desc'] = 'Contains the settings and stats for the whole Moodle site';
$string['dataincomplete'] = 'Record with componentid {$a->id} is incomplete and could not be treated entirely. Check field "{$a->field}".';
$string['dateandtime'] = 'Date and time';
$string['dateerror'] = 'Wrong date in line {$a}: ';
$string['datenotset'] = 'Date not set';
$string['dateparseformat'] = 'Date parse format';
$string['dateparseformat_help'] = 'Please, use date format like specified in CSV file. Help with <a href="http://php.net/manual/en/function.date.php">this</a> resource for options.';
$string['dates'] = 'Dates';
$string['datesandentities'] = 'Given dates with given entities';
$string['datesheader'] = 'Dates';
$string['dayofweek'] = 'Weekday';
$string['dayofweektime'] = 'Day & Time';
$string['days'] = '{$a} days';
$string['daysafter'] = '{$a} day(s) after';
$string['daysbefore'] = '{$a} day(s) before';
$string['daystonotify'] = 'Number of days in advance of the event-start to notify participants';
$string['daystonotify2'] = 'Second notification before start of event to notify participants.';
$string['daystonotify_help'] = "Will work only if start and end date of option are set! 0 for disabling this functionality.";
$string['daystonotifysession'] = 'Notification n days before start';
$string['daystonotifysession_help'] = "Number of days in advance of the session start to notify participants.
Enter 0 to deactivate the e-mail notification for this session.";
$string['daystonotifysessionrulenooverride'] = 'Do not override number of days (use rule normally)';
$string['daystonotifysessionruleoverride'] = 'Number of days before start';
$string['daystonotifysessionruleoverride_help'] = 'Here you can override the number of days for the execution of the booking rule(s) for this date.';
$string['daystonotifyteachers'] = 'Number of days in advance of the event-start to notify teachers';
$string['deduction'] = 'Deduction';
$string['deductionnotpossible'] = 'All teachers were present at this date. So no deduction can be logged.';
$string['deductionreason'] = 'Reason for the deduction';
$string['defaultbookingoption'] = 'Default booking options';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['defaultoptionsort'] = 'Default sorting by column';
$string['defaultpricecategoryinfoalert'] = 'The default price category always has the identifier "default" and it cannot be disabled.';
$string['defaultpricecategoryname'] = 'Default price category name';
$string['defaultpriceformula'] = "Price formula";
$string['defaultpriceformuladesc'] = "The JSON object permits the configuration of the automatic price calculation with a booking option.";
$string['defaulttemplate'] = 'Default template';
$string['defaulttemplatedesc'] = 'Default booking option template when creating a new booking option.';
$string['defaultvalue'] = 'Default price value';
$string['defaultvalue_help'] = 'Enter a default value for every price in this category. Of course, this value can be overwritten later.';
$string['definecmidforshortcode'] = "To use this shortcode, enter the id of a booking instance like this: [courselist cmid=23]";
$string['definedresponsiblecontactrole'] = 'Define role for responsible contact person of a booking option';
$string['definedresponsiblecontactrole_desc'] = 'When a responsible contact person is added to a booking option, they will be assigned the selected role in the connected Moodle course.';
$string['definedteacherrole'] = 'Teachers of booking option are assigned to this role';
$string['definedteacherrole_desc'] = 'When a teacher is added to a bookingoption, he/she will be assigned to this role in the corresponding course.';
$string['definefieldofstudy'] = 'You can show here all booking options from the whole field fo study. To make this work,
 use groups with the name of your field of study. In a course which is used in "Psychology" and "Philosophy",
 you will have two groups, named like these fields of study. Follow this scheme for all your courses.
 Now add the custom booking field with the shortname "recommendedin", where you add the comma separated
 shortcodes of those courses, in which a booking option should be recommended. If a user is subscribed
 to "philosophy", she will see all the booking options in which at least one of the "philosohpy"-courses is recommended.';
$string['delcustfield'] = 'Delete this field and all associated field settings in the booking options';
$string['delete'] = 'Delete';
$string['deleteallchildren'] = 'Delete all children of this option';
$string['deletebooking'] = 'Delete this booking';
$string['deletebookingaction'] = 'Delete this after booking action';
$string['deletebookingcampaign'] = 'Delete campaign';
$string['deletebookingcampaignconfirmtext'] = 'Do you really want to delete the following campaign?';
$string['deletebookinglong'] = 'Do you really want to delete this booking?';
$string['deletebookingrule'] = 'Delete rule';
$string['deletebookingruleconfirmtext'] = 'Do you really want to delete the following rule?';
$string['deletecategory'] = 'Delete';
$string['deletecheckedanswersbody'] = 'Do you really want to delete the selected bookings?';
$string['deleteconditionsfrombookinganswer'] = 'Delete userdata from booking form';
$string['deletecustomfield'] = 'Delete custom field?';
$string['deletecustomfield_help'] = 'Caution: Setting this checkbox will delete the associated custom field when saving.';
$string['deleted'] = 'Deleted';
$string['deletedatafrombookinganswer'] = 'Delete userdata from booking form';
$string['deletedatafrombookingansweradhoc'] = 'Booking: Delete userdata from booking form (adhoc task)';
$string['deletedbookings'] = 'Deleted bookings';
$string['deletedbookingusermessage'] = 'Hello {$a->participant},
Your booking for {$a->title} ({$a->startdate} {$a->starttime}) has been cancelled.
';
$string['deletedbookingusersubject'] = 'Booking for {$a->title} cancelled';
$string['deletedrule'] = 'Rule deleted.';
$string['deletedtext'] = 'Cancelled booking message (enter 0 to turn off)';
$string['deletedtextmessage'] = 'Booking option has been deleted: {$a->title}
User: {$a->participant}
Title: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Course: {$a->courselink}
Booking link: {$a->bookinglink}
';
$string['deletedtextsubject'] = 'Deleted booking: {$a->title} by {$a->participant}';
$string['deletedusers'] = 'Deleted users';
$string['deleteholiday'] = 'Delete holiday';
$string['deleteinfoscheckboxadmin'] = 'Delete the data provided by the user after the option has ended.';
$string['deleteinfoscheckboxadminwarning'] = '<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span> A corresponding <a target="_blank" href="{$a}">booking rule</a> must be configured to execute this option!</span>
</div>';
$string['deleteoptiondate'] = 'Remove date';
$string['deleteresponsesactivitycompletion'] = 'Delete all users with completed activity: {$a}';
$string['deleterule'] = 'Delete';
$string['deletesemester'] = 'Delete semester';
$string['deletesubcategory'] = 'Please, first delete all subcategories of this category!';
$string['deletethisbookingoption'] = 'Delete this booking option';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['delnotification'] = 'You deleted {$a->del} of {$a->all} users. Users, that have completed activity, can\'t be deleted!';
$string['delnotificationactivitycompletion'] = 'You deleted {$a->del} of {$a->all} users. Users, that have completed activity, can\'t be deleted!';
$string['department'] = 'Department';
$string['description'] = 'Description';
$string['descriptionmaxlength'] = 'Maximum length of description';
$string['descriptionmaxlength_desc'] = 'The description of a booking option can\'t be longer';
$string['details'] = 'Details';
$string['disablebookingforinstance'] = 'Disable booking for all options of this instance';
$string['disablebookingusers'] = 'Disable booking of users - hide Book now button';
$string['disablecancel'] = "Disable cancellation of this booking option";
$string['disablecancelforinstance'] = "Disable cancellation for the whole booking instance.
(If you activate this, then it won't be possible to cancel any booking within this instance.)";
$string['disablepricecategory'] = 'Disable price category';
$string['disablepricecategory_help'] = 'When you disable a price category, you will not be able to use it anymore.';
$string['displayemptyprice'] = 'Display price if it is 0';
$string['displayemptyprice_desc'] = "If a booking option has prices for some price categories and not for others, you can decide if users for whom the option is free see the price 0.";
$string['displayinfoaboutrules'] = 'Display warning about the need to configure rules?';
$string['displayloginbuttonforbookingoptions'] = 'Display a button with redirect to login site for bookingoption';
$string['displayloginbuttonforbookingoptions_desc'] = 'Will be displayed for users not logged in only.';
$string['displayshoppingcarthistory'] = 'Display shoppingcart history at "my bookings"';
$string['displayshoppingcarthistory_desc'] = 'Should transactions, receipts etc. be displayed like with the shoppingcart shortname [shoppingcarthistory] on the page "My bookings" (mybookings.php)?';
$string['displaytext'] = "Display text";
$string['dontaddpersonalevents'] = 'Dont add personal calendar events';
$string['dontaddpersonaleventsdesc'] = 'For each booked option and for all of its sessions, personal events are created in the moodle calendar. Suppressing them improves performance for heavy load sites.';
$string['dontapply'] = 'Don\'t apply';
$string['dontmove'] = 'Don\'t move';
$string['dontusetemplate'] = 'Don\'t use template';
$string['download'] = 'Download';
$string['downloadallresponses'] = 'Download all responses for all booking options';
$string['downloaddemofile'] = 'Download demofile';
$string['downloadusersforthisoptionods'] = 'Download users as .ods';
$string['downloadusersforthisoptionxls'] = 'Download users as .xls';
$string['doyouwanttobook'] = 'Do you want to book <b>{$a}</b>?';
$string['duedate'] = 'Duedate of installment';
$string['duplicatebookingoption'] = 'Duplicate this booking option';
$string['duplicatemoodlecourses'] = 'Duplicate Moodle course';
$string['duplicatemoodlecourses_desc'] = 'When this setting is active and you duplicate a booking option,
then the connected Moodle course will also be duplicated. This will be done with an adhoc task,
so be sure that CRON runs regularly.';
$string['duplicatename'] = 'This booking option name already exists. Please choose another one.';
$string['duplication'] = 'Duplication';
$string['duplicationrestore'] = 'Booking instances: Duplication, backup and restore';
$string['duplicationrestorebookings'] = 'Include bookings <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['duplicationrestoredesc'] = 'Here you can set which information you want to include when duplicating or backing up / restoring booking instances.';
$string['duplicationrestoreentities'] = 'Include entities';
$string['duplicationrestoreoption'] = 'Booking options: Duplication settings <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['duplicationrestoreoption_desc'] = 'Special settings for the duplication of booking options.';
$string['duplicationrestoreprices'] = 'Include prices';
$string['duplicationrestoresubbookings'] = 'Include subbookings <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['duplicationrestoreteachers'] = 'Include teachers';
$string['duration'] = "Duration";
$string['duration:minutes'] = 'Duration (minutes)';
$string['duration:units'] = 'Units ({$a} min)';
$string['durationforcertificate'] = '{$a->hours} hour(s) {$a->minutes} minutes';
$string['easyavailabilitypreviouslybooked'] = 'Easy already booked condition';
$string['easyavailabilityselectusers'] = 'Easy selected users condition';
$string['easybookingclosingtime'] = 'Easy booking closing time';
$string['easybookingopeningtime'] = 'Easy booking opening time';
$string['easytext'] = 'Easy, not changeable text';
$string['editaction'] = "Edit Action";
$string['editbookingoption'] = 'Edit booking option';
$string['editbookingoptions'] = 'Edit Bookingoptions';
$string['editcampaign'] = 'Edit campaign';
$string['editcategory'] = 'Edit';
$string['editingoptiondate'] = 'You are currently editing this session';
$string['editinstitutions'] = 'Edit institutions';
$string['editotherbooking'] = 'Other booking rules';
$string['editrule'] = "Edit";
$string['editsubbooking'] = 'Edit subbooking';
$string['edittag'] = 'Edit';
$string['editteachers'] = 'Edit';
$string['editteacherslink'] = 'Edit teachers';
$string['educationalunitinminutes'] = 'Length of an educational unit (minutes)';
$string['educationalunitinminutes_desc'] = 'Enter the length of an educational unit in minutes. It will be used to calculate the performed teaching units.';
$string['elective'] = "Elective";
$string['electivedeselectbtn'] = 'Deselect elective';
$string['electiveforcesortorder'] = 'Teacher can force sort order';
$string['electivenotbookable'] = 'Not bookable';
$string['electivesbookedsuccess'] = 'Your selected electives have been booked successfully.';
$string['electivesettings'] = 'Elective Settings';
$string['email'] = "Email";
$string['emailbody'] = 'Email body';
$string['emailsettings'] = 'E-mail settings <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['enable'] = 'Enable';
$string['enablecompletion'] = 'At least one of the booked options has to be marked as completed';
$string['enablecompletion_help'] = 'To mark the options as completed, open the "Configure fields and columns" section and add the "Completed" field under "Manage responses". The options can then be marked as completed on the report page.';
$string['enablecompletiongroup'] = 'Require entries';
$string['enddate'] = "Enddate";
$string['endtime'] = "Endtime";
$string['endtimenotset'] = 'End date not set';
$string['enforceorder'] = 'Enforce booking order';
$string['enforceorder_help'] = 'Users will be inscribed only once they have completed the previous booking option';
$string['enforceteacherorder'] = 'Enforce teachers order';
$string['enforceteacherorder_help'] = 'Users will not be able to define order of selected options but they will be determined by teacher';
$string['enrolementstatus'] = 'Mode of course enrolment';
$string['enrolledcomments'] = 'Only enrolled';
$string['enrolledinoptions'] = "already booked in booking options: ";
$string['enrolledratings'] = 'Only enrolled';
$string['enrolledusers'] = 'Users enrolled in course';
$string['enrollink'] = 'Enrolment link';
$string['enrollink:0'] = 'An error occured during the enrolment';
$string['enrollink:1'] = 'You are already enrolled to this course and can access it';
$string['enrollink:2'] = 'Successfully enrolled';
$string['enrollink:3'] = 'Your enrolment link is invalid.';
$string['enrollink:4'] = 'There are no more places available in the bundle.';
$string['enrollink:5'] = 'No guestusers allowed';
$string['enrollink:6'] = 'Your registration has been completed and must still be confirmed by an authorised person.';
$string['enrollinktriggered'] = 'Enrol link generation triggered';
$string['enrollinktriggered:description'] = 'The event as the basis for the automatic generation of an enrolment link has been triggered.';
$string['enrolmentstatus'] = 'Enrol users at course start time (Default: Not checked &rarr; enrol them immediately.)';
$string['enrolmentstatus_help'] = 'Notice: In order for automatic enrolment to work, you need to change the booking instance setting
 "Automatically enrol users" to "Yes".';
$string['enrolmultipleusers'] = 'Enrol multiple users';
$string['enrolusersaction:alert'] = '<div class="alert alert-info" role="alert">
<i class="fa fa-info-circle"></i>
<span><b>This function refers to the selected course in the Moodle Courses area..</b> <br>
</span>
</div>';
$string['enroluserstowaitinglist'] = "Put booking users on waitinglist and enrol only after confirmation?";
$string['enroluserwhobookedtocourse'] = "Do you also want to book the option for yourself?";
$string['enroluserwhobookedtocoursewarning'] = "If you purchase only one seat and choose to be enrolled yourself, there will be no enrollment link created.";
$string['enteruserprofilefield'] = "Select users by entering a value for custom user profile field. Attention! This targets all the users on the plattform.";
$string['entervalidurl'] = 'Please, enter a valid URL!';
$string['entities'] = 'Choose places with entities plugin';
$string['entitiesfieldname'] = 'Place(s)';
$string['entitydeleted'] = 'Location has been deleted';
$string['equals'] = 'has exactly this value (text or number)';
$string['equalsnot'] = 'has not exactly this value (text or number)';
$string['equalsnotplain'] = 'has not exactly this value';
$string['equalsplain'] = 'has exactly this value';
$string['error:bookingstrackernotactivated'] = 'You are not allowed to open this page.
Either the setting for the bookings tracker (bookingstracker) is not activated or you do not have Booking PRO
(or Booking PRO is expired).';
$string['error:campaignend'] = 'Campaign end has to be after campaign start.';
$string['error:campaignstart'] = 'Campaign start has to be before campaign end.';
$string['error:chooseint'] = 'You have to enter a number here.';
$string['error:choosevalue'] = 'You have to choose a value here.';
$string['error:confirmthatyouaresure'] = 'Please confirm that you are sure.';
$string['error:coursecategoryvaluemissing'] = 'You need to choose a value here as it is needed as course category
for the automatically created Moodle course.';
$string['error:deactivatelegacymailtemplates'] = 'If you want to use this rule you first have to <a href="{$a}" target="_blank">deactivate legacy mail templates</a>.';
$string['error:entervalue'] = 'You have to enter a value here.';
$string['error:failedtosendconfirmation'] = 'The following user did not receive a confirmation mail
Booking status: {$a->status}
Participant: {$a->participant}
Booking option: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Link: {$a->bookinglink}
Associated course: {$a->courselink}
';
$string['error:formcapabilitymissing'] = 'You do not have the necessary permission to edit this form. Please contact an administrator.';
$string['error:identifierexists'] = 'Choose another identifier. This one already exists.';
$string['error:invalidcmid'] = 'The report cannot be opened because no valid course module ID (cmid) was provided. It needs to be the cmid of a booking instance!';
$string['error:limitfactornotbetween1and2'] = 'You need to enter a value between 0 and 2, e.g. 1.2 to add 20% more bookable places.';
$string['error:missingblockinglabel'] = 'Please enter the message to show when booking is blocked.';
$string['error:missingcapability'] = 'Necessary capability is missing. Please contact an administrator.';
$string['error:missingteacherid'] = 'Error: Report cannot be loaded because of missing teacherid.';
$string['error:mustnotbeempty'] = 'Must not be empty.';
$string['error:negativevaluenotallowed'] = 'Please enter a positive value.';
$string['error:newcoursecategorycfieldmissing'] = 'You need to create a <a href="{$a->bookingcustomfieldsurl}"
 target="_blank">booking custom field</a> for new course categories first. After you have created one, make sure
 it is selected in the <a href="{$a->settingsurl}" target="_blank">Booking plugin settings</a>.';
$string['error:noendtagfound'] = 'End the placeholder section "{$a}" with backslash ("/").';
$string['error:nofieldchosen'] = 'You have to choose a field.';
$string['error:percentageavailableplaces'] = 'You need to enter a valid percentage beween 0 and 100 (without %-sign!).';
$string['error:pricefactornotbetween0and1'] = 'You need to enter a value between 0 and 1, e.g. 0.9 to reduce prices by 10%.';
$string['error:pricemissing'] = 'Please enter a price.';
$string['error:reasonfordeduction'] = 'Enter a reason for the deduction.';
$string['error:reasonfornoteacher'] = 'Enter a reason why no teachers were present at this date.';
$string['error:reasonforsubstituteteacher'] = 'Enter a reason for the substitute teacher(s).';
$string['error:reasontoolong'] = 'Reason is too long, enter a shorter text.';
$string['error:ruleactionsendcopynotpossible'] = 'It\'s not possible to send an e-mail copy for the event you chose.';
$string['error:selflearningcourseallowsnodates'] = 'Booking options of type "{$a}" are not allowed to have dates. Please remove all dates and save again.';
$string['error:semestermissingbutcanceldependentonsemester'] = 'The setting to calculate cancellation periods from semester start is active but semester is missing!';
$string['error:taskalreadystarted'] = 'You have already started a task!';
$string['error:wrongteacherid'] = 'Error: No user could be found for the provided "teacherid".';
$string['errorduplicatepricecategoryidentifier'] = 'Price category identifiers need to be unique.';
$string['errorduplicatepricecategoryname'] = 'Price category names need to be unique.';
$string['errorduplicatepricecatsortorder'] = 'Price category sort order needs to be unique.';
$string['errorduplicatesemesteridentifier'] = 'Semester identifiers need to be unique.';
$string['errorduplicatesemestername'] = 'Semester names need to be unique.';
$string['erroremptycustomfieldname'] = 'Custom field name is not allowed to be empty.';
$string['erroremptycustomfieldvalue'] = 'Custom field value is not allowed to be empty.';
$string['erroremptypricecategoryidentifier'] = 'Price category identifier is not allowed to be empty.';
$string['erroremptypricecategoryname'] = 'Price category name is not allowed to be empty.';
$string['erroremptysemesteridentifier'] = 'Semester identifier is needed!';
$string['erroremptysemestername'] = 'Semester name is not allowed to be empty';
$string['errorholidayend'] = 'Holiday is not allowed to end before the start date.';
$string['errorholidaystart'] = 'Holiday is not allowed to start after the end date.';
$string['errormultibooking'] = 'There was an ERROR when booking the electives.';
$string['erroroptiondateend'] = 'Date end needs to be after date start.';
$string['erroroptiondatestart'] = 'Date start needs to be before date end.';
$string['errorpagination'] = 'Please enter a number bigger than 0';
$string['errorpricecategoryidentifierdefaultnotallowed'] = 'The identifier "default" is reserved for the first price category.';
$string['errorpricecategoryidentifiermustbedefault'] = 'The identifier "default" must be used for the first price category.';
$string['errorsemesterend'] = 'Semester end needs to be after semester start.';
$string['errorsemesterstart'] = 'Semester start needs to be before semester end.';
$string['errortoomanydecimals'] = 'Only 2 decimals are allowed.';
$string['errorusernotfound'] = 'Error: The organizer with ID "{$a}" could not be found.';
$string['eventalreadyover'] = 'This event is already over.';
$string['eventdesc:bookinganswercancelled'] = 'The user "{$a->user}" cancelled "{$a->relateduser}" from "{$a->title}".';
$string['eventdesc:bookinganswercancelledself'] = 'The user "{$a->user}" cancelled "{$a->title}".';
$string['eventdesc:bookinganswercustomformconditionsdeleted'] = 'The user "{$a->user}" deleted data about the condition customform of {$a->relateduser} in bookinanswer with ID "{$a->bookinganswerid}".';
$string['eventdesc:bookinganswerupdated'] = 'The user "{$a->user}" updated "{$a->title}" in column "{$a->column}".';
$string['eventdescription'] = "Description of event";
$string['eventduration'] = 'Event duration';
$string['eventpoints'] = 'Points';
$string['eventreportviewed'] = 'Report viewed';
$string['eventslist'] = 'Recent updates';
$string['eventteacheradded'] = 'Teacher added';
$string['eventteacherremoved'] = 'Teacher removed';
$string['eventtype'] = 'Event type';
$string['eventtype_help'] = 'You can either enter the event type manually or choose from a list of previous event types.
                             You can choose one event type only. Once you save, the event type will be added to the list.';
$string['eventuserprofilefieldsupdated'] = 'Userprofile updated';
$string['excelfile'] = 'CSV file with activity completion';
$string['executerestscript'] = 'Execute REST script';
$string['existingsubscribers'] = 'Existing subscribers';
$string['expired'] = 'Sorry, this activity closed on {$a} and is no longer available';
$string['extendlimitforoverbooked'] = 'Add overbooked seats to applied factor';
$string['extendlimitforoverbooked_help'] = 'If you select this option, the following happens:
    A course has a limit of 40 but is already overbooked with 2 participants to 42 participants.
    If a limit increase of, for example, 10% is applied to this course, the limit will be increased to 46 (40 + 4 (10%) + 2 (overbooked seats)), instead of 44 (40 + 4).';
$string['fallbackonlywhenempty'] = 'Fallback only when user profile field is empty';
$string['fallbackonlywhennotmatching'] = 'Fallback when not matching (also empty)';
$string['fallbackturnedoff'] = 'Fallback turned off';
$string['feedbackurl'] = 'Poll url';
$string['feedbackurl_help'] = 'Enter a link to a feedback form that should be sent to participants.
 It can be added to e-mails with the <b>{pollurl}</b> placeholder.';
$string['feedbackurlteachers'] = 'Teachers poll url';
$string['feedbackurlteachers_help'] = 'Enter a link to a feedback form that should be sent to teachers.
 It can be added to e-mails with the <b>{pollurlteachers}</b> placeholder.';
$string['fieldnamesdontmatch'] = "The imported fieldnames don't match the defined fieldnames.";
$string['fieldofstudycohortoptions'] = "Shortcode to show all booking options of a field of study.
 They are defined by a course group with the same name. Booking options are defined by having comma
 separated shortnames of at least one of theses courses in the recommendedin custom booking options field.";
$string['fieldofstudyoptions'] = "Shortcode to show all booking options of a field of study.
 They are defined by a common cohort sync enrolement & the booking availabilty condition of
 having to be inscribed in one of these courses.";
$string['fillinatleastoneoption'] = 'You need to provide at least two possible answers.';
$string['filterbtn'] = 'Filter';
$string['filterenddate'] = 'Until';
$string['filterstartdate'] = 'From';
$string['firstname'] = "Firstname";
$string['firstnamerelated'] = "Firstname of the related person (like i.e. the user the booking was made for)";
$string['forcourse'] = 'for course';
$string['format'] = 'format';
$string['formconfig'] = 'Show which form is used.';
$string['formtype'] = "Type of form";
$string['friday'] = 'Friday';
$string['from'] = 'From';
$string['full'] = 'Full';
$string['fullname'] = 'Full name';
$string['fullwaitinglist'] = 'Full waitinglist';
$string['fullybooked'] = 'Fully booked';
$string['general'] = 'General';
$string['generalsettings'] = 'General settings';
$string['generaterecnum'] = "Generate numbers";
$string['generaterecnumareyousure'] = "This will generate new numbers and permanently delete the old one!";
$string['generaterecnumnotification'] = "New numbers have been generated.";
$string['global'] = 'global';
$string['globalactivitycompletiontext'] = 'Message to be sent to user when booking option is completed (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalbookedtext'] = 'Booking confirmation (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalbookingchangedtext'] = 'Message to be sent when a booking option changes (will only be sent to users who have already booked). Use the placeholder {changes} to show the changes. Enter 0 to turn off change notifications. (Global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalcurrency'] = 'Currency';
$string['globalcurrencydesc'] = 'Choose the currency for booking option prices';
$string['globaldeletedtext'] = 'Cancelled booking message (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalmailtemplates'] = 'Legacy mail templates <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalmailtemplates_desc'] = 'After activation, you can go to the settings of a booking instance and set the source of mail templates to global.';
$string['globalnotifyemail'] = 'Participant notification before start (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalnotifyemailteachers'] = 'Teacher notification before start (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalpollurlteacherstext'] = 'Message for the poll url sent to teachers (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalpollurltext'] = 'Message for sending poll url to booked users (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalstatuschangetext'] = 'Status change message (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globaluserleave'] = 'User has cancelled his/her own booking (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['globalwaitingtext'] = 'Waiting list confirmation (global template) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Deprecated</span>';
$string['gotobooking'] = '&lt;&lt; Bookings';
$string['gotobookingoption'] = "Go to bookingoption";
$string['gotobookingoptionlink'] = '{$a}';
$string['gotomanageresponses'] = '&lt;&lt; Manage bookings';
$string['gotomoodlecourse'] = 'Go to Moodle course';
$string['groupdeleted'] = 'This booking instance creates groups automatically in the target course. But the group has been manually deleted in the target course. Activate the following checkbox in order to recreate the group';
$string['groupexists'] = 'The group already exists in the target course, please choose another name for the booking option';
$string['groupname'] = 'Group name';
$string['h'] = '';
$string['hascapability'] = 'Except has capability';
$string['helptext:emailsettings'] = '<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span>&nbsp;Deprecated function, please migrate your templates & settings to <a href="{$a}">Booking Rules</a></span>!
</div>';
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
$string['hidedescription'] = 'Hide description';
$string['hidelistoncoursepage'] = 'Hide extra information on course page (default)';
$string['holiday'] = "Holiday";
$string['holidayend'] = 'End';
$string['holidayendactive'] = 'End is not on the same day';
$string['holidayname'] = "Holiday name (optional)";
$string['holidays'] = "Holidays";
$string['holidaystart'] = 'Holiday (start)';
$string['hours'] = '{$a} hours';
$string['howmanytimestorepeat'] = 'Number of repetitions';
$string['howmanyusers'] = 'Book other users limit';
$string['howoftentorepeat'] = 'Repetition interval';
$string['icalcancel'] = 'Include iCal event when booking is cancelled as cancelled event';
$string['icalcanceldesc'] = 'When a users cancels a booking or is removed from the booked users list, then attach an iCal attachment as cancelled event.';
$string['icalcfg'] = 'Calendar settings and configuration of iCal attachements';
$string['icalcfgdesc'] = 'Configure calendar settings and the iCal (*.ics) files that are attached to e-mail messages. These files alow adding the booking dates to the personal calendar.';
$string['icalfieldlocation'] = 'Text to display in iCal field location';
$string['icalfieldlocationdesc'] = 'Choose from the dropdown list what what text should be used for the calendar field location';
$string['id'] = "Id";
$string['identifier'] = 'Identification';
$string['ifdefinedusedtomatch'] = 'If defined, will be used to match.';
$string['importaddtocalendar'] = 'Zum Moodle Kalender hinzufügen';
$string['importcolumnsinfos'] = 'Informations about columns to be imported:';
$string['importcoursenumber'] = 'Moodle ID number of a Moodle course in which the participants are enrolled';
$string['importcourseshortname'] = 'Short name of a Moodle course in which the participants are enrolled';
$string['importcsv'] = 'CSV Importer';
$string['importcsvbookingoption'] = 'Import CSV with booking options';
$string['importcsvtitle'] = 'Import CSV';
$string['importdayofweek'] = 'Day of the week for a booking option, e.g., Monday';
$string['importdayofweekendtime'] = 'End time of a course, e.g., 12:00';
$string['importdayofweekstarttime'] = 'Start time of a course, e.g., 10:00';
$string['importdayofweektime'] = 'Day of the week and time for a booking option, e.g., Monday, 10:00 - 12:00';
$string['importdefault'] = 'Default price of a booking option. Additional prices can only be specified if the default price is set. The columns must match the short names of the booking categories.';
$string['importdescription'] = 'Description of the booking option';
$string['importexcelbutton'] = 'Import activity completion';
$string['importexceltitle'] = 'Import activity completion';
$string['importfailed'] = 'Import failed';
$string['importfinished'] = 'Importing finished!';
$string['importidentifier'] = 'Einzigartiger Identifikator einer Buchungsoption';
$string['importinfo'] = 'Import info: You can use the following columns in the csv upload (Explanation in parenthesis)';
$string['importlocation'] = 'Ort einer Buchungsoption. Wird automatisch bei 100% Übereinstimmung mit dem Klarnamen einer "Entity" (local_entities) verknüpft. Auch die ID Nummer einer Entity kann hier eingegeben werden.';
$string['importmaxanswers'] = 'Maximale Anzahl von Buchungen pro Buchungsoption';
$string['importmaxoverbooking'] = 'Maximale Anzahl an Wartelistenplätzen pro Buchungsoption';
$string['importpartial'] = 'The import was only partially completed. There were problems with following lines and they were not imported: ';
$string['importsuccess'] = 'Import was successful. {$a} record(s) treated.';
$string['importteacheremail'] = 'Email addresses of users on the platform who can be listed as teachers in the booking options. Use a comma as a separator for multiple email addresses (beware of escaping for comma-separated CSVs!)';
$string['importtext'] = 'Title of a booking option (synonym for text)';
$string['importtileprefix'] = 'Prefix (e.g., course number)';
$string['importtitle'] = 'Title of a booking option';
$string['importuseremail'] = 'Email addresses of users on the platform who have booked this option. Use a comma as a separator for multiple email addresses (beware of escaping for comma-separated CSVs!)';
$string['inarray'] = 'user has one of these comma separated values';
$string['includeteachers'] = 'Include teachers in the sign-in sheet';
$string['indexnumber'] = 'Numbering';
$string['info:teachersforoptiondates'] = 'Go to the <a href="{$a}" target="_self">teaching journal</a>, to manage teachers for specific dates.';
$string['infoalreadybooked'] = '<div class="infoalreadybooked"><i>You are already booked for this option.</i></div>';
$string['infonobookingoption'] = 'In order to add a booking option please use the settings block or the settings-icon on top of the page';
$string['infotext:installmoodlebugfix'] = 'Wunderbyte has added a bug fix to the Moodle core. This bug fix has not yet been included in your Moodle version. Therefore, you may encounter JavaScript error messages in certain areas. Starting with Moodle 4.1, it is sufficient to apply the ongoing security updates.';
$string['infotext:prolicensenecessary'] = '<a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">Get your PRO license here...</a>';
$string['infotext:prolicensenecessarytextandlink'] = 'You need a Booking PRO license if you want to use this feature <a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">Get your PRO license here...</a>';
$string['infowaitinglist'] = '<div class="infowaitinglist"><i>You are on the waiting list for this option.</i></div>';
$string['installmentprice'] = 'Price of installment';
$string['installmoodlebugfix'] = 'Moodle update necessary <span class="badge bg-danger text-light"><i class="fa fa-cogs" aria-hidden="true"></i> Important</span>';
$string['instancename'] = "Name of instance";
$string['instancenotsavednovalidlicense'] = 'Booking instance could not be saved as template.
                                                  Upgrade to PRO version to save an unlimited number of templates.';
$string['instancesuccessfullysaved'] = 'This booking instance was successfully saved as template.';
$string['instancetemplate'] = 'Instance template';
$string['institution'] = 'Institution';
$string['institution_help'] = 'You can either enter the institution name manually or choose from a list of previous institutions.
                                    You can choose one institution only. Once you save, the institution will be added to the list.';
$string['institutions'] = 'Institutions';
$string['interval'] = "Delay";
$string['interval_help'] = "In minutes. 1440 for 24 hours.";
$string['invisible'] = 'Invisible';
$string['invisibleoption'] = '<i class="fa fa-eye-slash" aria-hidden="true"></i> Invisible';
$string['invisibleoption:notallowed'] = 'You are not allowed to see this booking option.';
$string['invisibleoptions'] = 'Invisible booking options';
$string['iselective'] = 'Use instance as elective';
$string['iselective_help'] = 'This allows you to force users to book several booking options at once in a specific order
 or in specific relations to each other. Additionally, you can force the use of credits.';
$string['isempty'] = 'field is empty';
$string['isnotempty'] = 'field is not empty';
$string['journal'] = "Journal";
$string['json'] = "Stores supplementary information";
$string['keepusersbookedonreducingmaxanswers'] = 'Keep users booked on limit reduction';
$string['keepusersbookedonreducingmaxanswers_desc'] = 'Keep users booked even when the limit of bookable places (maxanswers) is reduced.
Example: A booking option has 5 spots. The limit is reduced to 3. The 5 users who have already booked will still remain booked.';
$string['lastname'] = "Lastname";
$string['lastnamerelated'] = "Lastname of related person (like i.e. the user the booking was made for)";
$string['lblacceptingfrom'] = 'Name of label: Accepting from';
$string['lblbooking'] = 'Name of label: Booking';
$string['lblbooktootherbooking'] = 'Name of button: Book users to other booking option';
$string['lblinstitution'] = 'Name of label: Institution';
$string['lbllocation'] = 'Name of label: Location';
$string['lblname'] = 'Name of label: Name';
$string['lblnumofusers'] = 'Name of label: Num. of users';
$string['lblsputtname'] = 'Name of label: Send poll url to teachers';
$string['lblsurname'] = 'Name of label: Surname';
$string['lblteachname'] = 'Name of label: Teachers';
$string['leftandrightdate'] = '{$a->leftdate} to {$a->righttdate}';
$string['licenseactivated'] = 'PRO version activated successfully.<br>(Expires: ';
$string['licenseinvalid'] = 'Invalid license key';
$string['licensekey'] = 'PRO license key';
$string['licensekeycfg'] = 'Activate PRO version';
$string['licensekeycfgdesc'] = '<div class="alert alert-warning"><i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;
<a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">
Get you FREE trial of Booking PRO for 30 days! Click here for more info.
</a>
</div>';
$string['licensekeycfgdesc:active'] = '<div class="alert alert-info"><i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;
<a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">
When your license expires, you can click here to renew it.
</a>
</div>';
$string['licensekeydesc'] = 'Upload a valid license key to activate the PRO version.';
$string['limit'] = 'Limit';
$string['limitanswers'] = 'Limit the number of participants';
$string['limitanswers_help'] = 'If you change this option and you have booked people, you can remove them without notification!';
$string['limitchangestrackinginrules'] = "Limit reactions on changes in booking rules";
$string['limitchangestrackinginrulesdesc'] = "If you activate this setting, the booking rule react on change will only apply to the selected fields.";
$string['limitfactor'] = 'Booking limit factor';
$string['limitfactor_help'] = 'Specify a value by which to multiply the booking limit. For example, to increase the booking limit by 20%, enter the value <b>1.2</b>. Always rounded up. Set to 0 for unlimited.';
$string['linkbacktocourse'] = 'Link to booking option';
$string['linkgotobookingoption'] = 'Go to booked option: {$a}</a>';
$string['linknotavailableyet'] = "The link to access the meeting is available only 15 minutes before the start
until the end of the session.";
$string['linknotvalid'] = 'This link or meeting is not accessible.
If it is a meeting you have booked, please check again, shortly before start.';
$string['linktocalendarurltext'] = "Link to calendar";
$string['linktocourse'] = "Link to course";
$string['linktomoodlecourseonbookedbutton'] = 'Show Link to Moodle course directly on booked button';
$string['linktomoodlecourseonbookedbutton_desc'] = 'Instead of an extra link, this will transform the booked button the a link to the moodle course';
$string['linktoshowroom:bookingrules'] = '<div class="alert alert-secondary"><i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;
<a href="https://showroom.wunderbyte.at/course/view.php?id=70" target="_blank">
Click here to expand yor knowledge on Booking Rules.
</a>
</div>';
$string['linktoteachersinstancereport'] = '<p><a href="{$a}" target="_self">&gt;&gt; Go to teachers report for booking instance</a></p>';
$string['listentoaddresschange'] = "React on change of address of bookingoption";
$string['listentoresponsiblepersonchange'] = "React on change of responsible person of bookingoption";
$string['listentoteacherschange'] = "React on change of teacher of bookingoption";
$string['listentotextchange'] = "React on change of text of bookingoption";
$string['listentotimestampchange'] = "React on change of time (and day) of bookingoption";
$string['location'] = 'Location';
$string['location_help'] = 'You can either enter the location name manually or choose from a list of previous locations.
                                    You can choose one location only. Once you save, the location will be added to the list.';
$string['loginbuttonforbookingoptionscoloroptions'] = 'Choose style (color) of displayed button';
$string['loginbuttonforbookingoptionscoloroptions_desc'] = 'Uses bootstrap 4 styles. Colors are for default application.';
$string['loopprevention'] = 'The placeholder {$a} causes a loop. Please replace it.';
$string['lowerthan'] = 'is lower than (number)';
$string['mail'] = 'Mail';
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['mailtemplatesadvanced'] = 'Activate advanced settings for e-mail templates';
$string['mailtemplatesglobal'] = 'Use global mail templates from plugin settings';
$string['mailtemplatesinstance'] = 'Use mail templates from this booking instance (default)';
$string['mailtemplatessource'] = 'Set source of mail templates';
$string['mailtemplatessource_help'] = '<b>Caution:</b> If you choose global e-mail templates, the instance-specific mail
templates won\'t be used. Instead the e-mail templates specified in the booking plugin settings will be used. <br><br>
Please make sure that there are existing e-mail templates in the booking settings for each e-mail type.';
$string['managebookedusers_heading'] = 'Manage bookings for <b>{$a->scopestring}</b>: "{$a->title}"';
$string['managebooking'] = 'Manage';
$string['managebookinginstancetemplates'] = 'Manage booking instance templates';
$string['managecustomreporttemplates'] = 'Manage custom report templates';
$string['manageoptiontemplates'] = 'Manage booking option templates';
$string['manageresponses'] = 'Manage bookings';
$string['manageresponsesdownloadfields'] = 'Manage responses - Download (CSV, XLSX...)';
$string['manageresponsespagefields'] = 'Manage responses - Page';
$string['mandatory'] = 'mandatory';
$string['matchuserprofilefield'] = "Select users by matching field in booking option and user profile field.";
$string['maxanswers'] = 'Limit for answers';
$string['maxcredits'] = 'Max credits to use';
$string['maxcredits_help'] = 'You can define the max amount of credits users can or must use when booking options. You can define in every booking option how many credits it is worth.';
$string['maxoptionsfromcategory'] = 'Limit the number of bookings per category';
$string['maxoptionsfromcategorycount'] = 'How many booking options can a user book at most in category "{$a}"? This applies to each field as selected below and 0 means unlimited.';
$string['maxoptionsfromcategorydesc'] = 'The exact settings are made in the booking instance. If desired, check the box, save and set which field should be selected in the next step.';
$string['maxoptionsfromcategoryfield'] = 'Which field should be used for the restrictions?';
$string['maxoptionsfromcategoryfielddesc'] = 'Select a field whose values can be used to restrict the booking of multiple options for users in the booking instance.';
$string['maxoptionsfromcategoryvalue'] = 'Which value needs to be given in "{$a}" so that this restriction is applied?';
$string['maxoptionsfrominstance'] = 'Limitation applies only to bookings of this instance';
$string['maxoptionsstring'] = 'You have reached the maximum of bookings of this type.';
$string['maxoptionsstringdetailed'] = 'You have reached the maximum of {$a->max} bookings of type "{$a->type}" (in category "{$a->category}"): <br> {$a->maxoptions}';
$string['maxoverbooking'] = 'Max. number of places on waiting list';
$string['maxoverbooking_help'] = 'Enter "-1" for unlimited waitinglist and "0" to disable waitinglist.';
$string['maxparticipantsnumber'] = 'Max. number of participants';
$string['maxparticipantsnumber_help'] = 'Enter "0" for unlimited';
$string['maxperuser'] = 'Max current bookings per user';
$string['maxperuser_help'] = 'The maximum number of bookings an individual user can make in this activity at once.
<b>Attention:</b> In the Booking plugin settings, you can choose if users who completed or attended and booking options
that have already passed should be counted or not counted to determine the maximum number of bookings a user can book within this instance.';
$string['maxperuserdontcountcompleted'] = 'Max. number of bookings: Ignore completed';
$string['maxperuserdontcountcompleted_desc'] = 'Do not count bookings that have been marked as "completed" or that
have a presence status "Attending" or "Complete" when calculating the maximum number of bookings per user per instance';
$string['maxperuserdontcountnoshow'] = 'Max. number of bookings: Ignore users who did not show up';
$string['maxperuserdontcountnoshow_desc'] = 'Do not count bookings that have been marked as "No show"
when calculating the maximum number of bookings per user per instance';
$string['maxperuserdontcountpassed'] = 'Max. number of bookings: Ignore courses passed';
$string['maxperuserdontcountpassed_desc'] = 'When calculating the maximum number of bookings per user per instance,
do not count booking options that have already passed';
$string['maxperuserwarning'] = 'You currently have used {$a->count} out of {$a->limit} maximum available bookings ({$a->eventtype}) for your user account';
$string['messagebutton'] = 'Message';
$string['messageprovider:bookingconfirmation'] = "Booking confirmations";
$string['messageprovider:sendmessages'] = 'Can send messages';
$string['messagesend'] = 'Your message has been sent.';
$string['messagesent'] = 'Message sent';
$string['messagesubject'] = 'Subject';
$string['messagetext'] = 'Message';
$string['messagingteacherimpossible'] = 'You cannot send messages to this teacher
 because you are not enrolled in any courses of her/him.';
$string['minanswers'] = 'Min. number of participants';
$string['minanswers_help'] = '"0" means no minimum';
$string['minutes'] = '{$a} minutes';
$string['missinghours'] = 'Missing hours';
$string['missinglabel'] = 'Imported CSV does not contain mandatory column {$a}. Data can not be imported.';
$string['mobileappheading'] = "Mobile App";
$string['mobileappheading_desc'] = "Choose your booking instance to display on the connected Moodle Mobile Apps.";
$string['mobileappnobookinginstance'] = "No booking instance on your plattform";
$string['mobileappnobookinginstance_desc'] = "You need to create at least one booking instance";
$string['mobileappprice'] = 'Price';
$string['mobileappsetinstance'] = "Booking instance";
$string['mobileappsetinstancedesc'] = "Choose the Booking instance which should be shown on the mobile app.";
$string['mobilefieldrequired'] = 'This field is required';
$string['mobilenotification'] = 'Form has been submitted';
$string['mobileresetsubmission'] = 'Reset Submission form';
$string['mobilesetsubmission'] = 'Submit';
$string['mobilesettings'] = 'Settings for the Moodle App';
$string['mobilesettings_desc'] = 'Here you can set parameters for the Moodle Mobile App';
$string['mobilesubmittedsuccess'] = 'You can continue and book the course';
$string['mobileviewoptionsdesc'] = 'Selection of available mobile views';
$string['mobileviewoptionstext'] = 'Mobile Views';
$string['mod/booking:bookanyone'] = 'Book anyone';
$string['mod/booking:expertoptionform'] = 'Bookingoption for experts';
$string['mod/booking:reducedoptionform1'] = 'Reduced booking option 1';
$string['mod/booking:reducedoptionform2'] = 'Reduced booking option 2';
$string['mod/booking:reducedoptionform3'] = 'Reduced booking option 3';
$string['mod/booking:reducedoptionform4'] = 'Reduced booking option 4';
$string['mod/booking:reducedoptionform5'] = 'Reduced booking option 5';
$string['mod/booking:seepersonalteacherinformation'] = 'See personal teacher information';
$string['modaloptiondateformtitle'] = 'Custom dates';
$string['modulename'] = 'Booking';
$string['modulenameplural'] = 'Bookings';
$string['monday'] = 'Monday';
$string['movedbookinghistory'] = 'The booking option has been moved from the booking with ID: {a->oldbooking} to {$a->newbooking}.';
$string['moveoption'] = 'Move booking option';
$string['moveoption_help'] = 'Move booking option to different booking instance';
$string['moveoptionto'] = 'Move booking option to other booking instance';
$string['multipledayofweektimestringshint'] = 'You can enter enter one combination of week day and time <b>per line</b>.<br>Example: "Monday, 10:00 - 12:00" and "Tuesday, 15:00 - 16:30"';
$string['multiselect'] = 'Multiple selection';
$string['mustchooseone'] = 'You must choose an option before saving. Nothing was saved.';
$string['mustcombine'] = 'Necessary booking options';
$string['mustcombine_help'] = 'Booking options which have to be combined with this option';
$string['mustfilloutuserinfobeforebooking'] = 'Befor proceeding to the booking form, please fill in some personal booking information';
$string['mustnotcombine'] = 'Excluded booking options';
$string['mustnotcombine_help'] = 'Booking options which can\'t be  combined with this option';
$string['mybookingoptions'] = 'My booked options';
$string['mybookingsbooking'] = 'Booking (Course)';
$string['mybookingsoption'] = 'Option';
$string['mycourselist'] = 'Show my Booking options. ';
$string['myinstitution'] = 'My institution';
$string['name'] = 'Name';
$string['newcourse'] = 'Create new course...';
$string['newcoursecategorycfield'] = 'Booking option custom field to be used as course category';
$string['newcoursecategorycfielddesc'] = 'Choose a booking option custom field which will be used as course category for automatically created
 courses using the dropdown entry "Create new course..." in the form for creating new booking options.';
$string['newoptiondate'] = 'Create a new session...';
$string['newtemplatesaved'] = 'New template for booking option was saved.';
$string['next'] = 'Next';
$string['no'] = 'No';
$string['nobookinginstancesexist'] = 'No booking instances exist yet';
$string['nobookingpossible'] = 'No booking possible.';
$string['nobookingselected'] = 'No booking option selected';
$string['nocancelreason'] = "You need to give a reason for canceling this booking option";
$string['nocfnameselected'] = "Nothing selected. Either type new name or select one from the list.";
$string['nocomments'] = 'Commenting disabled';
$string['nocourse'] = 'No course selected for this booking option';
$string['nocourseselected'] = 'No course selected';
$string['nodatesstring'] = "There are currently no dates associated with this booking option";
$string['nodatesstring_desc'] = "no dates";
$string['nodescriptionmaxlength'] = 'No maximum length of description';
$string['nodirectbookingbecauseofprice'] = 'Booking for others is only partially possible with this booking option. The reasons for this are as follows:
    <ul>
    <li>a price is entered</li>
    <li>the Shopping Cart module is installed</li>
    <li>the waiting list is not globally deactivated</li>
    </ul>
    The intention of this behaviour is to prevent "mixed" bookings with and without shopping cart. Please use shopping cart cashier function to book users.';
$string['noelement'] = "No element";
$string['noeventtypeselected'] = 'No event type selected';
$string['nofieldchosen'] = 'No field chosen';
$string['nofieldofstudyfound'] = "No field of study could be determined via cohorts";
$string['noformlink'] = "No link to form of booking option";
$string['nogrouporcohortselected'] = 'You need to select at least one group or cohort.';
$string['noguestchoose'] = 'Sorry, guests are not allowed to enter data';
$string['noinstitutionselected'] = 'No institution selected';
$string['nolabels'] = 'No column labels defined in settings object.';
$string['nolocationselected'] = 'No location selected';
$string['nomoodlecourseconnection'] = 'No connection to Moodle course';
$string['nooptionselected'] = 'No booking option selected';
$string['nooverlapblocking'] = 'This option cannot be booked as it overlaps with your already booked option(s): {$a}';
$string['nooverlappingselectblocking'] = 'Block booking';
$string['nooverlappingselectinfo'] = 'If this booking option is selected even though the sessions overlap with another, what should happen?';
$string['nooverlappingselectwarning'] = 'Show warning';
$string['nooverlappingsettingcheckbox'] = 'Restrict booking of overlapping options';
$string['nooverlapwarning'] = 'Warning, this option overlaps with your already booked option(s): {$a}';
$string['nopermissiontoaccesscontent'] = '<div class="alert alert-danger" role="alert">You have no permission to access this content.</div>';
$string['nopermissiontoaccesspage'] = '<div class="alert alert-danger" role="alert">You have no permission to access this page.</div>';
$string['nopricecategoriesyet'] = 'No price categories have been created yet.';
$string['nopricecategoryselected'] = 'Enter the name of a new price category';
$string['nopriceformulaset'] = 'No formula set on setting page. <a href="{$a->url}" target="_blank">Set it here.</a>';
$string['nopriceisset'] = 'No price has been set for pricecategory {$a}';
$string['noratings'] = 'Ratings disabled';
$string['norestriction'] = 'No restriction';
$string['noresultsviewable'] = 'The results are not currently viewable.';
$string['norighttobook'] = 'Booking is not possible for your user role. Please contact the site administrator to give you the appropriate rights or enrol/sign in.';
$string['norowsselected'] = 'You did not select anything. Please close this window and first select the rows you want to apply this action for.';
$string['noruleselected'] = 'No rules selected';
$string['noselection'] = 'No selection';
$string['nosemester'] = 'No semester chosen';
$string['nosubscribers'] = 'There are no teachers assigned!';
$string['notallbooked'] = 'The following users could not be booked due to reaching the max number of bookings per user or lack of available places for the booking option: {$a}';
$string['notanswered'] = 'Not answered';
$string['notateacher'] = 'The user selected is not teaching any courses and is probably not a teacher.';
$string['notbookable'] = "Not bookable";
$string['notbookablecombiantion'] = 'This combination of electives is not allowed';
$string['notbooked'] = 'Not yet booked';
$string['notconectedbooking'] = 'Not connected';
$string['noteacherfound'] = 'The user specified as teacher on line {$a} does not exist on the platform.';
$string['noteacherset'] = 'No teacher';
$string['notemplate'] = 'Do not use as template';
$string['notemplateyet'] = 'No template yet';
$string['notenoughcreditstobook'] = 'Not enough credit to book';
$string['notes'] = 'Notes';
$string['notesedited'] = 'Notes edited';
$string['noteseditedhistory'] = 'The notes were changed from "{$a->notesold}" to "{$a->notesnew}".';
$string['noteseditedinfo'] = 'The notes of {$a->relateduser} were changed from "{$a->notesold}" to "{$a->notesnew}".';
$string['notfullwaitinglist'] = 'Not full waitinglist';
$string['notfullybooked'] = 'Not fully booked';
$string['notificationlist'] = 'Notification list';
$string['notificationlistdesc'] = 'When no place is available anymore, users can still register to be notified when there is an opening';
$string['notificationtext'] = 'Notification message';
$string['notifyemail'] = 'Participant notification before start';
$string['notifyemailmessage'] = 'Your booking will start soon:
Booking status: {$a->status}
Participant: {$a->participant}
Booking option: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['notifyemailsubject'] = 'Your booking will start soon';
$string['notifyemailteachers'] = 'Teacher notification before start';
$string['notifyemailteachersmessage'] = 'Your booking will start soon:
{$a->bookingdetails}
You have <b>{$a->numberparticipants} booked participants</b> and <b>{$a->numberwaitinglist} people on the waiting list</b>.
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['notifyemailteacherssubject'] = 'Your booking will start soon';
$string['notifyme'] = 'Notify when available';
$string['notifymelistdeleted'] = 'User removed from the notification list';
$string['notinarray'] = 'user has none of these comma separated values';
$string['notopenyet'] = 'Sorry, this activity is not available until {$a} ';
$string['notstarted'] = "Not yet started";
$string['nouserfound'] = 'No user found: ';
$string['nousers'] = 'No users!';
$string['numberofinstallment'] = 'Number of installment (1 for first)';
$string['numberofinstallmentstring'] = 'installment number {$a}';
$string['numberparticipants'] = "Number of participants";
$string['numberrows'] = 'Number rows';
$string['numberrowsdesc'] = 'Number each row of the sign-in sheet. Number will be displayed left of the name in the same column';
$string['numberwaitinglist'] = "Number on waitinglist";
$string['numgenerator'] = 'Enable rec. number generation?';
$string['numrec'] = "Rec. num.";
$string['off'] = "off";
$string['on'] = "on";
$string['onecohortmustbefound'] = 'User has to be member to at least one of these cohorts';
$string['onecoursemustbefound'] = 'User has to be subscribed to at least only one of these courses';
$string['onlineoptiondate'] = 'Takes place online';
$string['onlyaddactionsonsavedoption'] = "Actions after booking can only be added once the booking option is saved.";
$string['onlyaddentitiesonsavedsubbooking'] = "You need to save this subbooking before you can add an entity.";
$string['onlyaddsubbookingsonsavedoption'] = "You need to save this booking option before you can add subbookings.";
$string['onlythisbookingoption'] = 'Only this booking option';
$string['onlyusersfrominstitution'] = 'You can only add users from this institution: {$a}';
$string['onwaitinglist'] = 'You are on the waiting list';
$string['openbookingdetailinsametab'] = 'Display detailview directly when clicking on the title of the booking option';
$string['openbookingdetailinsametab_desc'] = "In the course list, clicking on the title of the booking option will lead to it's detail view. If you select this option, users will see this new page immediately. Otherwise the detail view will open in a new tab.";
$string['openformat'] = 'open format';
$string['optional'] = 'optional';
$string['optionannotation'] = 'Internal annotation';
$string['optionannotation_help'] = 'Add internal remarks, annotations or anything you want. It will only be shown in this form and nowhere else.';
$string['optionbookablebody'] = '{$a->title} is now available again. <a href="{$a->url}">Click here</a> to directly go there.<br><br>
(You receive this mail because you have clicked on the notification button for this option.)<br><br>
<a href="{$a->unsubscribelink}">Unsubscribe from notification e-mails for "{$a->title}".</a>';
$string['optionbookabletitle'] = '{$a->title} is available again';
$string['optiondate'] = 'Date';
$string['optiondateend'] = 'End';
$string['optiondates'] = 'Dates';
$string['optiondatesmanager'] = 'Manage option dates';
$string['optiondatesmessage'] = 'Session {$a->number}: {$a->date} <br> From: {$a->starttime} <br> To: {$a->endtime}';
$string['optiondatessuccessfullydelete'] = "Session time was deleted.";
$string['optiondatessuccessfullysaved'] = "Session time was saved.";
$string['optiondatestart'] = 'Start';
$string['optiondatesteacheradded'] = 'Substitution teacher was added';
$string['optiondatesteacherdeleted'] = 'Teacher deleted from teaching journal';
$string['optiondatesteachersreport'] = 'Substitutions / Cancelled dates';
$string['optiondatesteachersreport_desc'] = 'This report gives an overview of which teacher was present at which specific date.<br>
By default, every date will be filled in with the option\'s teacher. You can overwrite specific dates with replacement teachers.';
$string['optiondatestime'] = 'Session time';
$string['optionformconfig'] = 'Configure booking option forms (PRO)';
$string['optionformconfig:nobooking'] = 'You need to create at least one booking instance before you can use this form!';
$string['optionformconfiggetpro'] = ' With Booking <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span> you have the possibility to create individual forms with drag and drop
for specific user roles and contexts (e.g. for a specific booking instance or system wide).';
$string['optionformconfiginfotext'] = 'With this PRO feature, you can create your individual booking option forms by using drag & drop
and the checkboxes. The forms are stored on a specific context level (e.g. booking instance, system-wide...). Users can only access the forms
if they have the appropriate capabilities.';
$string['optionformconfignotsaved'] = 'No special configuration was saved for your form';
$string['optionformconfigsaved'] = 'Configuration for the booking option form saved.';
$string['optionformconfigsavedcourse'] = 'Your form definition was saved on context level course';
$string['optionformconfigsavedcoursecat'] = 'Your form definition was saved on context level course category';
$string['optionformconfigsavedmodule'] = 'Your form definition was saved on context level module';
$string['optionformconfigsavedother'] = 'Your form definition was saved on context level {$a}';
$string['optionformconfigsavedsystem'] = 'Your form definition was saved on context level system';
$string['optionformconfigsubtitle'] = '<p>Turn off features you do not need, in order to make the booking option form more compact for your administrators.</p>
<p><strong>BE CAREFUL:</strong> Only deactivate fields if you are completely sure that you won\'t need them!</p>';
$string['optionid'] = 'Option ID';
$string['optionidentifier'] = 'Unique identifier';
$string['optionidentifier_help'] = 'Add a unique identifier for this booking option.';
$string['optioninvisible'] = 'Hide from normal users (visible to entitled users only)';
$string['optionmenu'] = 'This booking option';
$string['optionmoved'] = 'Bookingoption moved';
$string['optionnoimage'] = 'No image';
$string['optionsdownloadfields'] = 'Booking options overview - Download (CSV, XLSX...)';
$string['optionsfield'] = 'Booking option field';
$string['optionsfields'] = 'Booking option fields';
$string['optionsiamresponsiblefor'] = 'I am responsible contact';
$string['optionsiteach'] = 'Taught by me';
$string['optionspagefields'] = 'Booking options overview - Page';
$string['optionspecificcampaignwarning'] = 'If you choose fields here beneath, the campaign will apply only for booking options that fullfill the requirements.
<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span> Watch out: According to your settings, this campaign may disable booking options in many cases.</span>
</div>
If you choose a Booking option field AND a Custom user profile field, both requirements have to be fullfilled.';
$string['optiontemplate'] = 'Option template';
$string['optiontemplatename'] = 'Option template name';
$string['optiontemplatenotsavednovalidlicense'] = 'Booking option template could not be saved as template.
                                                  Upgrade to PRO version to save an unlimited number of templates.';
$string['optiontemplatessettings'] = 'Booking option templates';
$string['optionviewcustomfields'] = 'Show customfields on detail page';
$string['optionviewcustomfieldsdesc'] = 'Select the booking option customfields that you want to be shown on the details page of a booking option. To change the order of the customfields on the details page, you can just change the order of the customfields <a href="/mod/booking/customfield.php" target="_blank">here</a>';
$string['optionvisibility'] = 'Visibility';
$string['optionvisibility_help'] = 'Here you can choose whether the option should be visible for everyone or if it should be hidden from normal users and be visible to entitled users only.';
$string['optionvisible'] = 'Visible to everyone (default)';
$string['optionvisibledirectlink'] = 'Normal users can only see this option with a direct link';
$string['organizatorname'] = 'Organizer name';
$string['organizatorname_help'] = 'You can either enter the organizer name manually or choose from a list of previous organizers.
                                    You can choose one organizer only. Once you save, the organizer will be added to the list.';
$string['orotherfield'] = 'OR additional field';
$string['otherbookingaddrule'] = 'Add new rule';
$string['otherbookinglimit'] = "Limit";
$string['otherbookinglimit_help'] = "How many users you accept from option. If 0, you can accept unlimited users.";
$string['otherbookingnumber'] = 'Num. of users';
$string['otherbookingoptions'] = 'Accepting from';
$string['otherbookingsuccessfullysaved'] = 'Rule saved!';
$string['otheroptionsavailable'] = 'Linked options available';
$string['otheroptionsnotavailable'] = 'Linked Option(s) not available';
$string['overridecondition'] = 'Condition';
$string['overrideconditioncheckbox'] = 'Has relation to other condition';
$string['overrideoperator'] = 'Operator';
$string['overrideoperator:and'] = 'AND';
$string['overrideoperator:or'] = 'OR';
$string['overwriteblockingwarnings'] = 'Overwrite blocking warnings with text below';
$string['page:bookingpolicy'] = 'Booking policy';
$string['page:bookitbutton'] = 'Book';
$string['page:checkout'] = 'Checkout';
$string['page:confirmation'] = 'Booking complete';
$string['page:customform'] = 'Fill out form';
$string['page:subbooking'] = 'Supplementary bookings';
$string['paginationnum'] = "N. of records - pagination";
$string['participant'] = "participant";
$string['pdflandscape'] = 'Landscape';
$string['pdfportrait'] = 'Portrait';
$string['percentageavailableplaces'] = 'Percentage of available places';
$string['percentageavailableplaces_help'] = 'You need to enter a valid percentage beween 0 and 100 (without %-sign!).';
$string['personnr'] = 'Person n° {$a}';
$string['placeholders'] = 'Placeholders';
$string['placeholders_help'] = 'Leave this blank to use the site default text.';
$string['places'] = 'Places';
$string['placesinfoshowbooked'] = 'Show booked places';
$string['placesinfoshowfreeonly'] = 'Show text for free places';
$string['placesinfoshowinfotexts'] = 'Show availability info texts';
$string['pluginadministration'] = 'Booking administration';
$string['pluginname'] = 'Booking';
$string['pollstartdate'] = "Startdate of poll";
$string['pollstrftimedate'] = '%Y-%m-%d';
$string['pollurl'] = 'Poll url';
$string['pollurlteachers'] = 'Teachers poll url';
$string['pollurlteacherstext'] = 'Message for the poll url sent to teachers';
$string['pollurlteacherstextmessage'] = 'Please take the survey:
Survey URL: <a href="{pollurlteachers}" target="_blank">{pollurlteachers}</a>
';
$string['pollurlteacherstextsubject'] = 'Please take the survey';
$string['pollurltext'] = 'Message for sending poll url to booked users';
$string['pollurltextmessage'] = 'Please take the survey:
Survey URL: <a href="{pollurl}" target="_blank">{pollurl}</a>
';
$string['pollurltextsubject'] = 'Please take the survey';
$string['populatefromtemplate'] = 'Populate from template';
$string['potentialsubscribers'] = 'Potential subscribers';
$string['prepareimport'] = "Prepare Import";
$string['presence'] = "Presence";
$string['presencechanged'] = "Presence changed";
$string['presencechangedhistory'] = 'The attendance has been changed from "{$a->presenceold}" to "{$a->presencenew}".';
$string['presencechangedinfo'] = 'The attendance of {$a->relateduser} has been changed from "{$a->presenceold}" to "{$a->presencenew}".';
$string['presencecount'] = 'Presence count';
$string['presenceoptions'] = "Options for attendance status";
$string['presenceoptions_desc'] = "Which status should be possible?";
$string['presencestatustoissuecertificate'] = 'Certification issue with presence status <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['presencestatustoissuecertificate_desc'] = "If set, you can trigger the issue of a certificate ONLY with the chosen presence. The completion of the booking option then no longer issues a certificate.";
$string['previous'] = 'Previous';
$string['price'] = 'Price';
$string['pricecategories'] = 'Booking: Price categories';
$string['pricecategoriessaved'] = 'Price categories were saved';
$string['pricecategoriessubtitle'] = '<p>Here you can define different price categories, e.g.
    special price categories for students, employees or externals.
    <b>Be careful:</b> Once you have added a category, you cannot delete it.
    Only disable or rename it.</p>';
$string['pricecategory'] = 'Price category';
$string['pricecategorychanged'] = 'Price category changed';
$string['pricecategorychoosehighest'] = 'Highest sorted price category is chosen first';
$string['pricecategorychoosehighest_desc'] = 'If a user has multiple price category identifiers in their user profile, the highest-ranked price category will be chosen first. By default, the lowest is chosen.';
$string['pricecategoryfallback'] = 'Fall back on default price category';
$string['pricecategoryfallback_desc'] = 'Use default price category when no machting cateogry is found';
$string['pricecategoryfield'] = 'User profile field for price category';
$string['pricecategoryfielddesc'] = 'Choose the user profile field, which stores the price category identifier for each user.';
$string['pricecategoryidentifier'] = 'Price category identifier';
$string['pricecategoryidentifier_help'] = 'Enter a short text to identify the category, e.g. "stud" or "acad".';
$string['pricecategoryname'] = 'Price category name';
$string['pricecategoryname_help'] = 'Enter the full name of the price category to be shown in booking options, e.g. "Student price".';
$string['pricecatsortorder'] = 'Sort order (number)';
$string['pricecatsortorder_help'] = 'Enter a full number. "1" means that the price category will be shown at first place, "2" at second place etc.';
$string['pricecurrency'] = 'Currency';
$string['pricefactor'] = 'Price factor';
$string['pricefactor_help'] = 'Specify a value by which to multiply the price. For example, to discount the prices by 20%, enter the value <b>0.8</b>.';
$string['priceformulaadd'] = 'Absolute value';
$string['priceformulaadd_help'] = 'Additional value to <strong>add</strong> to the result.';
$string['priceformulaheader'] = 'Price formula <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['priceformulaheader_desc'] = "Use a price formula to automatically calculate prices for booking options.";
$string['priceformulainfo'] = '<a data-toggle="collapse" href="#priceformula" role="button" aria-expanded="false" aria-controls="priceformula">
<i class="fa fa-code"></i> Show JSON for price formula...
</a>
<div class="collapse" id="priceformula">
<samp>{$a->formula}</samp>
</div><br>
<a href="{$a->url}" target="_blank"><i class="fa fa-edit"></i> Edit formula...</a><br><br>
Below, you can additionally add a manual factor (multiplication) and an absolute value (addition) to be added to the formula.';
$string['priceformulaisactive'] = 'On saving, calculate prices with price formula (this will overwrite current prices).';
$string['priceformulamultiply'] = 'Manual factor';
$string['priceformulamultiply_help'] = 'Additional value to <strong>multiply</strong> the result with.';
$string['priceformulaoff'] = 'Prevent recalculation of prices';
$string['priceformulaoff_help'] = 'Activate this option, in order to prevent the function "Calculate all prices from
 instance with formula" from recalculating the prices for this booking option.';
$string['priceisalwayson'] = 'Prices always active';
$string['priceisalwayson_desc'] = 'If you activate this checkbox, you cannot deactive prices for individual booking options.
 However, you can still set a price of 0 EUR.';
$string['privacy:metadata:bookinganswers'] = 'Represents a booking of an event';
$string['privacy:metadata:bookinganswers:bookingid'] = 'ID of the booking instance';
$string['privacy:metadata:bookinganswers:completed'] = 'User that booked has completed the task';
$string['privacy:metadata:bookinganswers:frombookingid'] = 'ID of connected booking';
$string['privacy:metadata:bookinganswers:notes'] = 'Additional notes';
$string['privacy:metadata:bookinganswers:numrec'] = 'Record number';
$string['privacy:metadata:bookinganswers:optionid'] = 'ID of the booking option';
$string['privacy:metadata:bookinganswers:status'] = 'Status info for this booking';
$string['privacy:metadata:bookinganswers:timecreated'] = 'Timestamp when booking was created';
$string['privacy:metadata:bookinganswers:timemodified'] = 'Timestamp when booking was last modified';
$string['privacy:metadata:bookinganswers:userid'] = 'User that is booked for this event';
$string['privacy:metadata:bookinganswers:waitinglist'] = 'True if user is on the waitinglist';
$string['privacy:metadata:bookingicalsequence'] = 'Ical sequence';
$string['privacy:metadata:bookingicalsequence:optionid'] = 'Booking option ID for ical';
$string['privacy:metadata:bookingicalsequence:sequencevalue'] = 'Ical sequence value';
$string['privacy:metadata:bookingicalsequence:userid'] = 'User ID for ical';
$string['privacy:metadata:bookingodtdeductions'] = 'This table is used to log if we want to deduct a part of a teachers salary if (s)he has missing hours.';
$string['privacy:metadata:bookingodtdeductions:optiondateid'] = 'The option date ID';
$string['privacy:metadata:bookingodtdeductions:reason'] = 'Reason for the deduction.';
$string['privacy:metadata:bookingodtdeductions:timecreated'] = 'The time created';
$string['privacy:metadata:bookingodtdeductions:timemodified'] = 'The time last modified';
$string['privacy:metadata:bookingodtdeductions:userid'] = 'Userid of the teacher who gets a deduction for this option date.';
$string['privacy:metadata:bookingodtdeductions:usermodified'] = 'The user that modified';
$string['privacy:metadata:bookingoptiondatesteachers'] = 'Track teachers for each session.';
$string['privacy:metadata:bookingoptiondatesteachers:optiondateid'] = 'ID of the option date';
$string['privacy:metadata:bookingoptiondatesteachers:userid'] = 'The userid of the teacher.';
$string['privacy:metadata:bookingratings'] = 'Your rating of an event';
$string['privacy:metadata:bookingratings:optionid'] = 'ID of the rated booking option';
$string['privacy:metadata:bookingratings:rate'] = 'Rate that was assigned';
$string['privacy:metadata:bookingratings:userid'] = 'User that rated this event';
$string['privacy:metadata:bookingsubbookinganswers'] = 'Stores the anwers (the bookings) of a user for a particular subbooking.';
$string['privacy:metadata:bookingsubbookinganswers:itemid'] = 'itemid can be the same as sboptionid, but there are some types (eg. timeslots which provide slots) where one sboptionid provides a lot of itemids.';
$string['privacy:metadata:bookingsubbookinganswers:json'] = 'supplementary data if necessary';
$string['privacy:metadata:bookingsubbookinganswers:optionid'] = 'The option ID';
$string['privacy:metadata:bookingsubbookinganswers:sboptionid'] = 'id of the booked subbooking';
$string['privacy:metadata:bookingsubbookinganswers:status'] = 'The bookings status, as in booked, waiting list, in the shopping cart, on a notify list or deleted';
$string['privacy:metadata:bookingsubbookinganswers:timecreated'] = 'The time created';
$string['privacy:metadata:bookingsubbookinganswers:timeend'] = 'Timestamp for end time of this booking';
$string['privacy:metadata:bookingsubbookinganswers:timemodified'] = 'The time last modified';
$string['privacy:metadata:bookingsubbookinganswers:timestart'] = 'Timestamp for start time of this booking';
$string['privacy:metadata:bookingsubbookinganswers:userid'] = 'Userid of the booked user.';
$string['privacy:metadata:bookingsubbookinganswers:usermodified'] = 'The user that modified';
$string['privacy:metadata:bookingteachers'] = 'Teacher(s) of an event';
$string['privacy:metadata:bookingteachers:bookingid'] = 'ID of booking instance for teacher';
$string['privacy:metadata:bookingteachers:calendarid'] = 'ID of calendar event for teacher';
$string['privacy:metadata:bookingteachers:completed'] = 'If task is completed for the teacher';
$string['privacy:metadata:bookingteachers:optionid'] = 'ID of the booking option which is taught';
$string['privacy:metadata:bookingteachers:userid'] = 'User that is teaching this event';
$string['privacy:metadata:bookinguserevents'] = 'User events in calendar';
$string['privacy:metadata:bookinguserevents:eventid'] = 'ID of event in events table';
$string['privacy:metadata:bookinguserevents:optiondateid'] = 'ID of optiondate (session) for user event';
$string['privacy:metadata:bookinguserevents:optionid'] = 'ID of booking option for user event';
$string['privacy:metadata:bookinguserevents:userid'] = 'User ID for user event';
$string['problemsofcohortorgroupbooking'] = '<br><p>Not all users could be booked with cohort booking:</p>
<ul>
<li>{$a->notenrolledusers} users are not enrolled in the course</li>
<li>{$a->notsubscribedusers} users not booked for other reasons</li>
</ul>';
$string['problemwithdate'] = 'Please verify dates';
$string['profeatures:appearance'] = '<ul>
<li><b>hide Wunderbyte logo and link</b></li>
<li><b>collapse description</b></li>
<li><b>collapse show dates</b></li>
<li><b>turn off modals</b></li>
<li><b>options for attendance status</b></li>
</ul>';
$string['profeatures:automaticcoursecreation'] = '<ul>
<li><b>booking option custom field to be used as course category</b></li>
<li><b>mark course with tags to use as templates</b></li>
</ul>';
$string['profeatures:availabilityinfotexts'] = '<ul>
<li><b>show availability info for booking places</b></li>
<li><b>enable “booking places low” message</b></li>
<li><b>show availability info for waiting list</b></li>
<li><b>enable “waiting list places low” message</b></li>
<li><b>show place on waiting list</b></li>
</ul>';
$string['profeatures:boactions'] = '<ul>
<li><b>activate actions after booking</b></li>
</ul>';
$string['profeatures:bookingstracker'] = '<ul>
<li><b>Allow users to manage bookings across the entire site on various hierarchical booking levels
(date, booking option, booking instance, Moodle course, entire platform)
and record attendance for booked users.</b></li>
<li><b>Track attendance - You can specify for each date (session) which users were present.</b></li>
<li><b>Select the attendance status you wish to track.</b></li>
</ul>';
$string['profeatures:cachesettings'] = '<ul>
<li><b>No caching of bookingoption settings</b></li>
<li><b>No caching of booking answers</b></li>
</ul>';
$string['profeatures:cancellationsettings'] = '<ul>
<li><b>adjustable cancellation period</b></li>
<li><b>cancellation cool off period (seconds)</b></li>
</ul>';
$string['profeatures:duplicationrestoreoption'] = '<ul>
<li><b>duplicate moodle course when duplicating a booking option</b></li>
</ul>';
$string['profeatures:overbooking'] = '<ul>
<li><b>allow overbooking</b></li>
</ul>';
$string['profeatures:priceformula'] = '<ul>
<li><b>use price formula to automatically calculate prices</b></li>
<li><b>apply unit factor</b></li>
<li><b>round prices</b></li>
</ul>';
$string['profeatures:progressbars'] = '<ul>
<li><b>show progress bars of time passed (for booking option)</b></li>
<li><b>make progress bars collapsible</b></li>
</ul>';
$string['profeatures:selflearningcourse'] = '<ul>
<li><b>activate booking options with fixed duration (e.g. for self-learning courses)</b></li>
<li><b>define a custom name for this type (e.g. "Self-learning course")</b></li>
</ul>';
$string['profeatures:shortcodes'] = '<ul>
<li><b>Shortcodes to show booking options on any page</b></li>
</ul>';
$string['profeatures:subbookings'] = '<ul>
<li><b>activate subbookings</b></li>
</ul>';
$string['profeatures:tabwhatsnew'] = '<ul>
<li><b>Tab "What\'s new?" for recently made visible (or newly published) booking options</b></li>
<li><b>The number of days for which a booking option is considered "new" can be configured</b></li>
<li><b>Tab can be named individually.</b></li>
</ul>';
$string['profeatures:teachers'] = '<ul>
<li><b>add links to teacher pages</b></li>
<li><b>login for teacher pages not necessary</b></li>
<li><b>always show teacher’s email addresses to everyone</b></li>
<li><b>show teacher’s email addresses to booked users</b></li>
<li><b>teachers can send email to all booked users using own mail client</b></li>
<li><b>teachers of booking option are assigned to fitting role</b></li>
</ul>';
$string['profeatures:unenroluserswithoutaccess'] = '<ul>
<li><b>Delete bookings of users who have lost access to the course containing the booking.</b></li>
</ul>';
$string['profilepicture'] = 'Profile picture';
$string['progressbars'] = 'Progress bars of time passed <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['progressbars_desc'] = 'Get a visual representation of the time which has already passed for a booking option.';
$string['progressbarscollapsible'] = 'Make progress bars collapsible';
$string['prolicensefeatures'] = '<p>You need a Booking PRO license if you want to use this feature.
Purchasing a Pro license will allow you to use the following features:</p>';
$string['proversion:extraviews'] = 'With Booking PRO you can use additional views like cards view or list view with images.';
$string['proversiononly'] = 'Upgrade to Booking PRO to use this feature.';
$string['qrenrollink'] = "QR code for enrolment link";
$string['qrid'] = "qr_id";
$string['qrusername'] = "qr_username";
$string['question'] = "Question";
$string['ratings'] = 'Bookingoption ratings';
$string['ratingsuccessful'] = 'The ratings were successfully updated';
$string['reallydeleteaction'] = 'Really delete?';
$string['reason'] = 'Reason';
$string['recalculateall'] = 'Calculate all prices';
$string['recalculateprices'] = 'Calculate all prices from instance with formula';
$string['recommendedin'] = "Shortcode to show a list of booking options which should be recommended in a given course.
 To use this, add a booking customfield with the shortname 'recommendedin' and comma separated values with the shortnames
 of the courses you want to show this recommendations. So: When you want recommend option1 to the participants enroled in
 Course 1 (course1), then you need to set the customfield 'recommendedin' from within the booking option to 'course1'.";
$string['recordsimported'] = 'Booking options imported via csv';
$string['recordsimporteddescription'] = '{$a} booking options imported via csv';
$string['recreategroup'] = 'Recreate group in the target course and enrol users in group';
$string['recurringactioninfo'] = 'This action will be executed when you save this form (by clicking on "Save" button). <b>Be careful</b>, this can not be undone!';
$string['recurringchildoptions'] = 'Children of this booking option:';
$string['recurringheader'] = '<i class="fa fa-fw fa-repeat" aria-hidden="true"></i>&nbsp;Recurring options';
$string['recurringmultiparenting'] = 'Create recurring options repeatedly from same parent';
$string['recurringmultiparenting_desc'] = 'If a booking option is already a "parent" should it be possible to create another set of recurring options?';
$string['recurringnotpossibleinfo'] = '<div class="alert alert-info" role="alert">
    It’s not possible to create children for this booking option, since it’s already linked to other options (as mother or as child).
    </div>';
$string['recurringoptions'] = 'Recurring booking options';
$string['recurringparentoption'] = 'Parent of this option:';
$string['recurringsameparentoptions'] = 'Bookingoption(s) with same parent:';
$string['recurringsaveinfo'] = '<div class="alert alert-info" role="alert">
                                <strong>Attention:</strong> Please save any changes before creating recurring booking options. Otherwise, your changes will not be applied to the new options.
                                </div>';
$string['recurringselectapplysiblings'] = 'Apply changes to all following options with same parent?';
$string['recurringsettingsheader'] = 'Recurring Booking options  <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['recurringsettingsheader_desc'] = 'Settings for booking options and possible children';
$string['redirectonlogintocourse'] = 'Redirect logged-out users to course';
$string['redirectonlogintocourse_desc'] = 'If enabled, users will be redirected to the booked course after logging in instead of the booking details page.';
$string['relatedcourseidneeded'] = 'A related course is needed because of your availabilty condition(s).';
$string['reminder1sent'] = 'First reminder sent';
$string['reminder2sent'] = 'Second reminder sent';
$string['reminderteachersent'] = 'Teacher reminder sent';
$string['removeafterminutes'] = 'Remove activity completion after N minutes';
$string['removeresponses'] = 'Remove all responses';
$string['removeuseronunenrol'] = 'Remove user from booking upon unenrolment from associated course?';
$string['reoccurringdatestring'] = 'Weekday, start and end time (Day, HH:MM - HH:MM)';
$string['reoccurringdatestring_help'] = 'Enter a text in the following format:
    "Day, HH:MM - HH:MM", e.g. "Monday, 10:00 - 11:00" or "Sun 09:00-10:00" or "block" for blocked events.';
$string['reoccurringdatestringerror'] = 'Enter a text in the following format:
    Day, HH:MM - HH:MM or "block" for blocked events.';
$string['repeatthisbooking'] = 'Repeat this option';
$string['report2labelcourse'] = 'Moodle course';
$string['report2labelinstance'] = 'Booking instance';
$string['report2labeloption'] = 'Booking option';
$string['report2labeloptiondate'] = 'Date';
$string['report2labelsystem'] = 'Site';
$string['reportfields'] = 'Report fields';
$string['reportremindermessage'] = '{bookingdetails}';
$string['reportremindersubject'] = 'Reminder: Your booked course';
$string['requirepreviousoptionstobebooked'] = 'Activate condition: User has to book the previous bookingoption to be able to book the following option.';
$string['reserveddeleted'] = 'Reserved User deleted';
$string['reservedusers'] = 'Shortterm reservations';
$string['reset'] = 'Reset';
$string['responses'] = 'Responses';
$string['responsesfields'] = 'Fields in participants list';
$string['responsesto'] = 'Responses to {$a} ';
$string['responsible'] = 'Responsible';
$string['responsiblecontact'] = 'Responsible contact(s)';
$string['responsiblecontact_help'] = 'Choose contact(s) which are responsible for this booking option.
This is not supposed to be the teacher!';
$string['responsiblecontactcanedit'] = 'Allow responsible contacts to edit';
$string['responsiblecontactcanedit_desc'] = 'Activate this setting if you want to allow responsible contact persons
to edit their booking options and to see and edit the list of booked users.<br>
<b>Important:</b> The responsible contact person additionally needs the capability
<b>mod/booking:addeditownoption</b>.';
$string['responsiblecontactenroltocourse'] = 'Enrol responsible contact person to connected Moodle course';
$string['responsiblecontactenroltocourse_desc'] = 'Please also define the role the responsible contact person should have in the connected Moodle course.';
$string['restresponse'] = "rest_response";
$string['restrictanswerperiodclosing'] = 'Booking is possible only until a certain date';
$string['restrictanswerperiodopening'] = 'Booking is possible only after a certain date';
$string['restrictavailabilityforinstance'] = 'Apply access restrictions of booking instances to booking options';
$string['restrictavailabilityforinstance_desc'] = 'If you activate this feature and you have access restrictions on your booking instance,
these restrictions will also apply to booking options even if they are used in a shortcode like [courselist].';
$string['restscriptexecuted'] = 'After the rest call has been executed';
$string['restscriptfailed'] = 'Script execution has failed';
$string['restscriptsuccess'] = 'Rest script execution';
$string['resultofcohortorgroupbooking'] = '<p>This is the result of your cohort booking:</p>
<ul>
<li>{$a->sumcohortmembers} users found in the selected cohorts</li>
<li>{$a->sumgroupmembers} users found in the selected groups</li>
<li><b>{$a->subscribedusers} users where booked for this option</b></li>
</ul>';
$string['returnurl'] = "Url to return to";
$string['reviewed'] = 'Reviewed';
$string['rootcategory'] = 'Root';
$string['roundpricesafterformula'] = 'Round prices (price formula)';
$string['roundpricesafterformula_desc'] = 'If active, prices will be rounded to full numbers (no decimals) after the <strong>price formula</strong> has been applied.';
$string['rowupdated'] = 'Row was updated.';
$string['rulecustomprofilefield'] = 'Custom user profile field';
$string['ruledatefield'] = 'Date field';
$string['ruledays'] = 'Number of days';
$string['ruledaysbefore'] = 'Trigger n days in relation to a certain date';
$string['ruledaysbefore_desc'] = 'Choose a date field of booking options and the number of days in relation to that date.';
$string['ruleevent'] = 'Event';
$string['ruleeventcondition'] = 'Execute when...';
$string['rulemailtemplate'] = 'Mail template';
$string['rulename'] = "Custom name for the rule";
$string['ruleoperator'] = 'Operator';
$string['ruleoptionfield'] = 'Option field to compare';
$string['ruleoptionfieldaddress'] = 'Address (address)';
$string['ruleoptionfieldbookingclosingtime'] = 'End of allowed booking period (bookingclosingtime)';
$string['ruleoptionfieldbookingopeningtime'] = 'Start of allowed booking period (bookingopeningtime)';
$string['ruleoptionfieldcourseendtime'] = 'End (courseendtime)';
$string['ruleoptionfieldcoursestarttime'] = 'Begin (coursestarttime)';
$string['ruleoptionfieldlocation'] = 'Location (location)';
$string['ruleoptionfieldoptiondatestarttime'] = 'Start of every session (date)';
$string['ruleoptionfieldselflearningcourseenddate'] = 'End date of self-learning course';
$string['ruleoptionfieldtext'] = 'Name of the booking option (text)';
$string['rulereactonchangeevent_desc'] = 'For the "Booking option updated" event, you can specify options here: <a href="{$a}">Booking Plugin Settings</a>.';
$string['rulereactonevent'] = "React on event";
$string['rulereactonevent_desc'] = "Choose an event that should trigger the rule.<br>
<b>Hint:</b> You can use the placeholder <code>{eventdescription}</code> to show a description of the event.";
$string['rulereactoneventaftercompletion'] = "Number of days after end of booking option, where rule still applies";
$string['rulereactoneventaftercompletion_help'] = "Leave this field empty or set to 0 if you want to keep executing the action. You can use negative numbers if the rule should be suspended before the specified courseend.";
$string['rulereactoneventcancelrules'] = 'Skip this rule';
$string['rulesendmailcpf'] = '[Preview] Send an e-mail to user with custom profile field';
$string['rulesendmailcpf_desc'] = 'Choose an event that should trigger the "Send an e-mail" rule. Enter an e-mail template
 (you can use placeholders like {bookingdetails}) and define to which users the e-mail should be sent.
  Example: All users having the value "Vienna center" in a custom user profile field called "Study center".';
$string['rulesheader'] = '<i class="fa fa-fw fa-pencil-square" aria-hidden="true"></i>&nbsp; Rules';
$string['rulesincontextglobalheader'] = '<a href="{$a}" target="_blank">Global rules</a>';
$string['rulesincontextheader'] = '<a href="{$a->rulesincontexturl}" target="_blank">Rules in bookinginstance "{$a->bookingname}"</a>';
$string['rulesnotfound'] = 'No rules found for this bookingoption';
$string['rulessettings'] = "Settings for Booking Rules";
$string['rulessettingsdesc'] = 'Settings that apply to the <a href="{$a}">Booking Rules Feature</a>.';
$string['ruletemplatebookingoptioncompleted'] = "Template - Bookingoption completed with poll";
$string['ruletemplatebookingoptioncompletedbody'] = "You have completed the following booking option:<br>{bookingdetails}<br> Please participate in the Poll. Poll link: {pollurl} <br>To the course: {courselink}<br>View all booking options: {bookinglink}";
$string['ruletemplatebookingoptioncompletedsubject'] = "Bookingoption completed";
$string['ruletemplateconfirmbooking'] = "Template - Confirm booking";
$string['ruletemplateconfirmbookingbody'] = "Dear {firstname} {lastname},<br>Thank you very much for your booking<br>{bookingdetails}<br>All the best!";
$string['ruletemplateconfirmbookingsubject'] = "You have successfully booked";
$string['ruletemplateconfirmwaitinglist'] = "Template - Confirm waiting list";
$string['ruletemplateconfirmwaitinglistbody'] = "Dear {firstname} {lastname},<br>You are on the waiting list<br>{bookingdetails}<br>All the best!";
$string['ruletemplateconfirmwaitinglistsubject'] = "You are on the waiting list";
$string['ruletemplatecourseupdate'] = "Template - Updates";
$string['ruletemplatecourseupdatebody'] = "This is new: <br> {changes} <br> Click the following link to view the change(s) and an overview of all bookings: {bookinglink}";
$string['ruletemplatecourseupdatesubject'] = "Your booking \"{title}\" has changed";
$string['ruletemplatedaysbefore'] = "Template - Notification n days before start";
$string['ruletemplatedaysbeforebody'] = "Your booking starts in a few days: {bookingdetails} <br> Name: {participant} <br> To get an overview of all bookings, click on the following link:{bookinglink}<br> Here is the link to the course: {courselink}";
$string['ruletemplatedaysbeforesubject'] = "Your booking starts in a few days";
$string['ruletemplatepaymentconfirmation'] = "Template - Payment for booking is confirmed";
$string['ruletemplatepaymentconfirmationbody'] = "Thank you for your booking!<br>Your booking {Title} with the price: {price} has been successfully made.<br>Here is the confirmation link:<br>{bookingconfirmationlink}<br>Here is the course link:<br>{courselink}<br>Best regards";
$string['ruletemplatepaymentconfirmationsubject'] = "Payment for {Title} confirmed";
$string['ruletemplatesessionreminders'] = 'Template - Reminder before each session (date)';
$string['ruletemplatesessionremindersbody'] = 'Good day {firstname} {lastname},<br>the next session of "{title}" will start soon:<br><br>{bookingdetails}';
$string['ruletemplatesessionreminderssubject'] = 'A new session of {Title} will start soon';
$string['ruletemplatetrainercancellation'] = "Template - Booking option cancellation - Mail to teachers";
$string['ruletemplatetrainercancellationbody'] = "Good day {firstname} {lastname},<br>unfortunately, the following event had to be cancelled:<br>
Event: {Title}<br>Best regards";
$string['ruletemplatetrainercancellationsubject'] = "Cancellation of {Title}";
$string['ruletemplatetrainerpoll'] = "Template - Teacher poll n days after end";
$string['ruletemplatetrainerpollbody'] = "Please participate in the Poll. Poll link: {pollurlteachers}";
$string['ruletemplatetrainerpollsubject'] = "Poll";
$string['ruletemplatetrainerreminder'] = "Template - Teacher reminder n days before start";
$string['ruletemplatetrainerreminderbody'] = "Your course starts in a few days:<br>{bookingdetails}<br>You have {numberparticipants} participants and {numberwaitinglist} people on the waiting list.<br>To get an overview of all bookings, click on the following link<br>{bookinglink}<br>Hier geht's zum Kurs: {courselink}";
$string['ruletemplatetrainerremindersubject'] = "Your booking starts in a few days";
$string['ruletemplateusercancellation'] = "Template - Booking option cancellation - Mail to users";
$string['ruletemplateusercancellationbody'] = "Good day {firstname} {lastname},<br> unfortunately, the following event had to be canceled:<br> Event: {Title}<br> Best regards";
$string['ruletemplateusercancellationsubject'] = "Cancellation of {Title}";
$string['ruletemplateuserpoll'] = "Template - User poll n days after end";
$string['ruletemplateuserpollbody'] = "<p>Please participate in the Poll. Poll link: {pollurl}</p>";
$string['ruletemplateuserpollsubject'] = "Poll";
$string['ruletemplateuserstorno'] = "Template - Individual user cancellation";
$string['ruletemplateuserstornobody'] = "<p>Hello {participant},<br>You have successfully unsubscribed from {title}.</p>";
$string['ruletemplateuserstornosubject'] = "Storno";
$string['rulevalue'] = 'Value';
$string['sameday'] = 'same day';
$string['saturday'] = 'Saturday';
$string['save'] = 'Save';
$string['saveinstanceastemplate'] = 'Add booking instance to template';
$string['savenewtagtemplate'] = 'Save';
$string['sccartdescription'] = "Description in the Shopping Cart";
$string['sccartdescription_desc'] = "Description displayed in the Shopping Cart. Fields of the booking option can be inserted using placeholders, e.g., {location}";
$string['scgfbookgroupscohorts'] = 'Book cohort(s) or group(s)';
$string['scgfcohortheader'] = 'Cohort subscription';
$string['scgfgroupheader'] = 'Group subscription';
$string['scgfselectcohorts'] = 'Select cohort(s)';
$string['scgfselectgroups'] = 'Select group(s)';
$string['sch_allowinstallment'] = 'Allow installments';
$string['sch_allowrebooking'] = 'Allow rebooking';
$string['screstoreitemfromreserved'] = 'Automatically put reserved items in the shopping cart';
$string['screstoreitemfromreserved_desc'] = 'This setting will put back items in your shopping cart when the cache was deleted';
$string['search'] = 'Search...';
$string['searchdate'] = 'Date';
$string['searchname'] = 'First name';
$string['searchsurname'] = 'Last name';
$string['searchtag'] = 'Search tags';
$string['searchwaitinglist'] = 'On waiting list';
$string['select'] = 'Selection';
$string['selectanoption'] = 'Please, select a booking option';
$string['selectatleastoneuser'] = 'Please, select at least 1 user!';
$string['selectboactiontype'] = 'Select action after booking';
$string['selectcategory'] = 'Select parent category';
$string['selected'] = 'Selected';
$string['selectelective'] = 'Select elective for {$a} credits';
$string['selectfield'] = 'Drop-down list';
$string['selectfieldofbookingoption'] = 'Select field of booking option';
$string['selectoptionid'] = 'Please, select option!';
$string['selectoptioninotherbooking'] = "Option";
$string['selectoptionsfirst'] = "Please select booking options first.";
$string['selectpresencestatus'] = "Choose presence status";
$string['selectstudentinbo'] = "Select users of a booking option";
$string['selectteacherinbo'] = "Select teachers of a booking option";
$string['selectteacherswithprofilefieldonly'] = 'Restrict trainer selection';
$string['selectteacherswithprofilefieldonlydesc'] = 'Only users with a specific value in a defined user profile field can be selected as trainers.<br>
<span class="text-danger">Note: <b>Save and reload the page</b> to select the profile field and specify the value.</span>';
$string['selectteacherswithprofilefieldonlyfield'] = '⤷ Select user profile field for trainers';
$string['selectteacherswithprofilefieldonlyvalue'] = '⤷ Value';
$string['selectteacherswithprofilefieldonlyvaluedesc'] = 'Either enter a specific value or a comma-separated list of possible values';
$string['selectuserfromevent'] = "Select user from event";
$string['selectusers'] = "Directly select users without connection to the booking option";
$string['selectusersfromuserfieldofeventuser'] = "Select user(s) from profilefield of user from event";
$string['selectusershoppingcart'] = "Choose user who has to pay installments";
$string['selflearncoursesall'] = "Display all";
$string['selflearncoursesnotdisplayed'] = "Don't display any";
$string['selflearncoursessortingdateinfuture'] = "Sortingdate in the future";
$string['selflearningcourse'] = 'Self-learning course';
$string['selflearningcourse_help'] = 'Booking options of type "{$a}" have a fixed duration but no fixed dates. The course will start once it is booked.';
$string['selflearningcourseactive'] = 'Activate booking options with fixed duration';
$string['selflearningcoursealert'] = 'If a Moodle course is connected, then for booking options of type "{$a}" users will always be enrolled <b>immediately after booking</b>. They will stay enrolled in the Moodle course for the provided duration.<br><br><b>Please note:</b> You cannot provide any dates but you can provide a sorting date (in the "Dates" section).';
$string['selflearningcoursecoursestarttime'] = 'Sorting date';
$string['selflearningcoursecoursestarttime_help'] = 'This date will be used for sorting because booking options of type "{$a}" do not have a fixed start date.';
$string['selflearningcoursecoursestarttimealert'] = 'As you have activated the "{$a}" checkbox under the "Moodle course" settings you cannot add dates here but only provide a sorting date.';
$string['selflearningcoursedisplayinshortcode'] = 'Which self-learning courses should be displayed in shortcodes with time limits';
$string['selflearningcoursedisplayinshortcodedesc'] = 'Some of the plugin-specific shortcodes include the option to filter for booking options that lie in the future. Should in this case all, none or only self-learning courses with a sortingdate in the future be displayed?';
$string['selflearningcoursedurationinfo'] = 'This course will be accessible for {$a}.';
$string['selflearningcoursehideduration'] = 'Hide duration for self-learning courses';
$string['selflearningcourselabel'] = 'Name for booking options with fixed duration';
$string['selflearningcourselabeldesc'] = 'Booking options with a fixed duration but without dates are usually called "self-learning course". If you want to use another name for this type of booking options you can enter it here.';
$string['selflearningcourseplaceholder'] = 'You can access your course/the offer right away.';
$string['selflearningcourseplaceholderduration'] = 'It will be available to you for {$a}.';
$string['selflearningcourseplaceholderdurationexpired'] = 'You don\'t have access to this course anymore.';
$string['selflearningcoursesettingsheader'] = 'Booking options with fixed duration &nbsp;<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['selflearningcoursesettingsheaderdesc'] = 'This feature allows you to create booking options with a fixed duration but without dates. The booked users will be enrolled into the connected Moodle course for the specified duration.';
$string['selflearningcoursetimeremaininginfo'] = 'There are {$a} left to complete this course.';
$string['selflearningcoursetimeremaininginfoexpired'] = 'You don\'t have access to this course anymore.';
$string['semester'] = 'Semester';
$string['semesterend'] = 'Last day of semester';
$string['semesterend_help'] = 'The day the semester ends';
$string['semesterid'] = 'SemesterID';
$string['semesteridentifier'] = 'Identifier';
$string['semesteridentifier_help'] = 'Short text to identify the semester, e.g. "ws22".';
$string['semestername'] = 'Name';
$string['semestername_help'] = 'Enter the full name of the semester, e.g. "Semester of Winter 2021/22"';
$string['semesters'] = 'Semesters';
$string['semesterssaved'] = 'Semesters have been saved';
$string['semesterssubtitle'] = 'Here you can add, change or delete <strong>semesters and holidays</strong>.
    After saving, the entries will be ordered by their <strong>start date in descending order</strong>.';
$string['semesterstart'] = 'First day of semester';
$string['semesterstart_help'] = 'The day the semester starts.';
$string['send'] = 'Send';
$string['sendcopyofmail'] = 'Send an email copy';
$string['sendcopyofmailmessageprefix'] = 'Message prefix for the copy';
$string['sendcopyofmailsubjectprefix'] = 'Subject prefix for the copy';
$string['sendcustommsg'] = 'Send custom message';
$string['sendical'] = 'Attach ical file (.ics)';
$string['sendicalcreateorcancel'] = 'Should the ical create or cancel calendar events?';
$string['sendmail'] = 'Send email';
$string['sendmailheading'] = 'Send mail to all teachers of selected bookingoption(s)';
$string['sendmailinterval'] = 'Send a message to multiple users with a time delay';
$string['sendmailtoallbookedusers'] = 'Send e-mail to all booked users';
$string['sendmailtobooker'] = 'Book other users page: Send mail to user who books instead to users who are booked';
$string['sendmailtobooker_help'] = 'Activate this option in order to send booking confirmation mails to the user who books other users instead to users, who have been added to a booking option. This is only relevant for bookings made on the page "book other users".';
$string['sendmailtoteachers'] = 'Send mail to teacher(s)';
$string['sendmessage'] = 'Send message';
$string['sendpollurltoteachers'] = 'Send poll url';
$string['sendreminderemail'] = "Send reminder e-mail";
$string['sendreminderemailsuccess'] = 'Notification e-mail has been sent!';
$string['session'] = 'Date';
$string['sessionnotifications'] = 'E-mail notifications for each session';
$string['sessionremindermailmessage'] = '<p>Keep in mind: You are booked for the following session:</p>
<p>{$a->optiontimes}</p>
<p>##########################################</p>
<p>{$a->sessiondescription}</p>
<p>##########################################</p>
<p>Booking status: {$a->status}</p>
<p>Participant: {$a->participant}</p>
';
$string['sessionremindermailsubject'] = 'Reminder: You have an upcoming session';
$string['sessionremindershint'] = 'You can set up reminders for dates with <a href="{$a}" target="_blank">Booking Rules</a>';
$string['sessionremindersruleexists'] = 'There is at least one Booking Rule applied for this date.';
$string['sessions'] = 'Session(s)';
$string['sharedplacenoselect'] = 'Shared with <a href="{$a->url}">{$a->text}</a>';
$string['sharedplaces'] = 'Shared places';
$string['sharedplaces_help'] = 'Booked places of another booking option are added. If both options have 10 available places and only one is booked, only 9 places remain in both.';
$string['sharedplacespriority'] = 'Has priority';
$string['sharedplacespriority_help'] = 'If two linked booking options have free places at the same time, this one should be booked first.';
$string['sharedplacespriorityerror'] = 'The following booking option already has priority, so this one cannot have priority: <br> {$a}';
$string['shoppingcart'] = 'Set payment options with shopping cart plugin';
$string['shoppingcartplaceholder'] = 'Shoppingcart';
$string['shortcode:cmidnotexisting'] = 'The course module id {$a} does not exist for the booking activity.';
$string['shortcode:courseidnotexisting'] = 'The moodle course id {$a} does not exist.';
$string['shortcode:error'] = "This shortcode creates errors. Verify all the parameters";
$string['shortcodenotsupportedonyourdb'] = "This shortcode is not supported on your DB. It only works on postgres & mariadb";
$string['shortcodesettings'] = "Shortcodes Settings";
$string['shortcodesettings_desc'] = "Booking supports a couple of shortcodes which will allow you to show booking options at different places on your site.";
$string['shortcodesispasswordprotected'] = "Shortcodes are protected by passwords";
$string['shortcodesoff'] = 'Disable booking shortcodes';
$string['shortcodesoff_desc'] = 'Activate this setting, if you want to disable booking shortcodes (e.g. [courselist]) for the whole site.';
$string['shortcodesoffwarning'] = 'Shortcode [{$a}] can not be used, because shortcodes are turned off.';
$string['shortcodespassword'] = "Password";
$string['shortcodespassword_desc'] = "If you enter a value here, shortcodes can only be used with the parameter 'password', otherwise a warning will be displayed.
Example: [courselist cmid=1 <b>password=top_secret123</b>] or [courselist cmid=2 <b>password=\"password with spaces\"</b>].";
$string['shorttext'] = "Shorttext";
$string['showallbookingoptions'] = 'All booking options';
$string['showallteachers'] = '&gt;&gt; Show all teachers';
$string['showboactions'] = "Activate actions after booking";
$string['showbookingdetailstoall'] = 'Show booking details to all';
$string['showbookingdetailstoall_desc'] = 'Also guests and logged out users can see the booking details.';
$string['showcertificates'] = 'Show Certificates';
$string['showcoursenameandbutton'] = 'Show course name, short info and a button redirecting to the available booking options';
$string['showcoursesofteacher'] = 'Courses';
$string['showcustomfields'] = 'Custom booking option fields';
$string['showcustomfields_desc'] = 'Select the custom booking option fields to be shown on the sign-in sheet';
$string['showdates'] = 'Show dates';
$string['showdescription'] = 'Show description';
$string['showdetaildotsnextbookedalert'] = 'Show link to details for booked options';
$string['showdetaildotsnextbookedalert_desc'] = 'If this option is activated, a small button with three dots is displayed next to the information that a booking option has been booked.
This button linkes to the detailview of that option.';
$string['showinapi'] = 'Show in API?';
$string['showlistoncoursepage'] = 'Show extra information on course page';
$string['showlistoncoursepage_help'] = 'If you activate this setting, the course name, a short info and a button
                                            redirecting to the available booking options will be shown.';
$string['showmessages'] = 'Show messages';
$string['showmybookingsonly'] = 'My booked options';
$string['showmyfieldofstudyonly'] = "My field of study";
$string['showoptiondatesextrainfo'] = 'Show additional information for dates';
$string['showoptiondatesextrainfo_desc'] = 'Show comments and extra infos of sessions (dates) in the list of booking options
(on the booking option detail page the extra infos will always be shown regardless of this setting).
<i>Note: Links to online rooms (Teams, Zoom...) will only be shown on the details page.</i>';
$string['showpriceifnotloggedin'] = 'Display price(s) for users not logged in';
$string['showprogressbars'] = 'Show progress bars of time passed';
$string['showrecentupdates'] = 'Show recent updates';
$string['showsimilaroptions'] = 'Show similar options';
$string['showsubbookings'] = 'Activate subbookings';
$string['showteachersmailinglist'] = 'Show a list of e-mails for all teachers...';
$string['showviews'] = 'Views to show in the booking options overview';
$string['signature'] = 'Signature';
$string['signinadddatemanually'] = 'Add date manually';
$string['signinaddemptyrows'] = 'Add empty rows';
$string['signincustfields'] = 'Custom profile fields';
$string['signincustfields_desc'] = 'Select the custom profiles fields to be shown on the sign-in sheet';
$string['signinextracols'] = 'Additional column';
$string['signinextracols_desc'] = 'You can print up to 3 additional columns on the sign-in sheet. Fill in the column title or leave it blank for no additional column';
$string['signinextracolsheading'] = 'Additional columns on the sign-in sheet';
$string['signinextrasessioncols'] = 'Add extra columns for dates';
$string['signinformat'] = 'Save as';
$string['signinformatbutton'] = 'Create from HTML template';
$string['signinhidedate'] = 'Hide dates';
$string['signinlogo'] = 'Logo to display on the sign-in sheet';
$string['signinlogofooter'] = 'Logo in footer to display on the sign-in sheet';
$string['signinlogoheader'] = 'Logo in header to display on the sign-in sheet';
$string['signinonesession'] = 'Display date(s) in the header';
$string['signinsheet_htmltemplate'] = 'HTML template';
$string['signinsheet_legacy'] = 'Classic sign-in sheet';
$string['signinsheetaddress'] = 'Address: ';
$string['signinsheetconfigure'] = 'Configure sign-in sheet';
$string['signinsheetdate'] = 'Date(s): ';
$string['signinsheetdatetofillin'] = 'Date: ';
$string['signinsheetdownload'] = 'Download sign-in sheet';
$string['signinsheetfields'] = 'Sign-in sheet fields (PDF)';
$string['signinsheethtml'] = 'HTML template for generating signature sheets';
$string['signinsheethtmldescription'] = 'You can use the following placeholders:

<b>Inside [[users]] ... [[/users]]:</b><br>
[[fullname]], [[firstname]], [[lastname]], [[email]], [[signature]], [[institution]], [[description]], [[city]], [[country]], [[idnumber]], [[phone1]], [[department]], [[address]], [[places]]<br>
(possibly also custom profile fields, if configured)

<b>Outside of [[users]]:</b><br>
[[location]], [[dayofweektime]], [[teachers]], [[dates]], [[logourl]], [[tablename]]

Use only basic HTML that is supported by TCPDF / PhpWord. To insert signatures into a table, use the CSS class <code>"signaturetable"</code>.';
$string['signinsheetlocation'] = 'Location: ';
$string['signinsheetmode'] = 'Sign-in sheet mode';
$string['signinsheetmode_desc'] = 'Select the mode for downloading the sign-in sheet: HTML template or Legacy mode.';
$string['skipbookingrulesmode'] = 'Apply booking rules';
$string['skipbookingrulesoptin'] = 'Opt in: Only apply the following rules';
$string['skipbookingrulesoptout'] = 'Opt out: Don\'t apply the following rules';
$string['skipbookingrulesrules'] = 'Choose booking rules';
$string['sortbookingoptions'] = "Please sort your bookings in the right order. You will only be able to access the associated courses one after the other. Top comes first.";
$string['sortorder'] = 'Sort order';
$string['sortorder:asc'] = 'A&rarr;Z';
$string['sortorder:desc'] = 'Z&rarr;A';
$string['spaceleft'] = 'space available';
$string['spacesleft'] = 'spaces available';
$string['sqlfiltercheckstring'] = 'Hide bookingoption when condition not met';
$string['startdate'] = "startdate";
$string['starttime'] = "starttime";
$string['starttimenotset'] = 'Start date not set';
$string['status'] = 'status';
$string['statusattending'] = "Attending";
$string['statuschangetext'] = 'Status change message';
$string['statuschangetextmessage'] = 'Hello {$a->participant}!
Your booking status has changed.
Booking status: {$a->status}
Participant:   {$a->participant}
Booking option: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Go to the booking option: {$a->gotobookingoption}
';
$string['statuschangetextsubject'] = 'Booking status has changed for {$a->title}';
$string['statuscomplete'] = "Complete";
$string['statusexcused'] = "Excused";
$string['statusfailed'] = "Failed";
$string['statusincomplete'] = "Incomplete";
$string['statusnoshow'] = "No show";
$string['statusnotset'] = "No status.";
$string['statusunknown'] = "Unknown";
$string['sthwentwrongwithplaceholder'] = '';
$string['studentbooked'] = 'Users who booked';
$string['studentbookedandwaitinglist'] = 'Users who booked and are on waitinglist';
$string['studentdeleted'] = 'Users who were already deleted';
$string['studentnotificationlist'] = 'Users on the notification list';
$string['studentwaitinglist'] = 'Users on the waiting list';
$string['subbookingadditemformlink'] = "Link to the form of this booking option";
$string['subbookingadditemformlink_help'] = "Select the form element you want to link with this additional booking. The additional booking will only be displayed if the user has selected the corresponding value in the form beforehand.";
$string['subbookingadditemformlinkvalue'] = "Value that should be selected in the form";
$string['subbookingadditionalitem'] = "Additional item booking";
$string['subbookingadditionalitem_desc'] = "This permits you to add optinally bookable items to this booking option,
 eg. you can book a better special seat etc. or breakfast to your hotel room.";
$string['subbookingadditionalitemdescription'] = "Describe the additionally bookable item:";
$string['subbookingadditionalperson'] = "Additional person booking";
$string['subbookingadditionalperson_desc'] = "This permits you to add other persons to this booking option,
 e.g. to book places for your family members.";
$string['subbookingadditionalpersondescription'] = "Describe the additional person booking option";
$string['subbookingaddpersons'] = "Add additional person(s)";
$string['subbookingbookedpersons'] = "The following person(s) are added:";
$string['subbookingduration'] = "Duration in minutes";
$string['subbookingname'] = "Name of the subbooking";
$string['subbookings'] = "Subbookings";
$string['subbookings_desc'] = 'Activate subbookings in order to enable the booking of additional items or time slots (e.g. for tennis courts).';
$string['subbookingsheader'] = 'Subbookings <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['subbookingtimeslot'] = "Timeslot booking";
$string['subbookingtimeslot_desc'] = "This opens timeslots for every booking date with a set duration.";
$string['subject'] = 'Subject';
$string['submitandadd'] = 'Add a new booking option';
$string['submitandgoback'] = 'Close this form';
$string['submitandstay'] = 'Stay here';
$string['subplugintype_bookingextension_plural'] = 'Booking extensions';
$string['subscribersto'] = 'Teachers for \'{$a}\'';
$string['subscribetocourse'] = 'Enrol users in the course';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['substitutions'] = 'Substitution(s)';
$string['successfulcalculation'] = 'Price calculation successful!';
$string['successfulldeleted'] = 'Category was deleted!';
$string['successfullybooked'] = 'Successfully booked';
$string['successfullysorted'] = 'Successfully sorted';
$string['sucesfullcompleted'] = 'Activity was successfully completed for users.';
$string['sucesfullytransfered'] = 'Users were successfully transfered.';
$string['sucessfullybooked'] = 'Sucessfully booked';
$string['sumunits'] = 'Sum of units';
$string['sunday'] = 'Sunday';
$string['switchtemplates'] = 'Users can switch between views';
$string['switchtemplates_help'] = 'Enable this setting to allow users to switch between different views. In the next setting, you can define the views that can be switched between.';
$string['switchtemplatesselection'] = 'Views that can be switched between';
$string['switchtemplatesselection_help'] = 'Select the views between which users can switch.';
$string['system'] = 'System';
$string['tableheadercourseendtime'] = 'Course end';
$string['tableheadercoursestarttime'] = 'Course start';
$string['tableheadermaxanswers'] = 'Available places';
$string['tableheadermaxoverbooking'] = 'Waiting list places';
$string['tableheaderminanswers'] = 'Min. number of participants';
$string['tableheaderteacher'] = 'Teacher(s)';
$string['tableheadertext'] = 'Course name';
$string['tabwhatsnew'] = 'Booking tab: "What\'s new?"';
$string['tabwhatsnew_desc'] = 'You can use this tab to show users all new bookings that have been
set to visible OR have been created within a certain number of days (counted from today) which you can define here.
<span class="text-danger">Please remember to add the tab in the settings of your Booking instances after activating it.</span>';
$string['tabwhatsnewdays'] = 'Number of days for "What\'s new?"';
$string['tabwhatsnewdays_desc'] = 'Enter the number of days into the past until when a Booking option is considered as new.
Example: If you choose 30 then a booking option which has been set to visible or created longer than 30 days ago will not
show up in the "What\'s new?"-Tab. 0 means that only booking options having been created or set to visible today will be shown.';
$string['tagdeleted'] = 'Tag template was deleted!';
$string['tagsuccessfullysaved'] = 'Tag was saved.';
$string['tagtag'] = 'Tag';
$string['tagtemplates'] = 'Tag templates';
$string['tagtext'] = 'Text';
$string['taken'] = 'Taken';
$string['taskadhocresetoptiondatesforsemester'] = 'Adhoc task: Reset and generate new optiondates for semester';
$string['taskcheckanswers'] = 'Booking: Check answers';
$string['taskcleanbookingdb'] = 'Booking: Clean database';
$string['taskenrolbookeduserstocourse'] = 'Booking: Enrol booked users to course';
$string['taskpurgecampaigncaches'] = 'Booking: Clean caches for booking campaigns';
$string['taskrecalculateprices'] = 'Prices of a booking instance are recalculated using the price formula';
$string['taskremoveactivitycompletion'] = 'Booking: Remove activity completion';
$string['tasksendcompletionmails'] = 'Booking: Send completion mails';
$string['tasksendconfirmationmails'] = 'Booking: Send confirmation mails';
$string['tasksendmailbyruleadhoc'] = 'Booking: Send mail by rule (adhoc task)';
$string['tasksendnotificationmails'] = 'Booking: Send notification mails';
$string['tasksendremindermails'] = 'Booking: Send reminder mails';
$string['teacher'] = 'Teacher';
$string['teacherdescription'] = 'Description';
$string['teachernotfound'] = 'Teacher could not be found or does not exist.';
$string['teacherpageshiddenbookingids'] = 'Booking instances to be hidden on teacher pages';
$string['teacherroleid'] = 'Select the following role to enrol teachers in a newly created course (if given).';
$string['teachers'] = 'Teachers';
$string['teachersallowmailtobookedusers'] = 'Allow teachers to send an e-mail to all booked users using their own mail client';
$string['teachersallowmailtobookedusers_desc'] = 'If you activate this setting, teachers can click a button to send an e-mail
    to all booked users using their own mail client - the e-mail-addresses of all users will be visible.
    <span class="text-danger"><b>Be careful:</b> This might be a privacy issue. Only activate this,
    if you are sure it corresponds with your organization\'s privacy policy.</span>';
$string['teachersalwaysenablemessaging'] = 'Allow users to send message all teachers';
$string['teachersalwaysenablemessaging_desc'] = 'If you activate this setting, users can send messages to teachers even if they aren\'t enroled in any of their courses.';
$string['teachersettings'] = 'Teachers <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['teachersettings_desc'] = 'Teacher-specific settings.';
$string['teachersforoption'] = 'Teachers';
$string['teachersforoption_help'] = '<b>BE CAREFUL: </b>When adding teachers here, they will also be <b>added to each date in the future</b> in the teaching report.
When deleting teachers here, they will be <b>removed from each date in the future</b> in the teaching report!';
$string['teachersinstanceconfig'] = 'Edit booking option form';
$string['teachersinstancereport'] = 'Teachers report';
$string['teachersinstancereport:subtitle'] = '<strong>Hint:</strong> The number of units of a course (booking option) is calculated by the duration of an educational unit
 which you can <a href="{$a}" target="_blank">set in the booking settings</a> and the specified date series string (e.g. "Tue, 16:00-17:30").
 For blocked events or booking options missing this string, the number of units cannot be calculated!';
$string['teacherslinkonteacher'] = 'Add links to teacher pages';
$string['teacherslinkonteacher_desc'] = 'When there are teachers added to booking options, this setting will add a link to an overview page for each teacher.';
$string['teachersnologinrequired'] = 'Login for teacher pages not necessary';
$string['teachersnologinrequired_desc'] = 'If you activate this setting, everyone can access the teacher pages, regardless if logged in or not.';
$string['teachersshowemails'] = 'Always show teacher\'s email addresses to everyone';
$string['teachersshowemails_desc'] = 'If you activate this setting, every user can see
    the e-mail address of any teacher - even if they are not logged in. <span class="text-danger"><b>Be careful:</b> This might be
    a privacy issue. Only activate this, if you are sure it corresponds with your organization\'s privacy policy.</span>';
$string['teachingconfigforinstance'] = 'Edit booking option form for ';
$string['teachingreportforinstance'] = 'Teaching overview report for ';
$string['teachingreportfortrainer'] = 'Report of performed teaching units for trainer';
$string['teachingreportfortrainer:subtitle'] = '<strong>Hint:</strong> You can change the duration of
an educational unit in the plugin settings (e.g. 45 instead of 60 minutes).<br/>
<a href="{$a}" target="_blank">
&gt;&gt; Go to plugin settings...
</a>';
$string['teamsmeeting'] = 'Teams meeting';
$string['template'] = 'Templates';
$string['templatecategoryname'] = 'Short name of the course category where the template courses are located.';
$string['templatecategoryname_desc'] = 'Booking options can be linked to Moodle courses. This feature allows the courses to be created upon the first saving of the booking option.';
$string['templatedeleted'] = 'Template was deleted!';
$string['templatefile'] = 'Template file';
$string['templatesuccessfullysaved'] = 'Template was saved.';
$string['terminated'] = "Terminated";
$string['text'] = 'Title';
$string['textarea'] = "Textarea";
$string['textdependingonstatus'] = "Text depending on booking status ";
$string['textfield'] = 'Single line text input';
$string['thankyoubooked'] = '<i class="fa fa-3x fa-calendar-check-o text-success" aria-hidden="true"></i><br><br>
Thank you! You have successfully booked <b>{$a}</b>.';
$string['thankyoucheckout'] = '<i class="fa fa-3x fa-shopping-cart text-success" aria-hidden="true"></i><br><br>
Thank you! You have successfully put <b>{$a}</b> into the shopping cart. Now click on <b>"Proceed to checkout"</b>
 to continue.';
$string['thankyouerror'] = '<i class="fa fa-3x fa-frown-o text-danger" aria-hidden="true"></i><br>
Unfortunately, there was an error when booking <b>{$a}</b>.';
$string['thankyouwaitinglist'] = '<i class="fa fa-3x fa-clock-o text-primary" aria-hidden="true"></i><br><br>
 You were added to the waiting list for <b>{$a}</b>. You will automatically move up, in case someone drops out.';
$string['thisinstance'] = 'This booking instance';
$string['thursday'] = 'Thursday';
$string['timecreated'] = 'Time created';
$string['timefilter:bookingtime'] = 'Booking time';
$string['timefilter:coursetime'] = 'Course time';
$string['timeintervalls'] = "Time intervals";
$string['timeintervalls_desc'] = "If selected, time selection fields will use 5-minute intervals instead of 1-minute intervals.";
$string['timemadevisible'] = 'Made visible at';
$string['timemodified'] = 'Time modified';
$string['timerestrict'] = 'Restrict answering to this time period: This is deprecated and will be removed. Please use "Restrict Access" settings for making the booking activity available for a certain period';
$string['title'] = "Title";
$string['titleprefix'] = 'Prefix';
$string['titleprefix_help'] = 'Add a prefix which will be shown before the option title, e.g. "BB42".';
$string['to'] = 'To';
$string['toomanytoshow'] = 'Too many records found...';
$string['toomuchusersbooked'] = 'The max number of users you can book is: {$a}';
$string['topic'] = "Topic";
$string['transefusers'] = "Transfer users";
$string['transfer'] = 'Transfer';
$string['transferheading'] = 'Transfer selected users to the selected booking option';
$string['transferhelp'] = 'Transfer users, that have not completed activity from selected option to {$a}.';
$string['transferoptionsuccess'] = 'The booking option and the users have successfully been transferred.';
$string['transferproblem'] = 'The following could not be transferred due to booking option limitation or user limitation: {$a}';
$string['transfersuccess'] = 'The users have successfully been transferred to the new booking option';
$string['tuesday'] = 'Tuesday';
$string['turnoffmodals'] = "Turn off modals";
$string['turnoffmodals_desc'] = "Some steps during the booking process will open modals. This settings will show the information inline, no modals will open.
<b>Please note:</b> If you use the Booking <b>cards view</b>, then modals will still be used. You can <b>only turn them off for list view</b>.";
$string['turnoffwaitinglist'] = 'Turn off waiting list globally';
$string['turnoffwaitinglist_desc'] = 'Activate this setting, if you do not want to use the waiting list
 feature on this site (e.g. because you only want to use the notification list).';
$string['turnoffwaitinglistaftercoursestart'] = 'Turn off automatic moving up from waiting list after a booking option has started.';
$string['turnoffwunderbytelogo'] = 'Do not show Wunderbyte logo and link';
$string['turnoffwunderbytelogo_desc'] = 'If you activate this setting, the Wunderbyte logo and the link to the Wunderbyte website won\'t be shown.';
$string['turnthisoninsettings'] = 'Activation in config settings necessary';
$string['turnthisoninsettings_help'] = 'Not yet activated. <a href="{$a}" target="_blank">Click here to enable this functionality in the global settings</a>.';
$string['unconfirm'] = 'Delete confirmation';
$string['unconfirmbooking'] = 'Delete confirmation of this booking';
$string['unconfirmbookinglong'] = 'Do you really want to delete the confirmation of this booking?';
$string['undocancelreason'] = "Do you really want to undo the cancellation of this booking option?";
$string['undocancelthisbookingoption'] = "Undo cancelling of this booking option";
$string['unenrolfromgroupofcurrentcourse'] = 'Unenrol from specific group when user is unenroled from corresponding bookingoption?';
$string['unenroluserswithoutaccess'] = 'Delete booking answers of users without access';
$string['unenroluserswithoutaccess_desc'] = 'Automatically unenroll users who no longer have access to a Moodle course or a Booking activity.
<div class="text-danger">Warning: This might make tracking more difficult. Once this checkbox is activated, the system will perform a one-time
check system-wide to see if there are bookings to be deleted. The deletion of the bookings always occurs asynchronously with a delay of
approx. 15 minutes. So if you mistakenly unenroll a user, you have a few minutes to remove this checkbox and thereby prevent automatic deletion.</div>';
$string['unenroluserswithoutaccessareyousure'] = 'Do you really want to activate "Delete booking answers of users without access"?';
$string['unenroluserswithoutaccessareyousure_desc'] = 'Only after activating this checkbox and saving, you will be able to activate the actual setting.
You need to check both checkboxes for this to take any effect.';
$string['unenroluserswithoutaccessheader_desc'] = 'Automatically delete booking answers of users who no longer have access to a Moodle course
or a booking activity. (<b>Be careful</b>: This can lead to unexpected behavior. Only activate this if you really need it.)';
$string['units'] = 'Units';
$string['unitscourses'] = 'Courses / Units';
$string['unitsunknown'] = 'Number of units unknown';
$string['unlimitedcredits'] = 'Don\'t use credits';
$string['unlimitedplaces'] = 'Unlimited';
$string['unlinkallchildren'] = 'Delete linking of all children';
$string['unlinkchild'] = 'Delete linking to parent';
$string['unsubscribe:alreadyunsubscribed'] = 'You are already unsubscribed.';
$string['unsubscribe:errorotheruser'] = 'You are not allowed to unsubscribe a different user than yourself!';
$string['unsubscribe:successnotificationlist'] = 'You were unsubscribed successfully from e-mail notifications for "{$a}".';
$string['until'] = 'Until';
$string['updatebooking'] = 'Update booking';
$string['updatedrecords'] = '{$a} record(s) updated.';
$string['uploadheaderimages'] = 'Header images for booking options';
$string['usecompetencies'] = 'Use competencies';
$string['usecompetencies_desc'] = 'Booking options can be tagged with competencies and sorted accordingly';
$string['usecoursecategorytemplates'] = 'Use templates for newly created Moodle courses';
$string['usecoursecategorytemplates_desc'] = '';
$string['usedinbooking'] = 'You can\'t delete this category, because you\'re using it in booking!';
$string['usedinbookinginstances'] = 'Template is used in following booking instances';
$string['uselegacymailtemplates'] = 'Still use legacy mail templates';
$string['uselegacymailtemplates_desc'] = 'This function is deprecated and will be removed in the near future. We strongly encourage you to migrate your templates & settings to <a href="{$a}">Booking Rules</a>.
 <span class="text-danger"><b>Be careful:</b> If you uncheck this box, your email templates in your booking-instances won\'t be shown and used anymore.</span>';
$string['usenotificationlist'] = 'Use notification list';
$string['useonlyonefield'] = 'No additional field';
$string['useprice'] = 'Only book with price';
$string['useprotoenablemorerules'] = 'You need Booking PRO, to be able to add more rules.
<a href="https://wunderbyte.at/kontakt" target="_blank">Contact Wunderbyte</a>, if you want to buy a pro license.';
$string['useraffectedbyevent'] = 'User affected by the event';
$string['usercalendarentry'] = 'You are booked for <a href="{$a}">this session</a>.';
$string['usercalendarurl'] = "User calendar URL";
$string['userdownload'] = 'Download users';
$string['usergavereason'] = '{$a} gave the following reason for cancellation:';
$string['userid'] = 'UserID';
$string['userinfofieldoff'] = 'No user profile field selected';
$string['userinfosasstring'] = '{$a->firstname} {$a->lastname} (ID:{$a->id})';
$string['userleave'] = 'User has cancelled his/her own booking (enter 0 to turn off)';
$string['userleavemessage'] = 'Hello {$a->participant},
You have been unsubscribed from {$a->title}.
';
$string['userleavesubject'] = 'You successfully unsubscribed from {$a->title}';
$string['usermodified'] = 'Modified by';
$string['username'] = "username";
$string['usernameofbookingmanager'] = 'Choose a booking manager';
$string['usernameofbookingmanager_help'] = 'Username of the user who will be displayed in the "From" field of the confirmation notifications. If the option "Send confirmation e-mail to booking manager" is enabled, this is the user who receives a copy of the confirmation notifications.';
$string['userparameter_desc'] = "Use user parameter.";
$string['userparametervalue'] = "User parameter";
$string['userprofilefield'] = "Profile field";
$string['userprofilefieldoff'] = 'Do not show';
$string['userrank'] = 'Rank';
$string['usersmatching'] = 'Matching users';
$string['usersonlist'] = 'User on list';
$string['userspecificcampaignwarning'] = '
    If you choose a Custom user profile field here beneath, the campaign will only be effective for users with the defined or excluded value in the custom user profile field.
    ';
$string['userssuccessfullenrolled'] = 'All users have been enrolled!';
$string['userssuccessfullybooked'] = 'All users have been booked to the other booking option.';
$string['userssuccessfullygetnewpresencestatus'] = 'All users have a new presence status.';
$string['userssucesfullygetnewpresencestatus'] = 'Presence status for selected users successfully updated';
$string['userstonotify'] = 'Notification list';
$string['userwhotriggeredevent'] = 'User who triggered the event';
$string['viewallresponses'] = 'Manage {$a} responses';
$string['viewconfirmationbooked'] = 'Your booking has been registered:
{bookingdetails}
<p>##########################################</p>
Booking status: {status} <br>
Participant:   {firstname} {lastname} <br>
Back to overview of bookingoptions: {bookinglink}
';
$string['viewconfirmationwaiting'] = 'You are now on the waiting list of:
{bookingdetails}
<p>##########################################</p>
Booking status: {status} <br>
Participant:   {firstname} {lastname} <br>
Back to overview of bookingoptions: {bookinglink}
';
$string['viewparam'] = 'View type';
$string['viewparam:cards'] = 'Cards view';
$string['viewparam:list'] = 'List view';
$string['viewparam:listimgleft'] = 'List view with image on the left';
$string['viewparam:listimglefthalf'] = 'List view with image on the left over half the width';
$string['viewparam:listimgright'] = 'List view with image on the right';
$string['visibilitystatus'] = 'Visibility status';
$string['visibleoptions'] = 'Visible booking options';
$string['vuebookingstatsback'] = 'Back';
$string['vuebookingstatsbooked'] = 'Booked';
$string['vuebookingstatsbookingoptions'] = 'Booking Options';
$string['vuebookingstatscapability'] = 'Capability';
$string['vuebookingstatsno'] = 'No';
$string['vuebookingstatsreserved'] = 'Reserved';
$string['vuebookingstatsrestore'] = 'Restore';
$string['vuebookingstatsrestoreconfirmation'] = 'You really want to reset this configuration?';
$string['vuebookingstatssave'] = 'Save';
$string['vuebookingstatsselectall'] = 'Select all';
$string['vuebookingstatswaiting'] = 'Waiting List';
$string['vuebookingstatsyes'] = 'Yes';
$string['vuecapabilityoptionscapconfig'] = 'Capability Configuration';
$string['vuecapabilityoptionsnecessary'] = 'necessary';
$string['vuecapabilityunsavedchanges'] = 'There are unsaved changes';
$string['vuecapabilityunsavedcontinue'] = 'You really want to reset this configuration?';
$string['vueconfirmmodal'] = 'Are you sure you want to go back?';
$string['vuedashboardassignrole'] = 'Assign Roles';
$string['vuedashboardchecked'] = 'Default Checked';
$string['vuedashboardcoursecount'] = 'Course Count';
$string['vuedashboardcreateoe'] = 'Create new OE';
$string['vuedashboardgotocategory'] = 'Go to category';
$string['vuedashboardname'] = 'Name';
$string['vuedashboardnewcourse'] = 'Create new course';
$string['vuedashboardpath'] = 'Path';
$string['vueheadingmodal'] = 'Confirmation';
$string['vuenotfoundroutenotfound'] = 'Route not found';
$string['vuenotfoundtryagain'] = 'Please try later again';
$string['vuenotificationtextactionfail'] = 'Something went wrong while saving. The changes have not been made.';
$string['vuenotificationtextactionsuccess'] = 'Configuration was {$a} successfully.';
$string['vuenotificationtextunsave'] = 'There were no unsaved changes detected.';
$string['vuenotificationtitleactionfail'] = 'Configuration was not  {$a}';
$string['vuenotificationtitleactionsuccess'] = 'Configuration was {$a}';
$string['vuenotificationtitleunsave'] = 'No unsaved changes detected';
$string['waitforconfirmation'] = 'Book always only after confirmation';
$string['waitforconfirmationonwaitinglist'] = 'Confirmation only for waitinglist';
$string['waitinglist'] = 'Waiting list';
$string['waitinglistconfirmed'] = 'Waitinglist confirmed';
$string['waitinglistdeleted'] = 'From waitinglist deleted';
$string['waitinglistenoughmessage'] = 'Still enough waiting list places.';
$string['waitinglistfullmessage'] = 'Waiting list full.';
$string['waitinglistheader'] = 'Waiting list';
$string['waitinglistheader_desc'] = 'Here you can set how the booking waiting list should behave.';
$string['waitinglistinfotexts'] = 'Place availability display for waiting list';
$string['waitinglistinfotextsinfo'] = 'Select how place availability for the waiting list should be displayed to users.';
$string['waitinglistlowmessage'] = 'Only a few waiting list places left!';
$string['waitinglistlowpercentage'] = 'Percentage for waiting list low message';
$string['waitinglistlowpercentagedesc'] = 'If the available places on the waiting list reach or get below this percentage a waiting list low message will be shown.';
$string['waitinglistplacesplacesleft'] = '{$a} places left on the waiting list';
$string['waitinglistshowplaceonwaitinglist'] = 'Ranked waiting list';
$string['waitinglistshowplaceonwaitinglistinfo'] = 'Waiting list: Shows the exact place of the user on the waitinglist.
It\'s possible to re-rank the order of users on the waiting list manually.';
$string['waitinglisttaken'] = 'On the waiting list';
$string['waitinglistusers'] = 'Users on waiting list';
$string['waitingplacesavailable'] = 'Waiting list places available: {$a->overbookingavailable} of {$a->maxoverbooking}';
$string['waitingtext'] = 'Waiting list confirmation';
$string['waitingtextmessage'] = 'You are now on the waiting list of:
{$a->bookingdetails}
<p>##########################################</p>
Booking status: {$a->status}
Participant:   {$a->participant}
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['waitingtextsubject'] = 'Booking status for {$a->title} has changed';
$string['waitingtextsubjectbookingmanager'] = 'Booking status for {$a->title} has changed';
$string['waitspaceavailable'] = 'Places on waiting list available';
$string['warningcustomfieldsforbiddenshortname'] = 'You cannot use the following shortname(s) for custom fields: <b>{$a}</b>.
Please choose another shortname.';
$string['warningonlyteachersofselectedinstances'] = 'Please note: This page is currently only showing teachers
who are teachers in one of the Booking instances selected in the
<a href="{$a}" target="_blank">global setting "allteacherspagebookinginstances"</a>.';
$string['wednesday'] = 'Wednesday';
$string['week'] = "Week";
$string['whatsnew'] = 'What\'s new?';
$string['whichview'] = 'Default view for booking options';
$string['whichviewerror'] = 'You have to include the default view in: Views to show in the booking options overview';
$string['withselected'] = 'With selected users:';
$string['wrongdataallfields'] = 'Please, fill out all fields!';
$string['wronglabels'] = 'Imported CSV not containing the right labels. Column {$a} can not be imported.';
$string['xusersarebooked'] = '{$a} users are booked';
$string['yes'] = 'Yes';
$string['youareediting'] = 'You are editing "<b>{$a}</b>".';
$string['youareusingconfig'] = 'Your are using the following form configuration: {$a}';
$string['yourplaceonwaitinglist'] = 'You are on place {$a} on the waitinglist';
$string['yourselection'] = 'Your selection';
$string['zoommeeting'] = 'Zoom meeting';

// phpcs:disable
/*$string['ersaverelationsforoptiondates'] = 'Save entity for each date too';
$string['confirm:ersaverelationsforoptiondates'] = '<span class="text-danger">
<b>Be careful:</b> This booking option has dates with various entities.
Do you really want to set this entity for ALL dates?</span>';
$string['error:ersaverelationsforoptiondates'] = 'Please confirm that you want to overwrite deviating entities.'; */
// phpcs:enable

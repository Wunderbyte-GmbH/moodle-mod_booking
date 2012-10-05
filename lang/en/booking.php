<?PHP // $Id: booking.php,v 1.6.4.5 2011-02-01 23:05:09 dasistwas Exp $


$string['addmorebookings'] = 'Add more bookings';
$string['allowupdate'] = 'Allow booking to be updated';
$string['answered'] = 'Answered';
$string['attachical'] = 'Attach ical events';
$string['attachicaldesc'] = 'Email notifications will include an attached ical event, if this is enabled';
$string['booking'] = 'Booking';
$string['booking:choose'] = 'Boook';
$string['booking:deleteresponses'] = 'Delete responses';
$string['booking:downloadresponses'] = 'Download responses';
$string['booking:readresponses'] = 'Read responses';
$string['booking:updatebooking'] = 'Manage booking options';
$string['bookingclose'] = 'Until';
$string['bookingfull'] = 'There are no available places';
$string['bookingname'] = 'Booking name';
$string['bookingopen'] = 'Open';
$string['bookingtext'] = 'Booking text';
$string['expired'] = 'Sorry, this activity closed on {$a} and is no longer available';
$string['fillinatleastoneoption'] = 'You need to provide at least two possible answers.';
$string['full'] = 'Full';
$string['havetologin'] = 'You have to log in before you can submit your booking';
$string['limit'] = 'Limit';
$string['modulename'] = 'Booking';
$string['pluginname'] = 'Booking';
$string['pluginadministration'] = 'Booking administration';
$string['modulenameplural'] = 'Bookings';
$string['mustchooseone'] = 'You must choose an option before saving.  Nothing was saved.';
$string['noguestchoose'] = 'Sorry, guests are not allowed to enter data';
$string['noresultsviewable'] = 'The results are not currently viewable.';
$string['notopenyet'] = 'Sorry, this activity is not available until {$a} ';
$string['removeresponses'] = 'Remove all responses';
$string['responses'] = 'Responses';
$string['responsesto'] = 'Responses to {$a} ';
$string['spaceleft'] = 'space available';
$string['spacesleft'] = 'spaces available';
$string['taken'] = 'Taken';
$string['timerestrict'] = 'Restrict answering to this time period';
$string['viewallresponses'] = 'Manage {$a} responses';
$string['yourselection'] = 'Your selection';

// view.php
$string['coursedate'] = 'Date';
$string['select'] = 'Selection';
$string['availability'] = 'Still available';
$string['booknow'] = 'Book now';
$string['notbooked'] = 'Not yet booked';
$string['available'] = 'Places available';
$string['placesavailable'] = 'Places available';
$string['waitingplacesavailable'] = 'Waiting list places available';
$string['confirmbookingoffollowing'] = 'Please confirm the booking of following course';
$string['agreetobookingpolicy'] = 'I have read and agree to the following booking policies';
$string['bookingsaved'] = 'Your booking was successfully saved. You can now proceed to book other courses.';
$string['booked'] = 'Booked';
$string['cancelbooking'] = 'Cancel booking';
$string['deletebooking'] = 'Do you really want to unsubscribe from following course? <br /><br /> <b>{$a} </b>';
$string['bookingdeleted'] = 'Your booking was cancelled';
$string['nobookingselected'] = 'No booking option selected';
$string['updatebooking'] = 'Edit this booking option';
$string['managebooking'] = 'Manage';
$string['downloadusersforthisoptionods'] = 'Download users as .ods';
$string['downloadusersforthisoptionxls'] = 'Download users as .xls';
$string['download'] = 'Download';
$string['userdownload'] = 'Download users';
$string['allbookingoptions'] = 'Download users for all booking options';
$string['subscribetocourse'] = 'Enrol users in the course';
$string['closed'] = 'Booking closed';
$string['waitspaceavailable'] = 'Places on waiting list available';
$string['onwaitinglist'] = 'You are on the waiting list';
$string['bookingmeanwhilefull'] = 'Meanwhile someone took already the last place';
$string['unlimited'] = 'Unlimited';
$string['starttimenotset'] = 'Start date not set';
$string['endtimenotset'] = 'End date not set';
$string['mustfilloutuserinfobeforebooking'] = 'Befor proceeding to the booking form, please fill in some personal booking information';
$string['subscribeuser'] = 'Do you really want to enrol the users in the following course';
$string['deleteuserfrombooking'] = 'Do you really want to delete the users from the booking?';
$string['showallbookings'] = 'Show booking overview for all bookings';
$string['showmybookings'] = 'Show only my bookings';
$string['mailconfirmationsent'] = 'You will shortly receive a confirmation e-mail';
$string['deletebookingoption'] = 'Delete this booking option';
$string['confirmdeletebookingoption'] = 'Do you really want to delete this booking option?';
$string['norighttobook'] = 'Booking is not possible for your user role. Please contact the site administrator to give you the appropriate rights or sign in.';
$string['createdby'] = 'Booking module created by edulabs.org';
$string['maxperuserwarning'] = 'You have currently have {$a->count}/{$a->limit} maximum bookings';
$string['bookedpast'] = 'Booked (course finished)';

// mod_form
$string['limitanswers'] = 'Limit the number of participants';
$string['maxparticipantsnumber'] = 'Max. number of participants';
$string['maxoverbooking'] = 'Max. number of places on waiting list';
$string['defaultbookingoption'] = 'Default booking options';
$string['sendconfirmmail'] = 'Send confirmation email';
$string['sendconfirmmailtobookingmanger'] = 'Send confirmation email to booking manager';
$string['allowdelete'] = 'Allow users to cancel their booking themselves';
$string['bookingpolicy'] = 'Booking policy';
$string['confirmationmessagesettings'] = 'Confirmation email settings';
$string['usernameofbookingmanager'] = 'Username of the booking manager';
$string['autoenrol'] = 'Automatically enrol users';
$string['autoenrol_help'] = 'If selected, users will be enroled onto the relevant course as soon as they make the booking and unenroled from that course as soon as the booking is cancelled.';
$string['bookedtext'] = 'Booking confirmation';
$string['waitingtext'] = 'Waiting list confirmation';
$string['statuschangetext'] = 'Status change message';
$string['deletedtext'] = 'Cancelled booking message';
$string['bookedtext_help'] = 'Leave this blank to use the site default text. You can use any of the following placeholders in the text:
<ul>
<li>{status}</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
</ul>';
$string['waitingtext_help'] = $string['bookedtext_help'];
$string['statuschangetext_help'] = $string['bookedtext_help'];
$string['deletedtext_help'] = $string['bookedtext_help'];
$string['maxperuser'] = 'Max current bookings per user';
$string['maxperuser_help'] = 'The maximum number of bookings an individual user can make in this activity at once. After an event end time has passed, it is no longer counted against this limit.';

// editoptions.php
$string['submitandaddnew'] = 'Save and add new';
$string['choosecourse'] = 'Choose a course';
$string['startendtimeknown'] = 'Start and end time of course are known';
$string['coursestarttime'] = 'Start time of the course';
$string['courseendtime'] = 'End time of the course';
$string['addeditbooking'] = 'Edit booking';
$string['donotselectcourse'] = 'No course selected';
$string['waitinglisttaken'] = 'On the waiting list';
$string['addnewbookingoption'] = 'Add a new booking option';


// Confirmation mail
$string['days'] = '{$a} days';
$string['hours'] = '{$a} hours';
$string['minutes'] = '{$a} minutes';

$string['deletedbookingsubject'] = 'Deleted booking: {$a->title} by {$a->participant}';
$string['deletedbookingmessage'] = 'Booking for following course deleted: {$a->title}

User: {$a->participant}
Title: {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Course: {$a->courselink}
Booking link: {$a->bookinglink}

';

$string['confirmationsubject'] = 'Booking confirmation for {$a->title}';
$string['confirmationsubjectbookingmanager'] = 'New booking for {$a->title} by {$a->participant}';
$string['confirmationmessage'] = 'Your booking has been registered


Booking status: {$a->status}
Participant:   {$a->participant}
Course:   {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}

';
$string['confirmationsubjectwaitinglist'] = 'Booking status for {$a->title}';
$string['confirmationsubjectwaitinglistmanager'] = 'Booking status for {$a->title}';
$string['confirmationmessagewaitinglist'] = 'Hello {$a->participant},

Your booking request has been registered

Booking status: {$a->status}
Participant:   {$a->participant}
Course:   {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
To view all your booked courses click on the following link: {$a->bookinglink}

';
$string['statuschangebookedsubject'] = 'Booking status changed for {$a->title}';
$string['statuschangebookedmessage'] = 'Hello {$a->participant},

Your booking status has changed. You are now registered in {$a->title}.

Booking status: {$a->status}
Participant:   {$a->participant}
Course:   {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
To view all your booked courses click on the following link: {$a->bookinglink}
The associated course can be found here: {$a->courselink}
';
$string['deletedbookingusersubject'] = 'Booking for {$a->title} cancelled';
$string['deletedbookingusermessage'] = 'Hello {$a->participant},

Your booking for {$a->title} ({$a->startdate} {$a->starttime}) has been cancelled.
';

$string['error:failedtosendconfirmation'] = 'The following user did not receive a confirmation mail

Booking status: {$a->status}
Participant:   {$a->participant}
Course:   {$a->title}
Date: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Link: {$a->bookinglink}
Associated course: {$a->courselink}

';
//report.php
$string['withselected'] = 'With selected users:'; 
$string['associatedcourse'] = 'Associated course';
$string['bookedusers'] = 'Booked users';
$string['waitinglistusers'] = 'Users on waiting list';
$string['downloadallresponses'] = 'Download all responses for all booking options';


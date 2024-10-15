# Moodle Booking Module (mod_booking)

![Moodle Plugin CI](https://github.com/Wunderbyte-GmbH/moodle-mod_booking/actions/workflows/moodle-plugin-ci.yml/badge.svg)

This Moodle plugin allows provides users the possibility to book moodle-courses or other offline courses or any other
events. It has powerful features in order to manage and create booking options. Please contact info@wunderbyte.at for
improvement suggestions, bug reports etc.

## What does it do?

The booking module provides an easy to setup tool to manage course and event bookings. A typical use case would be an
event with a limited number of participants. You can set the maximum number of participants on the waiting list.
You can also automatically create sign-in sheets and mark the attendance in the module. Another usecase would be to
offer a management system for bookings for your online courses in Moodle. You can define the maximum number of
participants, enrol all users automatically to the course and move users from the waiting list to another booking option
when there is need for an additional course.

The tool also offers the possibility for specific users to manage their own event and to subscribe users to a course.
Also you can limit the maximum number of bookings a single user can make.

Booking is constantly updated with improvements, bugfixes and changes. Please have a look into **CHANGES.md** or into
**RELEASENOTES** to see what's new.

Interested in more features? [Contact us for a quote.](mailto:info@wunderbyte.at)

## Features
+ Max. number of participants
+ Waiting list
+ Move users to other booking options
+ Create a booking policy users have to agree to before making a booking
+ Booking confirmation e-mails with custom texts and placeholders for schedules
+ Add schedules as iCal attachment to confirmation e-mails
+ Automatic course enrolments for users who have successfully completed a booking
+ Manage bookings: Download all participants as CSV, Excel, PDF. Send custom messages. Send a reminder. Add notes to
individual bookings
+ Customised e-mail messages for users in waitng list or users with regular bookings. Cancellation messages, confirmation
messages.
+ Add start and end time for booking period
+ Automatic un-enrolment
+ Create and print sign-in sheets with custom logo and text
+ Customize the information included on the sign-in sheets and the bookings overview
+ Import participants from CSV files
+ Automatically add events to the Moodle calendar
+ Event/course description: Add location, poll URL, duration, additional files and tags
+ Organize booking instances into categories
+ Automatically enrol users in groups
+ Sort bookings by dates, etc.
+ Sending poll URLs to users
+ Up to 3 individual custom fields for multiple date sessions
+ Special functionality for video meeting links (will only redirect to session 15 minutes before until the end of the
session)
+ Session reminder e-mails
+ Detailed descriptions of booking options either via modal (little info button) or inline within the
  options table
+ Change notifications
+ Show a "business card" of the organizer
+ Possibility to show course name, short info and a button redirecting to the available booking options on course page
+ Cohort and group subscription for booking options
+ and much more...

## Features in PRO version
+ Appearance:
    + hide Wunderbyte logo and link
    + Collapse description and 'show dates'
    + Turn off modals
    + Options for attendance status
+ Teachers:
    + Add links to teacher pages
    + Login for teacher pages not necessary
    + Always show teacher’s email addresses to everyone
    + Show teacher’s email addresses to booked users
    + Teachers can send email to all booked users using own mail client
    + Teachers of booking option are assigned to fitting role
+ Cancellation settings:
    + Adjustable cancellation period
    + Cancellation cool off period (seconds)
+ Overbooking allowed of booking options
+ Automatic creation of Moodle courses:
    + Booking option custom field to be used as course category
    + Mark course with tags to use as templates
+ Price formula:
    + Use price formula to automatically calculate prices
    + Added features: applying unit factor, round prices
+ Duplicate moodle course when duplicating a booking option
+ Availability info texts for booking places and waiting list:
    + Show availability info for booking places
    + Enable “booking places low” message
    + Show availability info for waiting list
    + Enable “waiting list places low” message
    + Show place on waiting list
+ Activate subbookings
+ Activate actions after booking
+ Progress bars of time passed:
    + Show progress bars of time passed (for booking option)
    + Make progress bars collapsible

## Documentation
[Visit Moodle docs wiki](https://docs.moodle.org/311/en/Booking_module) for documentation.
For installation documentation see [installation](https://docs.moodle.org/35/en/Installing_plugins)

## Communication
+ [Twitter: @wunderbyte8](https://twitter.com/wunderbyte8)
+ [Github: @dasistwas](https://github.com/dasistwas)

## Contributing to the booking module

Contact me on Github (see above)

## Troubleshooting, Bugs, and Feedback
+ To report a bug, please go to [GitHub Issues](https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues).
+ To provide feedback, please use the [GitHub Issues](https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues).

## License
<a href="https://docs.moodle.org/dev/License" target="_blank"><img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/93/GPLv3_Logo.svg/220px-GPLv3_Logo.svg.png" alt="GPL Logo" align="right"></a>  The Moodle booking module is licensed under the [GNU General Public License, Version 3](http://www.gnu.org/licenses/gpl-3.0.html).

## Contributors
Main contributers are David Bogner, Georg Maißer, Bernhard Fischer, Andraž Prinčič and many others.
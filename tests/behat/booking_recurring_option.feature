@mod @mod_booking @booking_recurring_option
Feature: Create recurring options as teacher and configuring it.

  Background:
    ## Forcing of timezome is important for date validation
    Given the following config values are set as admin:
      | config        | value         |
      | timezone      | Europe/Berlin |
      | forcetimezone | Europe/Berlin |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name      | intro         | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | MyBooking | booking descr | teacher1       | Webinar   | All bookings                     |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: add few recurring options as a teacher and verify as student
    Given the following "mod_booking > options" exist:
      | booking   | text      | course | description | limitanswers | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | MyBooking | RecurrOpt | C1     | recurring   | 1            | 4          | 0              | 0              | 2373200400        | 2373208200      | 0              | 0              | 2383200400        | 2383300400      |
    ## 2045/03/15 14:20 - 2045/03/15 16:30 UTC
    ## 2045/07/09 08:06 - 2045/07/10 11:53 UTC
    And I am on the "MyBooking" Activity page logged in as teacher1
    ## Create 1st set of recurring options and validate it
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Recurring options"
    And I set the following fields to these values:
      | Repeat this option               | 1     |
      | Number of repetitions            | 3     |
      | Repetition interval              | Month |
      | requirepreviousoptionstobebooked | 1     |
    And I press "Save"
    And I should see "15 March 2045, 3:20 PM" in the ".allbookingoptionstable_r1" "css_element"
    ## Because of DST issue with PHP <=8.0 we check only dates:
    And I should see "9 July 2045" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "15 April 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "9 August 2045" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "15 May 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "9 September 2045" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "15 June 2045, 3:20 PM" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "9 October 2045" in the ".allbookingoptionstable_r4" "css_element"
    ## Create 2nd set of recurring options and validate it
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Recurring options"
    And I set the following fields to these values:
      | Repeat this option               | 1     |
      | Number of repetitions            | 2     |
      | Repetition interval              | Week  |
      | requirepreviousoptionstobebooked |       |
    And I press "Save"
    And I set the field "apply_to_children" to "Don't apply"
    And I press "Save"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "22 March 2045, 3:20 PM" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "16 July 2045" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r6" "css_element"
    And I should see "29 March 2045, 3:20 PM" in the ".allbookingoptionstable_r6" "css_element"
    And I should see "23 July 2045" in the ".allbookingoptionstable_r6" "css_element"
    And I log out
    ## Validate recurring options as student
    And I am on the "MyBooking" Activity page logged in as student1
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "15 March 2045, 3:20 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "9 July 2045" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "15 April 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "9 August 2045" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "15 May 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "9 September 2045" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r3" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "22 March 2045, 3:20 PM" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "16 July 2045" in the ".allbookingoptionstable_r5" "css_element"
    And I should not see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r5" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r6" "css_element"
    And I should see "29 March 2045, 3:20 PM" in the ".allbookingoptionstable_r6" "css_element"
    And I should see "23 July 2045" in the ".allbookingoptionstable_r6" "css_element"
    And I should not see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r6" "css_element"

  @javascript
  Scenario: Booking: add daily recurring options as a teacher and edit dates and titles
    Given the following "mod_booking > options" exist:
      | booking   | text      | course | description | limitanswers | maxanswers | availability | restrictanswerperiodopening | bookingopeningtime | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | MyBooking | RecurrOpt | C1     | recurring   | 1            | 4          | 1            | 1                           | 2373000400         | 0              | 0              | 2373200400        | 2373208200      | 0              | 0              | 2383200400        | 2383300400      |
    ## 2045/03/13 06:45 (bookingopeningtime)
    ## 2045/03/15 14:20 - 2045/03/15 16:30 UTC
    ## 2045/07/09 08:06 - 2045/07/10 11:53 UTC
    And I am on the "MyBooking" Activity page logged in as teacher1
    ## Create recurring options
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    And I set the following fields to these values:
      | Repeat this option               | 1   |
      | Number of repetitions            | 2   |
      | Repetition interval              | day |
    And I press "Save"
    And I should see "Bookable from: 13 March 2045, 7:46 AM" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "16 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Bookable from: 14 March 2045, 7:46 AM" in the ".allbookingoptionstable_r2 .bookingopeningtime" "css_element"
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "17 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Bookable from: 15 March 2045, 7:46 AM" in the ".allbookingoptionstable_r3 .bookingopeningtime" "css_element"
    ## Update existing recuring options
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    And I set the field "Booking option name" to "RecurrOptUpd1"
    And I click on "15 March 2045" "text" in the "#booking_optiondate_1" "css_element"
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 17   |
      | courseendtime_1[day]      | 17   |
    And I press "Apply"
    And I press "Save"
    And I set the field "apply_to_children" to "Overwrite all settings"
    And I press "Save"
    ## Verify that dates of child options have been updated
    And I should see "RecurrOptUpd1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "RecurrOptUpd1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "18 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOptUpd1" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "19 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    ## Update date of 2nd child
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I expand all fieldsets
    And I click on "19 March 2045" "text" in the "#booking_optiondate_1" "css_element"
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 20   |
      | courseendtime_1[day]      | 20   |
    And I press "Apply"
    And I press "Save"
    And I set the field "apply_to_siblings" to "Don't apply"
    And I press "Save"
    ## Verify that only date of 3rd child options has been updated
    And I should see "20 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "18 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    ## Update name of parent again
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Booking option name" to "RecurrOptUpd2"
    And I press "Save"
    And I set the field "apply_to_children" to "Apply current changes"
    And I press "Save"
    ## Verify that name of options has been updated
    And I should see "RecurrOptUpd2" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "RecurrOptUpd2" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "18 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOptUpd2" in the ".allbookingoptionstable_r3" "css_element"
    ## Verify that date of 2nd child option has NOT been updated
    And I should see "20 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    ## Update names of 2nd and 3rd childs' only
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I set the field "Booking option name" to "RecurrOptUpd3"
    And I press "Save"
    And I set the field "apply_to_siblings" to "Apply current changes"
    And I press "Save"
    ## Verify that names of 2nd and 3rd child options has been updated
    And I should see "RecurrOptUpd2" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "RecurrOptUpd3" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOptUpd3" in the ".allbookingoptionstable_r3" "css_element"
    ## Verify that dates of 2nd and 3rd child options has NOT been updated
    And I should see "18 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "20 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"

@mod @mod_booking @booking_recurring_option
Feature: Create recurring options as teacher and configuring it.

  Background:
    ## Unfortunately, TinyMCE is slow and has misbehavior which might cause number of site-wide issues. So - we disable it.
    Given the following config values are set as admin:
      | config        | value         |
      | texteditors   | atto,textarea |
    ## Forcing of timezome is important for date validation
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
    And the following "mod_booking > options" exist:
      | booking   | text      | course | description | limitanswers | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | MyBooking | RecurrOpt | C1     | recurring   | 1            | 4          | 0              | 0              | 2373200400        | 2373208200      | 0              | 0              | 2383200400        | 2383300400      |
    ## 2045/03/15 14:20 - 2045/03/15 16:30 UTC
    ## 2045/07/09 08:06 - 2045/07/10 11:53 UTC
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: add few recurring options as a teacher and verify as student
    Given I am on the "MyBooking" Activity page logged in as teacher1
    ## Create 1st set of recurring options
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Recurring options"
    And I set the following fields to these values:
      | Repeat this option               | 1     |
      | Number of repetitions            | 3     |
      | Repetition interval              | Month |
      | requirepreviousoptionstobebooked | 1     |
    And I press "Save"
    And I should see "15 March 2045, 3:20 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "9 July 2045, 10:06 AM" in the ".allbookingoptionstable_r1" "css_element"
    ## 1) Summmer time (DST) issue. 2) Potential interval issue.
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "14 April 2045, 4:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "8 August 2045, 10:06 AM" in the ".allbookingoptionstable_r2" "css_element"
    ## Because of summmer time (DST) issue we are testing date only for now:
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "14 May 2045" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "7 September 2045" in the ".allbookingoptionstable_r3" "css_element"
    And I log out
    ## Validate recurring options as student
    And I am on the "MyBooking" Activity page logged in as student1
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "15 March 2045, 3:20 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "9 July 2045, 10:06 AM" in the ".allbookingoptionstable_r1" "css_element"
    ## 1) Summmer time (DST) issue. 2) Potential interval issue.
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "14 April 2045, 4:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "8 August 2045, 10:06 AM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r2" "css_element"
    ## Because of summmer time (DST) issue we are testing date only for now:
    And I should see "RecurrOpt" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "14 May 2045" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "7 September 2045" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Booking: add daily recurring options as a teacher and edit dates
    Given I am on the "MyBooking" Activity page logged in as teacher1
    ## Create recurring options
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    ## And I follow "Recurring options"
    And I expand all fieldsets
    And I set the following fields to these values:
      | Repeat this option               | 1   |
      | Number of repetitions            | 2   |
      | Repetition interval              | day |
    And I press "Save"
    And I wait until the page is ready
    And I should see "RecurrOpt 1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "16 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOpt 2" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "17 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    ## Update existing recuring options
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I click on "15 March 2045" "text" in the "#booking_optiondate_1" "css_element"
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 17   |
      | courseendtime_1[day]      | 17   |
    And I press "Apply"
    And I wait until the page is ready
    And I expand all fieldsets
    And I set the field "Apply these changes to all the following bookingoption as well?" to "checked"
    And I press "Save"
    And I wait until the page is ready
    ## Verify that dates of child options have been updated
    And I should see "RecurrOpt 1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "18 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOpt 2" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "19 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    ## Update date of child
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I click on "19 March 2045" "text" in the "#booking_optiondate_1" "css_element"
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 20   |
      | courseendtime_1[day]      | 20   |
    And I press "Apply"
    And I wait until the page is ready
    And I press "Save"
    ## Update date of parent again
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I click on "17 March 2045" "text" in the "#booking_optiondate_1" "css_element"
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 10   |
      | courseendtime_1[day]      | 10   |
    And I press "Apply"
    And I wait until the page is ready
    And I expand all fieldsets
    And I set the field "Apply these changes to all the following bookingoption as well?" to "checked"
    And I press "Save"
    And I wait until the page is ready
    ## Verify that date of child options has been updated
    And I should see "RecurrOpt 1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "11 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    ## Verify that date of child options has NOT been updated
    And I should see "RecurrOpt 2" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "20 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"

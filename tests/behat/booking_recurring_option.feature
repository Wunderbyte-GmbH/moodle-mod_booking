@mod @mod_booking @booking_recurring_option
Feature: Create recurring options as tescher and configuring it.

  Background:
    Given the following "users" exist:
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
      | booking   | text      | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | RecurrOpt | C1     | recurring   | 1            | 4          | 1           | 0              | 0              | 2373200400        | 2373208200      |
    ## 2045/03/15 14:20 - 2045/03/15 16:30
    ## Unfortunately, TinyMCE is slow and has misbehavior which might cause number of site-wide issues. So - we disable it.
    And the following config values are set as admin:
      | config      | value         |
      | texteditors | atto,textarea |
      | timezone      | Europe/Berlin |
      | forcetimezone | Europe/Berlin |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: add daily recurring options as a teacher and verify as student
    Given I am on the "MyBooking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I follow "Recurring options"
    And I set the following fields to these values:
      | Repeat this option               | 1   |
      | How many times to repeat?        | 3   |
      | How often to repeat?             | day |
      | requirepreviousoptionstobebooked | 1   |
    And I press "Save"
    And I log out
    And I am on the "MyBooking" Activity page logged in as student1
    ##And I wait "13" seconds
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "RecurrOpt 1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "16 March 2045, 3:20 PM" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RecurrOpt 2" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "17 March 2045, 3:20 PM" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r3" "css_element"
    And I should see "RecurrOpt 3" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "18 March 2045, 3:20 PM" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Only users who have previously booked this option are allowed to book." in the ".allbookingoptionstable_r4" "css_element"

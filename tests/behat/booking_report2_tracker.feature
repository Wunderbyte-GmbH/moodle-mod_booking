@mod @mod_booking @booking_report2_tracker
Feature: Use the bookings tracker (report2.php) as replacement of the old report.php
  In order to manage booked users with the modernized report
  As a manager
  I need to reach the tracker scopes, see the option info line and download the sign-in sheet

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher1 | C1     | manager |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And I clean booking cache
    ## The tracker tables take their columns from the responsesfields setting,
    ## the sign-in sheet its columns from signinsheetfields.
    And the following "activities" exist:
      | activity | course | name     | intro         | bookingmanager | eventtype | responsesfields                       | signinsheetfields  |
      | booking  | C1     | Booking1 | Booking1Descr | teacher1       | Webinar   | completed,status,notes,fullname,email | fullname,signature |
    And the following "mod_booking > options" exist:
      | booking  | text       | course | description | useprice | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking1 | B1-Option1 | C1     | B1-Option1  | 0        | 5          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And the following "mod_booking > answers" exist:
      | booking  | option     | user     |
      | Booking1 | B1-Option1 | student1 |
      | Booking1 | B1-Option1 | student2 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Switch the system scope between aggregated options and single answers
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B1-Option1" in the "#booked_system_0_r1" "css_element"
    And I should see "2/5" in the "#booked_system_0_r1" "css_element"
    ## Switch to the non-aggregated answers view.
    When I click on "View all bookings separately" "link"
    And I wait until the page is ready
    Then I should see "student1@example.com"
    And I should see "student2@example.com"
    ## And back to the aggregated options view.
    When I click on "Aggregate bookings for each booking option" "link"
    And I wait until the page is ready
    Then I should see "2/5" in the "#booked_system_0_r1" "css_element"

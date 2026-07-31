@mod @mod_booking @booking_report2_tracker
Feature: Use the bookings tracker (report2.php) as replacement of the old report.php
  In order to manage booked users with the modernized report
  As a manager
  I need to reach the tracker scopes, see the option info line and download the sign-in sheet

  Background:
      Given the following "custom profile fields" exist:
      | datatype | shortname     | name                |
      | text     | userpricecat  | User Price Category |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 99           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 89           | 0        | 2                 |
      | 3        | zeroprice  | Zero  | 0            | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       |                            |
      | student2 | Student   | 2        | student2@example.com | S2       |                            |
      | student3 | Student   | 3        | student3@example.com | S3       | discount1                  |
      | student4 | Student   | 4        | student4@example.com | S4       | zeroprice                  |
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher1 | C1     | manager |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
      | student4 | C1     | student |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
    And I clean booking cache
    ## The tracker tables take their columns from the responsesfields setting,
    ## the sign-in sheet its columns from signinsheetfields.
    And the following "activities" exist:
      | activity | course | name     | intro         | bookingmanager | eventtype | responsesfields                                                      | signinsheetfields  |
      | booking  | C1     | Booking1 | Booking1Descr | teacher1       | Webinar   | completed,status,notes,fullname,email,waitinglist                    | fullname,signature |
      | booking  | C1     | Booking2 | Booking2Descr | teacher1       | Webinar   | completed,status,notes,fullname,email,waitinglist,price,userpricecat | fullname,signature |
    And the following "mod_booking > options" exist:
      | booking  | text       | course | description       | useprice | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking1 | B1-Option1 | C1     | B1-Option1        | 0        | 5          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking2 | B2-Option1 | C1     | B2-Option1-price  | 1        | 10         | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And the following "mod_booking > answers" exist:
      | booking  | option     | user     |
      | Booking1 | B1-Option1 | student1 |
      | Booking1 | B1-Option1 | student2 |
    And the following "mod_booking > user purchases" exist:
      | booking   | option     | user     |
      | Booking2  | B2-Option1 | student3 |
      | Booking2  | B2-Option1 | student4 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking report2: switch the system scope between aggregated options and single answers
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B2-Option1" in the "#booked_system_0_r1" "css_element"
    And I should see "B1-Option1" in the "#booked_system_0_r2" "css_element"
    And I should see "2/10" in the "#booked_system_0_r1" "css_element"
    And I should see "2/5" in the "#booked_system_0_r2" "css_element"
    ## Switch to the non-aggregated answers view.
    When I click on "View all bookings separately" "link"
    And I wait until the page is ready
    Then I should see "student1@example.com"
    And I should see "student2@example.com"
    And I should see "student3@example.com"
    And I should see "student4@example.com"
    ## And back to the aggregated options view.
    When I click on "Aggregate bookings for each booking option" "link"
    And I wait until the page is ready
    And I should see "2/10" in the "#booked_system_0_r1" "css_element"
    Then I should see "2/5" in the "#booked_system_0_r2" "css_element"

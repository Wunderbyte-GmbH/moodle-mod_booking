@mod @mod_booking @booking_installments
Feature: Enabling installments as admin configuring installments as a teacher and booking it as a student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name        | intro                | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingInst | Booking Installments | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    ## Default - enable installments by admin.
    And I log in as "admin"
    And I set the following administration settings values:
      | Enable Installments    | 1 |
      | Time Between Payments  | 2 |
      | Reminder x days before | 1 |
    And I change viewport size to "1366x10000"
    And I log out

  @javascript
  Scenario: Add an installmetn for a booking option as a teacher and verify it
    Given the following "mod_booking > options" exist:
      | booking     | text               | course | description | useprice | limitanswers | maxanswers | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | BookingInst | Option-installment | C1     | Deskr2      | 1        | 1            | 4          | 0              | 0              | ## +5 days ##     | ## +8 days ##   |
    And I am on the "BookingInst" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I follow "Shopping Cart"
    And I set the field "Allow installments" to "1"
    And I wait "1" seconds
    ## Intentional error and validation of it
    And I set the following fields to these values:
      | Down payment                           | 44 |
      | Number of Payments                     | 2  |
      | Due nr. of days after initial purchase | 10 |
      | Due nr. of days before coursestart     | 1  |
    And I press "Save"
    And I should see "Only one of these values can be more than 0"
    And I set the following fields to these values:
      | Due nr. of days after initial purchase | 0 |
    And I press "Save"
    And I wait "1" seconds
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And the following fields match these values:
      | Down payment                           | 44 |
      | Number of Payments                     | 2  |
      | Due nr. of days after initial purchase | 0  |
      | Due nr. of days before coursestart     | 1  |
    And I log out

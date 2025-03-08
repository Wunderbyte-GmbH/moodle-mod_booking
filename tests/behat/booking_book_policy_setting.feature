@mod @mod_booking @booking_policy_setting
Feature: Test of book policy setting in a booking instance
  As a teacher I add the bookig policy prompt
  As a student I book an option with agree on policy.

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
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail | bookingpolicy |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      | Are you sure? |
    And I create booking option "Test option 1" in "My booking"
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking policy: add promt to the booking instance as a teacher via UI
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Advanced options"
    And I set the field "Booking policy" to "Confirm booking!"
    And I press "Save and display"
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Confirm booking!" in the ".condition-bookingpolicy-form" "css_element"

  @javascript
  Scenario: Booking policy: book option with policy as student
    Given I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Are you sure?" in the ".condition-bookingpolicy-form" "css_element"
    And I set the field "bookingpolicy_checkbox" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Test option 1" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

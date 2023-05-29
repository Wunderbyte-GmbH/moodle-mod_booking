@mod @mod_booking @booking_price_formula
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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "Test option 1" in "My booking"
    And I create booking option "Test option 2" in "My booking"

  @javascript
  Scenario: Add booking policy promt to the booking instance
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I follow "Settings"
    And I follow "Miscellaneous settings"
    And I set the field "Booking policy" to "Are you sure?"
    And I press "Save and display"
    And I should see "Test option 1" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Are you sure?" in the ".condition-bookingpolicy-form" "css_element"
    And I log out

  @javascript
  Scenario: Add booking policy promt as teacher and book option with policy as student
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I follow "Settings"
    And I follow "Miscellaneous settings"
    And I set the field "Booking policy" to "Are you sure?"
    And I press "Save and display"
    And I should see "Test option 1" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Are you sure?" in the ".condition-bookingpolicy-form" "css_element"
    And I log out
    Given I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I wait "1" seconds
    And I should see "Test option 1" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Are you sure?" in the ".condition-bookingpolicy-form" "css_element"
    And I set the field "bookingpolicy_checkbox" to "checked"
    And I follow "Continue"
    ## And I wait "11" seconds
    And I should see "Book now" in the ".show .modalButtonAreaContainer" "css_element"
    And I click on "Book now" "text" in the ".show .modalButtonAreaContainer" "css_element"
    And I should see "Do you really want to book?" in the ".show .modalButtonAreaContainer" "css_element"
    And I click on "Do you really want to book?" "text" in the ".show .modalButtonAreaContainer" "css_element"
    And I should see "Booked" in the ".show .modalButtonAreaContainer" "css_element"
    And I follow "Continue"
    And I should see "You have successfully booked Test option 1" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Booked" in the "#allbookingoptionstable_r1" "css_element"

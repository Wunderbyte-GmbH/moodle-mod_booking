@mod @mod_booking @booking_price_formula
Feature: In a booking instance
  As a student
  I need to book option and then cancel it.

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
  Scenario: Simple booking of oprion as a student without cancellation
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I follow "Settings"
    And I follow "Miscellaneous settings"
    And I set the field "Allow users to cancel their booking themselves" to "No"
    And I press "Save and display"
    And I log out
    Given I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I wait "1" seconds
    Then I should see "Test option 1" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Do you really want to book?" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Do you really want to book?" "text" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Booked" in the "#allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I should not see "Undo my booking" in the "#allbookingoptionstable_r1 .booknow" "css_element"

@javascript
  Scenario: Simple booking of oprion as a student with cancellation
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I follow "Settings"
    And I follow "Miscellaneous settings"
    And I set the field "Allow users to cancel their booking themselves" to "Yes"
    And I press "Save and display"
    And I log out
    Given I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I wait "1" seconds
    Then I should see "Test option 1" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Do you really want to book?" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Do you really want to book?" "text" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "Booked" in the "#allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Undo my booking" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I click on "Undo my booking" "text" in the "#allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Do you really want to be removed from this booking option?" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Do you really want to be removed from this booking option?" "text" in the "#allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I should see "Book now" in the "#allbookingoptionstable_r1 .booknow" "css_element"
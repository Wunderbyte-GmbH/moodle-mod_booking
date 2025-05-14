@mod @mod_booking @booking_delete
Feature: In a booking delete
  As a teacher
  I need to add a booking option and event to a booking instance.

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
      | admin    | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     |
    And I create booking option "New option" in "My booking"
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Delete booking option as teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Delete this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Do you really want to delete this booking option New option?"
    And I click on "Continue" "button"
    And "//div[@id, 'allbookingoptionstable_r1']" "xpath_element" should not exist
    And I log out
    When I log in as "admin"
    And I trigger cron
    And I run all adhoc tasks
    And I visit "/report/loglive/index.php"
    Then I should see "Booking option deleted"
    And I log out

  @javascript
  Scenario: Delete user from booking option as teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I should see "Student 1"
    And I should see "Student 2"
    And I click on "selectall" "checkbox"
    And I click on "Delete responses" "button"
    And I should not see "Student 1"
    And I should not see "Student 2"
    When I log in as "admin"
    And I trigger cron
    And I run all adhoc tasks
    And I visit "/report/loglive/index.php"
    Then I should see "The user \"Teacher 1 (ID:"
    And I should see "cancelled \"Student 1 (ID:"
    And I should see "cancelled \"Student 2 (ID:"
    And I should see "from \"New option (ID:"
    And I log out

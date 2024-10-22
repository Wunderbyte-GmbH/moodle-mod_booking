@mod @mod_booking @booking_manage_waiting_list
Feature: In a course add a booking option and manage its waiting list
  As an administrator or a teacher
  I need to manage waiting list and booked students of booking option

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
      | student3 | Student   | 3        | student3@example.com | S3       |
      | student4 | Student   | 4        | student4@example.com | S4       |
      | student5 | Student   | 5        | student5@example.com | S5       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: reorder waiting list
    Given the following "mod_booking > options" exist:
      | booking    | text                 | course | description  | teachersforoption | maxanswers | maxoverbooking | datesmarker | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | waitforconfirmation |
      | My booking | Option: waiting list | C1     | Waiting list | teacher1          | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 1                   |
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Student 3 (student3@example.com)" "text"
    When I click on "Add" "button"
    ## Book 2 students
    And I click on "[data-target='#accordion-item-waitinglist']" "css_element"
    And I click on "tr:has(:contains('Student 1')) [data-methodname='confirmbooking']"
    # And I click on the element with the number "3" with the dynamic identifier "waitinglist"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    ## And I wait until the page is ready
    And I wait "1" seconds
    And I click on "tr:has(:contains('Student 2')) [data-methodname='confirmbooking']"
    # And I click on the element with the number "2" with the dynamic identifier "waitinglist"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I wait until the page is ready
    Then I should see "Student 1 (student1@example.com)" in the ".userselector #removeselect" "css_element"
    And I should see "Student 2 (student2@example.com)" in the ".userselector #removeselect" "css_element"
    ## Add 2 more students
    And I click on "Student 4 (student4@example.com)" "text"
    And I click on "Student 5 (student5@example.com)" "text"
    When I click on "Add" "button"
    ## Verify location
    And I should see "student3@example.com" in the "//tr[contains(@id, 'waitinglist') and contains(@id, '_r1')]" "xpath_element"
    And I should see "student5@example.com" in the "//tr[contains(@id, 'waitinglist') and contains(@id, '_r2')]" "xpath_element"
    ## Resort rows
    And I drag "//tr[contains(@id, '_r2')]//span[@data-drag-type='move']" "xpath_element" and I drop it in "//tr[contains(@id, '_r1')]//span[@data-drag-type='move']" "xpath_element"
    And I should see "student5@example.com" in the "//tr[contains(@id, 'waitinglist') and contains(@id, '_r1')]" "xpath_element"

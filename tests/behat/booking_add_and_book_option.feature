@mod @mod_booking @booking_add_and_book_option
Feature: In a booking instance create booking options
  As a teacher
  I need to add booking options and events to a booking instance

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
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   |

  @javascript
  Scenario: Create booking instance as teacher
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "Booking" to section "0"
    And I set the following fields to these values:
      | Booking instance name            | Test booking                                           |
      | Event type                       | Webinar                                                |
      | Booking text                     | This is the description for the test booking instance. |
      | Organizer name                   | Teacher 1                                              |
      | Sort by                          | Name (without prefix)                                  |
      | Default view for booking options | All booking options                                    |
    And I press "Save and return to course"
    Then I should see "Test booking"

  @javascript
  Scenario: Create booking option as a teacher, see it on activity page and book it as a student
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Test option - Webinar |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | ## tomorrow ## %d ## |
      | coursestarttime_1[month]  | ## tomorrow ## %B ## |
      | coursestarttime_1[year]   | ## tomorrow ## %Y ## |
      | coursestarttime_1[hour]   | 00                   |
      | coursestarttime_1[minute] | 00                   |
    And I set the following fields to these values:
      | courseendtime_1[day]    | ## + 1 year ## %d ## |
      | courseendtime_1[month]  | ## + 1 year ## %B ## |
      | courseendtime_1[year]   | ## + 1 year ## %Y ## |
      | courseendtime_1[hour]   | 00                   |
      | courseendtime_1[minute] | 00                   |
    And I press "Save"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    When I am on the "My booking" Activity page logged in as student1
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Booked" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"

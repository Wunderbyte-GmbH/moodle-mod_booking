@mod @mod_booking @booking_add_option
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
  Scenario: Create booking instance
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "Booking" to section "0"
    And I set the following fields to these values:
      | Booking name                     | Test booking                                           |
      | Event type                       | Webinar                                                |
      | Booking text                     | This is the description for the test booking instance. |
      | Organizer name                   | Teacher 1                                              |
      | Sort by                          | Booking option name                                    |
      | Default view for booking options | All bookings                                           |
    And I press "Save and return to course"
    Then I should see "Test booking"
    And I log out

  @javascript
  Scenario: Create booking option and see it on activity page
    Given I am on the "Course 1" course page logged in as teacher1
    And I follow "My booking"
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Test option - Webinar |
    And I set the field "startendtimeknown" to "checked"
    And I set the field "addtocalendar" to "1"
    And I set the following fields to these values:
      | coursestarttime[day]    | 31       |
      | coursestarttime[month]  | December |
      | coursestarttime[year]   | 2021     |
      | coursestarttime[hour]   | 09       |
      | coursestarttime[minute] | 00       |
    And I set the following fields to these values:
      | courseendtime[day]    | 31       |
      | courseendtime[month]  | December |
      | courseendtime[year]   | 2022     |
      | courseendtime[hour]   | 09       |
      | courseendtime[minute] | 00       |
    And I press "Save and go back"
    And I should see "Book now"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I press "Book now"
    Then I should see "Your booking was successfully saved"
    And I press "Continue"
    And I should see "Booked"
    And I should not see "Book now"

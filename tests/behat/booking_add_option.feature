@mod @mod_booking @booking_add_option
Feature: In a booking create
  As a teacher
  I need to add booking and event to a booking.

    Background:
        Given the following "users" exist:
            | username | firstname | lastname | email | idnumber |
            | teacher1 | Teacher | 1 | teacher1@example.com | T1 |
            | admin1   | Admin   | 1 | admin1@example.com   | A1 |
            | student1 | Student | 1 | student1@example.com | S1 |
            | student2 | Student | 2 | student2@example.com | S2 |
        And the following "courses" exist:
            | fullname | shortname | category | enablecompletion |
            | Course 1 | C1        | 0 | 1 |
        And the following "course enrolments" exist:
            | user | course | role |
            | teacher1 | C1 | editingteacher |
            | teacher1 | C1 | manager |
            | student1 | C1 | student |
            | student2 | C1 | student |
       And the following "activities" exist:
            | activity | course |  name  | intro  | bookingmanager | eventtype |
            | booking     | C1     | My booking | My booking description | teacher1 | Webinar |

    @javascript
    Scenario: Create booking
        Given I log in as "teacher1"
        And I am on "Course 1" course homepage with editing mode on
        And I add a "Booking" to section "1" and I fill the form with:
            | Booking name | Test booking |
            | Event type | Webinar |
            | Default view for booking options | All bookings |
        And I log out

    Scenario: Create Booking option and see it on activity page.
        Given I log in as "teacher1"
        When I am on "Course 1" course homepage
        And I follow "Test booking"
        And I follow "Actions menu"
        And I follow "Add a new booking option"
        And I set the following fields to these values:
           | Booking option name | Test booking - Webinar |
        Then I click on "Start and end time of course are known" "checkbox"
        Then I set the field "Add to calendar" to "Add to calendar (visible only to course participants)"
        And I set the following fields to these values:
            | coursestarttime[day] | 31 |
            | coursestarttime[month] | December |
            | coursestarttime[year] | 2021 |
            | coursestarttime[hour] | 09 |
            | coursestarttime[minute] | 00|
        And I set the following fields to these values:
            | courseendtime[day] | 31 |
            | courseendtime[month] | December |
            | courseendtime[year] | 2022 |
            | courseendtime[hour] | 09 |
            | courseendtime[minute] | 00 |
        Then I set the field "Add as template" to "Use as global template"
        And I press "Save and go back"
        Then I should see "My booking"
    
    @javascript
    Scenario: Add instances
        Given I log in as "teacher1"
        When I am on "Course 1" course homepage
        Then I follow "My booking"
        And I follow "Actions menu"
        And I follow "Add booking instance to template"
        And I set the following fields to these values:
            | Name | New instance |
        And I press "Save changes"
  
    @javascript
    Scenario: 
        Given I log in as "student1"
        When I am on "Course 1" course homepage
        And I follow "My booking"
    
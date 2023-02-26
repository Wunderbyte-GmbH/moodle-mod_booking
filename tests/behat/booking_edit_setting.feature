@mod @mod_booking @booking_edit_setting
Feature: In a booking edit settings
  As a teacher
  I need to add booking and event to a booking.

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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "New option" in "My booking"

  @javascript
  Scenario: Edit Settings
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "New booking option"
    And I follow "Settings"
    And I set the following fields to these values:
      | pollurl | https://example.com |
    Then I set the field "Send confirmation e-mail" to "Yes"
    And I set the field "" to ""
    And I should see "Booking confirmation"
    And I set the following fields to these values:
      | Booking confirmation          | {bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option) |
      | Max current bookings per user | 30                                                                                                    |
    And I press "Save and display"

  @javascript
  Scenario: Create two semester settings for booking options
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I follow "Booking: Semesters"
    ## And I press "Add semester"
    And I set the following fields to these values:
      | semesteridentifier[0]   | nextmay            |
      | semestername[0]         | Next May           |
      | semesterstart[0][day]   | 1                  |
      | semesterstart[0][month] | May                |
      | semesterstart[0][year]  | ## + 1 year ##%Y## |
      | semesterend[0][day]     | 31                 |
      | semesterend[0][month]   | May                |
      | semesterend[0][year]    | ## + 1 year ##%Y## |
    ## Need to overrider potential bug:
    And I set the field "semesterend[0][day]" to "31"
    And I press "Add semester"
    And I set the following fields to these values:
      | semesteridentifier[1]   | nextjune           |
      | semestername[1]         | Next June          |
      | semesterstart[1][day]   | 1                  |
      | semesterstart[1][month] | June               |
      | semesterstart[1][year]  | ## + 1 year ##%Y## |
      | semesterend[1][day]     | 30                 |
      | semesterend[1][month]   | June               |
      | semesterend[1][year]    | ## + 1 year ##%Y## |
    ## Need to overrider potential bug:
    And I set the field "semesterend[1][day]" to "30"
    And I press "Save changes"
    Then I should see "Semester 1"
    And the following fields match these values:
      | semesteridentifier[0]   | nextjune           |
      | semestername[0]         | Next June          |
      | semesterstart[0][day]   | 1                  |
      | semesterstart[0][month] | June               |
      | semesterstart[0][year]  | ## + 1 year ##%Y## |
      | semesterend[0][day]     | 30                 |
      | semesterend[0][month]   | June               |
      | semesterend[0][year]    | ## + 1 year ##%Y## |
    And I should see "Semester 1"
    And the following fields match these values:
      | semesteridentifier[1]   | nextmay            |
      | semestername[1]         | Next May           |
      | semesterstart[1][day]   | 1                  |
      | semesterstart[1][month] | May                |
      | semesterstart[1][year]  | ## + 1 year ##%Y## |
      | semesterend[1][day]     | 31                 |
      | semesterend[1][month]   | May                |
      | semesterend[1][year]    | ## + 1 year ##%Y## |
    And I log out
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I follow "New booking option"
    And I follow "Dates"
    And I should see "Next May (nextmay)" in the "#id_datesheadercontainer .form-autocomplete-selection" "css_element"
    And I expand the "Select time period" autocomplete
    ## And I open the autocomplete suggestions list in the "#id_datesheadercontainer" "css_element"
    And I wait "21" seconds
    And I should see "Next June (nextjune)" in the "#id_datesheadercontainer .form-autocomplete-suggestions" "css_element"
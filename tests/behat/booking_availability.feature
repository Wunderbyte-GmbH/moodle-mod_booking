@mod @mod_booking @booking_availability
Feature: In a booking
  As a teacher I configure various availability conditions
  For different booking options

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
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And I create booking option "Option - availability by dates" in "My booking"
    And I create booking option "Option - advanced availability" in "My booking"

  @javascript
  Scenario: Configure availability condition by dates
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I should see "Option - availability by dates" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Settings" "icon" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Edit this booking option" "link" in the "#allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking option is availably only after a certain date" to ""
    And I set the field "Booking option is available until a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingclosingtime[day]    | 10                 |
      | bookingclosingtime[month]  | May                |
      | bookingclosingtime[year]   | ## - 1 year ##%Y## |
    And I press "Save and go back"
    Then I should see "Cannot be booked anymore" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "May 10" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "## - 1 year ##%Y##" in the "#allbookingoptionstable_r1" "css_element"
    And I log out
    Given I log in as "student1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    Then I should see "Cannot be booked anymore" in the "#allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the "#allbookingoptionstable_r1" "css_element"
    And I log out
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I wait "1" seconds
    And I click on "Settings" "icon" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Edit this booking option" "link" in the "#allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking option is available until a certain date" to ""
    And I set the field "Booking option is availably only after a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingopeningtime[day]    | ##yesterday##%d## |
      | bookingopeningtime[month]  | ##yesterday##%B## |
      | bookingopeningtime[year]   | ##yesterday##%Y## |
    And I press "Save and go back"
    And I log out
    Given I log in as "student1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    Then I should see "Book now" in the "#allbookingoptionstable_r1" "css_element"
    And I log out
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I wait "1" seconds
    And I click on "Settings" "icon" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Edit this booking option" "link" in the "#allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking option is available until a certain date" to ""
    And I set the field "Booking option is availably only after a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingopeningtime[day]    | 10                 |
      | bookingopeningtime[month]  | March              |
      | bookingopeningtime[year]   | ## + 1 year ##%Y## |
    And I press "Save and go back"
    Then I should see "Can be booked from" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "March 10" in the "#allbookingoptionstable_r1" "css_element"
    And I should see "## + 1 year ##%Y##" in the "#allbookingoptionstable_r1" "css_element"
    And I log out
    Given I log in as "student1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    Then I should see "Cannot be booked yet" in the "#allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the "#allbookingoptionstable_r1" "css_element"

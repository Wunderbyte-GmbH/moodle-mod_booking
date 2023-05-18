@mod @mod_booking @booking_multisessions
Feature: In a booking create multi session options
  As a teacher
  I need to add booking options with multiple dates

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
    And I create booking option "New option - Webinar" in "My booking"

  @javascript
  Scenario: Create session with multiple dates
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I should see "New option"
    And I click on "Book now" "text" in the "#allbookingoptionstable_r1" "css_element"
    And I wait "5" seconds
    And I click on "Settings" "icon" in the "#allbookingoptionstable_r1" "css_element"
    And I click on "Duplicate this booking option" "link" in the "#allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name | Test option - Copy - Multisession |
    And I press "Save and go back"
    And I click on "Settings" "icon" in the "#allbookingoptionstable_r2" "css_element"
    And I click on "Duplicate this booking option" "link" in the "#allbookingoptionstable_r2" "css_element"
    And I set the following fields to these values:
      | Booking option name | Test option - Copy2 |
    And I press "Save and go back"
    And I click on "Settings" "icon" in the "#allbookingoptionstable_r3" "css_element"
    And I click on "Manage option dates" "link" in the "#allbookingoptionstable_r3" "css_element"
    And I set the following fields to these values:
      | coursestarttime[day]    | 15                    |
      | coursestarttime[month]  | March                 |
      | coursestarttime[year]   | ## + 1 year ##%Y##  |
      | coursestarttime[hour]   | 13                    |
      | coursestarttime[minute] | 00                    |
      | endhour                 | 20                    |
      | endminute               | 00                    |
    And I press "Save"
    And I set the following fields to these values:
      | coursestarttime[day]    | 20  |
      | coursestarttime[month]  | June |
      | coursestarttime[year]   | ## + 2 year ##%Y##  |
      | coursestarttime[hour]   | 14                    |
      | coursestarttime[minute] | 00                    |
      | endhour                 | 21                    |
      | endminute               | 00                    |
    And I press "Save"
    And I set the following fields to these values:
      | coursestarttime[day]    | 25  |
      | coursestarttime[month]  | September |
      | coursestarttime[year]   | ## + 3 year ##%Y##  |
      | coursestarttime[hour]   | 15                    |
      | coursestarttime[minute] | 00                    |
      | endhour                 | 22                    |
      | endminute               | 00                    |
    And I press "Save"
    Then I should see "15 March" in the "#region-main table.generaltable" "css_element"
    And I should see "## + 1 year ##%Y##" in the "#region-main table.generaltable" "css_element"
    And I should see "1:00 PM to 8:00 PM" in the "#region-main table.generaltable" "css_element"
    And I should see "20 June" in the "#region-main table.generaltable" "css_element"
    And I should see "## + 2 year ##%Y##" in the "#region-main table.generaltable" "css_element"
    And I should see "2:00 PM to 9:00 PM" in the "#region-main table.generaltable" "css_element"
    And I should see "25 September" in the "#region-main table.generaltable" "css_element"
    And I should see "## + 3 year ##%Y##" in the "#region-main table.generaltable" "css_element"
    And I should see "3:00 PM to 10:00 PM" in the "#region-main table.generaltable" "css_element"
    And I press "Back"
    Then I should see "Test option - Copy - Multisession" in the "#allbookingoptionstable_r3" "css_element"
    And I wait "5" seconds
    And I click on "Show dates" "link" in the "#allbookingoptionstable_r3" "css_element"
    And I wait "1" seconds
    Then I should see "15 March" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "## + 1 year ##%Y##" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "1:00 PM - 8:00 PM" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "20 June" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "## + 2 year ##%Y##" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "2:00 PM - 9:00 PM" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "25 September" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "## + 3 year ##%Y##" in the "#allbookingoptionstable_r3" "css_element"
    And I should see "3:00 PM - 10:00 PM" in the "#allbookingoptionstable_r3" "css_element"

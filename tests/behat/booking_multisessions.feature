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
    And I create booking option "New option - Multisession" in "My booking"

  @javascript
  Scenario: Boooking option: add multiple session dates by editing booking option
    Given I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I press "Add date"
    And I wait "1" seconds
    And I should see "## today ##%Y##" in the "#booking_optiondate_1" "css_element"
    And I should see "## today ##%B##" in the "#booking_optiondate_1" "css_element"
    And I should see "## today ##%d##" in the "#booking_optiondate_1" "css_element"
    ## Add 1st date
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 15                 |
      | coursestarttime_1[month]  | March              |
      | coursestarttime_1[year]   | ## + 1 year ##%Y## |
      | coursestarttime_1[hour]   | 13                 |
      | coursestarttime_1[minute] | 00                 |
      | courseendtime_1[day]      | 15                 |
      | courseendtime_1[month]    | March              |
      | courseendtime_1[year]     | ## + 1 year ##%Y## |
      | courseendtime_1[hour]     | 16                 |
      | courseendtime_1[minute]   | 00                 |
    And I press "applydate_1"
    ## Add 2nd date
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_2[day]    | 20                 |
      | coursestarttime_2[month]  | June               |
      | coursestarttime_2[year]   | ## + 2 year ##%Y## |
      | coursestarttime_2[hour]   | 14                 |
      | coursestarttime_2[minute] | 00                 |
      | courseendtime_2[day]      | 20                 |
      | courseendtime_2[month]    | June               |
      | courseendtime_2[year]     | ## + 2 year ##%Y## |
      | courseendtime_2[hour]     | 17                 |
      | courseendtime_2[minute]   | 00                 |
    And I press "applydate_2"
    ## Verify on booking oprion form page
    And I wait "1" seconds
    Then I should see "15 March" in the "#booking_optiondate_1" "css_element"
    And I should see "## + 1 year ##%Y##" in the "#booking_optiondate_1" "css_element"
    And I should see "1:00 PM - 4:00 PM" in the "#booking_optiondate_1" "css_element"
    And I should see "20 June" in the "#booking_optiondate_2" "css_element"
    And I should see "## + 2 year ##%Y##" in the "#booking_optiondate_2" "css_element"
    And I should see "2:00 PM - 5:00 PM" in the "#booking_optiondate_2" "css_element"
    And I press "Save and go back"
    ## Verify on booking oprions list page
    And I wait until the page is ready
    Then I should see "15 March" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "## + 1 year ##%Y##" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "1:00 PM - 4:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "20 June" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "## + 2 year ##%Y##" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "2:00 PM - 5:00 PM" in the ".allbookingoptionstable_r1" "css_element"

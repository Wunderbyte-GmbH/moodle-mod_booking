@mod @mod_booking @booking_add_and_book_option
Feature: In a booking instance create booking options
  As a teacher
  I need to add booking options and events to a booking instance

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
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
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   |
    And I change viewport size to "1366x10000"

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

  @javascript
  Scenario: Create booking option via DB than edit it and review changes as a teacher
    Given the following "mod_booking > options" exist:
      | booking    | text           | course | description   | limitanswers | maxanswers | teachersforoption | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | My booking | Option-created | C1     | Deskr-created | 0            | 0          | teacher1          | 0              | 0              | 2346937200        | 2347110000      |
      ## 2044/05/15 - 2044/05/17
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name         | Option-updated |
      | Description                 | Deskr-updated  |
      | Max. number of participants | 5 |
      | Assign teachers             | teacher2 |
    And I click on "15 May 2044" "text" in the "#booking_optiondate_1" "css_element"
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 20   |
      | coursestarttime_1[month]  | June |
      | coursestarttime_1[year]   | 2050 |
      | coursestarttime_1[hour]   | 00   |
      | coursestarttime_1[minute] | 00   |
    And I set the following fields to these values:
      | courseendtime_1[day]    | 25   |
      | courseendtime_1[month]  | July |
      | courseendtime_1[year]   | 2050 |
      | courseendtime_1[hour]   | 00   |
      | courseendtime_1[minute] | 00   |
    And I set the field "After saving..." to "Stay here"
    And I press "Save"
    And I wait "1" seconds
    And I click on "Show recent updates..." "button"
    And I should see "1 of 1 records found" in the "#showEventList" "css_element"
    And I should see "Title:" in the "#showEventList" "css_element"
    And I should see "Option-created" in the "#showEventList" "css_element"
    And I should see "Option-updated" in the "#showEventList" "css_element"
    And I should see "Dates:" in the "#showEventList" "css_element"
    And I should see "20 June 2050" in the "#showEventList" "css_element"
    And I should see "25 July 2050" in the "#showEventList" "css_element"
    And I should see "Description:" in the "#showEventList" "css_element"
    And I should see "Deskr-created" in the "#showEventList" "css_element"
    And I should see "Deskr-updated" in the "#showEventList" "css_element"
    And I should see "Limit for answers:" in the "#showEventList" "css_element"
    And I should see "Teachers:" in the "#showEventList" "css_element"
    And I should see "Teacher 1" in the "#showEventList" "css_element"
    And I should see "Teacher 2" in the "#showEventList" "css_element"

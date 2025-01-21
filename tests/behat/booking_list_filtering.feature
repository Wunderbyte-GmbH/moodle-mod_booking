@mod @mod_booking @booking_list_filtering
Feature: In a booking - create options and filter it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | teacher3 | Teacher   | 3        | teacher3@example.com | T3       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
      | student3 | Student   | 3        | student3@example.com | S3       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | teacher2 | C1     | manager        |
      | teacher3 | C1     | editingteacher |
      | teacher3 | C1     | manager        |
      | admin1   | C1     | editingteacher |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Booking option name  |
      | booking  | C1     | My booking | My booking description | admin1         | Webinar   | All bookings                     | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text              | course | description  | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - Teacher1 | C1     | Option deskr | teacher1          | 0              | 0              | 2346937200        | 2347110000      |
      | My booking | Option - Teacher2 | C1     | Option deskr | teacher2          | 0              | 0              | 2347110000        | 2347282800      |
      | My booking | Option - Teacher3 | C1     | Option deskr | teacher3,teacher1 | 0              | 0              | 2347369200        | 2347542000      |
    ## 2044/05/15 - 2044/05/17
    ## 2044/05/17 - 2044/05/19
    ## 2044/05/20 - 2044/05/22
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option list: filtering by teachers and by dates
    Given I am on the "My booking" Activity page logged in as teacher1
    ## And I press "Filter table"
    And I click on "Filter table" "button" in the ".allbookingoptionstable.wunderbyte_table_filter_on" "css_element"
    ## Filtering by teacher assigned to a single option
    And I click on "Teachers" "button"
    And I should see "2, Teacher" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element"
    And I set the field "2, Teacher" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element" to "checked"
    And I should see "Teacher 2" in the ".allbookingoptionstable_r1" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable_r2')]" "xpath_element" should not exist
    And I set the field "2, Teacher" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element" to ""
    And I should see "Teacher 3" in the ".allbookingoptionstable_r3" "css_element"
    ## Filtering by teacher assigned to the pair of options
    And I set the field "1, Teacher" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element" to "checked"
    And I should see "Teacher 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Teacher 1" in the ".allbookingoptionstable_r2" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable_r3')]" "xpath_element" should not exist
    And I set the field "1, Teacher" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element" to ""
    And I should see "Teacher 3" in the ".allbookingoptionstable_r3" "css_element"
    ## Hide filter - required for a new filter tool
    ## Workaround for case when hidden "search" "input" intercepts focus - so we cannot press "Teachers" "button"
    And I click on "//aside[contains(@class, 'wunderbyte_table_components')]" "xpath_element"
    ## Filtering by timespan
    And I click on "Course time" "button"
    ## TODO: actual dates has been set as -1 day for some reason (same as in wb_table).
    And I set the following fields to these values:
      | date-coursestarttime | 2044-05-17 |
      | date-courseendtime   | 2044-05-21 |
      | Display records      | within     |
      | coursestarttime      | 1          |
    And I should see "Option - Teacher2" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Display records" to "overlapping beginning"
    And I should see "Option - Teacher1" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Display records" to "after"
    And I should see "Option - Teacher3" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Display records" to "overlapping"
    And I should see "Option - Teacher1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Option - Teacher2" in the ".allbookingoptionstable_r2" "css_element"
    ## Hide filter - required for a new filter tool
    ## Workaround for case when hidden "search" "input" intercepts focus - so we cannot press "Teachers" "button"
    And I click on "//aside[contains(@class, 'wunderbyte_table_components')]" "xpath_element"

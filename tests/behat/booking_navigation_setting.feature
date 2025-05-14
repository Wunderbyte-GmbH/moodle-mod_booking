@mod @mod_booking @booking_navigation_setting
Feature: Configure and use booking's pagination and perform filtering - as a teacher.

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
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "Booking Option 1" in "My booking"
    And I create booking option "Booking Option 2" in "My booking"
    And I create booking option "Booking Option 3" in "My booking"
    And I create booking option "Booking Option 4" in "My booking"
    And I create booking option "Booking Option 5" in "My booking"
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Configure pagination and navigate pages with list of booking options
    Given I am on the "My booking" Activity page logged in as teacher1
    And I should see "Booking Option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking Option 5" in the ".allbookingoptionstable_r5" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[@class='pagination']" "xpath_element" should not exist
    When I follow "Settings"
    And I follow "Advanced options"
    And I wait "1" seconds
    And I set the field "paginationnum" to "3"
    And I press "Save and display"
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[@class='pagination']" "xpath_element" should exist
    Then I should see "1" in the ".allbookingoptionstable .pagination" "css_element"
    And I should see "2" in the ".allbookingoptionstable .pagination" "css_element"
    And I should see "Booking Option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking Option 3" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Booking Option 4" in the ".allbookingoptionstable" "css_element"
    And I should not see "Booking Option 5" in the ".allbookingoptionstable" "css_element"
    ## Goto page 2
    And I click on "2" "text" in the ".allbookingoptionstable .pagination" "css_element"
    And I should see "Booking Option 4" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking Option 5" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Filter of list of booking options including if pagination
    Given I am on the "My booking" Activity page logged in as teacher1
    And I should see "Booking Option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking Option 5" in the ".allbookingoptionstable_r5" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[@class='pagination']" "xpath_element" should not exist
    ## Set filter without pagination
    And I set the field "Search" in the ".allbookingoptionstable" "css_element" to "Option 4"
    And I wait "1" seconds
    And I should see "Booking Option 4" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "1 of 5 records found" in the ".allbookingoptionstable .wb-records-count-label" "css_element"
    And I set the field "Search" in the ".allbookingoptionstable" "css_element" to ""
    ## Set pagination witout filter
    When I follow "Settings"
    And I follow "Advanced options"
    And I wait "1" seconds
    And I set the field "paginationnum" to "3"
    And I press "Save and display"
    And I wait until the page is ready
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[@class='pagination']" "xpath_element" should exist
    Then I should see "1" in the ".allbookingoptionstable .pagination" "css_element"
    And I should see "2" in the ".allbookingoptionstable .pagination" "css_element"
    And I should see "Booking Option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking Option 3" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Booking Option 4" in the ".allbookingoptionstable" "css_element"
    And I should not see "Booking Option 5" in the ".allbookingoptionstable" "css_element"
    ## Set search filter together with pagination
    And I set the field "Search" in the ".allbookingoptionstable" "css_element" to "Option 4"
    And I should see "Booking Option 4" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "1 of 5 records found" in the ".allbookingoptionstable .wb-records-count-label" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[@class='pagination']" "xpath_element" should not exist

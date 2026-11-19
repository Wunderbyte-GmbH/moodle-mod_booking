@mod @mod_booking @booking_instance_searchpriority
Feature: As admin - configure the priority of the search results of a booking instance and validate the order of the results.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name     | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | Booking0 | Booking description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking  | titleprefix | text          | course | description | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking0 | 424         | Pilates       | C1     | first       | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking0 | A3          | 424           | C1     | second      | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking0 | A4          | Kurs 424 Yoga | C1     | third       | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  Scenario: Booking: a new booking instance presets the priority, an existing booking instance has none
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    ## Existing booking instances are not changed.
    Then the field "Priority of the search results" matches value ""
    And I add a "booking" activity to course "Course 1" section "1"
    And the field "Priority of the search results" matches value "titleprefix,text"

  @javascript
  Scenario: Booking: order the priority columns of a booking instance and search as student
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    And I should see "No priority: the search results keep the sorting of the table."
    ## Teachers can be chosen, the unique identifier cannot.
    And the "Choose column..." select box should contain "Teachers"
    And the "Choose column..." select box should not contain "Unique identifier"
    ## Add the columns in the wrong order and move the prefix up.
    And I set the field "Choose column..." to "Booking option name"
    And I press "Add column"
    And I set the field "Choose column..." to "Prefix"
    And I press "Add column"
    And "Booking option name" "text" should appear before "Prefix" "text" in the "[data-region='searchpriority-list']" "css_element"
    When I click on "Move up: Prefix" "button"
    Then "Prefix" "text" should appear before "Booking option name" "text" in the "[data-region='searchpriority-list']" "css_element"
    And I press "Save and display"
    ## The order is kept.
    And I am on the "Booking0" "booking activity editing" page
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    And "Prefix" "text" should appear before "Booking option name" "text" in the "[data-region='searchpriority-list']" "css_element"
    And I log out
    ## Prefix equals the search text, then name equals, then name contains.
    And I am on the "Booking0" Activity page logged in as student1
    And I set the field "Search" in the ".allbookingoptionstable" "css_element" to "424"
    And I wait "1" seconds
    And I should see "Pilates" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "A3" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Kurs 424 Yoga" in the ".allbookingoptionstable_r3" "css_element"
    And I log out
    ## Remove the priority again: the results keep the sorting by name.
    And I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    And I click on "Remove: Prefix" "button"
    And I click on "Remove: Booking option name" "button"
    And I should see "No priority: the search results keep the sorting of the table."
    And I press "Save and display"
    And I log out
    And I am on the "Booking0" Activity page logged in as student1
    And I set the field "Search" in the ".allbookingoptionstable" "css_element" to "424"
    And I wait "1" seconds
    And I should see "A3" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Kurs 424 Yoga" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Pilates" in the ".allbookingoptionstable_r3" "css_element"

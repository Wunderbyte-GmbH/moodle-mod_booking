@mod @mod_booking @booking_teachers_order
Feature: As admin - set the order of the teachers of a booking option and see the teachers in this order.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Carl      | Adler    | teacher1@example.com | T1       |
      | teacher2 | Bea       | Maier    | teacher2@example.com | T2       |
      | teacher3 | Anna      | Zimmer   | teacher3@example.com | T3       |
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
      | booking  | text            | course | description | teachersforoption          | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking0 | Option teachers | C1     | Deskr       | teacher1,teacher2,teacher3 | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking0 | Option single   | C1     | Deskr       | teacher1                   | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: order the teachers in the option form and see them in this order as student
    Given I am on the "Booking0" Activity page logged in as admin
    ## Without an order the teachers are sorted by name.
    And "Adler" "text" should appear before "Maier" "text" in the ".allbookingoptionstable_r2" "css_element"
    And "Maier" "text" should appear before "Zimmer" "text" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    ## The saved teachers are listed in their order. They can only be ordered: nothing to add, nothing to remove.
    And "Carl Adler" "text" should appear before "Bea Maier" "text" in the "[data-region='sortableselect-list']" "css_element"
    And "Bea Maier" "text" should appear before "Anna Zimmer" "text" in the "[data-region='sortableselect-list']" "css_element"
    And "Remove: Anna Zimmer" "button" should not exist
    And "[data-action='sortableselect-add']" "css_element" should not exist
    When I click on "Move up: Anna Zimmer" "button"
    And I click on "Move up: Anna Zimmer" "button"
    Then "Anna Zimmer" "text" should appear before "Carl Adler" "text" in the "[data-region='sortableselect-list']" "css_element"
    And "Carl Adler" "text" should appear before "Bea Maier" "text" in the "[data-region='sortableselect-list']" "css_element"
    And I press "Save"
    And I wait until the page is ready
    ## The list of booking options shows the new order.
    And "Zimmer" "text" should appear before "Adler" "text" in the ".allbookingoptionstable_r2" "css_element"
    And "Adler" "text" should appear before "Maier" "text" in the ".allbookingoptionstable_r2" "css_element"
    ## The form keeps the order.
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    And "Anna Zimmer" "text" should appear before "Carl Adler" "text" in the "[data-region='sortableselect-list']" "css_element"
    And "Carl Adler" "text" should appear before "Bea Maier" "text" in the "[data-region='sortableselect-list']" "css_element"
    And I log out
    ## Students see the teachers in this order, too.
    And I am on the "Booking0" Activity page logged in as student1
    And "Zimmer" "text" should appear before "Adler" "text" in the ".allbookingoptionstable_r2" "css_element"
    And "Adler" "text" should appear before "Maier" "text" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Booking option: with less than two saved teachers the option form shows a hint instead of the order
    Given I am on the "Booking0" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    Then I should see "As soon as at least two teachers are saved in this booking option, you can set their order here."
    And "[data-region='sortableselect-list']" "css_element" should not exist

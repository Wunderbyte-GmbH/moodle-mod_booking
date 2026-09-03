@mod @mod_booking @booking_entitytreefilter
Feature: Multilevel location filter and location hover card in the booking options table
  As an admin I want the location filter to offer the entity tree or only its top level
  and the hover card for deep location hierarchies to be switchable

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
    And I clean wbtable cache
    ## The entities must exist before the options referencing them by name.
    And the following "local_entities > entities" exist:
      | name     | shortname | parent   |
      | Location | loc       |          |
      | Building | bld       | Location |
      | Floor    | flr       | Building |
      | Other    | oth       |          |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking    | text         | course | description | entity |
      | My booking | Option Floor | C1     | Deep entity | Floor  |
      | My booking | Option Other | C1     | Root entity | Other  |
    And the following config values are set as admin:
      | config           | value | plugin  |
      | entitytreefilter | 1     | booking |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Location tree filter offers all levels and the location hover card is shown by default
    Given I am on the "My booking" Activity page logged in as student1
    ## The deep location renders as "direct parent (entity)" with the ancestors in the hover card.
    Then I should see "Building (Floor)" in the ".allbookingoptionstable" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//span[contains(@class, 'mod-booking-location-path')]//a[contains(., 'Location')]" "xpath_element" should exist
    And "//div[contains(@class, 'allbookingoptionstable')]//span[contains(@class, 'mod-booking-location-path')]//a[contains(., 'Building')]" "xpath_element" should exist
    When I click on "Filter table" "button" in the ".allbookingoptionstable.wunderbyte_table_filter_on" "css_element"
    And I click on "Location" "button" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element"
    ## All levels of the occupied branch are offered in the tree, nested below their parents.
    Then I should see "Building" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And I should see "Floor" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And I should see "Other" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[contains(@class, 'wbt-treechildren')]" "xpath_element" should exist
    ## Selecting the top node filters the whole branch. The checkbox is addressed via its data-key
    ## (plain label matching would hit the filter search input "Search in filter Location" instead)
    ## and clicked for real, so the change event fires and triggers the table reload.
    And I click on "//div[contains(@class, 'allbookingoptionstable')]//input[contains(@class, 'wbt-treenode-checkbox') and @data-key='Location']" "xpath_element"
    And I wait "1" seconds
    And I should see "Option Floor" in the ".allbookingoptionstable_r1" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable_r2')]" "xpath_element" should not exist

  @javascript
  Scenario: Location tree filter offers only the top level when the top level only setting is on
    Given the following config values are set as admin:
      | config                       | value | plugin  |
      | entitytreefiltertoplevelonly | 1     | booking |
    And I am on the "My booking" Activity page logged in as student1
    When I click on "Filter table" "button" in the ".allbookingoptionstable.wunderbyte_table_filter_on" "css_element"
    And I click on "Location" "button" in the ".allbookingoptionstable .wunderbyteTableFilter" "css_element"
    ## Only the first level is offered, without any children.
    Then I should see "Location" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And I should see "Other" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And I should not see "Building" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And I should not see "Floor" in the ".allbookingoptionstable .treehierarchy" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//ul[contains(@class, 'wbt-treechildren')]" "xpath_element" should not exist
    ## Selecting the top level entry still filters the whole branch. The checkbox is addressed via
    ## its data-key (plain label matching would hit the filter search input instead) and clicked
    ## for real, so the change event fires and triggers the table reload.
    And I click on "//div[contains(@class, 'allbookingoptionstable')]//input[contains(@class, 'wbt-treenode-checkbox') and @data-key='Location']" "xpath_element"
    And I wait "1" seconds
    And I should see "Option Floor" in the ".allbookingoptionstable_r1" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable_r2')]" "xpath_element" should not exist

  @javascript
  Scenario: Location hover card can be turned off globally
    Given the following config values are set as admin:
      | config                                | value | plugin  |
      | entitytreefiltershowlocationhovercard | 0     | booking |
    And I am on the "My booking" Activity page logged in as student1
    ## The deep location still renders as "direct parent (entity)", but as a plain link without a card.
    Then I should see "Building (Floor)" in the ".allbookingoptionstable" "css_element"
    And "//div[contains(@class, 'allbookingoptionstable')]//span[contains(@class, 'mod-booking-location-path')]" "xpath_element" should not exist
    And "//div[contains(@class, 'allbookingoptionstable')]//span[contains(@class, 'mod-booking-location-cell')]" "xpath_element" should not exist

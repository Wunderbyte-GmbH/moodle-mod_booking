@mod @mod_booking @booking_multisessions @booking_duplicate_option
Feature: In a booking create booking option with multiple custom options
  As an admin
  I need to duplicate booking option with multiple custom options

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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "custom field categories" exist:
      | name     | component   | area    | itemid |
      | SportArt | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name   | category | type | shortname | configdata[defaultvalue] |
      | Sport1 | SportArt | text | spt1      | defsport1                |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier    | name          | defaultvalue | disabled | pricecatsortorder |
      | 1        | default       | Base Price    | 70           | 0        | 1                 |
      | 2        | specialprice  | Special Price | 60           | 0        | 2                 |
    And I create booking option "New option - duplication source" in "My booking"

  @javascript
  Scenario: Simple duplication of booking option
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name | Test option - Copy1 |
    And I press "Save"
    ##And I wait until the page is ready
    Then I should see "Test option - Copy1" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Duplicate booking option with multiple customized settings
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the following fields to these values:
      | Prefix                                | MIB                   |
      | Booking option name                   | Topic: Statistics     |
      | Description                           | Class om Statistics   |
      | Internal annotation                   | Statistics for medics |
      | Max. number of participants           | 10                    |
      | Max. number of places on waiting list | 5                     |
      | Min. number of participants           | 3                     |
      | Teachers poll url                     | https://google.com    |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | ##tomorrow##%d##   |
      | coursestarttime_1[month]  | ##tomorrow##%B##   |
      | coursestarttime_1[year]   | ##tomorrow##%Y##   |
      | coursestarttime_1[hour]   | 09                 |
      | coursestarttime_1[minute] | 00                 |
      | courseendtime_1[day]      | ##tomorrow##%d##   |
      | courseendtime_1[month]    | ##tomorrow##%B##   |
      | courseendtime_1[year]     | ## + 1 year ##%Y## |
      | courseendtime_1[hour]     | 18                 |
      | courseendtime_1[minute]   | 00                 |
      | daystonotify_1 | 1 |
    And I set the field "Add to course calendar" to "Add to calendar (visible only to course participants)"
    And I set the field "Assign teachers:" to "Teacher 1"
    And I wait "1" seconds
    And I set the field "Only book with price" to "checked"
    And I set the following fields to these values:
      | pricegroup_default[bookingprice_default]           | 75                            |
      | pricegroup_specialprice[bookingprice_specialprice] | 65                            |
      | customfield_spt1                                   | tenis                         |
      | Notification message                               | Advanced notification message |
      | Before booked                                      | Before booked message         |
      | After booked                                       | After booked message          |
    And I press "Save"
    ## And I wait until the page is ready - does not work, force timeout
    And I wait "1" seconds
    ## Create a copy
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field "Booking option name" to "Topic: Statistics - Copy 1"
    And I press "Save"
    ## And I wait until the page is ready - does not work, force timeout
    And I wait "1" seconds
    ## Verify copy and its options
    Then I should see "Topic: Statistics - Copy 1" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    And I should see "Teacher 1" in the "//div[contains(@id, 'id_bookingoptionteachers_')]//span[contains(@class, 'user-suggestion')]" "xpath_element"
    And the following fields match these values:
      | Prefix                                | MIB                           |
      | Booking option name                   | Topic: Statistics - Copy 1    |
      | Description                           | Class om Statistics           |
      | Internal annotation                   | Statistics for medics         |
      | Max. number of participants           | 10                            |
      | Max. number of places on waiting list | 5                             |
      | Min. number of participants           | 3                             |
      | Teachers poll url                     | https://google.com            |
      | coursestarttime_1[day]                | ##tomorrow##%d##              |
      | coursestarttime_1[month]              | ##tomorrow##%B##              |
      | coursestarttime_1[year]               | ##tomorrow##%Y##              |
      | coursestarttime_1[hour]               | 09                            |
      | coursestarttime_1[minute]             | 00                            |
      | courseendtime_1[day]                  | ##tomorrow##%d##              |
      | courseendtime_1[month]                | ##tomorrow##%B##              |
      | courseendtime_1[year]                 | ## + 1 year ## %Y ##          |
      | courseendtime_1[hour]                 | 18                            |
      | courseendtime_1[minute]               | 00                            |
      | daystonotify_1                        | 1                             |
      | pricegroup_default[bookingprice_default]           | 75               |
      | pricegroup_specialprice[bookingprice_specialprice] | 65               |
      | customfield_spt1                      | tenis                         |
      | Notification message                  | Advanced notification message |
      | Before booked                         | Before booked message         |
      | After booked                          | After booked message          |

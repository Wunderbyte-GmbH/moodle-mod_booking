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
    And I press "Save and go back"
    ##And I wait until the page is ready
    Then I should see "Test option - Copy1" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Duplicate booking option with multiple customized settings
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Prefix              | MIB                   |
      | Booking option name | Topic: Statistics     |
      | Description         | Class om Statistics   |
      | Internal annotation | Statistics for medics |
      | Location            | MI Departmant         |
      | Institution         | Morphology Institute  |
      | Address             | Ternopil              |
    And I set the field "Limit the number of participants" to "checked"
    And I set the following fields to these values:
      | Max. number of participants           | 10    |
      | Max. number of places on waiting list | 5     |
      | Min. number of participants           | 3     |
      | duration[number]                      | 2     |
      | duration[timeunit]                    | hours |
    And I click on "Start and end time of course are known" "checkbox"
    And I set the following fields to these values:
      | coursestarttime[day]    | ##tomorrow##%d##     |
      | coursestarttime[month]  | ##tomorrow##%B##     |
      | coursestarttime[year]   | ##tomorrow##%Y##     |
      | coursestarttime[hour]   | 09                   |
      | coursestarttime[minute] | 00                   |
      | courseendtime[day]      | ##tomorrow##%d##     |
      | courseendtime[month]    | ##tomorrow##%B##     |
      | courseendtime[year]     | ## + 1 year ## %Y ## |
      | courseendtime[hour]     | 18                   |
      | courseendtime[minute]   | 00                   |
      | Teachers poll url       | https://google.com   |
      | reoccurringdatestring   | FR, 13:30 - 14:30    |
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
    And I press "Save and go back"
    And I wait until the page is ready
    ## Create a copy
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Booking option name" to "Topic: Statistics - Copy 1"
    And I press "Save and go back"
    And I wait until the page is ready
    ## Verify copy and its options
    Then I should see "Topic: Statistics - Copy 1" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And the following fields match these values:
      | Prefix              | MIB                        |
      | Booking option name | Topic: Statistics - Copy 1 |
      | Description         | Class om Statistics        |
      | Internal annotation | Statistics for medics      |
      | Address             | Ternopil                   |
    And I should see "MI Departmant" in the "#fitem_id_location" "css_element"
    And I should see "Morphology Institute" in the "#fitem_id_institution" "css_element"
    And I should see "Teacher 1" in the "#id_bookingoptionteacherscontainer" "css_element"
    And the field "Limit the number of participants" matches value "checked"
    And the field "Start and end time of course are known" matches value "checked"
    And the following fields match these values:
      | Max. number of participants           | 10                            |
      | Max. number of places on waiting list | 5                             |
      | Min. number of participants           | 3                             |
      | duration[number]                      | 2                             |
      | duration[timeunit]                    | hours                         |
      | coursestarttime[day]                  | ##tomorrow##%d##              |
      | coursestarttime[month]                | ##tomorrow##%B##              |
      | coursestarttime[year]                 | ##tomorrow##%Y##              |
      | coursestarttime[hour]                 | 09                            |
      | coursestarttime[minute]               | 00                            |
      | courseendtime[day]                    | ##tomorrow##%d##              |
      | courseendtime[month]                  | ##tomorrow##%B##              |
      | courseendtime[year]                   | ## + 1 year ## %Y ##          |
      | courseendtime[hour]                   | 18                            |
      | courseendtime[minute]                 | 00                            |
      | Teachers poll url                     | https://google.com            |
      | reoccurringdatestring                 | FR, 13:30 - 14:30             |
      | pricegroup_default[bookingprice_default]           | 75               |
      | pricegroup_specialprice[bookingprice_specialprice] | 65               |
      | customfield_spt1                      | tenis                         |
      | Notification message                  | Advanced notification message |
      | Before booked                         | Before booked message         |
      | After booked                          | After booked message          |

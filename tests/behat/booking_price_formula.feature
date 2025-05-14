@mod @mod_booking @booking_price_formula
Feature: As a teacher - configure and use booking's price formula feature.

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
    And the following "mod_booking > semesters" exist:
      | identifier | name     | startdate                        | enddate                         |
      | nextmay    | Next May | ## first day of May next year ## | ## last day of May next year ## |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name       | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Base Price | 70.1         | 0        | 1                 |
      | 2        | special    | Spec Price | 80.1         | 0        | 1                 |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking    | text                                         | course | description  | datesmarker | semester | dayofweek | dayofweektime    | dayofweekstarttime | dayofweekendtime |
      | My booking | Price formula option - Dates In timeslot     | C1     | Option deskr | 1           | nextmay  | Mon       | Mon, 18:00-20:00 | 1800               | 2000             |
      | My booking | Price formula option - Dates NOT in timeslot | C1     | Option deskr | 1           | nextmay  | Tue       | Tue, 9:00-11:00  | 900                | 1100             |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking price formula - option dates not in timeslot of the price formula
    Given the following config values are set as admin:
       | config              | value        | plugin  |
       | defaultpriceformula | [{"timeslot":[{"starttime":"17:00","endtime":"23:00","weekdays":"Mon,Fri","multiplier":"0.5"}]}] | booking |
    When I am on the "My booking" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Dates"
    And I press "Create date series"
    And I wait "1" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "2"
    And I set the field "Absolute value" to "5"
    And I press "Save"
    Then I should see "285.00 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - option dates are in timeslot of the price formula
    Given the following config values are set as admin:
       | config              | value        | plugin  |
       | defaultpriceformula | [{"timeslot":[{"starttime":"17:00","endtime":"23:00","weekdays":"Mon,Fri","multiplier":"0.5"}]}] | booking |
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I press "Create date series"
    And I wait "1" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "2"
    And I set the field "Absolute value" to "5"
    And I press "Save"
    Then I should see "145.00 EUR" in the ".allbookingoptionstable_r1 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - no unit factor and option dates not in timeslot of the price formula
    Given I log in as "admin"
    And I set the following administration settings values:
      | Apply unit factor | |
      | Round prices (price formula) | 1 |
      | Price formula | [{"timeslot":[{"starttime":"17:00","endtime":"23:00","weekdays":"Mon,Fri","multiplier":"0.5"}]}] |
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Dates"
    And I press "Create date series"
    And I wait "1" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "3"
    And I set the field "Absolute value" to "5"
    And I press "Save"
    Then I should see "215.00 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - no price rounding and option dates not in timeslot of the price formula
    Given I log in as "admin"
    And I set the following administration settings values:
      | Apply unit factor | 1 |
      | Round prices (price formula) | |
      | Price formula | [{"timeslot":[{"starttime":"17:00","endtime":"23:00","weekdays":"Mon,Fri","multiplier":"0.5"}]}] |
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Dates"
    And I press "Create date series"
    And I wait "1" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "3"
    And I set the field "Absolute value" to "5"
    And I press "Save"
    And I wait until the page is ready
    Then I should see "425.60 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - empty price formula not being applied
    Given I log in as "admin"
    And I set the following administration settings values:
      | Price formula | |
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    Then the following fields match these values:
    ##  | pricegroup_default[bookingprice_default] | 70.1 |
      | bookingprice_default | 70.1 |
      | bookingprice_spec | 80.1 |
    And I should not see "On saving, calculate prices with price formula" in the "#editoptionsformcontainer" "css_element"

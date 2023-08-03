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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > semesters" exist:
      | identifier | name     | startdate                        | enddate                         |
      | nextmay    | Next May | ## first day of May next year ## | ## last day of May next year ## |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name       | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Base Price | 70.1         | 0        | 1                 |
    And I create booking option "Price formula option - Dates In timeslot" in "My booking"
    And I create booking option "Price formula option - Dates NOT in timeslot" in "My booking"
    And I create booking option "Price formula option - No unit factor" in "My booking"
    And I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I set the following fields to these values:
      | Price formula | [{"timeslot":[{"starttime":"17:00","endtime":"23:00","weekdays":"Mon,Fri","multiplier":"0.5"}]}] |
    And I press "Save changes"
    And I log out

  @javascript
  Scenario: Booking price formula - option dates not in timeslot of the price formula
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "Price formula option - Dates NOT in timeslot" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Dates"
    And I wait "1" seconds
    And I set the field "Select time period" to "Next May (nextmay)"
    And I set the field "reoccurringdatestring" to "Tue, 9:00-11:00"
    And I press "Create date series"
    And I wait "2" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "2"
    And I set the field "Absolute value" to "5"
    And I press "Save and go back"
    And I should see "285.00 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - option dates are in timeslot of the price formula
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "Price formula option - Dates In timeslot" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I wait "1" seconds
    And I set the field "Select time period" to "Next May (nextmay)"
    And I set the field "reoccurringdatestring" to "Mon, 18:00-20:00"
    And I press "Create date series"
    And I wait "2" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "2"
    And I set the field "Absolute value" to "5"
    And I press "Save and go back"
    And I should see "145.00 EUR" in the ".allbookingoptionstable_r1 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - no unit factor and option dates not in timeslot of the price formula
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I set the field "Apply unit factor" to ""
    And I set the field "Round prices (price formula)" to "checked"
    And I press "Save changes"
    And I log out
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "Price formula option - No unit factor" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Dates"
    And I wait "1" seconds
    And I set the field "Select time period" to "Next May (nextmay)"
    And I set the field "reoccurringdatestring" to "Tue, 9:00-11:00"
    And I press "Create date series"
    And I wait "2" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "3"
    And I set the field "Absolute value" to "5"
    And I press "Save and go back"
    And I should see "215.00 EUR" in the ".allbookingoptionstable_r3 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - no price rounding and option dates not in timeslot of the price formula
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I set the field "Apply unit factor" to "checked"
    And I set the field "Round prices (price formula)" to ""
    And I press "Save changes"
    And I log out
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "Price formula option - No unit factor" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Dates"
    And I wait "1" seconds
    And I set the field "Select time period" to "Next May (nextmay)"
    And I set the field "reoccurringdatestring" to "Tue, 9:00-11:00"
    And I press "Create date series"
    And I wait "2" seconds
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And I set the field "On saving, calculate prices with price formula" to "checked"
    And I set the field "Manual factor" to "3"
    And I set the field "Absolute value" to "5"
    And I press "Save and go back"
    And I wait "20" seconds
    And I should see "425.60 EUR" in the ".allbookingoptionstable_r3 .pricecurrency" "css_element"

  @javascript
  Scenario: Booking price formula - empty price formula not being applied
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I set the field "Price formula" to ""
    And I press "Save changes"
    And I log out
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "Price formula option - No unit factor" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    And the following fields match these values:
      | pricegroup_default[bookingprice_default] | 70.1 |
    And I should not see "On saving, calculate prices with price formula" in the "#id_bookingoptionpricecontainer" "css_element"

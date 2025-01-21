@mod @mod_booking @booking_prices
Feature: As an admin - configure booking's prices feature and validate it as students.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 99           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 89           | 0        | 2                 |
      | 3        | zeroprice  | Zero  | 0            | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       | discount1                  |
      | student2 | Student   | 2        | student2@example.com | S2       | zeroprice                  |
      | student3 | Student   | 3        | student3@example.com | S3       |                            |
    And I clean booking cache
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking     | text         | course | description | importing | useprice | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP  | Option-price | C1     | Price       | 1         | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking prices: setup zero price and verify it as students
    Given the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
      | displayemptyprice  |              | booking |
    When I am on the "BookingCMP" Activity page logged in as student3
    And I should see "99.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    And I am on the "BookingCMP" Activity page logged in as student1
    And I should see "89.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    Then I am on the "BookingCMP" Activity page logged in as student2
    And I should not see "0.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out

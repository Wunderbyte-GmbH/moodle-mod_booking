@mod @mod_booking @booking_installments
Feature: Enabling installments as admin configuring installments as a teacher and booking it as a student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > user credits" exist:
      | user     | credit | currency |
      | student1 | 100    | EUR      |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  |
      | Account1 |
    And the following "activities" exist:
      | activity | course | name        | intro                | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingInst | Booking Installments | teacher1       | Webinar   | All bookings                     | Yes                      |
    ## Default - enable installments by admin.
    ##And I log in as "admin"
    And the following config values are set as admin:
      | config              | value | plugin              |
      | enableinstallments  | 1     | local_shopping_cart |
      | timebetweenpayments | 2     | local_shopping_cart |
      | reminderdaysbefore  | 1     | local_shopping_cart |
    And I change viewport size to "1366x10000"
    ##And I log out

  @javascript
  Scenario: Add an installmetn for a booking option as a teacher and verify it
    Given the following "mod_booking > options" exist:
      | booking     | text               | course | description | importing | useprice | limitanswers | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingInst | Option-installment | C1     | Deskr2      | 1         | 1        | 1            | 4          | 0              | 0              | ## +5 days ##     | ## +8 days ##   |
    And I am on the "BookingInst" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Shopping Cart"
    And I set the field "Allow installments" to "1"
    And I wait "1" seconds
    ## Intentional error and validation of it
    And I set the following fields to these values:
      | Down payment                           | 44 |
      | Number of Payments                     | 2  |
      | Due nr. of days after initial purchase | 10 |
      | Due nr. of days before coursestart     | 1  |
    And I press "Save"
    And I should see "Only one of these values can be more than 0"
    And I set the following fields to these values:
      | Due nr. of days after initial purchase | 0 |
    And I press "Save"
    And I wait "1" seconds
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And the field "Down payment" matches value "44"
    And the field "Number of Payments" matches value "2"
    And the field "Due nr. of days after initial purchase" matches value "0"
    And the field "Due nr. of days before coursestart" matches value "1"
    ## Above is little bit faster than the following
    ##And the following fields match these values:
    ##  | Down payment                           | 44 |
    ##  | Number of Payments                     | 2  |
    ##  | Due nr. of days after initial purchase | 0  |
    ##  | Due nr. of days before coursestart     | 1  |
    And I log out

  @javascript
  Scenario: Add an installment for a booking option via DB and brought it as student
    Given the following "mod_booking > options" exist:
      | booking     | text               | course | description | importing | useprice | sch_allowinstallment | sch_downpayment | sch_numberofpayments | sch_duedaysbeforecoursestart | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingInst | Option-installment | C1     | Deskr2      | 1         | 1        | 1                    | 44              | 2                    | 1                            | 0              | 0              | ## +6 days ##     | ## +8 days ##   |
    And I am on the "BookingInst" Activity page logged in as student1
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I visit "/local/shopping_cart/checkout.php"
    And I wait "1" seconds
    And I set the field "Use installment payments" to "checked"
    And I wait "1" seconds
    And I should see "Down payment for Option-installment"
    And I should see "44 EUR instead of 88 EUR"
    And I should see "Further payments"
    And I should see "2" occurrences of "22 EUR on" in the ".sc_installments .furtherpayments" "css_element"
    When I press "Checkout"
    And I wait "1" seconds
    And I press "Confirm"
    Then I should see "Payment successful!"
    And I should see "Option-installment" in the ".payment-success ul.list-group" "css_element"
    And I should see "44.00 EUR" in the ".payment-success ul.list-group" "css_element"

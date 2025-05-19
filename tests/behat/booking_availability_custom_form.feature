@mod @mod_booking @booking_availability_custom_form
Feature: Create custom availability form for booking options as admin and booking it as a student.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 99           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 89           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 79           | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       | default                    |
      | student2 | Student   | 2        | student2@example.com | S2       | discount1                  |
      | student3 | Student   | 3        | student3@example.com | S3       | discount2                  |
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
    And the following "local_shopping_cart > user credits" exist:
      | user     | credit | currency |
      | student1 | 300    | EUR      |
      | student2 | 350    | EUR      |
      | student3 | 400    | EUR      |
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
      | booking     | text         | course | description | useprice | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP  | Option-form  | C1     | Price-form  | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option availability: custom form with selection of prices
    Given the following config values are set as admin:
       | config                      | value        | plugin  |
       | pricecategoryfield          | userpricecat | booking |
    ## Or use
    ## And I set the following administration settings values:
    ##  | User profile field for price category | userpricecat |
    And I log in as "admin"
    And I am on the "BookingCMP" Activity page
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1   | select |
      | bo_cond_customform_label_1_1    | Rooms  |
      | bo_cond_customform_notempty_1_1 | 1      |
    And I set the field "bo_cond_customform_value_1_1" to multiline:
    """
    choose => Select...
    singleroom => Single Room => 10 => 100
    doubleroom => Double Room => 5 => discount2:100,discount1:200,default:150.4
    """
    And I press "Save"
    And I log out
    When I am on the "BookingCMP" Activity page logged in as student1
    Then I should see "99.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Rooms" in the ".condition-customform" "css_element"
    And I set the field "customform_select_1" to "doubleroom"
    And I should see "Double Room, 5 still available (+150.40 EUR)" in the ".condition-customform" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully put Option-form into the shopping cart." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Proceed to checkout" "text" in the ".modal-dialog.modal-xl .modalFooter" "css_element"
    And I wait to be redirected
    ## Verify prices and credits
    And I should see "Option-form" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "249.40 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "249.40 EUR" in the ".sc_price_label .sc_initialtotal" "css_element"
    And I should see "Use credit: 300.00 EUR" in the ".sc_price_label .sc_credit" "css_element"
    And I should see "249.40 EUR" in the ".sc_price_label .sc_deductible" "css_element"
    And I should see "50.60 EUR" in the ".sc_price_label .sc_remainingcredit" "css_element"
    And I should see "0 EUR" in the ".sc_totalprice" "css_element"
    And I press "Checkout"
    And I wait "1" seconds
    And I press "Confirm"
    And I should see "Payment successful!"
    And I should see "Credits used" in the ".payment-success ul.list-group" "css_element"
    And I should see "-249.40 EUR" in the ".payment-success ul.list-group" "css_element"
    And I should see "Option-form" in the ".payment-success ul.list-group" "css_element"
    And I log out

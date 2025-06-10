@mod @mod_booking @booking_payment_account
Feature: As an admin - configure booking's paymen account feature and validate it as students.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 66           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 55           | 0        | 2                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       |                            |
      | student2 | Student   | 2        | student2@example.com | S2       | discount1                  |
      | student3 | Student   | 3        | student3@example.com | S3       |                            |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
    And I clean booking cache
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
      | Account2       |
      | Account3       |
      | Account4       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                              |
      | Account1 | paypal  | 1       | {"brandname":"Paypal1","clientid":"Test1","secret":"Test1","environment":"sandbox"} |
      | Account2 | paypal  | 1       | {"brandname":"Paypal2","clientid":"Test2","secret":"Test2","environment":"sandbox"} |
      | Account3 | paypal  | 1       | {"brandname":"Paypal3","clientid":"Test3","secret":"Test3","environment":"sandbox"} |
      | Account4 | paypal  | 1       | {"brandname":"Paypal4","clientid":"Test4","secret":"Test4","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee | allowchooseaccount |
      | Account2 | 0              | 1                  |
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
      | booking     | text    | course | description | importing | useprice | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP  | Option1 | C1     | Price       | 1         | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | BookingCMP  | Option2 | C1     | Price       | 1         | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | BookingCMP  | Option3 | C1     | Price       | 1         | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | BookingCMP  | Option4 | C1     | Price       | 1         | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking payment accounts:override payment account for option as an admin and verify it as students
    ## Override payment account for option 4as a admin (editingteacher does not have permission).
    Given I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r4" "css_element"
    And I follow "Shopping Cart"
    And I set the field "Change the payment account" to "Account3"
    And I press "Save"
    ## Validate that it is impossible to add option 4 to cart at the same time with other options.
    When I am on the "BookingCMP" Activity page logged in as student2
    And I should see "55.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "55.00 EUR" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "3" in the ".popover-region-shopping_carts .count-container" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Different payment account" in the ".modal-dialog" "css_element"
    ##And I press "OK"
    And I click on "OK" "text" in the ".modal-dialog .modal-footer" "css_element"
    And I should see "3" in the ".popover-region-shopping_carts .count-container" "css_element"
    And I visit "/local/shopping_cart/checkout.php"
    And I should see "Option1" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "Option2" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "Option3" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should not see "Option4" in the ".shopping-cart-checkout-items-container" "css_element"
    And I log out
    ## Disable allowchooseaccount
    And the following config values are set as admin:
       | config             | value        | plugin              |
       | allowchooseaccount |              | local_shopping_cart |
    ## Validate that it is possible to add option 4 to cart at the same time with other options.
    And I am on the "BookingCMP" Activity page logged in as student2
    And I should see "3" in the ".popover-region-shopping_carts .count-container" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "4" in the ".popover-region-shopping_carts .count-container" "css_element"
    And I visit "/local/shopping_cart/checkout.php"
    And I should see "Option1" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "Option2" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "Option3" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "Option4" in the ".shopping-cart-checkout-items-container" "css_element"
    And I log out

@mod @mod_booking @booking_subboking
Feature: Enabling subboking as admin configuring subboking as a teacher and booking it as a student.

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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "Test option 1" in "My booking"
    ## Default - enable subbookings by admin.
    And the following config values are set as admin:
      | config          | value | plugin  |
      | showsubbookings | 1     | booking |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Add single subbooking option for a booking option as a teacher and verify as students
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I press "Subbookings"
    And I click on "Add a subbooking" "text" in the ".booking-subbookings-container" "css_element"
    ## Only manual fileds setup have to be used - other hand "Expand all" attemted to be invoked.
    And I set the field "Name of the subbooking" to "Partner(s)"
    And I set the field "subbooking_type" to "Additional person booking"
    And I set the field "Describe the additional person booking option" to "You can invite your partner(s):"
    And I press "Save changes"
    And I press "Subbookings"
    And I should see "Partner(s)" in the ".booking-subbookings-list" "css_element"
    And I press "Save"
    And I log out
    ## Verify subbokings working: book as stundet with subbokings
    When I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    Then I should see "Test option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Test option 1?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Partner(s)" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I press "Partner(s)"
    And I set the field "Add additional person(s)" to "2"
    And I wait "1" seconds
    And I set the following fields to these values:
      | person_firstname_1 | Ann   |
      | person_lastname_1  | Smith |
      | person_age_1       | 20    |
      | person_firstname_2 | Tomm  |
      | person_lastname_2  | Smith |
      | person_age_2       | 30    |
    And I click on "Book now" "text" in the ".subbooking-additionalperson-form" "css_element"
    ## And I should see "Start" in the ".subbooking-additionalperson-form" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Test option 1" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Add subbooking person via DB to a booking option and verify as students
    Given the following "mod_booking > subbookings" exist:
      | name    | type                        | option        | block | json                                                                                                                                     |
      | Partner | subbooking_additionalperson | Test option 1 | 0     | {"name":"Partner(s)","type":"subbooking_additionalperson","data":{"description":"You can invite your partner:","descriptionformat":"1"}} |
    ## Verify subbokings working: book as stundet with subbokings
    When I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    Then I should see "Test option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Test option 1?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Partner(s)" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I press "Partner(s)"
    And I set the field "Add additional person(s)" to "1"
    And I wait "1" seconds
    And I set the following fields to these values:
      | person_firstname_1 | Ann   |
      | person_lastname_1  | Smith |
      | person_age_1       | 20    |
    And I click on "Book now" "text" in the ".subbooking-additionalperson-form" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Test option 1" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Add subbooking item without price via DB to a booking option and verify as students
    Given the following "mod_booking > subbookings" exist:
      | name | type                      | option        | block | json                                                                                                                                                                                                |
      | item | subbooking_additionalitem | Test option 1 | 0     | {"name":"MyItem","type":"subbooking_additionalitem","data":{"description":"item descr","descriptionformat":"1","useprice":"0","subbookingadditemformlink":"0","subbookingadditemformlinkvalue":""}} |
    ## Verify subbokings working: book as stundet with subbokings
    When I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    Then I should see "Test option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Test option 1?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Supplementary bookings" in the ".modal-dialog.modal-xl .modalHeader" "css_element"
    And I should see "MyItem" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Test option 1" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Add subbooking item when price set via DB to a booking option and verify as students
    Given the following "core_payment > payment accounts" exist:
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
      | teacher1 | 200    | EUR      |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
    And the following "mod_booking > options" exist:
      | booking    | text           | course | description | importing | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | useprice |
      | My booking | Option-subitem | C1     | Subitem     | 1         | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        |
    And the following "mod_booking > subbookings" exist:
      | name | type                      | option         | block | json                                                                                                                                                                                                |
      | item | subbooking_additionalitem | Option-subitem | 0     | {"name":"MyItem","type":"subbooking_additionalitem","data":{"description":"item descr","descriptionformat":"1","useprice":"1","subbookingadditemformlink":"0","subbookingadditemformlinkvalue":""}} |
    And the following "mod_booking > prices" exist:
      | itemname | area       | pricecategoryidentifier | price | currency |
      | item     | subbooking | default                 | 55    | EUR      |
      | item     | subbooking | discount1               | 44    | EUR      |
    ## Verify subbokings working: book as stundet with subboking item.
    When I am on the "Course 1" course page logged in as teacher1
    And I follow "My booking"
    Then I should see "Option-subitem" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Option-subitem?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Add to cart" "text" in the ".modal-dialog.modal-xl .modalButtonAreaContainer .pricecontainer" "css_element"
    And I follow "Continue"
    And I click on "Add to cart" "text" in the ".modal-dialog.modal-xl .modalMainContent .pricecontainer" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully put Option-subitem into the shopping cart." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    ##And I follow "Proceed to checkout"
    And I click on "Proceed to checkout" "text" in the ".modal-dialog.modal-xl .modalFooter" "css_element"
    And I wait to be redirected
    ## Verify prices and credits
    And I should see "Option-subitem" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "88.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "MyItem" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "55.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "143.00 EUR" in the ".sc_price_label .sc_initialtotal" "css_element"
    And I should see "Use credit: 200.00 EUR" in the ".sc_price_label .sc_credit" "css_element"
    And I should see "143.00 EUR" in the ".sc_price_label .sc_deductible" "css_element"
    And I should see "57.00 EUR" in the ".sc_price_label .sc_remainingcredit" "css_element"
    And I should see "0 EUR" in the ".sc_totalprice" "css_element"
    And I press "Checkout"
    And I wait "1" seconds
    And I press "Confirm"
    And I should see "Payment successful!"
    And I should see "Option-subitem" in the ".payment-success ul.list-group" "css_element"
    And I should see "MyItem" in the ".payment-success ul.list-group" "css_element"

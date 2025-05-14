@mod @mod_booking @booking_enrollink
Feature: Create enrollink availability form for booking options with connected course as admin and booking it as a student.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 25           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 20           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 15           | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       | default                    |
      | student2 | Student   | 2        | student2@example.com | S2       | discount1                  |
      | student3 | Student   | 3        | student3@example.com | S3       | discount2                  |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
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
      | teacher1 | 450    | EUR      |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course1  | C1        | 0        | 1                |
      | Course2  | C2        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | teacher1 | C2     | editingteacher |
      | teacher1 | C2     | manager        |
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking     | text         | course | description | importing | chooseorcreatecourse | enrolmentstatus | useprice | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP  | Option-form  | C1     | Price-form  | 1         | 0                    | 2               | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option enrollink: create and validate
    Given the following "mod_booking > rules" exist:
      | conditionname        | contextid | conditiondata  | name      | actionname | actiondata                                                                                                                                                            | rulename            | ruledata                                                                                           | cancelrules |
      | select_student_in_bo | 1         | {"borole":"0"} | enrollink | send_mail  | {"subject":"Enrollinksubj","template":"<p>{enrollink}<\/p><p>{qrenrollink}<\/p><p>{#customform}<\/p><p>{customform}<\/p><p>{\/customform}<\/p>","templateformat":"1"} | rule_react_on_event | {"boevent":"\\\\mod_booking\\\\event\\\\enrollink_triggered","aftercompletion":"","condition":"0"} |             |
    And I am on the "BookingCMP" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    ## And I follow "Availability conditions".
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
    ## Buyer enrolled directly, users by enrollink - after confirmation.
      | bo_cond_customform_select_1_1               | enrolusersaction |
      | bo_cond_customform_label_1_1                | Number of user   |
      | bo_cond_customform_value_1_1                | 2                |
      | bo_cond_customform_enroluserstowaitinglist1 | 1                |
      | waitforconfirmation                         |                  |
    ## To avoid duplicated field label "Connected Moodle course"!
    And I set the field "chooseorcreatecourse" to "Connected Moodle course"
    And I wait "1" seconds
    And I set the field with xpath "//div[contains(@id, 'fitem_id_courseid_')]//input[contains(@id, 'form_autocomplete_input-')]" to "Course2"
    And I press "Save"
    And I should see "25.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Number of user" in the ".condition-customform" "css_element"
    And I set the field "customform_enrolusersaction_1" to "3"
    And I set the field "customform_enroluserwhobookedcheckbox_enrolusersaction_1" to "checked"
    And I follow "Continue"
    And I wait "1" seconds
    And I should see "75.00 EUR" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    ##And I should see "Thank you! You have successfully put Option-form into the shopping cart. Now click on \"Proceed to checkout\" to continue." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Proceed to checkout" "text" in the ".modal-dialog.modal-xl .modalFooter" "css_element"
    And I wait to be redirected
    ## Verify prices and credits
    And I should see "Option-form" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "75.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "75.00 EUR" in the ".sc_price_label .sc_initialtotal" "css_element"
    And I should see "Use credit: 450.00 EUR" in the ".sc_price_label .sc_credit" "css_element"
    And I should see "75.00 EUR" in the ".sc_price_label .sc_deductible" "css_element"
    And I should see "375.00 EUR" in the ".sc_price_label .sc_remainingcredit" "css_element"
    And I should see "0 EUR" in the ".sc_totalprice" "css_element"
    And I press "Checkout"
    And I wait "1" seconds
    And I press "Confirm"
    And I should see "Payment successful!"
    And I should see "Credits used" in the ".payment-success ul.list-group" "css_element"
    And I should see "-75.00 EUR" in the ".payment-success ul.list-group" "css_element"
    And I should see "Option-form" in the ".payment-success ul.list-group" "css_element"
    And I am on the "BookingCMP" Activity page
    And I should see "3" in the ".allbookingoptionstable_r1 .col-ap-availableplaces.text-success.avail .text-success" "css_element"
    And I should see "/ 6" in the ".allbookingoptionstable_r1 .col-ap-availableplaces.text-success.avail" "css_element"
    And I log out
    ## Send messages via cron and verify via events log
    ## Steps below disabled because fails at GithHub (works OK locally)
    ## And I am logged in as admin
    ## And I trigger cron
    ## And I visit "/report/loglive/index.php"
    ## And I should see "Custom message A message e-mail with subject \"Enrollinksubj\" has been sent to user: \"Teacher 1\" by the user \"Teacher 1\""
    ## And I follow "Custom message A message e-mail with subject \"Enrollinksubj\" has been sent to user: \"Teacher 1\" by the user \"Teacher 1\""
    ## And I should see "/mod/booking/enrollink.php?erlid="
    ## And I should see "Number of user: 3"
    ## Logout is mandatory for admin pages to avoid error
    ## And I log out

  @javascript
  Scenario: Booking option enrollink: create with waiting lists and validate
    Given the following "mod_booking > rules" exist:
      | conditionname        | contextid | conditiondata  | name      | actionname | actiondata                                                                                                                                                            | rulename            | ruledata                                                                                           | cancelrules |
      | select_student_in_bo | 1         | {"borole":"0"} | enrollink | send_mail  | {"subject":"Enrollinksubj","template":"<p>{enrollink}<\/p><p>{qrenrollink}<\/p><p>{#customform}<\/p><p>{customform}<\/p><p>{\/customform}<\/p>","templateformat":"1"} | rule_react_on_event | {"boevent":"\\\\mod_booking\\\\event\\\\enrollink_triggered","aftercompletion":"","condition":"0"} |             |
    And the following "mod_booking > options" exist:
      | booking    | text               | description | importing | course | chooseorcreatecourse | enrolmentstatus | useprice | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | waitforconfirmation | bo_cond_customform_restrict | bo_cond_customform_select_1_1 | bo_cond_customform_label_1_1 | bo_cond_customform_value_1_1 | bo_cond_customform_enroluserstowaitinglist1 |
      | BookingCMP | Option-waitinglist | waitinglist | 1         | C2     | 1                    | 2               | 1        | 6          | teacher1          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 1                   | 1                           | enrolusersaction              | Number of user               | 2                            | 1                                           |
    And I am on the "BookingCMP" Activity page logged in as teacher1
    And I should not see "25.00 EUR" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I click on "Book it - on waitinglist" "text" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Number of user" in the ".condition-customform" "css_element"
    And I set the field "customform_enrolusersaction_1" to "3"
    And I set the field "customform_enroluserwhobookedcheckbox_enrolusersaction_1" to "checked"
    And I follow "Continue"
    And I should see "You were added to the waiting list for Option-waitinglist." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I follow "Close"
    And I should not see "75.00 EUR" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "User is on the waiting list" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    ## Confirm teacher's purchase by themself
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "[data-target='#accordion-item-waitinglist']" "css_element"
    And I click on ".confirmbooking-username-teacher1 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I am on the "BookingCMP" Activity page
    And I should see "75.00 EUR" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r2" "css_element"
    And I visit "/local/shopping_cart/checkout.php"
    ## Verify prices and credits
    And I should see "Option-waitinglist" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "75.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "75.00 EUR" in the ".sc_price_label .sc_initialtotal" "css_element"
    And I should see "Use credit: 450.00 EUR" in the ".sc_price_label .sc_credit" "css_element"
    And I should see "75.00 EUR" in the ".sc_price_label .sc_deductible" "css_element"
    And I should see "375.00 EUR" in the ".sc_price_label .sc_remainingcredit" "css_element"
    And I should see "0 EUR" in the ".sc_totalprice" "css_element"
    And I press "Checkout"
    And I wait "1" seconds
    And I press "Confirm"
    And I should see "Payment successful!"
    And I should see "Credits used" in the ".payment-success ul.list-group" "css_element"
    And I should see "-75.00 EUR" in the ".payment-success ul.list-group" "css_element"
    And I should see "Option-waitinglist" in the ".payment-success ul.list-group" "css_element"
    And I am on the "BookingCMP" Activity page
    And I should see "3" in the ".allbookingoptionstable_r2 .col-ap-availableplaces.text-success.avail .text-success" "css_element"
    And I should see "/ 6" in the ".allbookingoptionstable_r2 .col-ap-availableplaces.text-success.avail" "css_element"
    And I log out
    ## Send messages via cron and verify via events log
    ## Steps below disabled because fails at GithHub (works OK locally)
    ## And I am logged in as admin
    ## And I trigger cron
    ## And I visit "/report/loglive/index.php"
    ## And I should see "Custom message A message e-mail with subject \"Enrollinksubj\" has been sent to user: \"Teacher 1\" by the user \"Teacher 1\""
    ## And I follow "Custom message A message e-mail with subject \"Enrollinksubj\" has been sent to user: \"Teacher 1\" by the user \"Teacher 1\""
    ## And I should see "/mod/booking/enrollink.php?erlid="
    ## And I should see "Number of user: 3"
    ## Logout is mandatory for admin pages to avoid error
    ## And I log out

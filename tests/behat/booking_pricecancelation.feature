@mod @mod_booking @booking_pricecancelation
Feature: Create booking option with price and force students answer as admin than cancel as student.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       | default                    |
      | student2 | Student   | 2        | student2@example.com | S2       | discount1                  |
      | student3 | Student   | 3        | student3@example.com | S3       | discount2                  |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | cancancelbook | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | 1             | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking     | text            | course | description    | importing | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | useprice | canceluntil    | canceluntilcheckbox |
      | BookingCMP  | Option-tenis    | C1     | Price-tenis    | 1         | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        | ## tomorrow ## | 1                   |
      | BookingCMP  | Option-football | C1     | Price-football | 1         | 4          | 1           | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 1        | ## tomorrow ## | 1                   |
      | BookingCMP  | Option-xconsume | C1     | Price-xconsume | 1         | 4          | 1           | 0              | 0              | ## -48 hours ##   | ## +72 hours ## | 1        | ## tomorrow ## | 1                   |
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: option cancellation when price was set
    Given the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following "mod_booking > user purchases" exist:
      | booking     | option          | user     |
      | BookingCMP  | Option-tenis    | student1 |
      | BookingCMP  | Option-football | student2 |
    And I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Option-tenis" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r2" "css_element"
    ##And I wait "1" seconds
    When I click on "Cancel purchase" "text" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    Then I should see "Do you really want to cancel this purchase?" in the ".modal.show .modal-body" "css_element"
    And I should see "You'll get the costs of your purchase (88 EUR) minus a cancelation fee (0 EUR) as credit (88 EUR) for your next purchase." in the ".modal.show .modal-body" "css_element"
    And I click on "Cancel purchase" "button" in the ".modal.show .modal-footer" "css_element"
    ## Notification has been displayed but become clossed instantly
    ##And I should see "Successfully canceled" in the ".notifications" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r2 .booknow" "css_element"

  @javascript
  Scenario: Booking: cancellation of all users purchases by teacher when price was set
    Given the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 2              |
    And the following "mod_booking > user purchases" exist:
      | booking     | option          | user     |
      | BookingCMP  | Option-football | student1 |
      | BookingCMP  | Option-football | student2 |
      | BookingCMP  | Option-football | student3 |
    And I am on the "BookingCMP" Activity page logged in as admin
    ## Teacher does not have permission to cancel all - only cashier and above
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Cancel all booked users" "link" in the ".allbookingoptionstable_r1" "css_element"
    ## And I wait "1" seconds
    Then I should see "Do you really want to cancel this purchase for all users?" in the ".modal.show .modal-body" "css_element"
    And I should see "The following users will get their money back as credit:" in the ".modal.show .modal-body" "css_element"
    And I should see "student1@example.com, 88.00 EUR" in the ".modal.show .modal-body" "css_element"
    And I should see "student2@example.com, 77.00 EUR" in the ".modal.show .modal-body" "css_element"
    And I should see "student3@example.com, 66.00 EUR" in the ".modal.show .modal-body" "css_element"
    And I set the field "cancelationfee" to "3"
    And I click on "Save changes" "button" in the ".modal.show .modal-footer" "css_element"
    ## Verify records in the ledger table.
    And I visit "/local/shopping_cart/report.php"
    And the following should exist in the "cash_report_table" table:
      | Paid  | Credit: | Cancelation fee | Item name                  | E-Mail               | Payment method | Status   |
      | 0.00  | 63.00   | 3.00            | Canceled - Option-football | student3@example.com | Credits	       | Canceled |
      | 0.00  | 74.00   | 3.00            | Canceled - Option-football | student2@example.com | Credits	       | Canceled |
      | 0.00  | 85.00   | 3.00            | Canceled - Option-football | student1@example.com | Credits	       | Canceled |
      | 66.00 |         |                 | Option-football            | student3@example.com | Cashier (Cash) | Success  |
      | 77.00 |         |                 | Option-football            | student2@example.com | Cashier (Cash) | Success  |
      | 88.00 |         |                 | Option-football            | student1@example.com | Cashier (Cash) | Success  |
    And I log out

  @javascript
  Scenario: Booking: cancellation of all users purchases when price and fixed consumption were set
    Given the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee | calculateconsumation | calculateconsumationfixedpercentage |
      | Account1 | 4              | 1                    | 30                                  |
    And the following "mod_booking > user purchases" exist:
      | booking     | option          | user     |
      | BookingCMP  | Option-xconsume | student1 |
      | BookingCMP  | Option-xconsume | student2 |
      | BookingCMP  | Option-xconsume | student3 |
    And I am on the "BookingCMP" Activity page logged in as admin
    ## Teacher does not have permission to cancel all - only cashier and above
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    When I click on "Cancel all booked users" "link" in the ".allbookingoptionstable_r3" "css_element"
    ## And I wait "1" seconds
    Then I should see "Do you really want to cancel this purchase for all users?" in the ".modal.show .modal-body" "css_element"
    And I should see "The following users will get their money back as credit:" in the ".modal.show .modal-body" "css_element"
    And I should see "student1@example.com, 88.00 EUR (-30%)" in the ".modal.show .modal-body" "css_element"
    And I should see "student2@example.com, 77.00 EUR (-30%)" in the ".modal.show .modal-body" "css_element"
    And I should see "student3@example.com, 66.00 EUR (-30%)" in the ".modal.show .modal-body" "css_element"
    And I set the field "cancelationfee" to "3"
    And I click on "Save changes" "button" in the ".modal.show .modal-footer" "css_element"
    ## Verify records in the ledger table.
    And I visit "/local/shopping_cart/report.php"
    And the following should exist in the "cash_report_table" table:
      | Paid  | Credit: | Cancelation fee | Item name                  | E-Mail               | Payment method | Status   |
      | 0.00  | 43.00   | 3.00            | Canceled - Option-xconsume | student3@example.com | Credits	       | Canceled |
      | 0.00  | 51.00   | 3.00            | Canceled - Option-xconsume | student2@example.com | Credits	       | Canceled |
      | 0.00  | 59.00   | 3.00            | Canceled - Option-xconsume | student1@example.com | Credits	       | Canceled |
    And I log out

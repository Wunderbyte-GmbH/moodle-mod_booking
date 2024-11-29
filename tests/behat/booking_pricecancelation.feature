@mod @mod_booking @booking_pricecancelation
Feature: Create booking option with price and force students answer as admin than cancel as student.

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
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | cancancelbook | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | 1             | All bookings                     | Yes                      |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "mod_booking > options" exist:
      | booking     | text            | course | description    | maxanswers | datesmarker | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | canceluntil    | canceluntilcheckbox |
      | BookingCMP  | Option-tenis    | C1     | Price-tenis    | 2          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        | ## tomorrow ## | 1                   |
      | BookingCMP  | Option-football | C1     | Price-football | 2          | 1           | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 1        | ## tomorrow ## | 1                   |
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following "mod_booking > user purchases" exist:
      | booking     | option          | user     |
      | BookingCMP  | Option-tenis    | student1 |
      | BookingCMP  | Option-football | student2 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: option cancellation when price was set
    Given I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Option-tenis" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r2" "css_element"
    ##And I wait "1" seconds
    And I click on "Cancel purchase" "text" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    Then I should see "Do you really want to cancel this purchase?" in the ".modal.show .modal-body" "css_element"
    And I should see "You'll get the costs of your purchase (88 EUR) minus a cancelation fee (0 EUR) as credit (88 EUR) for your next purchase." in the ".modal.show .modal-body" "css_element"
    And I click on "Cancel purchase" "button" in the ".modal.show .modal-footer" "css_element"
    ## Notification has been displayed but become clossed instantly
    ##And I should see "Successfully canceled" in the ".notifications" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r2 .booknow" "css_element"

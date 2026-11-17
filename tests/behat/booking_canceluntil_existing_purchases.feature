@mod @mod_booking @booking_canceluntil_existing_purchases
Feature: As a cashier I pass the changed cancellation deadline of a booking option on to users who already bought it.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       | default                    |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | cancancelbook |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | 1             |
    ## The cancellation deadline has already passed when the student buys.
    And the following "mod_booking > options" exist:
      | booking     | text        | course | description | teachersforoption | importing | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | useprice | canceluntil     | canceluntilcheckbox |
      | BookingCMP  | Option-yoga | C1     | Price-yoga  | teacher1          | 1         | 4          | 1           | 0              | 0              | ## +20 days ##    | ## +21 days ##  | 1        | ## yesterday ## | 1                   |
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
      | booking     | option      | user     |
      | BookingCMP  | Option-yoga | student1 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: cashier extends the cancellation deadline of existing purchases after confirmation
    Given I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Option-yoga" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Cancel purchase" in the ".allbookingoptionstable_r1" "css_element"
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    ## The checkbox is never ticked by default.
    And the field "Also apply to existing purchases" matches value ""
    And I set the following fields to these values:
      | canceluntil[year]                | ## +1 year ##%Y## |
      | Also apply to existing purchases | 1                 |
    And I press "Save"
    Then I should see "Do you really want to change the cancellation deadline for all users who have already booked this booking option?" in the ".modal.show .modal-body" "css_element"
    And I should see "Purchases concerned: up to 1." in the ".modal.show .modal-body" "css_element"
    ## Cancelling the dialog does not save anything.
    And I click on "Cancel" "button" in the ".modal.show .modal-footer" "css_element"
    And I should see "Also apply to existing purchases"
    And I press "Save"
    And I click on "Save and extend deadlines" "button" in the ".modal.show .modal-footer" "css_element"
    And I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Cancel purchase" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking: without the checkbox existing purchases keep their cancellation deadline
    Given I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    When I set the field "canceluntil[year]" to "## +1 year ##%Y##"
    And I press "Save"
    And I am on the "BookingCMP" Activity page logged in as student1
    Then I should see "Option-yoga" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Cancel purchase" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Booking: a teacher who is no cashier is not offered to change existing purchases
    Given I am on the "BookingCMP" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    Then I should see "Cancelling is only possible until certain date"
    And I should not see "Also apply to existing purchases"

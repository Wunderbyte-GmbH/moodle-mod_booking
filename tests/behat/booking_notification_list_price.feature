@mod @mod_booking @booking_notification_list_price
Feature: Notification-list users can buy a priced option after a cancellation
  In order to book a place which becomes available
  As a student on the notification list
  I need the priced booking action to be rendered only once and without a stale notification bell

  Background:
    Given the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 79           | 0        | 1                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
      | student3 | Student   | 3        | student3@example.com | S3       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "core_payment > payment accounts" exist:
      | name     |
      | Account1 |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following config values are set as admin:
      | config              | value | plugin  |
      | usenotificationlist | 1     | booking |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | cancancelbook | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 1             | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking    | text          | course | description  | importing | useprice | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Priced option | C1     | Priced option | 1         | 1        | 1            | 2          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    # A user purchase creates both the paid purchase and its corresponding booking answer.
    # These two purchases therefore fill the option's two available places.
    And the following "mod_booking > user purchases" exist:
      | booking    | option        | user     |
      | My booking | Priced option | student1 |
      | My booking | Priced option | student2 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: A notified place is rendered correctly before and after opening checkout
    Given I am on the "My booking" Activity page logged in as student3
    And I should not see "Add to cart" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And ".allbookingoptionstable_r1 .booking-button-notify-me .fa-bell-o" "css_element" should exist
    When I click on ".allbookingoptionstable_r1 .booking-button-notify-me" "css_element"
    And ".allbookingoptionstable_r1 .booking-button-notify-me .fa-bell" "css_element" should exist
    And I log out
    # Cancelling one of the paid bookings makes the option bookable again.
    And I am on the "My booking" Activity page logged in as student1
    And I click on "Cancel purchase" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Do you really want to cancel this purchase?" in the ".modal.show .modal-body" "css_element"
    And I should see "You'll get the costs of your purchase (79.00 EUR) minus a cancelation fee (0.00 EUR) as credit for your next purchase: 79.00 EUR" in the ".modal.show .modal-body" "css_element"
    And I click on "Cancel purchase" "button" in the ".modal.show .modal-footer" "css_element"
    Then I should see "Add to cart" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And ".allbookingoptionstable_r1 .pricecontainer" "css_element" should exist
    And "(.//*[contains(@class, 'allbookingoptionstable_r1')]//*[contains(concat(' ', normalize-space(@class), ' '), ' pricecontainer ')])[2]" "xpath_element" should not exist
    And ".allbookingoptionstable_r1 .booking-button-notify-me" "css_element" should not exist
    And I log out
    # Opening the released place removes the stale notification-list state for student3.
    And I am on the "My booking" Activity page logged in as student3
    And I should see "Add to cart" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And ".allbookingoptionstable_r1 .pricecontainer" "css_element" should exist
    And "(.//*[contains(@class, 'allbookingoptionstable_r1')]//*[contains(concat(' ', normalize-space(@class), ' '), ' pricecontainer ')])[2]" "xpath_element" should not exist
    ## Notification bell still exists because the user is still on the notification list, but the priced option is now bookable.
    And ".allbookingoptionstable_r1 .booking-button-notify-me" "css_element" should exist
    And I click on "Add to cart" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "In cart" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    ## Notification bell should disappear by now but being async task the exact timing is not guaranteed.
    ## Therefore, we check for its absence after the checkout navigation below.
    ##And ".allbookingoptionstable_r1 .booking-button-notify-me" "css_element" should not exist
    And I click on "To checkout" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Priced option" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "79.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "79.00 EUR" in the ".sc_totalprice" "css_element"
    And ".shopping-cart-checkout-items-container .booking-button-notify-me" "css_element" should not exist
    # Revisit the list to cover a full render after the checkout navigation as well. Ensure that the notification bell is no longer shown.
    When I am on the "My booking" Activity page
    Then I should see "In cart" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And ".allbookingoptionstable_r1 .pricecontainer" "css_element" should exist
    And "(.//*[contains(@class, 'allbookingoptionstable_r1')]//*[contains(concat(' ', normalize-space(@class), ' '), ' pricecontainer ')])[2]" "xpath_element" should not exist
    And ".allbookingoptionstable_r1 .booking-button-notify-me" "css_element" should not exist

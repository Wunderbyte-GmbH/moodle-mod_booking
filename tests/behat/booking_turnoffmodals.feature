@mod @mod_booking @booking_turnoffmodals
Feature: Turn off modals - pre booking pages have to be shown inline
  As an admin I turn off the modals
  So that every pre booking page is shown inside the booking option row instead of a modal,
  no matter which list view is used and no matter if the list comes from a shortcode.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following config values are set as admin:
      | config        | value | plugin  |
      | turnoffmodals | 1     | booking |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | bookingpolicy |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Are you sure? |
    ## The option needs real (future) dates: the shortcode lists filter on courseendtime > today,
    ## so an option without optiondates would never show up there.
    And the following "mod_booking > options" exist:
      | booking    | text          | course | description  | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Test option 1 | C1     | Inline pages | 5          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Turn off modals: the booking policy is shown inline on the booking instance page
    Given I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Are you sure?" in the ".allbookingoptionstable_r1 .prepage-inline" "css_element"
    And ".modal.show" "css_element" should not exist
    ## The whole booking process has to run inline as well.
    And I set the field "bookingpolicy_checkbox" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Test option 1" in the ".prepage-inline .condition-confirmation" "css_element"
    And ".modal.show" "css_element" should not exist
    And I follow "Close"
    And ".prepage-inline.show" "css_element" should not exist

  @javascript
  Scenario Outline: Turn off modals: pre booking pages are inline in every list view of a shortcode
    Given I am logged in as admin
    And I create a page "shortcode_<type>" in course "C1" that refers booking "My booking" with shortcode "[courselist cmid=My booking type=<type>]"
    And I log out
    And I am on the "shortcode_<type>" Activity page logged in as student1
    And I wait until the page is ready
    When I click on "Book now" "text"
    Then I should see "Are you sure?" in the ".prepage-inline" "css_element"
    And ".modal.show" "css_element" should not exist

    Examples:
      | type          |
      | list          |
      | imageleft     |
      | imageright    |
      | imagelefthalf |

  @javascript
  Scenario: Turn off modals: a list shortcode of a cards instance still shows the pages inline
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I set the field "View type" to "Cards view"
    And I press "Save and display"
    And I log out
    And I am logged in as admin
    And I create a page "shortcode_listofcards" in course "C1" that refers booking "My booking" with shortcode "[courselist cmid=My booking type=list]"
    And I log out
    And I am on the "shortcode_listofcards" Activity page logged in as student1
    And I wait until the page is ready
    When I click on "Book now" "text"
    Then I should see "Are you sure?" in the ".prepage-inline" "css_element"
    And ".modal.show" "css_element" should not exist

  @javascript
  Scenario: Turn off modals: the cards view still uses a modal, because inline is not supported there
    Given I am logged in as admin
    And I create a page "shortcode_cards" in course "C1" that refers booking "My booking" with shortcode "[courselist cmid=My booking type=cards]"
    And I log out
    And I am on the "shortcode_cards" Activity page logged in as student1
    And I wait until the page is ready
    When I click on "Book now" "text"
    Then I should see "Are you sure?" in the ".modal.show" "css_element"

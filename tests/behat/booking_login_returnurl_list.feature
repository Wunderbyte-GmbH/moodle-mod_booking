@mod @mod_booking @booking_login_returnurl_list
Feature: Logging in from a page listing booking options ends where the visitor expects
  As a visitor who is not logged in
  I want a login from a page that lists booking options to lead back to the option I clicked, if any
  So that I am not sent to the detail page of an option I never opened

  ## Regression cover for Wunderbyte-GmbH/Wunderbyte-GmbH#2571: every login button rendered for a
  ## logged-out visitor stored its option's detail page as the post-login target while the card was
  ## built. On a page listing several options the last rendered card won, so a login through the
  ## regular login link took the visitor to that option's detail page, and the login button of one
  ## option could lead to another one.

  Background:
    Given the following config values are set as admin:
      | config                              | value | plugin  |
      | displayloginbuttonforbookingoptions | 1     | booking |
      | showbookingdetailstoall             | 1     | booking |
    ## Moodle 5.2+ (MDL-87545) sends logged-out visitors from the site home to the login page unless
    ## enablemyhome is on; earlier versions do not know the setting and ignore it. Fresh 5.2+ installs
    ## also force login by default (MDL-87523), which bounces every logged-out visit to the login page.
    And the following config values are set as admin:
      | config       | value |
      | enablemyhome | 1     |
      | forcelogin   | 0     |
    And the "shortcodes" filter is "on"
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name           | intro                | bookingmanager | eventtype |
      | booking  | C1     | Listed options | Listed options intro | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking        | text     | course | description      | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Listed options | Option A | C1     | Option A details | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
      | Listed options | Option B | C1     | Option B details | 1           | 0              | 0              | ## +4 days ##     | ## +5 days ##   |
      | Listed options | Option C | C1     | Option C details | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    And the following "activities" exist:
      | activity | course               | idnumber    | intro                                   | section |
      | label    | Acceptance test site | optionslist | [allbookingoptions requirelogin=false]  | 1       |
    And I clean booking cache

  @javascript
  Scenario: Logging in through the regular login link after viewing a list of options
    Given I am on site homepage
    And I should see "Option A"
    And I should see "Option C"
    And I should see "Log in to book this option."
    When I click on "Log in" "link" in the ".usermenu" "css_element"
    And I set the field "Username" to "student1"
    And I set the field "Password" to "student1"
    And I press "Log in"
    Then "body#page-mod-booking-optionview" "css_element" should not exist

  @javascript
  Scenario: Logging in through the login button of one listed option returns to that option
    Given I am on site homepage
    And I should see "Option A"
    And I should see "Option C"
    When I click on "Log in to book this option." "link" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' mod-booking-row ')][.//a[normalize-space(.)='Option A']]" "xpath_element"
    And I set the field "Username" to "student1"
    And I set the field "Password" to "student1"
    And I press "Log in"
    Then the url should match "/mod/booking/optionview\.php"
    And I should see "Option A details"
    And I should not see "Option C details"

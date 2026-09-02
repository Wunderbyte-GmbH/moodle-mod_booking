@mod @mod_booking @booking_login_deep_link
Feature: Deep link from the "Login to book" button back to the booking option
  As a visitor who is not logged in
  I need to land back on the booking option I was looking at after logging in
  So that I do not have to find it again from scratch

  ## Regression cover for Wunderbyte-GmbH/Wunderbyte-GmbH#1182: before that fix, the login button
  ## on an option WITH a price (isloggedinprice) linked to a bare /login/index.php, while the
  ## button on an option WITHOUT a price (isloggedin) already deep linked back to the option.
  ##
  ## The button for "Free option" / "Priced option" is deliberately rendered on a SEPARATE
  ## "Origin" option's detail page (via the bookingoptionview shortcode embedded in its
  ## description), not on the target option's own detail page. That distinction is the whole
  ## point of the test: clicking the button FROM the target's own page would send the browser's
  ## HTTP Referer to /login/index.php as that same page, and login/index.php falls back to the
  ## Referer as $SESSION->wantsurl whenever nothing else set it - so a test driven that way could
  ## land back on the target even if the plugin's own deep-link code did nothing at all. Driving
  ## the click from the unrelated Origin page removes that false positive: landing on the target's
  ## optionview.php afterwards can only be explained by the plugin explicitly having stored it as
  ## the post-login destination.

  Background:
    Given the following config values are set as admin:
      | config                              | value | plugin  |
      | displayloginbuttonforbookingoptions | 1     | booking |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 25           | 0        | 1                 |
    And the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | 1        | teacher1@example.com  |
      | student1 | Student   | 1        | student1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name           | intro                 | bookingmanager | eventtype |
      | booking  | C1     | Deep link test | Deep link test intro  | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking        | text            | course | description            | useprice | default |
      | Deep link test | Origin (free)   | C1     | replaced below by shortcode | 0  |         |
      | Deep link test | Free option     | C1     | Free option details         | 0  |         |
      | Deep link test | Origin (priced) | C1     | replaced below by shortcode | 0  |         |
      | Deep link test | Priced option   | C1     | Priced option details       | 1  | 25      |
    And I clean booking cache
    ## Overwrites each Origin option's description with the target's bookingoptionview shortcode -
    ## see the docblock on this step for why the button has to live away from its own option page.
    And I make option "Origin (free)" show option "Free option" via shortcode, both in booking "Deep link test"
    And I make option "Origin (priced)" show option "Priced option" via shortcode, both in booking "Deep link test"

  @javascript
  Scenario: Logging in through the button returns to a free option's own detail page (deep link on)
    Given the following config values are set as admin:
      | config                  | value | plugin  |
      | showbookingdetailstoall | 1     | booking |
    And I am on the option detail page for option "Origin (free)" in booking "Deep link test"
    And I should see "Log in to book this option."
    When I click on "Log in to book this option." "text"
    And I set the field "Username" to "student1"
    And I set the field "Password" to "student1"
    And I press "Log in"
    Then the url should match "/mod\/booking\/optionview\.php/"
    And I should see "Free option"

  @javascript
  Scenario: Logging in through the button returns to a priced option's own detail page (deep link on)
    Given the following config values are set as admin:
      | config                  | value | plugin  |
      | showbookingdetailstoall | 1     | booking |
    And I am on the option detail page for option "Origin (priced)" in booking "Deep link test"
    And I should see "Log in to book this option."
    When I click on "Log in to book this option." "text"
    And I set the field "Username" to "student1"
    And I set the field "Password" to "student1"
    And I press "Log in"
    Then the url should match "/mod\/booking\/optionview\.php/"
    And I should see "Priced option"

  @javascript
  Scenario: With the default settings, logging in through the button does not deep link
    Given I am on the option detail page for option "Origin (free)" in booking "Deep link test"
    And I should see "Log in to book this option."
    When I click on "Log in to book this option." "text"
    And I set the field "Username" to "student1"
    And I set the field "Password" to "student1"
    And I press "Log in"
    ## No showbookingdetailstoall / redirectonlogintocourse: the plugin never stores a deep link,
    ## so Moodle's own fallback returns the browser to wherever it came from - the Origin page it
    ## clicked the button from, never the target option it was trying to book.
    And I should see "Origin (free)"
    And I should not see "Free option details"

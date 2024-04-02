@mod @mod_booking @booking_rules
Feature: Create global booking rules as admin and insure they are working.

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
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking     | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | optiondateid_2 | daystonotify_2 | coursestarttime_2 | courseendtime_2 |
      | BookingCMP  | Option-tenis    | C1     | Deskr1      | 1            | 2          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
      | BookingCMP  | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   |
    ## And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking rules: create settings for booking rules via UI as admin and edit it
    Given I log in as "admin"
    And I visit "/mod/booking/edit_rules.php"
    And I click on "Add rule" "text"
    ## And I set the field "Campaign type" to "Change price or booking limit"
    And I set the following fields to these values:
      | Custom name for the rule | notifyadmin    |
      | Rule                     | React on event |
    And I wait "3" seconds
    And I set the following fields to these values:
      | Event                 | Substitution teacher was added (optiondates_teacher_added)     |
      | Condition of the rule | Directly select users without connection to the booking option |
    And I wait "1" seconds
    ## Mandatory workaround for autocomplete field
    And I set the field "Select the users you want to target" to "admin"
    And I wait "1" seconds
    And I set the following fields to these values:
      | Subject                             | Teacher was substituted              |
      | Message                             | Teacher was substituted successfully |
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "notifyadmin"
    And I click on "Edit" "text" in the ".booking-rules-list" "css_element"
    And I wait "1" seconds
    And I set the field "Custom name for the rule" to "rule1-notifyadmin"
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "rule1-notifyadmin"

  ## @javascript - JS no need for this test
  Scenario: Booking rules: create booking rule via DB and view as admin
    Given the following "mod_booking > rules" exist:
      | conditionname | conditiondata     | name        | actionname | actiondata                                                                      | rulename            | ruledata                                                      |
      | select_users  | {"userids":["2"]} | notifyadmin | send_mail  | {"subject":"teacher subst","template":"teacher sybst msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\optiondates_teacher_added"} |
    When I log in as "admin"
    And I visit "/mod/booking/edit_rules.php"
    ## And I wait until the page is ready
    And I should see "notifyadmin" in the ".booking-rules-list" "css_element"
    And I should see "React on event" in the ".booking-rules-list" "css_element"
    And I should see "Directly select users without connection to the booking option" in the ".booking-rules-list" "css_element"
    And I should see "Send email" in the ".booking-rules-list" "css_element"

  @javascript
  Scenario: Booking rules: create booking rule for teacher substituing event
    Given the following "mod_booking > rules" exist:
      | conditionname | conditiondata     | name        | actionname | actiondata                                                                      | rulename            | ruledata                                                      |
      | select_users  | {"userids":["2"]} | notifyadmin | send_mail  | {"subject":"teacher subst","template":"teacher sybst msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\optiondates_teacher_added"} |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Substitutions / Cancelled dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Option-football" in the "#region-main" "css_element"
    And I should see "No teacher" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I click on "Edit" "link" in the "[id^=optiondates_teachers_table] td.edit" "css_element"
    And I wait "1" seconds
    And I should see "Teachers" in the ".modal-header" "css_element"
    When I set the following fields to these values:
      | Teachers | teacher1   |
      | Reason   | Assign one |
    And I press "Save changes"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Substitution teacher was added"
    And I should see "An e-mail with subject 'teacher subst' has been sent to user with id: '2'"
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for option cancellation event
    Given the following "mod_booking > rules" exist:
      | conditionname | conditiondata     | name        | actionname | actiondata                                                                    | rulename            | ruledata                                                    |
      | select_users  | {"userids":["2"]} | notifyadmin | send_mail  | {"subject":"cancellation","template":"cancellation msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_cancelled"} |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Cancel this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Reason for cancelation of this booking option" to "rule testing"
    And I click on "Save changes" "button"
    And I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Cancelled" in the ".allbookingoptionstable_r1" "css_element"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Booking option cancelled"
    And I should see "An e-mail with subject 'cancellation' has been sent to user with id: '2'"
    ## Logout is mandatory for admin pages to avoid error
    And I log out

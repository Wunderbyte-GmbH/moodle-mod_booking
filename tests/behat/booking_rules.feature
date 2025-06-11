@mod @mod_booking @booking_rules
Feature: Create global booking rules as admin and insure they are working.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname | name  |
      | text     | sport     | Sport |
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_sport |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                     |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       | football, tennis    |
      | student1 | Student   | 1        | student1@example.com | S1       |                     |
      | student2 | Student   | 2        | student2@example.com | S2       |                     |
      | student3 | Student   | 3        | student3@example.com | S3       |                     |
      | student4 | Student   | 4        | student4@example.com | S4       |                     |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I change viewport size to "1366x4000"

  @javascript
  Scenario: Booking rules: create settings for booking rules via UI as admin and edit it
    Given I log in as "admin"
    And I visit "/mod/booking/edit_rules.php"
    And I click on "Add rule" "text"
    ## And I set the field "Campaign type" to "Change price or booking limit"
    And I set the following fields to these values:
      | Custom name for the rule | notifyadmin    |
      | Rule                     | React on event |
    And I wait "2" seconds
    And I set the field "Event" to "Substitution teacher was added (optiondates_teacher_added)"
    And I wait "2" seconds
    And I set the field "Condition of the rule" to "Directly select users without connection to the booking option"
    ##And I set the following fields to these values:
    ##  | Event                 | Substitution teacher was added (optiondates_teacher_added)     |
    ##  | Condition of the rule | Directly select users without connection to the booking option |
    And I wait "1" seconds
    ## Mandatory workaround for autocomplete field
    And I set the field "Select the users you want to target" to "admin"
    And I wait "1" seconds
    And I set the following fields to these values:
      | Subject                             | Teacher was substituted              |
      | Message                             | Teacher was substituted successfully |
    And I click on "Save changes" "button"
    And I should see "notifyadmin"
    And I click on "Edit" "text" in the ".booking-rules-list" "css_element"
    And I wait "1" seconds
    And I set the field "Custom name for the rule" to "rule1-notifyadmin"
    And I click on "Save changes" "button"
    And I should see "rule1-notifyadmin"

  ## @javascript - JS no need for this test
  @javascript
  Scenario: Booking rules: create booking rule via DB and view as admin
    Given the following "mod_booking > rules" exist:
      | conditionname | contextid | conditiondata     | name        | actionname | actiondata                                                                      | rulename            | ruledata                                                                                           | cancelrules |
      | select_users  | 1         | {"userids":["2"]} | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"teacher subst","template":"teacher sybst msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\optiondates_teacher_added","aftercompletion":"","condition":"0"} |             |
    When I log in as "admin"
    And I visit "/mod/booking/edit_rules.php"
    ## And I wait until the page is ready
    And I should see "notifyadmin" in the ".booking-rules-list" "css_element"
    And I should see "React on event" in the ".booking-rules-list" "css_element"
    And I should see "Directly select users without connection to the booking option" in the ".booking-rules-list" "css_element"
    And I should see "Send email" in the ".booking-rules-list" "css_element"

  @javascript
  Scenario: Booking rules: create booking rule for teacher substituing event
    Given the following "mod_booking > options" exist:
      | booking     | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP  | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname | contextid | conditiondata     | name        | actionname | actiondata                                                                      | rulename            | ruledata                                                                                           | cancelrules |
      | select_users  | 1         | {"userids":["2"]} | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"teacher subst","template":"teacher sybst msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\optiondates_teacher_added","aftercompletion":"","condition":"0"} |             |
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
    ## And I should see "An e-mail with subject 'teacher subst' has been sent to user with id: '2'"
    And I should see "Custom message A message e-mail with subject \"teacher subst\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for option cancellation event and notify students
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname        | contextid | conditiondata  | name        | actionname | actiondata                                                                    | rulename            | ruledata                                                                                         | cancelrules |
      | select_student_in_bo | 1         | {"borole":"5"} | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"cancellation","template":"cancellation msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_cancelled","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I am on the "BookingCMP" Activity page
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Cancel this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Reason for cancellation of this booking option" to "rule testing"
    And I click on "Save changes" "button"
    And I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Cancelled" in the ".allbookingoptionstable_r1" "css_element"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Booking option cancelled for all"
    ## Fails and temporarily disabled
    ## And I should see "Booking option cancelled for/by user"
    ## And I should see "Custom message A message e-mail with subject \"cancellation\" has been sent to user: \"Teacher 1\" by the user \"Student 2\""
    ## And I should see "Custom message A message e-mail with subject \"cancellation\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for answer cancellation event and notify students
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname        | contextid | conditiondata  | name        | actionname | actiondata                                                                                  | rulename            | ruledata                                                                                         | cancelrules |
      | select_student_in_bo | 1         | {"borole":"0"} | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"answer cancellation","template":"answer cancellation msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookinganswer_cancelled","aftercompletion":"","condition":"0"} |             |
    And the following "mod_booking > answers" exist:
      | booking    | option          | user     |
      | BookingCMP | Option-football | student1 |
      | BookingCMP | Option-football | student2 |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Remove" "button"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Option cancelled by teacher or system A message e-mail with subject \"Deleted booking: Option-football by Student 2\" has been sent to user: \"Teacher 1\" by the user \"Student 2\""
    And I should see "Custom message A message e-mail with subject \"answer cancellation\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for option cancellation for user event and notify admin
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname | contextid | conditiondata     | name        | actionname | actiondata                                                                       | rulename            | ruledata                                                                                         | cancelrules |
      | select_users  | 1         | {"userids":["2"]} | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"answer cancellation","template":"cancellation","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookinganswer_cancelled","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Delete responses" "button"
    And I should see "You deleted 1 of 1 users. Users, that have completed activity, can't be deleted!"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Booking option cancelled for/by user"
    And I should see "Option cancelled by teacher or system A message e-mail with subject \"Deleted booking: Option-football by Student 1\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    And I should see "Custom message A message e-mail with subject \"answer cancellation\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for teacher removal event and notify other teachers
    Given the following "mod_booking > options" exist:
      | booking     | text           | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption         |
      | BookingCMP  | Option-teacher | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | teacher2, teacher1, admin |
    And the following "mod_booking > rules" exist:
      | conditionname        | contextid | name        | actionname | actiondata                                                                          | rulename            | ruledata                                                                                             | cancelrules |
      | select_teacher_in_bo | 1         | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"teacher removed","template":"teacher removed msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\optiondates_teacher_deleted","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Substitutions / Cancelled dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Option-teacher" in the "#region-main" "css_element"
    And I click on "Edit" "link" in the "[id^=optiondates_teachers_table] td.edit" "css_element"
    And I wait "1" seconds
    And I click on "Teacher 2" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I set the field "Reason" to "Remove teacher"
    And I press "Save changes"
    And I should see "Admin" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    Then I should see "Teacher deleted from teaching journal"
    And I should see "Custom message A message e-mail with subject \"teacher removed\" has been sent to user: \"Teacher 1\" by the user \"Teacher 2\""
    And I should see "Custom message A message e-mail with subject \"teacher removed\" has been sent to user: \"Teacher 1\" by the user \"Teacher 1\""
    And I should see "Custom message A message e-mail with subject \"teacher removed\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for option completion event and notify by user from event
    Given the following config values are set as admin:
      | config                 | value  | plugin  |
      | uselegacymailtemplates | 1      | booking |
    And the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname          | contextid | conditiondata                  | name          | actionname | actiondata                                                                | rulename            | ruledata                                                                                         | cancelrules |
      | select_user_from_event | 1         | {"userfromeventtype":"userid"} | notifystudent | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"completion","template":"completion msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_completed","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "(Un)confirm completion status" "button"
    And I should see "All selected users have been marked for activity completion"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    Then I should see "Booking option completed"
    And I should see "Booking option completion A message e-mail with subject \"Booking option completed\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    And I should see "Custom message A message e-mail with subject \"completion\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for option cancellation event and notify user matching profile field value
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption  |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | teacher1, teacher2 |
    And the following "mod_booking > rules" exist:
      | conditionname          | contextid | conditiondata                                             | name         | actionname | actiondata                                                                               | rulename            | ruledata                                                                                         | cancelrules |
      | enter_userprofilefield | 1         | {"cpfield":"sport","operator":"~","textfield":"football"} | emailteacher | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"cancellation football","template":"football cancelled","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_cancelled","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Cancel this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Reason for cancellation of this booking option" to "rule testing"
    And I click on "Save changes" "button"
    And I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Cancelled" in the ".allbookingoptionstable_r1" "css_element"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Booking option cancelled"
    And I should see "Custom message A message e-mail with subject \"cancellation football\" has been sent to user: \"Teacher 1\" by the user \"Teacher 2\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for event of completion and notify user matching profile field with option name
    Given the following "mod_booking > options" exist:
      | booking    | text     | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption  |
      | BookingCMP | football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | teacher1, teacher2 |
    And the following "mod_booking > rules" exist:
      | conditionname          | contextid | conditiondata                                           | name         | actionname | actiondata                                                                         | rulename            | ruledata                                                                                         | cancelrules |
      | match_userprofilefield | 1         | {"optionfield":"text","operator":"~","cpfield":"sport"} | emailteacher | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"completion football","template":"completion msg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_completed","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "(Un)confirm completion status" "button"
    And I should see "All selected users have been marked for activity completion"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    Then I should see "Booking option completed"
    And I should see "Custom message A message e-mail with subject \"completion football\" has been sent to user: \"Teacher 1\" by the user \"Teacher 2\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rule for: copy to teacher a custom message sent to users who booked option
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname          | contextid | conditiondata                  | name          | actionname        | actiondata                                                  | rulename            | ruledata                                                                                     | cancelrules |
      | select_user_from_event | 1         | {"userfromeventtype":"userid"} | copytotrigger | send_copy_of_mail | {"sendical":0,"sendicalcreateorcancel":"","subjectprefix":"Custom msg copy","messageprefix":"copy:"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\custom_message_sent","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Send custom message" "button"
    And I set the following fields to these values:
      | Subject | Rule send_copy_of_mail test             |
      | Message | Test bookig Rule send_copy_of_mail test |
    And I press "Send message"
    And I should see "Your message has been sent."
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    Then I should see "Custom message sent"
    ## TODO: should be: "to user: \"Student 1\" by the user \"Teacher 1\ "
    And I should see "Custom message A message e-mail with subject \"Rule send_copy_of_mail test\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    And I should see "Unknown message type A message e-mail with subject \"Rule send_copy_of_mail test\" has been sent to user with id:"
    And I should see "Custom message A message e-mail with subject \"Custom msg copy: Rule send_copy_of_mail test\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rule for: copy to admin a bulk custom message sent to 3 users who booked option
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
    And the following "mod_booking > rules" exist:
      | conditionname | contextid | conditiondata     | name        | actionname        | actiondata                                                       | rulename            | ruledata                                                                                          | cancelrules |
      | select_users  | 1         | {"userids":["2"]} | bulktoadmin | send_copy_of_mail | {"sendical":0,"sendicalcreateorcancel":"","subjectprefix":"Custom bulk msg copy","messageprefix":"copy:"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\custom_bulk_message_sent","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Student 3 (student3@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Send custom message" "button"
    And I set the following fields to these values:
      | Subject | Rule send_copy_of_bulk_mail test             |
      | Message | Test bookig Rule send_copy_of_bulk_mail test |
    And I press "Send message"
    And I should see "Your message has been sent."
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    Then I should see "Custom message sent"
    And I should see "Custom message A message e-mail with subject \"Rule send_copy_of_bulk_mail test\" has been sent to user: \"Teacher 1\" by the user \"Student 3\""
    And I should see "A custom bulk message e-mail with subject 'Rule send_copy_of_bulk_mail test' has been sent to all users of booking option with id:"
    And I should see "Custom message A message e-mail with subject \"Custom bulk msg copy: Rule send_copy_of_bulk_mail test\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for option update and notify teachers about it
    Given the following "mod_booking > options" exist:
      | booking     | text           | course | description   | limitanswers | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption  |
      | BookingCMP  | Option-created | C1     | Deskr-created | 0            | 0          | 0              | 0              | 2346937200        | 2347110000      | teacher1, teacher2 |
      ## 2044/05/15 - 2044/05/17
    And the following "mod_booking > rules" exist:
      | conditionname        | contextid | name         | actionname | actiondata                                                                       | rulename            | ruledata                                                                                       | cancelrules |
      | select_teacher_in_bo | 1         | emailchanges | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"OptionChanged","template":"Changes: {changes}","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_updated","aftercompletion":"","condition":"0"} |             |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I change viewport size to "1366x10000"
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name         | Option-updated |
      | Description                 | Deskr-updated  |
      | Max. number of participants | 5              |
      | Assign teachers             | teacher1,admin |
    And I press "Save"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    Then I should see "Booking option updated"
    And I should see "Custom message A message e-mail with subject \"OptionChanged\" has been sent to user: \"Teacher 1\" by the user \"Admin User\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Booking rules: create booking rule for rule overriding
    Given the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption  |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | teacher1, teacher2 |
    And the following "mod_booking > rules" exist:
      | conditionname        | contextid | conditiondata     | name        | actionname | actiondata                                                               | rulename            | ruledata                                                                                         | cancelrules |
      | select_users         | 1         | {"userids":["2"]} | notifyadmin | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"answcancsubj","template":"answcancmsg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookinganswer_cancelled","aftercompletion":"","condition":"0"} |             |
      | select_teacher_in_bo | 1         |                   | override    | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"overridesubj","template":"overridemsg","templateformat":"1"} | rule_react_on_event | {"boevent":"\\mod_booking\\event\\bookingoption_cancelled","aftercompletion":"","condition":"0"} | notifyadmin |
    And the following "mod_booking > answers" exist:
      | booking    | option          | user     |
      | BookingCMP | Option-football | student1 |
    When I am on the "BookingCMP" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Cancel this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Reason for cancellation of this booking option" to "rule testing"
    And I click on "Save changes" "button"
    And I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Cancelled" in the ".allbookingoptionstable_r1" "css_element"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Booking option cancelled for all"
    ## Fails and temporarily disabled
    ## And I should see "Booking option cancelled for/by user"
    ## And I should not see "Custom message A message e-mail with subject \"answcancsubj\" has been sent to user"
    ## And I should see "Custom message A message e-mail with subject \"overridesubj\" has been sent to user: \"Teacher 1\" by the user \"Teacher 2\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  ## @javascript
  Scenario: Booking rule for: a day before booking course start time and after closing time
    Given I log in as "admin"
    And the following config values are set as admin:
      | config        | value       |
      | timezone      | Europe/Kyiv |
      | forcetimezone | Europe/Kyiv |
    And the following "mod_booking > rules" exist:
      | conditionname | contextid | conditiondata     | name       | actionname | actiondata                                                                     | rulename        | ruledata                                   |
      | select_users  | 1         | {"userids":["2"]} | 1daybefore | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"1daybefore","template":"will start tomorrow","templateformat":"1"} | rule_daysbefore | {"days":"1","datefield":"coursestarttime"} |
      | select_users  | 1         | {"userids":["2"]} | 1dayafter  | send_mail  | {"sendical":0,"sendicalcreateorcancel":"","subject":"1dayafter","template":"was ended yesterday","templateformat":"1"}  | rule_daysbefore | {"days":"-1","datefield":"courseendtime"}  |
    ## It is important to setup next day exactly in minutes
    And the following "mod_booking > options" exist:
      | booking    | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0   | courseendtime_0       |
      | BookingCMP | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +1440 minutes ## | ## +3 days ##         |
      | BookingCMP | Option-tennis   | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## -3 days ##       | ## -1440 minutes ##   |
    And I am on the "BookingCMP" Activity page
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    ## IMPORTANT: Steps below often cause failures due to time mismatch.
    ## IMPORTANT: Enable it only if correcponded phpunit test_rule_on_beforeafter_cursestart() fails!
    ## And I trigger cron
    ## And I visit "/report/loglive/index.php"
    ## And I should see "Custom message A message e-mail with subject \"1daybefore\" has been sent to user with id:"
    ## And I should see "Custom message A message e-mail with subject \"1dayafter\" has been sent to user with id:"
    ## Logout is mandatory for admin pages to avoid error
    And I log out

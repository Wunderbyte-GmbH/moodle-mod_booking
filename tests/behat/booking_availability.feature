@mod @mod_booking @booking_availability
Feature: Test booking options avaialbility conditions
  As a teacher I configure various availability conditions
  For different booking options

  ## Every availability condition is configured once through the option form (UI path); the other
  ## scenarios seed the condition through the generator and only verify the resulting availability.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname | name   |
      | text     | sport     | Sport  |
      | text     | credit    | Credit |
    Given the following "users" exist:
      | username | firstname | lastname | email                 | idnumber | profile_field_sport | profile_field_credit |
      | teacher1 | Teacher   | 1        | teacher1@example.com  | T1       |                     |                      |
      | admin1   | Admin     | 1        | admin1@example.com    | A1       |                     |                      |
      | student1 | Student   | 1        | student1@example1.com | S1       | football            |                      |
      | student2 | Student   | 2        | student2@example2.com | S2       | tennis              |                      |
      | student3 | Student   | 3        | student3@example3.com | S3       | football            | 100                  |
    And the following "cohorts" exist:
      | name                    | idnumber | visible |
      | System booking cohort 1 | SBC1     | 1       |
      | System booking cohort 2 | SBC2     | 1       |
    And the following "cohort members" exist:
      | user     | cohort |
      | student2 | SBC1   |
      | student3 | SBC1   |
      | student3 | SBC2   |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | admin    | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                           | course | description | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | My booking | Option - advanced availability | C1     | Deskr       | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
      | My booking | Option - availability by dates | C1     | Deskr       | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +5 days ##   |
      | My booking | Option - dependency            | C1     | Deskr       | 1           | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0              | 0              | ## +5 days ##     | ## +6 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Configure availability condition by dates - until
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only after a certain date" to ""
    And I set the field "Booking is possible only until a certain date" to "checked"
    And I set the following fields to these values:
      | bookingclosingtime[day]   | 10                 |
      | bookingclosingtime[month] | May                |
      | bookingclosingtime[year]  | ## - 1 year ##%Y## |
    And I press "Save"
    And I should see "Cannot be booked anymore" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "May 10" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "## - 1 year ##%Y##" in the ".allbookingoptionstable_r2" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Cannot be booked anymore" in the ".allbookingoptionstable_r2" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r2" "css_element"
    ## Update availability as a teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only after a certain date" to ""
    And I set the field "Booking is possible only until a certain date" to "checked"
    And I set the following fields to these values:
      | bookingclosingtime[day]   | 10                 |
      | bookingclosingtime[month] | May                |
      | bookingclosingtime[year]  | ## + 1 year ##%Y## |
    And I press "Save"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Availability condition by dates - after (seeded opening times)
    ## The booking-time form is covered by the "until" scenario; here both opening states are seeded.
    Given the following "mod_booking > options" exist:
      | booking    | text                            | course | description | availability | restrictanswerperiodopening | bookingopeningtime               | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded opening future  | C1     | Deskr       | 1            | 1                           | ## 10 March next year 12:00 ##   | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
      | My booking | Option - seeded opening past    | C1     | Deskr       | 1            | 1                           | ## yesterday noon ##             | 1           | 0              | 0              | ## +7 days ##     | ## +8 days ##   |
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Option - seeded opening future" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Can be booked from" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "March 10" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "## + 1 year ##%Y##" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Option - seeded opening past" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r5" "css_element"

  @javascript
  Scenario: Configure bookingoption-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "User has previously booked a certain option" to "checked"
    And I set the field "Must be already booked" to "Option - dependency"
    And I press "Save"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Only users who have previously booked" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Only users who have previously booked" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure userprofile-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "A chosen user profile field should have a certain value" to "checked"
    And I set the following fields to these values:
      | bo_cond_userprofilefield_field    | Email address   |
      | bo_cond_userprofilefield_operator | contains (text) |
      | bo_cond_userprofilefield_value    | gmail.com       |
    And I press "Save"
    And I should see "Only users with user profile field email set to value gmail.com are allowed to book." in the ".allbookingoptionstable_r3" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Userprofile-dependent availability condition matching the student (seeded)
    Given the following "mod_booking > options" exist:
      | booking    | text                    | course | description | bo_cond_userprofilefield_1_default_restrict | bo_cond_userprofilefield_field | bo_cond_userprofilefield_operator | bo_cond_userprofilefield_value | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded profile | C1     | Deskr       | 1                                           | email                          | ~                                 | example1.com                   | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    When I am on the "My booking" Activity page logged in as student1
    Then I should not see "Not allowed to book" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    When I am on the "My booking" Activity page logged in as student2
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Configure usercustomprofile-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "A custom user profile field should have a certain value" to "checked"
    And I set the following fields to these values:
      | bo_cond_customuserprofilefield_field              | Sport                                   |
      | bo_cond_customuserprofilefield_operator           | has exactly this value (text or number) |
      | bo_cond_customuserprofilefield_value              | football                                |
      | bo_cond_customuserprofilefield_connectsecondfield | AND additional field                    |
      | bo_cond_customuserprofilefield_field2             | Credit                                  |
      | bo_cond_customuserprofilefield_operator2          | is bigger than (number)                 |
      | bo_cond_customuserprofilefield_value2             | 500                                     |
    And I press "Save"
    And I should see "Only users with custom user profile field sport set to value football are allowed to book." in the ".allbookingoptionstable_r3" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student3
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Usercustomprofile-dependent availability condition with two fields (seeded)
    Given the following "mod_booking > options" exist:
      | booking    | text                    | course | description | bo_cond_userprofilefield_2_custom_restrict | bo_cond_customuserprofilefield_field | bo_cond_customuserprofilefield_operator | bo_cond_customuserprofilefield_value | bo_cond_customuserprofilefield_connectsecondfield | bo_cond_customuserprofilefield_field2 | bo_cond_customuserprofilefield_operator2 | bo_cond_customuserprofilefield_value2 | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded profile | C1     | Deskr       | 1                                          | sport                                | =                                       | football                             | &&                                                | credit                                | >                                        | 50                                    | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    ## student3: sport football and credit 100 - allowed
    When I am on the "My booking" Activity page logged in as student3
    Then I should not see "Not allowed to book" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    ## student1: sport football but no credit - not allowed
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Configure user-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "Only specific user(s) are allowed to book" to "checked"
    And I set the field "User(s) allowed to book" to "Student 2"
    And I press "Save"
    Then I should see "Only the following users are allowed to book:" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Student 2" in the ".allbookingoptionstable_r3" "css_element"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Booking not allowed" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    Given I am on the "My booking" Activity page logged in as student2
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Max participants limit blocks further bookings (seeded)
    Given the following "mod_booking > options" exist:
      | booking    | text                  | course | description | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded limit | C1     | Deskr       | 1          | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    And the following "mod_booking > answers" exist:
      | booking    | option                | user     |
      | My booking | Option - seeded limit | student1 |
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r4" "css_element"
    When I am on the "My booking" Activity page logged in as student2
    Then I should see "Fully booked" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Participants limit with waiting list (seeded bookings, waiting list booked via UI)
    Given the following "mod_booking > options" exist:
      | booking    | text                     | course | description | maxanswers | maxoverbooking | minanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded waitlist | C1     | Deskr       | 2          | 1              | 1          | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    And the following "mod_booking > answers" exist:
      | booking    | option                   | user     |
      | My booking | Option - seeded waitlist | student1 |
      | My booking | Option - seeded waitlist | student2 |
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r4" "css_element"
    When I am on the "My booking" Activity page logged in as student3
    Then I should see "Book it - on waitinglist" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book it - on waitinglist" "text" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Click again to confirm booking on waitinglist" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Click again to confirm booking on waitinglist" "text" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "You are on the waiting list" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Admin can overbook a fully booked option (seeded)
    Given the following config values are set as admin:
       | config                    | value | plugin  |
       | allowoverbooking          | 1     | booking |
    And the following "mod_booking > options" exist:
      | booking    | text                  | course | description | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded limit | C1     | Deskr       | 1          | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    And the following "mod_booking > answers" exist:
      | booking    | option                | user     |
      | My booking | Option - seeded limit | student1 |
    When I am on the "My booking" Activity page logged in as admin
    Then I should see "Fully booked" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Combined availability conditions - opening date AND previously booked option (seeded)
    Given the following "mod_booking > options" exist:
      | booking    | text                     | course | description | availability | restrictanswerperiodopening | bookingopeningtime             | bo_cond_previouslybooked_restrict | previouslybookedoption | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded combined | C1     | Deskr       | 1            | 1                           | ## 10 March next year 12:00 ## | 1                                 | Option - dependency    | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    And the following "mod_booking > answers" exist:
      | booking    | option              | user     |
      | My booking | Option - dependency | student1 |
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Can be booked from" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Combined availability conditions - previously booked option overrides the opening date (seeded)
    Given the following "mod_booking > options" exist:
      | booking    | text                     | course | description | availability | restrictanswerperiodopening | bookingopeningtime             | bo_cond_previouslybooked_restrict | previouslybookedoption | bo_cond_previouslybooked_overrideconditioncheckbox | bo_cond_previouslybooked_overrideoperator | bo_cond_previouslybooked_overridecondition | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded combined | C1     | Deskr       | 1            | 1                           | ## 10 March next year 12:00 ## | 1                                 | Option - dependency    | 1                                                  | OR                                        | 60                                         | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    And the following "mod_booking > answers" exist:
      | booking    | option              | user     |
      | My booking | Option - dependency | student1 |
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Can be booked from" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Configure combined availability conditions - overbooking given to user
    Given the following "mod_booking > answers" exist:
      | booking    | option              | user     |
      | My booking | Option - dependency | student1 |
    ## Setup overbooking given to user
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I follow "Availability conditions"
    And I set the field "Only specific user(s) are allowed to book" to "checked"
    And I set the field "User(s) allowed to book" to "Student 2"
    And I set the field "bo_cond_selectusers_overrideconditioncheckbox" to "checked"
    And I set the field "bo_cond_selectusers_overrideoperator" to "OR"
    And I set the field with xpath "//*[contains(@id, 'fitem_id_bo_cond_selectusers_overridecondition')]//*[contains(@id, 'form_autocomplete_input')]" to "Fully booked"
    And I press "Save"
    And I should see "Fully booked" in the ".allbookingoptionstable_r3" "css_element"
    ## Check availability as student2
    When I am on the "My booking" Activity page logged in as student2
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "/ 1" in the ".allbookingoptionstable_r3 .col-ap-availableplaces" "css_element"
    And I should see "1" in the ".allbookingoptionstable_r3 .col-ap-availableplaces .text-danger" "css_element"

  @javascript
  Scenario: Invisible booking option is hidden from students (seeded)
    Given the following "mod_booking > options" exist:
      | booking    | text                      | course | description | invisible | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded invisible | C1     | Deskr       | 1         | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    When I am on the "My booking" Activity page logged in as teacher1
    Then I should see "Option - seeded invisible" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Invisible" in the ".allbookingoptionstable_r4" "css_element"
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Option - dependency" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Option - seeded invisible"
    And ".allbookingoptionstable_r4" "css_element" should not exist

  @javascript @accessibility
  Scenario: Configure availability to fill modal agreement form
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1   | select                           |
      | bo_cond_customform_label_1_1    | Choose what you agree            |
      | bo_cond_customform_notempty_1_1 | 1                                |
    And I set the field "bo_cond_customform_value_1_1" to multiline:
    """
    1 => option one
    2 => option two
    """
    And I press "Save"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    ## Validate accessibility of booking options table before booking
    And the page should meet accessibility standards
    Then I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Choose what you agree" in the ".condition-customform" "css_element"
    ## Validate accessibility of booking options table before booking
    And the page should meet accessibility standards
    And I set the field "customform_select_1" to "option one"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Validate accessibility of booking options table before booking
    And the page should meet accessibility standards

  @javascript
  Scenario: Availability with modal form and data deletion (seeded form)
    Given the following "mod_booking > options" exist:
      | booking    | text                 | course | description | bo_cond_customform_restrict | bo_cond_customform_select_1_1 | bo_cond_customform_label_1_1 | bo_cond_customform_select_1_2 | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded form | C1     | Deskr       | 1                           | shorttext                     | Personal requirement:        | deleteinfoscheckboxuser       | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    ## Check availability as students
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r4" "css_element"
    Then I should see "Personal requirement:" in the ".condition-customform" "css_element"
    And I should see "Would you like the information provided here to be deleted after the event is over?" in the ".condition-customform" "css_element"
    And I set the field "customform_shorttext_1" to "lactose-free milk"
    And I set the field "customform_deleteinfoscheckboxuser" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Option - seeded form" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r4" "css_element"
    ## Check customform value as teacher
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r4" "css_element"
    And I follow "<< Back to responses"
    And I should see "student1" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "lactose-free milk" in the "#mod_booking_all_users_sort_new_r0" "css_element"

  @javascript
  Scenario: Availability with modal form and multiple elements (seeded form)
    Given the following "mod_booking > options" exist:
      | booking    | text                 | course | description | bo_cond_customform_restrict | bo_cond_customform_select_1_1 | bo_cond_customform_label_1_1 | bo_cond_customform_value_1_1 | bo_cond_customform_select_1_2 | bo_cond_customform_label_1_2 | bo_cond_customform_value_1_2 | bo_cond_customform_notempty_1_2 | bo_cond_customform_select_1_3 | bo_cond_customform_label_1_3 | bo_cond_customform_value_1_3 | bo_cond_customform_notempty_1_3 | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded form | C1     | Deskr       | 1                           | static                        | Static lavel                 | Static text                  | url                           | Provide URL:                 | Provide a valid URL          | 1                               | mail                          | Provide email:               | Provide a valid email        | 1                               | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    ## Check availability as students
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r4" "css_element"
    Then I should see "Static lavel" in the ".condition-customform" "css_element"
    And I should see "Static text" in the ".condition-customform" "css_element"
    And I should see "Provide URL:" in the ".condition-customform" "css_element"
    And I should see "Provide email:" in the ".condition-customform" "css_element"
    ## Chack form validation
    And I follow "Continue"
    And I should see "The URL is not valid or does not start with http or https." in the ".condition-customform" "css_element"
    And I should see "The email address is invalid." in the ".condition-customform" "css_element"
    And I set the field "customform_url_2" to "https://test.com"
    And I set the field "customform_mail_3" to "test@test.com"
    And I follow "Continue"
    And I should see "You have successfully booked Option - seeded form" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r4" "css_element"
    ## Check customform value as teacher
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r4" "css_element"
    And I follow "<< Back to responses"
    And I should see "student1" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "https://test.com" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "test@test.com" in the "#mod_booking_all_users_sort_new_r0" "css_element"

  @javascript
  Scenario: Availability to fill inline agreement form (seeded form)
    Given the following config values are set as admin:
       | config                 | value | plugin  |
       | turnoffmodals          | 1     | booking |
    And the following "mod_booking > options" exist:
      | booking    | text                 | course | description | bo_cond_customform_restrict | bo_cond_customform_select_1_1 | bo_cond_customform_label_1_1 | bo_cond_customform_notempty_1_1 | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded form | C1     | Deskr       | 1                           | advcheckbox                   | Confirm your intention       | 1                               | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    ## Check availability as students
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r4" "css_element"
    Then I should see "Confirm your intention" in the ".allbookingoptionstable_r4 .condition-customform" "css_element"
    And I set the field "customform_advcheckbox_1" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Option - seeded form" in the ".allbookingoptionstable_r4 .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r4" "css_element"

  @javascript
  Scenario: Option availability: check users cohort settings
    Given the following config values are set as admin:
      | config                   | value | plugin  |
      | usesqlfilteravailability | 1     | booking |
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "User is enrolled in certain cohort(s)" to "checked"
    ## Teacher: hide unavailable option and require both cohort membership
    And I set the following fields to these values:
      | Cohort(s)                                    | System booking cohort 1,System booking cohort 2 |
      | bo_cond_enrolledincohorts_cohortids_operator | User has to be member of all cohorts            |
      | bo_cond_enrolledincohorts_sqlfiltercheck     | 1                                               |
    And I press "Save"
    ## Check availability as students - only student3 supposed to see
    When I am on the "My booking" Activity page logged in as student1
    Then I should not see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I am on the "My booking" Activity page logged in as student2
    And I should not see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I am on the "My booking" Activity page logged in as student3
    And I should see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Option availability: at least one cohort membership without sql filter (seeded)
    Given the following config values are set as admin:
      | config                   | value | plugin  |
      | usesqlfilteravailability | 1     | booking |
    And the following "mod_booking > options" exist:
      | booking    | text                    | course | description | bo_cond_enrolledincohorts_restrict | cohorts   | bo_cond_enrolledincohorts_cohortids_operator | bo_cond_enrolledincohorts_sqlfiltercheck | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option - seeded cohorts | C1     | Deskr       | 1                                  | SBC1,SBC2 | OR                                           | 0                                        | 1           | 0              | 0              | ## +6 days ##     | ## +7 days ##   |
    ## student1 is in no cohort: option visible, booking blocked
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Option - seeded cohorts" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Booking not allowed because you are not enrolled in at least one of the following cohort(s): System booking cohort 1, System booking cohort 2" in the ".allbookingoptionstable_r4" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r4" "css_element"
    ## student2 is in one cohort: allowed
    And I am on the "My booking" Activity page logged in as student2
    And I should see "Option - seeded cohorts" in the ".allbookingoptionstable_r4" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r4" "css_element"

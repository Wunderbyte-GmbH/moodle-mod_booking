@mod @mod_booking @bookingextension @bookingextension_confirmation_supervisor @confirmation_suprevisor-confirmation_by_hr_supervisor_deputy
Feature: In a course add a booking option and manage waiting list wiht HR, supreviser and deputy confirmations
  As an administrator I create a booking option wiht supreviser confirmation of waiting list
  I need to approve student on waiting list as HR, supreviser and deputy in different combinations

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname   | name       |
      | text     | supervisor  | Supervisor |
      | text     | deputy      | Deputy     |
    And the following "users" exist:
      | username    | firstname  | lastname | email                   | idnumber | profile_field_supervisor | profile_field_deputy |
      | teacher1    | Teacher    | 1        | teacher1@example.com    | T1       |                          | |
      | hr1         | HR         | 1        | hr1@example.com         | HR1      |                          | |
      | hr2         | HR         | 2        | hr2@example.com         | HR2      |                          | |
      | supervisor1 | Superviser | 1        | supervisor1@example.com | SP1      |                          | |
      | supervisor2 | Superviser | 2        | supervisor2@example.com | SP1      |                          | |
      | deputy1     | Deputy     | 1        | deputy1@example.com     | DE1      |                          | |
      | deputy2     | Deputy     | 2        | deputy2@example.com     | DE2      |                          | |
      | student1    | Student    | 1        | student1@example.com    | ST1      |                          | |
      | student2    | Student    | 2        | student2@example.com    | ST2      |                          | |
      | student3    | Student    | 3        | student3@example.com    | ST3      |                          | |
      | student4    | Student    | 4        | student4@example.com    | ST4      |                          | |
      | student5    | Student    | 5        | student5@example.com    | ST5      |                          | |
    And I clean booking cache
    And I set userids "supervisor1" as value of profilefield "supervisor" for user "student1"
    And I set userids "supervisor2" as value of profilefield "supervisor" for user "student2"
    And I set userids "supervisor2" as value of profilefield "supervisor" for user "student3"
    And I set userids "deputy1,deputy2" as value of profilefield "deputy" for user "supervisor1"
    And I set userids "deputy1" as value of profilefield "deputy" for user "supervisor2"
    And the following config values are set as admin:
      | config                          | value      | plugin                                   |
      | confirmationtrainerenabled      |            | bookingextension_confirmation_trainer    |
      | confirmationsupervisorenabled   | 1          | bookingextension_confirmation_supervisor |
      | supervisor                      | supervisor | bookingextension_confirmation_supervisor |
      | deputy                          | deputy     | bookingextension_confirmation_supervisor |
      ## Confirmation first by HR, then supervisor
      | defaultconfirmationorder        | 2          | bookingextension_confirmation_supervisor |
    And I set userids "hr1,hr2" as config value "confirmation_supervisor_hrusers" in plugin "bookingextension_confirmation_supervisor"
    And I create custom role "approver"
    And I set the following system permissions of "approver" role:
      | capability                    | permission |
      | mod/booking:readresponses     | Allow      |
      | mod/booking:managebookedusers | Allow      |
      | mod/booking:bookforothers     | Allow      |
      | mod/booking:assigndeputies    | Allow      |
    And the following "role assigns" exist:
      | user        | role     | contextlevel | reference |
      | hr1         | approver | System       |           |
      | hr2         | approver | System       |           |
      | supervisor1 | approver | System       |           |
      | supervisor2 | approver | System       |           |
      | deputy1     | approver | System       |           |
      | deputy2     | approver | System       |           |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name           | intro                | bookingmanager | eventtype | cancancelbook |
      | booking  | C1     | ConfirmBooking | ConfirmBooking descr | teacher1       | Webinar   | 1             |
    And the following "blocks" exist:
      | blockname | contextlevel | reference   | pagetypepattern | defaultregion | defaultweight | title         | configdata                                           |
      | html      | User         | hr1         | my-index        | content       | 0             | HR1 block     | {"Tzo4OiJzdGRDbGFzcyI6Mzp7czo0OiJ0ZXh0IjtzOjM3OiI8cD5bbGlzdHRvYXBwcm92ZSBkZXB1dHlzZWxlY3Q9MV08L3A+IjtzOjU6InRpdGxlIjtzOjA6IiI7czo2OiJmb3JtYXQiO3M6MToiMSI7fQ=="} |
      | html      | User         | hr2         | my-index        | content       | 0             | HR2 block     | {"Tzo4OiJzdGRDbGFzcyI6Mzp7czo0OiJ0ZXh0IjtzOjM3OiI8cD5bbGlzdHRvYXBwcm92ZSBkZXB1dHlzZWxlY3Q9MV08L3A+IjtzOjU6InRpdGxlIjtzOjA6IiI7czo2OiJmb3JtYXQiO3M6MToiMSI7fQ=="} |
      | html      | User         | supervisor1 | my-index        | content       | 0             | Superv1 block | {"Tzo4OiJzdGRDbGFzcyI6Mzp7czo0OiJ0ZXh0IjtzOjM3OiI8cD5bbGlzdHRvYXBwcm92ZSBkZXB1dHlzZWxlY3Q9MV08L3A+IjtzOjU6InRpdGxlIjtzOjA6IiI7czo2OiJmb3JtYXQiO3M6MToiMSI7fQ=="} |
      | html      | User         | supervisor2 | my-index        | content       | 0             | Superv2 block | {"Tzo4OiJzdGRDbGFzcyI6Mzp7czo0OiJ0ZXh0IjtzOjM3OiI8cD5bbGlzdHRvYXBwcm92ZSBkZXB1dHlzZWxlY3Q9MV08L3A+IjtzOjU6InRpdGxlIjtzOjA6IiI7czo2OiJmb3JtYXQiO3M6MToiMSI7fQ=="} |
      | html      | User         | deputy1     | my-index        | content       | 0             | Deputy1 block | {"Tzo4OiJzdGRDbGFzcyI6Mzp7czo0OiJ0ZXh0IjtzOjM3OiI8cD5bbGlzdHRvYXBwcm92ZSBkZXB1dHlzZWxlY3Q9MV08L3A+IjtzOjU6InRpdGxlIjtzOjA6IiI7czo2OiJmb3JtYXQiO3M6MToiMSI7fQ=="} |
      | html      | User         | deputy2     | my-index        | content       | 0             | Deputy2 block | {"Tzo4OiJzdGRDbGFzcyI6Mzp7czo0OiJ0ZXh0IjtzOjM3OiI8cD5bbGlzdHRvYXBwcm92ZSBkZXB1dHlzZWxlY3Q9MV08L3A+IjtzOjU6InRpdGxlIjtzOjA6IiI7czo2OiJmb3JtYXQiO3M6MToiMSI7fQ=="} |
    ## configdata contains serialized string "[listtoapprove deputyselect=1]"
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking supervisor confirmation: confirm user on waiting list by HR then supervisor or deputy
    Given the following "mod_booking > options" exist:
##      | booking        | text                 | course | description  | importing | teachersforoption | waitforconfirmation | confirmationsupervisorenabled | confirmationonnotification | confirmationtrainerenabled | chooseorcreatecourse | maxanswers | maxoverbooking | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
##      | ConfirmBooking | Option: confirmation | C1     | Confirmation | 1         | teacher1          | 1                   | 2                             | 0                          |                            | 1                    | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | booking        | text           | course | description | importing | teachersforoption | waitforconfirmation | chooseorcreatecourse | maxanswers | maxoverbooking | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | ConfirmBooking | OptionConfirm1 | C1     | Confirm1    | 1         | teacher1          | 1                   | 1                    | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
      | ConfirmBooking | OptionConfirm2 | C1     | Confirm2    | 1         | teacher1          | 1                   | 1                    | 5          | 5              | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And the following config values are set as admin:
      | config                            | value | plugin  |
      | waitinglistshowplaceonwaitinglist |       | booking |
    And I am on the "ConfirmBooking" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Advanced options"
    ## Test main objective: confirmation first by HR, then supervisor (or deputy if configured)
    And I set the field "Allow confirmation by supervisor" to "Confirmation first by HR, then supervisor"
    And I press "Save"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Student 3 (student3@example.com)" "text"
    When I click on "Add" "button"
    And I click on "[data-bs-target='#accordion-item-waitinglist']" "css_element"
    # We commented out the next line because of new functionality which allow admin to confirm answers
    # on the waiting list via the canalwayscanconfirm capability.
    # And I should see "Not allowed to confirm" in the "#accordion-item-waitinglist" "css_element"
    And I log out
    ## Verify waiting list entry for student1
    And I am on the "ConfirmBooking" Activity page logged in as student1
    And I should see "Wait for confirmation" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as supervisor1, validate block on Dashboard and approve student1
    And I log in as "supervisor1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## supervisor1: Validate but cannot approve of stundet1 - HR1 must approve first
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "HR has to confirm" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I log out
    ## Login as HR2, validate block on Dashboard. Not approve students
    And I log in as "hr2"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r3" "css_element"
    And I log out
    ## Login as HR1, validate block on Dashboard and approve students
    And I log in as "hr1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## HR1: Validate and approve of stundet1
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student1 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should see "You already confirmed" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    ## HR1: Validate and approve of stundet2
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r2 .confirmbooking-username-student2 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should see "You already confirmed" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    ## HR1: Validate and approve of stundet3
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r3" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r3 .confirmbooking-username-student3 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should see "You already confirmed" in the "#optionstoconfirm_optionstoconfirm_0_r3" "css_element"
    And I log out
    ## Verify waiting list entry for student1
    And I am on the "ConfirmBooking" Activity page logged in as student1
    And I should see "Wait for confirmation" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as supervisor1, validate block on Dashboard and approve student1
    And I log in as "supervisor1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## supervisor1: Validate and approve of stundet1
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student1 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I should not see "Options to confirm"
    ##And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ##And I should see "You already confirmed" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I log out
    ## Verify booking for student1
    And I am on the "ConfirmBooking" Activity page logged in as student1
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as supervisor2, validate block on Dashboard and approve student1
    And I log in as "supervisor2"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## supervisor1: Validate and approve of stundet1
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student2 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should not see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I log out
    ## Verify booking for student2
    And I am on the "ConfirmBooking" Activity page logged in as student2
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as deputy1, validate block on Dashboard and approve student3
    And I log in as "deputy1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## Deputy1: Validate and approve of stundet2
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student3 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I should not see "Options to confirm"
    ##And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ##And I should see "You already confirmed" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I log out
    ## Verify booking for student3
    And I am on the "ConfirmBooking" Activity page logged in as student3
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out

  @javascript
  Scenario: Booking supervisor confirmation: confirm user on waiting list by supervisor or deputy
    Given the following "mod_booking > options" exist:
##      | booking        | text                 | course | description  | importing | teachersforoption | waitforconfirmation | confirmationsupervisorenabled | confirmationonnotification | confirmationtrainerenabled | chooseorcreatecourse | maxanswers | maxoverbooking | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
##      | ConfirmBooking | Option: confirmation | C1     | Confirmation | 1         | teacher1          | 1                   | 1                             | 0                          |                            | 1                    | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | booking        | text           | course | description | importing | teachersforoption | waitforconfirmation | chooseorcreatecourse | maxanswers | maxoverbooking | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | ConfirmBooking | OptionConfirm1 | C1     | Confirm1    | 1         | teacher1          | 1                   | 1                    | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
      | ConfirmBooking | OptionConfirm2 | C1     | Confirm2    | 1         | teacher1          | 1                   | 1                    | 5          | 5              | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And the following config values are set as admin:
      | config                            | value | plugin  |
      | waitinglistshowplaceonwaitinglist |       | booking |
    And I am on the "ConfirmBooking" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Advanced options"
    ## Test main objective: confirmation by supervisor (or deputy if configured)
    And I set the field "Allow confirmation by supervisor" to "Confirmation by supervisor"
    And I press "Save"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Student 3 (student3@example.com)" "text"
    When I click on "Add" "button"
    And I click on "[data-bs-target='#accordion-item-waitinglist']" "css_element"
    And I wait "30" seconds
    # We commented out the next line because of new functionality which allow admin to confirm answers
    # on the waiting list via the canalwayscanconfirm capability.
    # And I should see "Not allowed to confirm" in the "#accordion-item-waitinglist" "css_element"
    And I log out
    ## Verify waiting list entry for student2
    And I am on the "ConfirmBooking" Activity page logged in as student2
    And I should see "Wait for confirmation" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as HR1, validate that no students to approve
    And I log in as "hr1"
    And I follow "Dashboard"
    And I should not see "Options to confirm"
    And I log out
    ## Login as supervisor2, validate block on Dashboard and approve student2
    And I log in as "supervisor2"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## supervisor2: Validate and approve of stundet2
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student2 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should not see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I log out
    ## Verify booking for student2
    And I am on the "ConfirmBooking" Activity page logged in as student2
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as deputy2, validate block on Dashboard and student1 listed there
    And I log in as "deputy2"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should not see "student3@example.com"
    And I log out
    ## Login as deputy1, validate block on Dashboard and approve student1 and student3
    And I log in as "deputy1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## Deputy1: Validate and approve of stundet3
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student1 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should not see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student3 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I should not see "Options to confirm"
    And I log out
    ## Login as supervisor1, validate that no more students to approve
    And I log in as "supervisor1"
    And I follow "Dashboard"
    And I should not see "Options to confirm"
    And I log out
    ## Verify booking for student1
    And I am on the "ConfirmBooking" Activity page logged in as student1
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Verify booking for student3
    And I am on the "ConfirmBooking" Activity page logged in as student3
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out

  @javascript
  Scenario: Booking supervisor confirmation: confirm user on waiting list by HR or supervisor or deputy
    Given the following "mod_booking > options" exist:
##      | booking        | text                 | course | description  | importing | teachersforoption | waitforconfirmation | confirmationsupervisorenabled | confirmationonnotification | confirmationtrainerenabled | chooseorcreatecourse | maxanswers | maxoverbooking | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
##      | ConfirmBooking | Option: confirmation | C1     | Confirmation | 1         | teacher1          | 1                   | 5                             | 0                          |                            | 1                    | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | booking        | text           | course | description | importing | teachersforoption | waitforconfirmation | chooseorcreatecourse | maxanswers | maxoverbooking | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | ConfirmBooking | OptionConfirm1 | C1     | Confirm1    | 1         | teacher1          | 1                   | 1                    | 5          | 5              | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
      | ConfirmBooking | OptionConfirm2 | C1     | Confirm2    | 1         | teacher1          | 1                   | 1                    | 5          | 5              | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And the following config values are set as admin:
      | config                            | value | plugin  |
      | waitinglistshowplaceonwaitinglist |       | booking |
    And I am on the "ConfirmBooking" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Advanced options"
    ## Test main objective: confirmation by supervisor (or deputy if configured)
    And I set the field "Allow confirmation by supervisor" to "Confirmation by supervisor or HR"
    And I press "Save"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Student 3 (student3@example.com)" "text"
    When I click on "Add" "button"
    And I click on "[data-bs-target='#accordion-item-waitinglist']" "css_element"
    # We commented out the next line because of new functionality which allow admin to confirm answers
    # on the waiting list via the canalwayscanconfirm capability.
    # And I should see "Not allowed to confirm" in the "#accordion-item-waitinglist" "css_element"
    And I log out
    ## Verify waiting list entry for student3
    And I am on the "ConfirmBooking" Activity page logged in as student3
    And I should see "Wait for confirmation" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as HR1, validate 3 students and approve stundet3
    And I log in as "hr1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## supervisor2: Validate and approve of stundet3
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    And I should see "student3@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r3" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r3 .confirmbooking-username-student3 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    And I should not see "student3@example.com"
    And I log out
    ## Verify booking for student3
    And I am on the "ConfirmBooking" Activity page logged in as student3
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as deputy1, validate block on Dashboard (do not approve students)
    And I log in as "deputy1"
    And I follow "Dashboard"
    ##And I wait "11" seconds
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## Deputy1: Validate and approve of stundet3
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r2" "css_element"
    And I log out
    ## Login as supervisor2, validate block on Dashboard and approve student2
    And I log in as "supervisor2"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## supervisor2: Validate and approve of stundet2
    And I should see "student2@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I should not see "student3@example.com"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student2 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I should not see "Options to confirm"
    And I log out
    ## Verify booking for student2
    And I am on the "ConfirmBooking" Activity page logged in as student2
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Login as supervisor1, validate block on Dashboard and approve student1
    And I log in as "supervisor1"
    And I follow "Dashboard"
    And I click on "Options to confirm" "text" in the "#accordion-heading-optionstoconfirm" "css_element"
    ## Deputy1: Validate and approve of stundet1
    And I should see "student1@example.com" in the "#optionstoconfirm_optionstoconfirm_0_r1" "css_element"
    And I click on "#optionstoconfirm_optionstoconfirm_0_r1 .confirmbooking-username-student1 i" "css_element"
    And I wait "1" seconds
    And I click on "Book" "button" in the ".modal-footer" "css_element"
    And I should not see "Options to confirm"
    And I log out
    ## Login as deputy2, validate that no more students to approve
    And I log in as "deputy2"
    And I follow "Dashboard"
    And I should not see "Options to confirm"
    And I log out
    ## Verify booking for student1
    And I am on the "ConfirmBooking" Activity page logged in as student1
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out

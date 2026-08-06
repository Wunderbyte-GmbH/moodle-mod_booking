@mod @mod_booking @booking_report2_tracker
Feature: Use the bookings tracker (report2.php) as replacement of the old report.php
  In order to manage booked users with the modernized report
  As a manager
  I need to reach the tracker scopes, see the option info line and download the sign-in sheet

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name                |
      | text     | userpricecat  | User Price Category |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 99           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 89           | 0        | 2                 |
      | 3        | zeroprice  | Zero  | 0            | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       |                            |
      | student2 | Student   | 2        | student2@example.com | S2       |                            |
      | student3 | Student   | 3        | student3@example.com | S3       | discount1                  |
      | student4 | Student   | 4        | student4@example.com | S4       | zeroprice                  |
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher1 | C1     | manager |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
      | student4 | C1     | student |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
    And I clean booking cache
    ## The tracker tables take their columns from the responsesfields setting,
    ## the sign-in sheet its columns from signinsheetfields.
    And the following "activities" exist:
      | activity | course | name     | intro         | bookingmanager | eventtype | responsesfields                                                      | signinsheetfields  |
      | booking  | C1     | Booking1 | Booking1Descr | teacher1       | Webinar   | completed,status,notes,fullname,email,waitinglist                    | fullname,signature |
      | booking  | C1     | Booking2 | Booking2Descr | teacher1       | Webinar   | completed,status,notes,fullname,email,waitinglist,price,userpricecat | fullname,signature |
    And the following "mod_booking > options" exist:
      | booking  | text       | course | description       | useprice | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking1 | B1-Option1 | C1     | B1-Option1        | 0        | 5          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking2 | B2-Option1 | C1     | B2-Option1-price  | 1        | 10         | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And the following "mod_booking > answers" exist:
      | booking  | option     | user     |
      | Booking1 | B1-Option1 | student1 |
      | Booking1 | B1-Option1 | student2 |
    And the following "mod_booking > user purchases" exist:
      | booking   | option     | user     |
      | Booking2  | B2-Option1 | student3 |
      | Booking2  | B2-Option1 | student4 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking report2: switch the system scope between aggregated options and single answers
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B2-Option1" in the "#booked_system_0_r1" "css_element"
    And I should see "B1-Option1" in the "#booked_system_0_r2" "css_element"
    And I should see "2/10" in the "#booked_system_0_r1" "css_element"
    And I should see "2/5" in the "#booked_system_0_r2" "css_element"
    ## Switch to the non-aggregated answers view.
    When I click on "View all bookings separately" "link"
    And I wait until the page is ready
    Then I should see "student1@example.com"
    And I should see "student2@example.com"
    And I should see "student3@example.com"
    And I should see "student4@example.com"
    ## And back to the aggregated options view.
    When I click on "Aggregate bookings for each booking option" "link"
    And I wait until the page is ready
    And I should see "2/10" in the "#booked_system_0_r1" "css_element"
    Then I should see "2/5" in the "#booked_system_0_r2" "css_element"

  @javascript
  Scenario: Booking report2: switch from system scope to option scope and view preconfigured additional fields
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B2-Option1" in the "#booked_system_0_r1" "css_element"
    And I follow "B2-Option1"
    And I switch to a second window
    And I should see "Manage bookings for Booking option: \"B2-Option1\""
    And I should see "2 of 2 records found" in the "#accordion-item-bookedusers .wb-records-count-label" "css_element"
    And I should see "Email" in the "#accordion-item-bookedusers .wunderbyte-table-table" "css_element"
    And I should see "Price" in the "#accordion-item-bookedusers .wunderbyte-table-table" "css_element"
    And I should see "On waiting list" in the "#accordion-item-bookedusers .wunderbyte-table-table" "css_element"
    And I should see "User Price Category" in the "#accordion-item-bookedusers .wunderbyte-table-table" "css_element"
    And I should see "student3@example.com" in the "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "student4@example.com" in the "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')]" "xpath_element"
    And I should see "89.00" in the "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "0.00" in the "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')]" "xpath_element"
    And I should see "discount1" in the "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "zeroprice" in the "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')]" "xpath_element"

  @javascript
  Scenario: Booking report2: manage completion status in the option scope
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B2-Option1" in the "#booked_system_0_r1" "css_element"
    And I follow "B2-Option1"
    And I switch to a second window
    And I should see "Manage bookings for Booking option: \"B2-Option1\""
    ## Validate - no completions
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')]/td[@data-label='completed' and normalize-space(.)='']" "xpath_element" should exist
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')]/td[@data-label='completed' and normalize-space(.)='']" "xpath_element" should exist
    ## Select 2nd row, toggle completion status and validate the change.
    And I click on "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')][.//td[@data-label='email' and normalize-space(.)='student4@example.com']]//td[@data-label='wbcheckbox']//input[@type='checkbox']" "xpath_element"
    And I click on "Toggle completion status" "link"
    And I wait until the page is ready
    And I should see "Toggle completion status" in the "div[data-region='modal']" "css_element"
    And I click on "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Toggle completion status']]//button[@data-action='save' and normalize-space(.)='Apply']" "xpath_element"
    And I wait until the page is ready
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')][.//td[@data-label='email' and normalize-space(.)='student4@example.com']]//td[@data-label='completed']//i[contains(@class, 'fa-check-square') and @aria-label='Completed']" "xpath_element" should exist
    ## Select all records, toggle completion status and validate the changes.
    And I click on "//table[starts-with(@id, 'booked_option_')]//thead//input[contains(@class, 'tableheadercheckbox') and @type='checkbox']" "xpath_element"
    # Toggle completion for both selected records.
    And I click on "//a[@data-methodname='toggle_completion_booking_answers' and normalize-space(.)='Toggle completion status']" "xpath_element"
    # Confirm the operation in the displayed modal.
    And I should see "Toggle completion status" in the "div[data-region='modal']" "css_element"
    And I click on "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Toggle completion status']]//button[@data-action='save' and normalize-space(.)='Apply']" "xpath_element"
    And I wait until the page is ready
    # The first row must now be completed.
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')]//td[@data-label='completed']//i[contains(concat(' ', normalize-space(@class), ' '), ' fa-check-square ') and @aria-label='Completed']" "xpath_element" should exist
    # The second row must now have an empty completion cell.
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')]//td[@data-label='completed' and normalize-space(.)='' and not(.//i)]" "xpath_element" should exist

  @javascript
  Scenario: Booking report2: manage presence status in the option scope
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B2-Option1" in the "#booked_system_0_r1" "css_element"
    And I follow "B2-Option1"
    And I switch to a second window
    And I should see "Manage bookings for Booking option: \"B2-Option1\""
    # 1. Verify that Presence is "No status." for both records.
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')][.//td[@data-label='email' and normalize-space(.)='student3@example.com']]//td[@data-label='status' and contains(normalize-space(.), 'No status')]" "xpath_element" should exist
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')][.//td[@data-label='email' and normalize-space(.)='student4@example.com']]//td[@data-label='status' and contains(normalize-space(.), 'No status')]" "xpath_element" should exist
    # 2. Select the second record.
    And I click on "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')][.//td[@data-label='email' and normalize-space(.)='student4@example.com']]//td[@data-label='wbcheckbox']//input[@type='checkbox']" "xpath_element"
    # 3. Open the Presence action.
    And I click on "//a[@data-formname='mod_booking\form\optiondates\modal_change_status' and normalize-space(.)='Presence']" "xpath_element"
    # 4. In the modal, select Attending and save.
    And I should see "Change presence status" in the "div[data-region='modal']" "css_element"
    And I set the field "status" to "Attending"
    And I click on "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Change presence status']]//button[@data-action='save' and normalize-space(.)='Save']" "xpath_element"
    And I wait until the page is ready
    # 5. Verify that the second record is Attending.
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')][.//td[@data-label='email' and normalize-space(.)='student4@example.com']]//td[@data-label='status' and normalize-space(.)='Attending']" "xpath_element" should exist
    # The first record must still have its original status.
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')][.//td[@data-label='email' and normalize-space(.)='student3@example.com']]//td[@data-label='status' and contains(normalize-space(.), 'No status')]" "xpath_element" should exist
    # 6. Select all records using the table header checkbox.
    And I click on "//table[starts-with(@id, 'booked_option_')]//thead//input[@type='checkbox' and contains(concat(' ', normalize-space(@class), ' '), ' tableheadercheckbox ')]" "xpath_element"
    # 7. Open the Presence action again.
    And I click on "//a[@data-formname='mod_booking\form\optiondates\modal_change_status' and normalize-space(.)='Presence']" "xpath_element"
    # 8. In the modal, select Complete and save.
    And I should see "Change presence status" in the "div[data-region='modal']" "css_element"
    And I set the field "status" to "Complete"
    And I click on "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Change presence status']]//button[@data-action='save' and normalize-space(.)='Save']" "xpath_element"
    And I wait until the page is ready
    # 9. Verify that both records have Presence set to Complete.
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r1')][.//td[@data-label='email' and normalize-space(.)='student3@example.com']]//td[@data-label='status' and normalize-space(.)='Complete']" "xpath_element" should exist
    And "//tr[contains(@id, 'booked_option_') and contains(@id, '_r2')][.//td[@data-label='email' and normalize-space(.)='student4@example.com']]//td[@data-label='status' and normalize-space(.)='Complete']" "xpath_element" should exist

  @javascript
  Scenario: Booking report2: manage messages in the option scope
    Given I log in as "admin"
    And I visit "/mod/booking/report2.php"
    And I should see "B2-Option1" in the "#booked_system_0_r1" "css_element"
    And I follow "B2-Option1"
    And I switch to a second window
    And I should see "Manage bookings for Booking option: \"B2-Option1\""
    # 1. Expand the collapsed "Show messages" section.
    And I click on "Show messages" "text" in the "#accordion-heading-sentmessages" "css_element"
    And "//*[@id='accordion-item-sentmessages' and contains(concat(' ', normalize-space(@class), ' '), ' show ')]" "xpath_element" should exist
    # 2. Verify that the messages section is initially empty.
    And I should see "No records found." in the "accordion-item-sentmessages" "region"
    # Select both booking records. Both records must be selected because the modal
    # is expected to contain Student 3 and Student 4 as recipients.
    And I click on "//table[starts-with(@id, 'booked_option_')]//thead//input[@type='checkbox' and contains(concat(' ', normalize-space(@class), ' '), ' tableheadercheckbox ')]" "xpath_element"
    # 3. Open the custom-email modal.
    And I click on "//a[@data-formname='mod_booking\form\modal_send_custom_message' and normalize-space(.)='Send custom email']" "xpath_element"
    And I wait until the page is ready
    # 4. Verify the modal and both selected recipients.
    And I should see "Send custom email" in the "div[data-region='modal']" "css_element"
    And "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Send custom email']]//*[@role='option' and @aria-selected='true' and contains(normalize-space(.), 'Student 3')]" "xpath_element" should exist
    And "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Send custom email']]//*[@role='option' and @aria-selected='true' and contains(normalize-space(.), 'Student 4')]" "xpath_element" should exist
    # Fill in the message.
    And I set the field "Subject" to "Behat custom message subject"
    And I set the field "Message" to "Behat custom message body"
    # Send the message.
    And I click on "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Send custom email']]//button[@data-action='save' and normalize-space(.)='Send message']" "xpath_element"
    And I wait until the page is ready
    # TODO: page reload is necessary in order to load messages.
    And I reload the page
    And I click on "Show messages" "text" in the "#accordion-heading-sentmessages" "css_element"
    # 5. Verify that exactly two message records are displayed.
    And I should see "2 of 2 messages found" in the "accordion-item-sentmessages" "region"
    # Verify the messages for students 3 and 4.
    And "//*[@id='accordion-item-sentmessages']//tr[.//td[@data-label='relateduserid' and normalize-space(.)='Student 3'] and .//td[@data-label='eventname' and normalize-space(.)='Message sent'] and .//td[@data-label='description']//a[contains(normalize-space(.), 'Behat custom message subject')] and .//td[@data-label='description']//div[contains(concat(' ', normalize-space(@class), ' '), ' collapse ')]//*[contains(normalize-space(.), 'Behat custom message body')]]" "xpath_element" should exist
    And "//*[@id='accordion-item-sentmessages']//tr[.//td[@data-label='relateduserid' and normalize-space(.)='Student 4'] and .//td[@data-label='eventname' and normalize-space(.)='Message sent'] and .//td[@data-label='description']//a[contains(normalize-space(.), 'Behat custom message subject')] and .//td[@data-label='description']//div[contains(concat(' ', normalize-space(@class), ' '), ' collapse ')]//*[contains(normalize-space(.), 'Behat custom message body')]]" "xpath_element" should exist

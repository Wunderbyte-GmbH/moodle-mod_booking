@mod @mod_booking @booking_multisessions @booking_duplicate_option
Feature: In a booking create booking option with multiple custom options
  As an admin
  I need to duplicate booking option with multiple custom options

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
      | rcp1     | RCP       | 1        | rcp1@example.com     | RCP1     |
      | rcp2     | RCP       | 2        | rcp2@example.com     | RCP2     |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | teacher        |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | rcp1     | C1     | editingteacher |
      | rcp2     | C1     | teacher        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "custom field categories" exist:
      | name     | component   | area    | itemid |
      | SportArt | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name   | category | type | shortname | configdata[defaultvalue] |
      | Sport1 | SportArt | text | spt1      | defsport1                |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier    | name          | defaultvalue | disabled | pricecatsortorder |
      | 1        | default       | Base Price    | 70           | 0        | 1                 |
      | 2        | specialprice  | Special Price | 60           | 0        | 2                 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Duplication of booking option with teachers and responsible contacts
    ## To cover an issue reported in #551
    Given the following "mod_booking > options" exist:
      | booking    | text               | course | description | teachersforoption | responsiblecontact |
      | My booking | Duplication source | C1     | Source      | teacher1,teacher2 | rcp1,rcp2          |
    And I am on the "My booking" Activity page logged in as teacher1
    And I should see "Duplication source" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name | Test option - Copy1 |
    ## And I should see "Teacher 1" in the "//div[contains(@id, 'id_bookingoptionteachers_')]//span[contains(@class, 'user-suggestion')]" "xpath_element"
    When I press "Save"
    Then I should see "Test option - Copy1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Teacher 1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Teacher 2" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "RCP 1" in the ".allbookingoptionstable_r2 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    And I should see "RCP 2" in the ".allbookingoptionstable_r2 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"

  @javascript
  Scenario: Duplication of booking option with course
    Given the following config values are set as admin:
      | config                      | value | plugin  |
      | duplicatemoodlecourses      | 1     | booking |
    And the following "mod_booking > options" exist:
      | booking    | text               | description | teachersforoption | chooseorcreatecourse | course |
      | My booking | Duplication source | Source      | teacher1          | 1                    | C1     |
    And I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name | Duplication - Copy1 |
    And I press "Save"
    And I trigger cron
    And I am on "Course 1 (copy)" course homepage
    And I follow "My booking"
    Then I should see "Duplication - Copy1"

  @javascript
  Scenario: Duplicate booking option with multiple customized settings
    Given the following config values are set as admin:
      | timezone      | Europe/London |
      | forcetimezone | Europe/London |
    And the following "mod_booking > options" exist:
      | booking    | titleprefix | text              | annotation            | description         | teachersforoption | chooseorcreatecourse | course | maxanswers | maxoverbooking | minanswers | pollurl        | pollurlteachers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | addtocalendar | institution | useprice | customfield_spt1 | notificationtext              | beforebookedtext      | beforecompletedtext  |
      | My booking | MIB         | Topic: Statistics | Statistics for medics | Class om Statistics | teacher1          | 1                    | C1     | 10         | 5              | 3          | https://pu.com | https://tpu.com | 0              | 1              | 2529738000        | 2529856800      | 1             | TNMU        | 1        | tenis            | Advanced notification message | Before booked message | After booked message |
    ## March 1, 2050, 9:00 AM - March 2, 2050, 6:00 PM
    And the following "mod_booking > prices" exist:
      | itemname          | area   | pricecategoryidentifier | price | currency |
      | Topic: Statistics | option | default                 | 75    | EUR      |
      | Topic: Statistics | option | specialprice            | 65    | EUR      |
    And I am on the "My booking" Activity page logged in as teacher1
    ## Create a copy
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Booking option name" to "Topic: Statistics - Copy 1"
    And I press "Save"
    And I wait "1" seconds
    ## Verify copy and its options
    Then I should see "Topic: Statistics - Copy 1" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I expand all fieldsets
    And I wait "1" seconds
    And I should see "Course 1" in the "//div[contains(@id, 'fitem_id_courseid_')]//span[contains(@class, 'course-suggestion')]" "xpath_element"
    And I should see "Teacher 1" in the "//fieldset[contains(@id, 'id_bookingoptionteachers_')]" "xpath_element"
    ## And I should see "Teacher 1" in the "//div[contains(@id, 'fitem_id_teachersforoption_')]//div[contains(@id, 'form_autocomplete_selection-')]" "xpath_element"
    ## And I should see "TNMU" in the "//div[contains(@id, 'fitem_id_institution_')]//div[contains(@id, 'form_autocomplete_selection-')]" "xpath_element"
    And I should see "TNMU" in the "//div[contains(@id, 'fitem_id_institution_')]" "xpath_element"
    And I should see "March" in the "//span[@aria-controls='booking_optiondate_collapse1']" "xpath_element"
    And I should see "1 March 2050, 9:00 AM" in the "#booking_optiondate_1" "css_element"
    And I should see "2 March 2050, 6:00 PM" in the "#booking_optiondate_1" "css_element"
    And the field "Prefix" matches value "MIB"
    And the field "Booking option name" matches value "Topic: Statistics - Copy 1"
    And the field "Description" matches value "Class om Statistics"
    And the field "Internal annotation" matches value "Statistics for medics"
    And the field "Max. number of participants" matches value "10"
    And the field "Max. number of places on waiting list" matches value "5"
    And the field "Min. number of participants" matches value "3"
    And the field "Poll url" matches value "https://pu.com"
    And the field "Teachers poll url" matches value "https://tpu.com"
    And the field "daystonotify_1" matches value "1"
    And the field "chooseorcreatecourse" matches value "Connected Moodle course"
    And the field "bookingprice_default" matches value "75"
    And the field "bookingprice_specialprice" matches value "65"
    And the field "customfield_spt1" matches value "tenis"
    And the field "Notification message" matches value "Advanced notification message"
    And the field "Before booked" matches value "Before booked message"
    And the field "After booked" matches value "After booked message"
    ## ABOVE APPROACH 10 TIMES FASTER FOR DATE-TIME FIELDS!
    ##And the following fields match these values:
    ##  | Prefix                                | MIB                           |
    ##  | Booking option name                   | Topic: Statistics - Copy 1    |
    ##  | Description                           | Class om Statistics           |
    ##  | Internal annotation                   | Statistics for medics         |
    ##  | Max. number of participants           | 10                            |
    ##  | Max. number of places on waiting list | 5                             |
    ##  | Min. number of participants           | 3                             |
    ##  | Poll url                              | https://pu.com                |
    ##  | Teachers poll url                     | https://tpu.com               |
    ##  | chooseorcreatecourse                  | Connected Moodle course       |
    ##  | bookingprice_default                  | 75                            |
    ##  | bookingprice_specialprice             | 65                            |
    ##  | customfield_spt1                      | tenis                         |
    ##  | Notification message                  | Advanced notification message |
    ##  | Before booked                         | Before booked message         |
    ##  | After booked                          | After booked message          |
    ##  | coursestarttime_1[day]                | 1                             |
    ##  | coursestarttime_1[month]              | March                         |
    ##  | coursestarttime_1[year]               | 2050                          |
    ##  | coursestarttime_1[hour]               | 09                            |
    ##  | coursestarttime_1[minute]             | 00                            |
    ##  | courseendtime_1[day]                  | 2                             |
    ##  | courseendtime_1[month]                | March                         |
    ##  | courseendtime_1[year]                 | 2050                          |
    ##  | courseendtime_1[hour]                 | 18                            |
    ##  | courseendtime_1[minute]               | 00                            |
    ##  | daystonotify_1                        | 1                             |
    And I log out

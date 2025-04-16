@mod @mod_booking @booking_actions @mod_booking_actions
Feature: Create booking action as admin and ensure they are working as student.

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
      | Course 2 | C2        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher1 | C1     | manager |
      | teacher2 | C2     | manager |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C2     | student |
      | student4 | C2     | student |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name     | intro         | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | Booking1 | Booking1Descr | teacher1       | Webinar   | All bookings                     |
      | booking  | C2     | Booking2 | Booking2Descr | teacher2       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking   | text       | course | description | useprice | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking1  | B1-Option1 | C1     | B1-Option1  | 0        | 5          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking1  | B1-Option2 | C1     | B1-Option2  | 0        | 6          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
      | Booking2  | B2-Option1 | C1     | B2-Option1  | 0        | 5          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking2  | B2-Option2 | C1     | B2-Option2  | 0        | 6          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    ## Unfortunately, TinyMCE is slow and has misbehavior which might cause number of site-wide issues. So - we disable it.
    And the following config values are set as admin:
      | config        | value         | plugin      |
      | texteditors   | atto,textarea |             |
    ## Set test objective settings
      | showboactions | 1             | booking     |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking actions: create settings for booking action via UI as a teacher and edit it
    Given I am on the "Booking1" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    ##And I follow "Actions after booking [EXPERIMENTAL]"
    And I expand all fieldsets
    And I click on "Add action" "text"
    And I wait "1" seconds
    And I set the following fields to these values:
      | action_type                             | Book options                             |
      | boactionname                            | Book more options                        |
      | Book into other booking options as well |B1-Option2 (Booking1,B2-Option2 (Booking2 |
      | Handle restrictions of these options    | Only book if seats are available         |
    And I click on "Save changes" "button"
    And I wait "2" seconds
    ##And I follow "Actions after booking [EXPERIMENTAL]"
    And I expand all fieldsets
    And I should see "Book more options" in the ".booking-actions-list" "css_element"
    And I click on "Edit" "text" in the ".booking-actions-list" "css_element"
    And I wait "1" seconds
    And I set the field "Name of action" to "Book other options"
    And I click on "Save changes" "button"
    And I wait "2" seconds
    ##And I follow "Actions after booking [EXPERIMENTAL]"
    And I expand all fieldsets
    And I should see "Book other options" in the ".booking-actions-list" "css_element"

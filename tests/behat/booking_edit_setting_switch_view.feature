@mod @mod_booking @booking_edit_setting_switch_view
Feature: Edit booking's settings for the view swithing as a teacher and use it as a student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking    | text    | course | description | teachersforoption |
      | My booking | Option1 | C1     | Deskr 1     | teacher1          |
      | My booking | Option2 | C1     | Deskr 2     | teacher1          |
      | My booking | Option3 | C1     | Deskr 3     | teacher1          |
      | My booking | Option4 | C1     | Deskr 4     | teacher1          |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking settings - display template view switcher and use it
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I set the field "Users can switch between views" to "checked"
    And I press "Save and display"
    ## Validate Cards view
    And I set the field "wbtabletemplateswitcher" to "Cards view"
    And I wait "1" seconds
    And ".allbookingoptionstable .mod-booking-view-card-image-and-body-area" "css_element" should exist
    And ".allbookingoptionstable .allbookingoptionstable_r1" "css_element" should not exist
    ## Validate List view with image on the left
    And I set the field "wbtabletemplateswitcher" to "List view with image on the left"
    And I wait "1" seconds
    And ".allbookingoptionstable .allbookingoptionstable_r1 .mod-booking-view-list-image.rounded-left" "css_element" should exist
    ## Validate List view with image on the right
    And I set the field "wbtabletemplateswitcher" to "List view with image on the right"
    And I wait "1" seconds
    And ".allbookingoptionstable .allbookingoptionstable_r1 .mod-booking-view-list-image.rounded-right" "css_element" should exist
    ## Validate List view with image on the left over half the width
    And I set the field "wbtabletemplateswitcher" to "List view with image on the left over half the width"
    And I wait "1" seconds
    And ".allbookingoptionstable .allbookingoptionstable_r1 .mod-booking-view-list-image-half" "css_element" should exist
    And I log out
    ## Change view for student1
    And I am on the "My booking" Activity page logged in as student1
    And I set the field "wbtabletemplateswitcher" to "Cards view"
    And I wait "1" seconds
    And ".allbookingoptionstable .mod-booking-view-card-image-and-body-area" "css_element" should exist
    And I log out
    ## Change view for student2
    And I am on the "My booking" Activity page logged in as student2
    And I set the field "wbtabletemplateswitcher" to "List view with image on the right"
    And I wait "1" seconds
    And ".allbookingoptionstable .allbookingoptionstable_r1 .mod-booking-view-list-image.rounded-right" "css_element" should exist
    ## Validate if view settings were preserved for teacher1
    And I log out
    And I am on the "My booking" Activity page logged in as teacher1
    And ".allbookingoptionstable .allbookingoptionstable_r1 .mod-booking-view-list-image-half" "css_element" should exist
    And I log out
    ## Validate if view settings were preserved for student1
    And I am on the "My booking" Activity page logged in as student1
    And ".allbookingoptionstable .mod-booking-view-card-image-and-body-area" "css_element" should exist

  @javascript
  Scenario: Booking settings - manage template view switcher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I set the field "Users can switch between views" to "checked"
    And I press "Save and display"
    And I log out
    ## Change view for student1
    And I am on the "My booking" Activity page logged in as student1
    And I set the field "wbtabletemplateswitcher" to "Cards view"
    And I wait "1" seconds
    And ".allbookingoptionstable .mod-booking-view-card-image-and-body-area" "css_element" should exist
    And I log out
    ## Remove Cards view as teacher1
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I click on "Cards view" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I press "Save and display"
    And I log out
    ## Validate default view being forced
    And I am on the "My booking" Activity page logged in as student1
    And I wait "1" seconds
    And ".allbookingoptionstable .mod-booking-view-card-image-and-body-area" "css_element" should not exist
    And ".allbookingoptionstable .wunderbyte-table-list .allbookingoptionstable_r1 .col-md-9" "css_element" should exist
    And I log out

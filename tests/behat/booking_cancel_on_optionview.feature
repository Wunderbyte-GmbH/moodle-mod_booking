@mod @mod_booking @booking_cancel_on_optionview
Feature: Cancel a booking on the booking option detail page
  As a student
  I need to see the new booking status immediately after confirming the cancellation on optionview.php
  So that I do not have to reload the page to find out whether the cancellation worked.

  ## Regression cover for the cancel confirmation on optionview.php. The detail page has no
  ## booking options table, so the table reload which happens after a cancellation cannot mask a
  ## page that was not updated.
  ## Two things have to change without the user reloading: the booking button (rendered by the JS)
  ## and the status text of the option, which only the server knows (beforebookedtext while the
  ## option is not booked, beforecompletedtext while it is). The scenarios therefore check both.
  ## They come in two flavours, differing in the one aspect which used to decide between working
  ## and silently doing nothing: whether the cancelled option has pre booking pages, because only
  ## then does the webservice answer with the prepage template instead of a plain bookit button.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And I clean booking cache
    ## No booking policy: nothing blocks the booking, so the option has no pre booking pages.
    And the following "activities" exist:
      | activity | course | name          | intro                     | bookingmanager | eventtype | cancancelbook | Default view for booking options |
      | booking  | C1     | Plain booking | Booking without prepages  | teacher1       | Webinar   | 1             | All bookings                     |
    ## The booking policy is a pre booking page, so this option is booked through the prepage modal.
    And the following "activities" exist:
      | activity | course | name           | intro                  | bookingmanager | eventtype | cancancelbook | Default view for booking options | bookingpolicy                    |
      | booking  | C1     | Policy booking | Booking with a prepage | teacher1       | Webinar   | 1             | All bookings                     | Please accept our booking policy |
    ## The two status texts are set on the option itself, so they take precedence over the ones of
    ## the instance. Which of them is shown depends on the booking status of the user, so they have
    ## to swap with the cancellation.
    And the following "mod_booking > options" exist:
      | booking        | text          | course | description   | maxanswers | beforebookedtext            | beforecompletedtext         |
      | Plain booking  | Plain option  | C1     | Plain option  | 5          | Please book this option now | You have booked this option |
      | Policy booking | Policy option | C1     | Policy option | 5          | Please book this option now | You have booked this option |
    ## The bookings are seeded, so the student arrives on the detail page already booked and the
    ## scenarios test the cancellation only. For the policy option this also means the student has
    ## not accepted the policy, so it still blocks after the cancellation - exactly as it does for a
    ## user who books, cancels and books again.
    And the following "mod_booking > answers" exist:
      | booking        | option        | user     |
      | Plain booking  | Plain option  | student1 |
      | Policy booking | Policy option | student1 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Cancel a booking of an option without pre booking pages on the option detail page
    Given I am on the "Plain booking" Activity page logged in as student1
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1" "css_element"
    ## The option title opens the detail page (optionview.php) in a second window.
    When I click on "Plain option" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I switch to a second window
    Then I should see "You have booked this option" in the ".bo_statusdescription" "css_element"
    And I should see "Undo my booking" in the ".price-card" "css_element"
    And I click on "Undo my booking" "text" in the ".price-card" "css_element"
    And I should see "Click again to confirm cancellation" in the ".price-card" "css_element"
    And I click on "Click again to confirm cancellation" "text" in the ".price-card" "css_element"
    ## Deliberately no manual reload here - the page has to bring itself up to date.
    And I wait until the page is ready
    And I should see "Book now" in the ".price-card" "css_element"
    And I should not see "Undo my booking" in the ".price-card" "css_element"
    ## The status text is rendered server side and has to follow the new booking status.
    And I should see "Please book this option now" in the ".bo_statusdescription" "css_element"
    And I should not see "You have booked this option" in the ".bo_statusdescription" "css_element"
    And I close all opened windows

  @javascript
  Scenario: Cancel a booking of an option with pre booking pages on the option detail page
    Given I am on the "Policy booking" Activity page logged in as student1
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1" "css_element"
    ## The option title opens the detail page (optionview.php) in a second window.
    When I click on "Policy option" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I switch to a second window
    Then I should see "You have booked this option" in the ".bo_statusdescription" "css_element"
    And I should see "Undo my booking" in the ".price-card" "css_element"
    And I click on "Undo my booking" "text" in the ".price-card" "css_element"
    And I should see "Click again to confirm cancellation" in the ".price-card" "css_element"
    And I click on "Click again to confirm cancellation" "text" in the ".price-card" "css_element"
    ## Deliberately no manual reload here - the page has to bring itself up to date.
    ## This is the constellation which used to end in a page that did not change at all.
    And I wait until the page is ready
    And I should see "Book now" in the ".price-card" "css_element"
    And I should not see "Undo my booking" in the ".price-card" "css_element"
    ## The status text is rendered server side and has to follow the new booking status.
    And I should see "Please book this option now" in the ".bo_statusdescription" "css_element"
    And I should not see "You have booked this option" in the ".bo_statusdescription" "css_element"
    ## The option has to be bookable through its pre booking page again:
    ## booking now has to open the policy in the prepage modal.
    And I click on "Book now" "text" in the ".price-card" "css_element"
    And I should see "Please accept our booking policy" in the ".condition-bookingpolicy-form" "css_element"
    And I close all opened windows

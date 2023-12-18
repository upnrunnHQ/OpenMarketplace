@vendor_settlements
Feature: Vendor can accept settlements
  In order to settle my settlements
  As a Vendor
  I want to visit settlements page and accept them

  Background:
    Given the store operates on a channel named "United States"
    And there is a "verified" vendor user "test@company.domain" registered in country with code "US"
    And I am logged in as "test@company.domain"

  @ui @development
  Scenario: Vendor can see settlements
    Given there is a "new" settlement with total amount of "100.00" and commission amount of "10.00"
    And there is a "accepted" settlement with total amount of "540.00" and commission amount of "74.00"
    And there is a "settled" settlement with total amount of "130.00" and commission amount of "12.71"
    When I visit the vendor settlements page
    Then I should see 3 settlements

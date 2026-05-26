Feature: Atomic counters
  As a developer
  I want to atomically increase and decrease integer values
  So that I can track counts without read-modify-write races

  Background:
    Given the cache is empty

  Scenario: increase auto-creates a counter starting from zero
    When I increase key "fresh_counter" by 7
    And I retrieve the cache item with key "fresh_counter"
    Then the retrieved value should be "7"

  Scenario: decrease auto-creates a counter starting from zero
    When I decrease key "fresh_negative" by 3
    And I retrieve the cache item with key "fresh_negative"
    Then the retrieved value should be "-3"

  Scenario: Successive increases accumulate
    When I increase key "totals" by 10
    And I increase key "totals" by 5
    And I increase key "totals" by 1
    And I retrieve the cache item with key "totals"
    Then the retrieved value should be "16"

  Scenario: decrease subtracts from a previously increased counter
    When I increase key "balance" by 100
    And I decrease key "balance" by 40
    And I retrieve the cache item with key "balance"
    Then the retrieved value should be "60"

  Scenario: increase with a negative argument behaves like decrease
    When I increase key "swing" by 20
    And I increase key "swing" by -5
    And I retrieve the cache item with key "swing"
    Then the retrieved value should be "15"

  Scenario: decrease with a negative argument behaves like increase
    When I increase key "ups_and_downs" by 5
    And I decrease key "ups_and_downs" by -10
    And I retrieve the cache item with key "ups_and_downs"
    Then the retrieved value should be "15"

  Scenario: delete resets a counter
    When I increase key "resettable" by 42
    And I delete the cache item with key "resettable"
    And I increase key "resettable" by 1
    And I retrieve the cache item with key "resettable"
    Then the retrieved value should be "1"

Feature: TTL semantics
  As a developer
  I want to control how long entries live in the cache
  So that I can let stale data expire automatically

  Background:
    Given the cache is empty

  # ---------- Positive expiry ----------

  Scenario: Entry is readable before its TTL elapses
    Given I set a cache item with key "fresh" and value "still_here" with TTL 5 seconds
    When I retrieve the cache item with key "fresh"
    Then the retrieved value should be "still_here"

  Scenario: Entry disappears after its TTL elapses
    Given I set a cache item with key "shortlived" and value "gone_soon" with TTL 1 seconds
    When I wait 2 seconds
    And I retrieve the cache item with key "shortlived"
    Then the retrieved value should be null

  Scenario: Re-setting a key replaces both value and TTL
    Given I set a cache item with key "renew" and value "old" with TTL 1 seconds
    And I set a cache item with key "renew" and value "new" with TTL 5 seconds
    When I wait 2 seconds
    And I retrieve the cache item with key "renew"
    Then the retrieved value should be "new"

  Scenario: has() returns false after expiry
    Given I set a cache item with key "expiring" and value "x" with TTL 1 seconds
    When I wait 2 seconds
    Then the cache should not contain key "expiring"

  # ---------- Immediate-delete TTL values ----------

  Scenario: TTL of zero deletes the entry immediately
    Given I set a cache item with key "instant" and value "boom" with TTL 0 seconds
    Then the cache should not contain key "instant"
    When I retrieve the cache item with key "instant"
    Then the retrieved value should be null

  Scenario: Negative TTL deletes the entry immediately
    Given I set a cache item with key "past" and value "boom" with TTL -10 seconds
    Then the cache should not contain key "past"

  Scenario: TTL=0 on a previously-stored key removes it
    Given I set a cache item with key "victim" and value "live"
    And I set a cache item with key "victim" and value "live" with TTL 0 seconds
    Then the cache should not contain key "victim"

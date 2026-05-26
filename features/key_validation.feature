Feature: PSR-16 key validation
  As a developer using the cache
  I want invalid keys to be rejected with a clear error
  So that PSR-16's contract is enforced uniformly across every API

  Background:
    Given the cache is empty

  # ---------- Empty key rejection ----------

  Scenario: Empty key on get is rejected
    When I attempt to retrieve the cache item with key ""
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "non-empty"

  Scenario: Empty key on set is rejected
    When I attempt to set a cache item with key "" and value "x"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "non-empty"

  Scenario: Empty key on delete is rejected
    When I attempt to delete the cache item with key ""
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "non-empty"

  # ---------- Reserved-character rejection ----------
  # PSR-16 forbids the characters {}()/\@: in cache keys.

  Scenario Outline: Reserved characters in a get key are rejected
    When I attempt to retrieve the cache item with key "<key>"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "reserved characters"

    Examples:
      | key       |
      | brace{    |
      | brace}    |
      | paren(    |
      | paren)    |
      | slash/    |
      | back\\    |
      | at@now    |
      | colon:foo |

  Scenario Outline: Reserved characters in a set key are rejected
    When I attempt to set a cache item with key "<key>" and value "x"
    Then the operation should fail with an "InvalidArgumentException"

    Examples:
      | key      |
      | bad{key  |
      | bad@key  |
      | bad:key  |

  Scenario: Reserved characters in a tag are rejected
    Given I set a cache item with key "k" and value "v"
    When I attempt to tag key "k" with tag "bad:tag"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "reserved characters"

  Scenario: Reserved characters in a queue name are rejected
    When I attempt to enqueue "x" to queue "bad/queue"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "reserved characters"

  Scenario: Reserved characters in a tag passed to getTagged are rejected
    When I attempt to retrieve items by tag "bad@tag"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "reserved characters"

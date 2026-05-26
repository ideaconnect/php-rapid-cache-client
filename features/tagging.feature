Feature: Tagging
  As a developer
  I want to associate cache entries with arbitrary tags
  So that I can fetch or invalidate related entries in bulk

  Background:
    Given the cache is empty

  # ---------- setTagged + getTagged ----------

  Scenario: Two items sharing one tag are returned together
    Given I set a cache item with key "p1" and value "phone1" with tag "products"
    And I set a cache item with key "p2" and value "phone2" with tag "products"
    When I retrieve items by tag "products"
    Then the retrieved values should contain "p1" with value "phone1"
    And the retrieved values should contain "p2" with value "phone2"
    And the retrieved values should have 2 items

  Scenario: Tag isolation — fetching one tag doesn't bleed into another
    Given I set a cache item with key "a1" and value "apple1" with tag "fruit"
    And I set a cache item with key "a2" and value "apple2" with tag "fruit"
    And I set a cache item with key "c1" and value "car1" with tag "vehicle"
    When I retrieve items by tag "fruit"
    Then the retrieved values should contain "a1" with value "apple1"
    And the retrieved values should contain "a2" with value "apple2"
    And the retrieved values should not contain "c1"
    And the retrieved values should have 2 items

  Scenario: An expired tagged item is pruned from the tag set on read
    Given I set a cache item with key "ephemeral" and value "soon_gone" with tag "stale" with TTL 1 seconds
    And I set a cache item with key "permanent" and value "stays" with tag "stale"
    When I wait 2 seconds
    And I retrieve items by tag "stale"
    Then the retrieved values should contain "permanent" with value "stays"
    And the retrieved values should not contain "ephemeral"
    And the retrieved values should have 1 items

  # ---------- tag() / untag() ----------

  Scenario: tag() attaches a new tag to an existing key
    Given I set a cache item with key "later" and value "tagged_after"
    When I tag key "later" with tag "afterwards"
    And I retrieve items by tag "afterwards"
    Then the retrieved values should contain "later" with value "tagged_after"
    And the retrieved values should have 1 items

  Scenario: untag() removes the association but keeps the value
    Given I set a cache item with key "still_here" and value "x" with tag "removable"
    When I untag key "still_here" from tag "removable"
    And I retrieve items by tag "removable"
    Then the retrieved values should be empty
    When I retrieve the cache item with key "still_here"
    Then the retrieved value should be "x"

  Scenario: untag() on an unrelated tag is a no-op
    Given I set a cache item with key "k" and value "v" with tag "real_tag"
    When I untag key "k" from tag "phantom_tag"
    And I retrieve items by tag "real_tag"
    Then the retrieved values should contain "k" with value "v"

  Scenario: Tagging the same key with two tags makes it reachable from both
    Given I set a cache item with key "shared" and value "double" with tag "tag_left"
    When I tag key "shared" with tag "tag_right"
    And I retrieve items by tag "tag_left"
    Then the retrieved values should contain "shared" with value "double"
    When I retrieve items by tag "tag_right"
    Then the retrieved values should contain "shared" with value "double"

  Scenario: Untagging from one of two tags keeps the other association
    Given I set a cache item with key "dual" and value "v" with tag "first"
    And I tag key "dual" with tag "second"
    When I untag key "dual" from tag "first"
    And I retrieve items by tag "first"
    Then the retrieved values should be empty
    When I retrieve items by tag "second"
    Then the retrieved values should contain "dual" with value "v"

  # ---------- clearByTag ----------

  Scenario: clearByTag deletes every key under that tag
    Given I set a cache item with key "burn1" and value "x" with tag "to_burn"
    And I set a cache item with key "burn2" and value "y" with tag "to_burn"
    When I remove items by tag "to_burn"
    Then the cache should not contain key "burn1"
    And the cache should not contain key "burn2"

  Scenario: clearByTag of one tag leaves another tag's entries intact
    Given I set a cache item with key "drop1" and value "x" with tag "expendable"
    And I set a cache item with key "drop2" and value "y" with tag "expendable"
    And I set a cache item with key "keep1" and value "a" with tag "valuable"
    When I remove items by tag "expendable"
    Then the cache should not contain key "drop1"
    And the cache should not contain key "drop2"
    And the cache should contain key "keep1"

  Scenario: clearByTag on a key that also belongs to another tag also cleans the other tag's index
    Given I set a cache item with key "cross" and value "x" with tag "primary"
    And I tag key "cross" with tag "secondary"
    When I remove items by tag "primary"
    And I retrieve items by tag "secondary"
    Then the retrieved values should be empty

  Scenario: clearByTag on an unknown tag is a silent no-op
    When I remove items by tag "never_existed"
    And I retrieve items by tag "never_existed"
    Then the retrieved values should be empty

  # ---------- getTagCardinality ----------

  Scenario: getTagCardinality reports the number of keys in a tag set
    Given I set a cache item with key "c1" and value "x" with tag "counted"
    And I set a cache item with key "c2" and value "y" with tag "counted"
    And I set a cache item with key "c3" and value "z" with tag "counted"
    When I get the cardinality of tag "counted"
    Then the cardinality should be 3

  Scenario: getTagCardinality is zero for an unknown tag
    When I get the cardinality of tag "nothing_here"
    Then the cardinality should be 0

  # ---------- Negative paths ----------

  Scenario: tag() rejects a non-existent key
    When I attempt to tag key "ghost" with tag "anywhere"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "non-existing key"

  Scenario: setTagged with TTL=0 deletes immediately and skips tagging
    Given I set a cache item with key "blip" and value "x" with tag "skipped" with TTL 0 seconds
    Then the cache should not contain key "blip"
    When I retrieve items by tag "skipped"
    Then the retrieved values should be empty

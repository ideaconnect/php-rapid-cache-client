<?php

use Behat\Behat\Context\Context;
use IDCT\Cache\RapidCacheClient;
use PHPUnit\Framework\Assert;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context
{
    private RapidCacheClient $cacheService;
    private mixed $retrievedValue = null;
    /** @var array<int|string, mixed> */
    private array $retrievedValues = [];
    private int $retrievedCount = 0;
    private int $retrievedLength = 0;

    /**
     * Initializes context.
     */
    public function __construct()
    {
        $host = is_string($_ENV['REDIS_HOST'] ?? null) ? $_ENV['REDIS_HOST'] : 'localhost';
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6380);

        $this->cacheService = new RapidCacheClient($host, $port);
        $this->cacheService->clear(); // Start with clean slate
    }

    /**
     * @BeforeScenario
     */
    public function clearCache(): void
    {
        $this->cacheService->clear();
        $this->retrievedValue = null;
        $this->retrievedValues = [];
        $this->retrievedCount = 0;
        $this->retrievedLength = 0;
    }

    /**
     * @Given I set a cache item with key :key and value :value
     */
    public function iSetACacheItemWithKeyAndValue(string $key, string $value): void
    {
        $this->cacheService->set($key, $value);
    }

    /**
     * @Given I set a cache item with key :key and value :value with TTL :ttl seconds
     */
    public function iSetACacheItemWithKeyAndValueWithTtl(string $key, string $value, string $ttl): void
    {
        $this->cacheService->set($key, $value, (int) $ttl);
    }

    /**
     * @Given I set a cache item with key :key and value :value with tag :tag
     */
    public function iSetACacheItemWithKeyAndValueWithTag(string $key, string $value, string $tag): void
    {
        $this->cacheService->setTagged($key, $value, $tag);
    }

    /**
     * @Given I set a cache item with key :key and value :value with tag :tag with TTL :ttl seconds
     */
    public function iSetACacheItemWithKeyAndValueWithTagWithTtl(string $key, string $value, string $tag, string $ttl): void
    {
        $this->cacheService->setTagged($key, $value, $tag, (int) $ttl);
    }

    /**
     * @Given the cache is empty
     */
    public function theCacheIsEmpty(): void
    {
        $this->cacheService->clear();
    }

    /**
     * @When I retrieve the cache item with key :key
     */
    public function iRetrieveTheCacheItemWithKey(string $key): void
    {
        $this->retrievedValue = $this->cacheService->get($key);
    }

    /**
     * @When I retrieve items by tag :tag
     */
    public function iRetrieveItemsByTag(string $tag): void
    {
        $this->retrievedValues = [];
        foreach ($this->cacheService->getTagged($tag) as $key => $value) {
            $this->retrievedValues[$key] = $value;
        }
    }

    /**
     * @When I delete the cache item with key :key
     */
    public function iDeleteTheCacheItemWithKey(string $key): void
    {
        $this->cacheService->delete($key);
    }

    /**
     * @When I wait :seconds seconds
     */
    public function iWaitSeconds(string $seconds): void
    {
        sleep((int) $seconds);
    }

    /**
     * @When I enqueue :value to queue :queue
     */
    public function iEnqueueToQueue(string $value, string $queue): void
    {
        $this->cacheService->enqueue($queue, $value);
    }

    /**
     * @When I pop from queue :queue
     */
    public function iPopFromQueue(string $queue): void
    {
        $this->retrievedValue = $this->cacheService->pop($queue);
    }

    /**
     * @When I peek from queue :queue
     */
    public function iPeekFromQueue(string $queue): void
    {
        $this->retrievedValue = $this->cacheService->peek($queue);
    }

    /**
     * @When I peek :count items from queue :queue
     */
    public function iPeekItemsFromQueue(string $count, string $queue): void
    {
        $result = $this->cacheService->peek($queue, (int) $count);
        if (is_array($result)) {
            $this->retrievedValues = $result;
        } elseif ($result === null) {
            $this->retrievedValues = [];
        } else {
            $this->retrievedValues = [$result];
        }
    }

    /**
     * @When I get the length of queue :queue
     */
    public function iGetTheLengthOfQueue(string $queue): void
    {
        $this->retrievedLength = $this->cacheService->getQueueLength($queue);
    }

    /**
     * @When I increase key :key by :value
     */
    public function iIncreaseKeyBy(string $key, string $value): void
    {
        $this->cacheService->increase($key, (int) $value);
    }

    /**
     * @When I decrease key :key by :value
     */
    public function iDecreaseKeyBy(string $key, string $value): void
    {
        $this->cacheService->decrease($key, (int) $value);
    }

    /**
     * @When I create a set :set with values :values
     */
    public function iCreateASetWithValues(string $set, string $values): void
    {
        $valueArray = explode(',', $values);
        $this->cacheService->createSet($set, array_map('trim', $valueArray));
    }

    /**
     * @When I add :value to set :set
     */
    public function iAddToSet(string $value, string $set): void
    {
        $this->cacheService->addToSet($set, $value);
    }

    /**
     * @When I remove :value from set :set
     */
    public function iRemoveFromSet(string $value, string $set): void
    {
        $this->cacheService->removeFromSet($set, $value);
    }

    /**
     * @When I get set :set
     */
    public function iGetSet(string $set): void
    {
        $this->retrievedValues = $this->cacheService->getSet($set) ?? [];
    }

    /**
     * @When I get the cardinality of set :set
     */
    public function iGetTheCardinalityOfSet(string $set): void
    {
        $this->retrievedCount = $this->cacheService->getCardinality($set);
    }

    /**
     * @When I remove items by tag :tag
     */
    public function iRemoveItemsByTag(string $tag): void
    {
        $this->cacheService->clearByTag($tag);
    }

    /**
     * @Then the retrieved value should be :value
     */
    public function theRetrievedValueShouldBe(string $value): void
    {
        if ($value === 'null') {
            Assert::assertNull($this->retrievedValue);
        } else {
            Assert::assertEquals($value, $this->retrievedValue);
        }
    }

    /**
     * @Then the retrieved values should contain :key with value :value
     */
    public function theRetrievedValuesShouldContainWithValue(string $key, string $value): void
    {
        Assert::assertArrayHasKey($key, $this->retrievedValues);
        Assert::assertEquals($value, $this->retrievedValues[$key]);
    }

    /**
     * @Then the retrieved values should not contain :key
     */
    public function theRetrievedValuesShouldNotContain(string $key): void
    {
        Assert::assertArrayNotHasKey($key, $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should be empty
     */
    public function theRetrievedValuesShouldBeEmpty(): void
    {
        Assert::assertSame([], $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should equal :csv
     */
    public function theRetrievedValuesShouldEqual(string $csv): void
    {
        $expected = array_map('trim', explode(',', $csv));
        Assert::assertEquals($expected, $this->retrievedValues);
    }

    /**
     * @Then the queue length should be :length
     */
    public function theQueueLengthShouldBe(string $length): void
    {
        Assert::assertSame((int) $length, $this->retrievedLength);
    }

    /**
     * @Then the cardinality should be :count
     */
    public function theCardinalityShouldBe(string $count): void
    {
        Assert::assertSame((int) $count, $this->retrievedCount);
    }

    /**
     * @Then the set should contain :value
     */
    public function theSetShouldContain(string $value): void
    {
        Assert::assertContains($value, $this->retrievedValues);
    }

    /**
     * @Then the set should not contain :value
     */
    public function theSetShouldNotContain(string $value): void
    {
        Assert::assertNotContains($value, $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should have :count items
     */
    public function theRetrievedValuesShouldHaveItems(string $count): void
    {
        Assert::assertCount((int) $count, $this->retrievedValues);
    }
}

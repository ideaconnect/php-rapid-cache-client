<?php

use Behat\Behat\Context\Context;
use Praetorian\CacheService\RedisCacheService;
use PHPUnit\Framework\Assert;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context
{
    private RedisCacheService $cacheService;
    private mixed $retrievedValue = null;
    private array $retrievedValues = [];
    private int $retrievedCount = 0;
    private int $retrievedLength = 0;
    private array $queueItems = [];

    /**
     * Initializes context.
     */
    public function __construct()
    {
        $host = $_ENV['REDIS_HOST'] ?? 'localhost';
        $port = (int)($_ENV['REDIS_PORT'] ?? 6380);

        $this->cacheService = new RedisCacheService($host, $port);
        $this->cacheService->clear(); // Start with clean slate
    }

    /**
     * @BeforeScenario
     */
    public function clearCache()
    {
        $this->cacheService->clear();
        $this->retrievedValue = null;
        $this->retrievedValues = [];
        $this->retrievedCount = 0;
        $this->retrievedLength = 0;
        $this->queueItems = [];
    }

    /**
     * @Given I set a cache item with key :key and value :value
     */
    public function iSetACacheItemWithKeyAndValue($key, $value)
    {
        $this->cacheService->set($key, $value);
    }

    /**
     * @Given I set a cache item with key :key and value :value with TTL :ttl seconds
     */
    public function iSetACacheItemWithKeyAndValueWithTtl($key, $value, $ttl)
    {
        $this->cacheService->set($key, $value, null, (int)$ttl);
    }

    /**
     * @Given I set a cache item with key :key and value :value with tag :tag
     */
    public function iSetACacheItemWithKeyAndValueWithTag($key, $value, $tag)
    {
        $this->cacheService->set($key, $value, $tag);
    }

    /**
     * @Given I set a cache item with key :key and value :value with tag :tag with TTL :ttl seconds
     */
    public function iSetACacheItemWithKeyAndValueWithTagWithTtl($key, $value, $tag, $ttl)
    {
        $this->cacheService->set($key, $value, $tag, (int)$ttl);
    }

    /**
     * @Given the cache is empty
     */
    public function theCacheIsEmpty()
    {
        $this->cacheService->clear();
    }

    /**
     * @When I retrieve the cache item with key :key
     */
    public function iRetrieveTheCacheItemWithKey($key)
    {
        $this->retrievedValue = $this->cacheService->get($key);
    }

    /**
     * @When I retrieve items by tag :tag
     */
    public function iRetrieveItemsByTag($tag)
    {
        $this->retrievedValues = [];
        foreach ($this->cacheService->getTagged($tag) as $key => $value) {
            $this->retrievedValues[$key] = $value;
        }
    }

    /**
     * @When I delete the cache item with key :key
     */
    public function iDeleteTheCacheItemWithKey($key)
    {
        $this->cacheService->delete($key);
    }

    /**
     * @When I wait :seconds seconds
     */
    public function iWaitSeconds($seconds)
    {
        sleep((int)$seconds);
    }

    /**
     * @When I enqueue :value to queue :queue
     */
    public function iEnqueueToQueue($value, $queue)
    {
        $this->cacheService->enqueue($queue, $value);
    }

    /**
     * @When I pop from queue :queue
     */
    public function iPopFromQueue($queue)
    {
        $this->retrievedValue = $this->cacheService->pop($queue);
    }

    /**
     * @When I get the length of queue :queue
     */
    public function iGetTheLengthOfQueue($queue)
    {
        $this->retrievedLength = $this->cacheService->getQueueLength($queue);
    }

    /**
     * @When I increase key :key by :value
     */
    public function iIncreaseKeyBy($key, $value)
    {
        $this->cacheService->increase($key, (int)$value);
    }

    /**
     * @When I decrease key :key by :value
     */
    public function iDecreaseKeyBy($key, $value)
    {
        $this->cacheService->decrease($key, (int)$value);
    }

    /**
     * @When I create a set :set with values :values
     */
    public function iCreateASetWithValues($set, $values)
    {
        $valueArray = explode(',', $values);
        $this->cacheService->createSet($set, array_map('trim', $valueArray));
    }

    /**
     * @When I add :value to set :set
     */
    public function iAddToSet($value, $set)
    {
        $this->cacheService->addToSet($set, $value);
    }

    /**
     * @When I remove :value from set :set
     */
    public function iRemoveFromSet($value, $set)
    {
        $this->cacheService->removeFromSet($set, $value);
    }

    /**
     * @When I get set :set
     */
    public function iGetSet($set)
    {
        $this->retrievedValues = $this->cacheService->getSet($set) ?? [];
    }

    /**
     * @When I get the cardinality of set :set
     */
    public function iGetTheCardinalityOfSet($set)
    {
        $this->retrievedCount = $this->cacheService->getCardinality($set);
    }

    /**
     * @When I remove items by tag :tag
     */
    public function iRemoveItemsByTag($tag)
    {
        $this->cacheService->clearByTag($tag);
    }

    /**
     * @Then the retrieved value should be :value
     */
    public function theRetrievedValueShouldBe($value)
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
    public function theRetrievedValuesShouldContainWithValue($key, $value)
    {
        Assert::assertArrayHasKey($key, $this->retrievedValues);
        Assert::assertEquals($value, $this->retrievedValues[$key]);
    }

    /**
     * @Then the retrieved values should not contain :key
     */
    public function theRetrievedValuesShouldNotContain($key)
    {
        Assert::assertArrayNotHasKey($key, $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should be empty
     */
    public function theRetrievedValuesShouldBeEmpty()
    {
        Assert::assertEmpty($this->retrievedValues);
    }

    /**
     * @Then the queue length should be :length
     */
    public function theQueueLengthShouldBe($length)
    {
        Assert::assertEquals((int)$length, $this->retrievedLength);
    }

    /**
     * @Then the cardinality should be :count
     */
    public function theCardinalityShouldBe($count)
    {
        Assert::assertEquals((int)$count, $this->retrievedCount);
    }

    /**
     * @Then the set should contain :value
     */
    public function theSetShouldContain($value)
    {
        Assert::assertContains($value, $this->retrievedValues);
    }

    /**
     * @Then the set should not contain :value
     */
    public function theSetShouldNotContain($value)
    {
        Assert::assertNotContains($value, $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should have :count items
     */
    public function theRetrievedValuesShouldHaveItems($count)
    {
        Assert::assertCount((int)$count, $this->retrievedValues);
    }
}

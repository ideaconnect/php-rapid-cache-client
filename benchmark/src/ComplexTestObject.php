<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

use DateTime;

class ComplexTestObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public DateTime $createdAt,
        public array $metadata,
        public Address $address,
        public array $tags,
        public ?string $description = null,
        public bool $isActive = true,
        public float $score = 0.0,
        public array $preferences = []
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
            'address' => $this->address->toArray(),
            'tags' => $this->tags,
            'description' => $this->description,
            'isActive' => $this->isActive,
            'score' => $this->score,
            'preferences' => $this->preferences,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['email'],
            new DateTime($data['createdAt']),
            $data['metadata'],
            Address::fromArray($data['address']),
            $data['tags'],
            $data['description'] ?? null,
            $data['isActive'] ?? true,
            $data['score'] ?? 0.0,
            $data['preferences'] ?? []
        );
    }

    public static function generateRandom(int $id): self
    {
        $faker = [
            'names' => ['John Doe', 'Jane Smith', 'Bob Johnson', 'Alice Brown', 'Charlie Davis'],
            'domains' => ['example.com', 'test.org', 'demo.net', 'sample.io'],
            'streets' => ['Main St', 'Oak Ave', 'Elm Dr', 'Pine Rd', 'Cedar Ln'],
            'cities' => ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix'],
            'states' => ['NY', 'CA', 'IL', 'TX', 'AZ'],
            'countries' => ['USA', 'Canada', 'UK', 'Germany', 'France'],
            'tags' => ['important', 'urgent', 'verified', 'premium', 'standard', 'new', 'legacy']
        ];

        $name = $faker['names'][array_rand($faker['names'])];
        $domain = $faker['domains'][array_rand($faker['domains'])];
        $email = strtolower(str_replace(' ', '.', $name)) . '@' . $domain;

        $address = new Address(
            rand(100, 9999) . ' ' . $faker['streets'][array_rand($faker['streets'])],
            $faker['cities'][array_rand($faker['cities'])],
            $faker['states'][array_rand($faker['states'])],
            sprintf('%05d', rand(10000, 99999)),
            $faker['countries'][array_rand($faker['countries'])],
            ['lat' => rand(-90, 90) + rand(0, 100) / 100, 'lng' => rand(-180, 180) + rand(0, 100) / 100]
        );

        $selectedTags = array_rand($faker['tags'], rand(2, 5));
        if (!is_array($selectedTags)) {
            $selectedTags = [$selectedTags];
        }
        $tags = array_map(fn($idx) => $faker['tags'][$idx], $selectedTags);

        return new self(
            id: $id,
            name: $name,
            email: $email,
            createdAt: new DateTime('-' . rand(0, 365) . ' days'),
            metadata: [
                'source' => ['web', 'mobile', 'api'][array_rand(['web', 'mobile', 'api'])],
                'version' => '1.' . rand(0, 9) . '.' . rand(0, 9),
                'platform' => ['ios', 'android', 'web', 'desktop'][array_rand(['ios', 'android', 'web', 'desktop'])],
                'session_data' => [
                    'last_login' => (new DateTime('-' . rand(0, 30) . ' days'))->format('Y-m-d H:i:s'),
                    'login_count' => rand(1, 1000),
                    'ip_address' => rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255)
                ]
            ],
            address: $address,
            tags: $tags,
            description: rand(0, 1) ? 'A detailed description for object #' . $id . ' with some random content that makes it more realistic and complex.' : null,
            isActive: rand(0, 1) === 1,
            score: rand(0, 10000) / 100,
            preferences: [
                'theme' => ['light', 'dark'][array_rand(['light', 'dark'])],
                'language' => ['en', 'es', 'fr', 'de'][array_rand(['en', 'es', 'fr', 'de'])],
                'notifications' => [
                    'email' => rand(0, 1) === 1,
                    'sms' => rand(0, 1) === 1,
                    'push' => rand(0, 1) === 1
                ],
                'privacy_level' => rand(1, 5)
            ]
        );
    }
}

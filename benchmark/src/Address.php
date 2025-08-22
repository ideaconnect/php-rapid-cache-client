<?php

declare(strict_types=1);

namespace Praetorian\CacheBenchmark;

class Address
{
    public function __construct(
        public string $street,
        public string $city,
        public string $state,
        public string $zipCode,
        public string $country,
        public array $coordinates = []
    ) {}

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'state' => $this->state,
            'zipCode' => $this->zipCode,
            'country' => $this->country,
            'coordinates' => $this->coordinates,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['street'],
            $data['city'],
            $data['state'],
            $data['zipCode'],
            $data['country'],
            $data['coordinates'] ?? []
        );
    }
}

<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Address;

class NameParts
{
    public function __construct(
        private readonly string $lastname,
        private readonly string $firstname,
    ) {
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }
}

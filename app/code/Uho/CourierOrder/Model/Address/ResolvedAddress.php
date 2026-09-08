<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Address;

class ResolvedAddress
{
    public function __construct(
        private readonly string $cityRef,
        private readonly string $cityName,
        private readonly string $warehouseRef,
        private readonly string $warehouseName,
    ) {
    }

    public function getCityRef(): string
    {
        return $this->cityRef;
    }

    public function getCityName(): string
    {
        return $this->cityName;
    }

    public function getWarehouseRef(): string
    {
        return $this->warehouseRef;
    }

    public function getWarehouseName(): string
    {
        return $this->warehouseName;
    }
}

<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Reconciliation;

class PlanLine
{
    public function __construct(
        private readonly string $sku,
        private readonly int $qty,
        private readonly int $unitPriceCents,
    ) {
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }
}

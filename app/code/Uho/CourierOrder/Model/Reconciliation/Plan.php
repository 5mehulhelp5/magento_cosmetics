<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Reconciliation;

class Plan
{
    /**
     * @param PlanLine[] $lines
     */
    public function __construct(
        private readonly array $lines,
        private readonly int $adjustmentCents,
    ) {
    }

    /**
     * @return PlanLine[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getAdjustmentCents(): int
    {
        return $this->adjustmentCents;
    }
}

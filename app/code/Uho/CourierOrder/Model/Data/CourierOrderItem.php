<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Data;

use Uho\CourierOrder\Api\Data\CourierOrderItemInterface;

class CourierOrderItem implements CourierOrderItemInterface
{
    private string $sku = '';
    private float $qty = 0.0;

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function setQty(float $qty): self
    {
        $this->qty = $qty;

        return $this;
    }
}

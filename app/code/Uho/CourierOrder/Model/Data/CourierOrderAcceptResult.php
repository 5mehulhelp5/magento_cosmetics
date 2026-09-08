<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Data;

use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterface;

class CourierOrderAcceptResult implements CourierOrderAcceptResultInterface
{
    public function __construct(
        private readonly string $status,
        private readonly string $requestReference,
        private readonly ?string $orderIncrementId = null,
    ) {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestReference(): string
    {
        return $this->requestReference;
    }

    public function getOrderIncrementId(): ?string
    {
        return $this->orderIncrementId;
    }
}

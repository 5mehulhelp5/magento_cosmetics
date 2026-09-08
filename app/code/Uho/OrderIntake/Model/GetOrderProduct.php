<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model;

use Uho\OrderIntake\Api\GetOrderProductInterface;

/**
 * @todo Stub pending real catalog product mapping (spec §2) — see GetOrderProductInterface.
 */
class GetOrderProduct implements GetOrderProductInterface
{
    public function execute(string $storeCode, int $orderTotal): array
    {
        return [];
    }
}

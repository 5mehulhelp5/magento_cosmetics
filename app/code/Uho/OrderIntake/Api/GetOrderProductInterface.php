<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Api;

/**
 * Resolves catalog products for an intake order's total.
 *
 * @todo Stub pending real catalog product mapping (spec §2). Always returns an empty array today;
 *       the Phase 5 order builder falls back to two generic placeholder line items whenever this
 *       returns [], and is expected to use this list instead once a real mapping exists.
 */
interface GetOrderProductInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $storeCode, int $orderTotal): array;
}

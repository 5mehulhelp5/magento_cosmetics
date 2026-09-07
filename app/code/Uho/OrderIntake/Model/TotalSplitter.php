<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model;

/**
 * Splits a whole-currency-unit total into generic placeholder line-item amounts (spec §6), used by
 * OrderBuilder when GetOrderProductInterface has no real catalog mapping for an order.
 *
 * Normally splits into two positive whole-unit parts at a random cut point. When the total is too
 * small to produce two positive parts ($total === 1), a single one-part split is returned instead —
 * splitting into "two parts, each > 0" is mathematically impossible for a total of 1. This edge case
 * is deliberately handled here (not an oversight): the plan's Phase 6 test guidance explicitly names
 * total = 1 as a case to cover.
 */
class TotalSplitter
{
    /**
     * @return int[] One element when $total === 1, otherwise exactly two positive whole-unit parts
     *               summing to $total.
     */
    public function split(int $total): array
    {
        if ($total < 1) {
            throw new \InvalidArgumentException('Total must be a positive whole-currency-unit integer.');
        }

        if ($total === 1) {
            return [1];
        }

        $firstPart = random_int(1, $total - 1);

        return [$firstPart, $total - $firstPart];
    }
}

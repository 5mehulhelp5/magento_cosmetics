<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Reconciliation;

use Magento\Framework\Phrase;
use Uho\CourierOrder\Model\Exception\ReconciliationFailedException;

use function abs;
use function bcmul;
use function count;
use function intdiv;
use function preg_match;
use function trim;
class Planner
{
    public function __construct(
        private readonly EligibleProductPool $eligibleProductPool,
        private readonly Config $config,
        private readonly PlanFactory $planFactory,
        private readonly PlanLineFactory $lineFactory,
    ) {
    }

    public function plan(string $totalMajorUnits, int $storeId): Plan
    {
        $targetCents = $this->toCents($totalMajorUnits);
        $pool = $this->eligibleProductPool->getPool($storeId);
        $ceiling = $this->config->getAdjustmentCeilingKopecks($storeId);

        if ($pool === []) {
            throw new ReconciliationFailedException(new Phrase(
                'No eligible reconciliation products configured for store %1.',
                [$storeId]
            ));
        }

        [$lines, $remaining] = $this->greedyPass($pool, $targetCents);

        if ($remaining > $ceiling) {
            [$searchLines, $searchRemaining] = $this->boundedSearch($pool, $targetCents, $ceiling, $storeId);
            if ($searchRemaining < $remaining) {
                $lines = $searchLines;
                $remaining = $searchRemaining;
            }
        }

        if ($remaining > $ceiling) {
            [$lines, $remaining] = $this->bridgeGap($pool, $lines, $remaining);
        }

        return $this->buildPlan($lines, $remaining);
    }

    private function toCents(string $totalMajorUnits): int
    {
        $trimmed = trim($totalMajorUnits);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            throw new ReconciliationFailedException(new Phrase('Invalid total format: %1', [$totalMajorUnits]));
        }

        return (int) bcmul($trimmed, '100', 0);
    }

    /**
     * @param array<int, array{sku: string, priceCents: int}> $pool
     * @return array{0: array<string, array{sku: string, qty: int, unitPriceCents: int}>, 1: int}
     */
    private function greedyPass(array $pool, int $targetCents): array
    {
        $remaining = $targetCents;
        $lines = [];

        foreach ($pool as $product) {
            if ($remaining <= 0) {
                break;
            }

            $priceCents = $product['priceCents'];
            if ($priceCents <= 0 || $priceCents > $remaining) {
                continue;
            }

            $qty = intdiv($remaining, $priceCents);
            if ($qty <= 0) {
                continue;
            }

            $lines[$product['sku']] = [
                'sku' => $product['sku'],
                'qty' => $qty,
                'unitPriceCents' => $priceCents,
            ];
            $remaining -= $qty * $priceCents;
        }

        return [$lines, $remaining];
    }

    /**
     * @param array<int, array{sku: string, priceCents: int}> $pool
     * @return array{0: array<string, array{sku: string, qty: int, unitPriceCents: int}>, 1: int}
     */
    private function boundedSearch(
        array $pool,
        int $targetCents,
        int $ceiling,
        int $storeId,
    ): array {
        $maxNodes = $this->config->getMaxSearchNodes($storeId);
        $nodesVisited = 0;
        $best = null;
        $bestRemaining = PHP_INT_MAX;

        $search = function (
            int $index,
            int $remaining,
            array $current,
        ) use (
            &$search,
            &$nodesVisited,
            &$best,
            &$bestRemaining,
            $pool,
            $ceiling,
            $maxNodes,
        ): void {
            if ($nodesVisited >= $maxNodes) {
                return;
            }
            $nodesVisited++;

            $absRemaining = abs($remaining);
            if ($absRemaining < $bestRemaining && $remaining >= 0) {
                $bestRemaining = $absRemaining;
                $best = $current;
            }
            if ($bestRemaining <= $ceiling) {
                return;
            }
            if ($index >= count($pool) || $remaining <= 0) {
                return;
            }

            $product = $pool[$index];
            $priceCents = $product['priceCents'];
            $maxAffordable = $priceCents > 0 ? intdiv($remaining, $priceCents) : 0;

            for ($qty = $maxAffordable; $qty >= 0; $qty--) {
                if ($nodesVisited >= $maxNodes) {
                    break;
                }

                $next = $current;
                if ($qty > 0) {
                    $next[$product['sku']] = [
                        'sku' => $product['sku'],
                        'qty' => $qty,
                        'unitPriceCents' => $priceCents,
                    ];
                }
                $search($index + 1, $remaining - $qty * $priceCents, $next);
            }
        };

        $search(0, $targetCents, []);

        return [$best, $bestRemaining];
    }

    /**
     * Adds a single bridging product (or extra qty of one) so the selected lines overshoot the
     * target, returning the updated lines and the resulting (now <= 0) remainder.
     *
     * @param array<int, array{sku: string, priceCents: int, reconciliationNumber: int}> $pool
     * @param array<string, array{sku: string, qty: int, unitPriceCents: int}> $lines
     * @return array{0: array<string, array{sku: string, qty: int, unitPriceCents: int}>, 1: int}
     */
    private function bridgeGap(array $pool, array $lines, int $remaining): array
    {
        $cheapest = null;
        $covering = null;

        foreach ($pool as $product) {
            if ($cheapest === null || $this->isPreferredBridgeCandidate($product, $cheapest)) {
                $cheapest = $product;
            }

            if ($product['priceCents'] >= $remaining
                && ($covering === null || $this->isPreferredBridgeCandidate($product, $covering))
            ) {
                $covering = $product;
            }
        }

        if ($covering !== null) {
            $sku = $covering['sku'];
            $priceCents = $covering['priceCents'];
            $qty = 1;
        } else {
            $sku = $cheapest['sku'];
            $priceCents = $cheapest['priceCents'];
            $qty = intdiv($remaining, $priceCents) + 1;
        }

        if (isset($lines[$sku])) {
            $lines[$sku]['qty'] += $qty;
        } else {
            $lines[$sku] = [
                'sku' => $sku,
                'qty' => $qty,
                'unitPriceCents' => $priceCents,
            ];
        }

        return [$lines, $remaining - $qty * $priceCents];
    }

    /**
     * @param array{sku: string, priceCents: int, reconciliationNumber: int} $candidate
     * @param array{sku: string, priceCents: int, reconciliationNumber: int} $current
     */
    private function isPreferredBridgeCandidate(array $candidate, array $current): bool
    {
        if ($candidate['priceCents'] !== $current['priceCents']) {
            return $candidate['priceCents'] < $current['priceCents'];
        }

        return $candidate['reconciliationNumber'] < $current['reconciliationNumber'];
    }

    /**
     * @param array<string, array{sku: string, qty: int, unitPriceCents: int}> $lines
     */
    private function buildPlan(array $lines, int $adjustmentCents): Plan
    {
        $planLines = [];
        foreach ($lines as $line) {
            $planLines[] = $this->lineFactory->create([
                'sku' => $line['sku'],
                'qty' => $line['qty'],
                'unitPriceCents' => $line['unitPriceCents'],
            ]);
        }

        return $this->planFactory->create([
            'lines' => $planLines,
            'adjustmentCents' => $adjustmentCents,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy;

use Magento\Framework\Exception\LocalizedException;

use function array_keys;
use function implode;
/**
 * Resolves the configured uho_novaposhta/address/postcode_strategy value to a strategy.
 *
 * Fails loudly on an unknown value: the admin field is a select, but a bad value
 * can still be written straight into core_config_data.
 */
class StrategyPool
{
    /**
     * @param array<string, PostcodeStrategyInterface> $strategies
     */
    public function __construct(
        private readonly array $strategies = [],
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $strategyCode): PostcodeStrategyInterface
    {
        $strategy = $this->strategies[$strategyCode] ?? null;

        if (!$strategy instanceof PostcodeStrategyInterface) {
            throw new LocalizedException(
                __(
                    'Unknown Nova Poshta postcode strategy "%1". Known strategies: %2.',
                    $strategyCode,
                    implode(', ', array_keys($this->strategies)) ?: '-'
                )
            );
        }

        return $strategy;
    }
}

<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Region;

use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

use function sprintf;
use function trim;
/**
 * Resolves a Nova Poshta oblast name to a Magento region.
 *
 * Fails closed: an area that is not in the map is never silently substituted,
 * because a wrong region_id on a real order is worse than a blocked checkout.
 */
class Resolver
{
    public const string COUNTRY_ID = 'UA';

    /**
     * @var array<string, int>|null
     */
    private ?array $regionIdsByCode = null;

    public function __construct(
        private readonly RegionMap $regionMap,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function resolveRegionCode(
        string $areaDescription,
        ?string $cityRef = null,
        ?int $warehouseId = null,
    ): string {
        $cityRef = $cityRef !== null ? trim($cityRef) : '';
        if ($cityRef !== '') {
            $override = $this->regionMap->getRegionCodeForCityRef($cityRef);
            if ($override !== null) {
                return $override;
            }
        }

        $area = trim($areaDescription);
        $code = $area !== '' ? $this->regionMap->getRegionCodeForArea($area) : null;

        if ($code === null) {
            $this->logger->error(
                sprintf(
                    'Uho_NovaposhtaCheckout: unmapped Nova Poshta area "%s" (warehouse id: %s, city ref: %s). '
                    . 'Add it to etc/np_region_map.xml.',
                    $area,
                    $warehouseId !== null ? (string) $warehouseId : 'unknown',
                    $cityRef !== '' ? $cityRef : 'unknown',
                )
            );

            throw new LocalizedException(
                __('We cannot determine the region for the selected Nova Poshta warehouse. Please choose another one.')
            );
        }

        return $code;
    }

    /**
     * @throws LocalizedException
     */
    public function resolveRegionId(string $regionCode): int
    {
        $regionId = $this->getRegionIdsByCode()[$regionCode] ?? null;

        if ($regionId === null) {
            $this->logger->error(
                sprintf(
                    'Uho_NovaposhtaCheckout: region code "%s" is not present in directory_country_region for %s.',
                    $regionCode,
                    self::COUNTRY_ID,
                )
            );

            throw new LocalizedException(
                __('We cannot determine the region for the selected Nova Poshta warehouse. Please choose another one.')
            );
        }

        return $regionId;
    }

    /**
     * @return array<string, int>
     */
    private function getRegionIdsByCode(): array
    {
        if ($this->regionIdsByCode !== null) {
            return $this->regionIdsByCode;
        }

        $collection = $this->regionCollectionFactory->create();
        $collection->addCountryFilter(self::COUNTRY_ID);

        $map = [];
        foreach ($collection as $region) {
            $code = trim((string) $region->getCode());
            $regionId = (int) $region->getId();
            if ($code !== '' && $regionId > 0) {
                $map[$code] = $regionId;
            }
        }

        $this->regionIdsByCode = $map;

        return $this->regionIdsByCode;
    }
}

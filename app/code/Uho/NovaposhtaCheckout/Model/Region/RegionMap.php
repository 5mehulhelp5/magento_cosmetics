<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Region;

use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\Data as ConfigData;
use Magento\Framework\Serialize\SerializerInterface;
use Uho\NovaposhtaCheckout\Model\Region\Config\Converter;
use Uho\NovaposhtaCheckout\Model\Region\Config\Reader;

use function is_array;
use function is_string;
use function mb_strtolower;
/**
 * Nova Poshta oblast -> Magento region ISO code map, loaded from etc/np_region_map.xml.
 */
class RegionMap extends ConfigData
{
    private const string CACHE_ID = 'uho_np_region_map';

    public function __construct(
        Reader $reader,
        CacheInterface $cache,
        string $cacheId = self::CACHE_ID,
        ?SerializerInterface $serializer = null,
    ) {
        parent::__construct($reader, $cache, $cacheId, $serializer);
    }

    public function getRegionCodeForArea(string $areaDescription): ?string
    {
        $code = $this->getAreas()[$areaDescription] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function getRegionCodeForCityRef(string $cityRef): ?string
    {
        $code = $this->getCityOverrides()[mb_strtolower($cityRef)] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @return array<string, string>
     */
    public function getAreas(): array
    {
        $areas = $this->get(Converter::KEY_AREAS, []);

        return is_array($areas) ? $areas : [];
    }

    /**
     * @return array<string, string>
     */
    public function getCityOverrides(): array
    {
        $overrides = $this->get(Converter::KEY_CITY_OVERRIDES, []);

        return is_array($overrides) ? $overrides : [];
    }
}

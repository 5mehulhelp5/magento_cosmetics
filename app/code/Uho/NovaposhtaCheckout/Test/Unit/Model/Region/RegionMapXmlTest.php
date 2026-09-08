<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Model\Region;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Uho\NovaposhtaCheckout\Model\Region\Config\Converter;

use function array_values;
use function dirname;
use function sprintf;
/**
 * Guards the shipped etc/np_region_map.xml itself: it is hand-built data and a typo in it
 * would put a wrong region_id on a real order.
 */
class RegionMapXmlTest extends TestCase
{
    private const string KYIV_CITY_REF = '8d5a980d-391c-11dd-90d9-001a92567626';

    /**
     * The 23 distinct settlement_area_description values present in
     * perspective_novaposhta_catalog_warehouse, with their expected ISO codes.
     */
    private const array EXPECTED_AREAS = [
        'Київська' => 'UA-32',
        'Львівська' => 'UA-46',
        'Дніпропетровська' => 'UA-12',
        'Одеська' => 'UA-51',
        'Харківська' => 'UA-63',
        'Вінницька' => 'UA-05',
        'Полтавська' => 'UA-53',
        'Хмельницька' => 'UA-68',
        'Черкаська' => 'UA-71',
        'Рівненська' => 'UA-56',
        'Івано-Франківська' => 'UA-26',
        'Тернопільська' => 'UA-61',
        'Житомирська' => 'UA-18',
        'Волинська' => 'UA-07',
        'Миколаївська' => 'UA-48',
        'Закарпатська' => 'UA-21',
        'Чернігівська' => 'UA-74',
        'Запорізька' => 'UA-23',
        'Чернівецька' => 'UA-77',
        'Кіровоградська' => 'UA-35',
        'Сумська' => 'UA-59',
        'Донецька' => 'UA-14',
        'Херсонська' => 'UA-65',
    ];

    public function testShippedMapValidatesAgainstItsXsd(): void
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->load($this->mapPath()));
        $this->assertTrue($dom->schemaValidate($this->xsdPath()));
    }

    public function testShippedMapCoversEveryNovaPoshtaArea(): void
    {
        $areas = $this->loadMap()[Converter::KEY_AREAS];

        foreach (self::EXPECTED_AREAS as $area => $expectedCode) {
            $this->assertArrayHasKey($area, $areas, sprintf('Area "%s" is missing from the map.', $area));
            $this->assertSame($expectedCode, $areas[$area], sprintf('Area "%s" maps to the wrong region.', $area));
        }
    }

    public function testShippedMapOverridesKyivCityToUa30(): void
    {
        $overrides = $this->loadMap()[Converter::KEY_CITY_OVERRIDES];

        $this->assertSame(['UA-30'], array_values($overrides));
        $this->assertArrayHasKey(self::KYIV_CITY_REF, $overrides);
    }

    /**
     * @return array{areas: array<string, string>, city_overrides: array<string, string>}
     */
    private function loadMap(): array
    {
        $dom = new DOMDocument();
        $dom->load($this->mapPath());

        return (new Converter())->convert($dom);
    }

    private function mapPath(): string
    {
        return dirname(__DIR__, 4) . '/etc/np_region_map.xml';
    }

    private function xsdPath(): string
    {
        return dirname(__DIR__, 4) . '/etc/np_region_map.xsd';
    }
}

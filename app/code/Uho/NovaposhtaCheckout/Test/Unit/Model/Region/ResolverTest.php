<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Model\Region;

use ArrayIterator;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Uho\NovaposhtaCheckout\Model\Region\RegionMap;
use Uho\NovaposhtaCheckout\Model\Region\Resolver;

use function str_contains;
class ResolverTest extends TestCase
{
    private const string KYIV_CITY_REF = '8d5a980d-391c-11dd-90d9-001a92567626';
    private const string BROVARY_CITY_REF = 'e718a680-4b33-11e4-ab6d-005056801329';

    private RegionMap&MockObject $regionMap;
    private RegionCollectionFactory&MockObject $regionCollectionFactory;
    private LoggerInterface&MockObject $logger;
    private Resolver $resolver;

    protected function setUp(): void
    {
        $this->regionMap = $this->createMock(RegionMap::class);
        $this->regionCollectionFactory = $this->createMock(RegionCollectionFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resolver = new Resolver(
            $this->regionMap,
            $this->regionCollectionFactory,
            $this->logger,
        );
    }

    public function testKyivCityRefOverridesTheAreaMap(): void
    {
        $this->regionMap->method('getRegionCodeForCityRef')
            ->with(self::KYIV_CITY_REF)
            ->willReturn('UA-30');
        $this->regionMap->expects($this->never())->method('getRegionCodeForArea');
        $this->logger->expects($this->never())->method('error');

        $this->assertSame('UA-30', $this->resolver->resolveRegionCode('Київська', self::KYIV_CITY_REF, 17222));
    }

    public function testCityWithoutOverrideFallsBackToTheAreaMap(): void
    {
        $this->regionMap->method('getRegionCodeForCityRef')->willReturn(null);
        $this->regionMap->method('getRegionCodeForArea')->with('Київська')->willReturn('UA-32');
        $this->logger->expects($this->never())->method('error');

        $this->assertSame('UA-32', $this->resolver->resolveRegionCode('Київська', self::BROVARY_CITY_REF, 42));
    }

    public function testNormalOblastResolvesFromTheAreaMap(): void
    {
        $this->regionMap->method('getRegionCodeForCityRef')->willReturn(null);
        $this->regionMap->method('getRegionCodeForArea')->with('Львівська')->willReturn('UA-46');

        $this->assertSame('UA-46', $this->resolver->resolveRegionCode('Львівська', 'ref', 1));
    }

    public function testAreaDescriptionIsTrimmedBeforeLookup(): void
    {
        $this->regionMap->method('getRegionCodeForCityRef')->willReturn(null);
        $this->regionMap->method('getRegionCodeForArea')->with('Волинська')->willReturn('UA-07');

        $this->assertSame('UA-07', $this->resolver->resolveRegionCode("  Волинська \n", null, null));
    }

    public function testUnmappedAreaFailsClosedAndLogsTheWarehouseId(): void
    {
        $this->regionMap->method('getRegionCodeForCityRef')->willReturn(null);
        $this->regionMap->method('getRegionCodeForArea')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->callback(static function (string $message): bool {
                return str_contains($message, 'Новоземельська')
                    && str_contains($message, '999333')
                    && str_contains($message, 'np_region_map.xml');
            }));

        $this->expectException(LocalizedException::class);

        $this->resolver->resolveRegionCode('Новоземельська', 'some-ref', 999333);
    }

    public function testEmptyAreaFailsClosed(): void
    {
        $this->regionMap->method('getRegionCodeForCityRef')->willReturn(null);
        $this->regionMap->expects($this->never())->method('getRegionCodeForArea');
        $this->logger->expects($this->once())->method('error');

        $this->expectException(LocalizedException::class);

        $this->resolver->resolveRegionCode('   ', null, 5);
    }

    public function testResolveRegionIdMapsCodeToDirectoryId(): void
    {
        $this->givenDirectoryRegions(['UA-30' => 1110, 'UA-32' => 1095]);

        $this->assertSame(1110, $this->resolver->resolveRegionId('UA-30'));
        // Second call must be served from the in-memory map, not a second query.
        $this->assertSame(1095, $this->resolver->resolveRegionId('UA-32'));
    }

    public function testResolveRegionIdFailsClosedForAnUnknownCode(): void
    {
        $this->givenDirectoryRegions(['UA-30' => 1110]);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('UA-99'));

        $this->expectException(LocalizedException::class);

        $this->resolver->resolveRegionId('UA-99');
    }

    /**
     * @param array<string, int> $regions
     */
    private function givenDirectoryRegions(array $regions): void
    {
        $items = [];
        foreach ($regions as $code => $regionId) {
            // A plain DataObject stands in for Magento\Directory\Model\Region here: both
            // expose getCode()/getId() only via DataObject's magic __call, which PHPUnit's
            // mock builder cannot configure directly (it isn't a declared method).
            $items[] = new DataObject(['code' => $code, 'id' => $regionId]);
        }

        $collection = $this->createMock(RegionCollection::class);
        $collection->expects($this->once())->method('addCountryFilter')->with('UA')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new ArrayIterator($items));

        $this->regionCollectionFactory->expects($this->once())->method('create')->willReturn($collection);
    }
}

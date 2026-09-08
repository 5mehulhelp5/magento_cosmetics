<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Model\Address\PostcodeStrategy;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\OblastCentreStrategy;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\SentinelStrategy;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\StrategyPool;
use Uho\NovaposhtaCheckout\Model\Config\Source\PostcodeStrategy as PostcodeStrategySource;

use function sprintf;
class PostcodeStrategyTest extends TestCase
{
    private const string UA_POSTCODE_PATTERN = '/^[0-9]{5}$/';

    public function testSentinelAlwaysReturnsTheSyntheticPostcode(): void
    {
        $strategy = new SentinelStrategy();

        $this->assertSame('00000', $strategy->getPostcode('UA-30'));
        $this->assertSame('00000', $strategy->getPostcode('UA-65'));
        $this->assertSame('00000', $strategy->getPostcode(''));
        $this->assertMatchesRegularExpression(self::UA_POSTCODE_PATTERN, $strategy->getPostcode('UA-30'));
    }

    #[DataProvider('oblastCentreProvider')]
    public function testOblastCentreReturnsThePostcodeOfTheRegionCentre(string $regionCode, string $expected): void
    {
        $this->assertSame($expected, (new OblastCentreStrategy())->getPostcode($regionCode));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oblastCentreProvider(): array
    {
        return [
            'Kyiv city' => ['UA-30', '01001'],
            'Kyivska oblast' => ['UA-32', '08000'],
            'Lvivska oblast' => ['UA-46', '79000'],
            'Odeska oblast' => ['UA-51', '65000'],
            'Kharkivska oblast' => ['UA-63', '61000'],
            'Sevastopol' => ['UA-40', '99000'],
            'padded input' => ['  UA-12  ', '49000'],
        ];
    }

    public function testEveryOblastCentrePostcodeSatisfiesMagentoUaValidation(): void
    {
        $strategy = new OblastCentreStrategy();

        foreach (self::ALL_UA_REGION_CODES as $code) {
            $this->assertMatchesRegularExpression(
                self::UA_POSTCODE_PATTERN,
                $strategy->getPostcode($code),
                sprintf('Postcode for %s must match Magento\'s UA zip pattern.', $code)
            );
        }
    }

    public function testOblastCentreFailsClosedForAnUnknownRegionCode(): void
    {
        $this->expectException(LocalizedException::class);

        (new OblastCentreStrategy())->getPostcode('UA-99');
    }

    public function testStrategyPoolResolvesTheConfiguredStrategies(): void
    {
        $sentinel = new SentinelStrategy();
        $oblastCentre = new OblastCentreStrategy();

        $pool = new StrategyPool([
            PostcodeStrategySource::SENTINEL => $sentinel,
            PostcodeStrategySource::OBLAST_CENTRE => $oblastCentre,
        ]);

        $this->assertSame($sentinel, $pool->get(PostcodeStrategySource::SENTINEL));
        $this->assertSame($oblastCentre, $pool->get(PostcodeStrategySource::OBLAST_CENTRE));
    }

    public function testStrategyPoolFailsLoudlyOnAnUnknownStrategyValue(): void
    {
        $pool = new StrategyPool([PostcodeStrategySource::SENTINEL => new SentinelStrategy()]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown Nova Poshta postcode strategy "whatever-was-written-into-config"');

        $pool->get('whatever-was-written-into-config');
    }

    public function testStrategyPoolRejectsAnEntryOfTheWrongType(): void
    {
        $pool = new StrategyPool([PostcodeStrategySource::SENTINEL => new \stdClass()]);

        $this->expectException(LocalizedException::class);

        $pool->get(PostcodeStrategySource::SENTINEL);
    }

    /**
     * @var string[]
     */
    private const array ALL_UA_REGION_CODES = [
        'UA-05', 'UA-07', 'UA-09', 'UA-12', 'UA-14', 'UA-18', 'UA-21', 'UA-23', 'UA-26',
        'UA-30', 'UA-32', 'UA-35', 'UA-40', 'UA-43', 'UA-46', 'UA-48', 'UA-51', 'UA-53',
        'UA-56', 'UA-59', 'UA-61', 'UA-63', 'UA-65', 'UA-68', 'UA-71', 'UA-74', 'UA-77',
    ];
}

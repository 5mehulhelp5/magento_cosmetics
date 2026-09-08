<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy;

use Magento\Framework\Exception\LocalizedException;

use function trim;
/**
 * Returns the postal index of the administrative centre of the address region.
 *
 * Plausible-looking but not the customer's real index; kept as a configurable
 * alternative to the sentinel for downstream systems that reject 00000.
 */
class OblastCentreStrategy implements PostcodeStrategyInterface
{
    /**
     * @var array<string, string>
     */
    private const array POSTCODES = [
        'UA-05' => '21000', // Вінницька — Вінниця
        'UA-07' => '43000', // Волинська — Луцьк
        'UA-09' => '93000', // Луганська — Сєвєродонецьк
        'UA-12' => '49000', // Дніпропетровська — Дніпро
        'UA-14' => '85000', // Донецька — Краматорськ
        'UA-18' => '10000', // Житомирська — Житомир
        'UA-21' => '88000', // Закарпатська — Ужгород
        'UA-23' => '69000', // Запорізька — Запоріжжя
        'UA-26' => '76000', // Івано-Франківська — Івано-Франківськ
        'UA-30' => '01001', // Київ
        'UA-32' => '08000', // Київська — Біла Церква
        'UA-35' => '25000', // Кіровоградська — Кропивницький
        'UA-40' => '99000', // Севастополь
        'UA-43' => '95000', // АР Крим — Сімферополь
        'UA-46' => '79000', // Львівська — Львів
        'UA-48' => '54000', // Миколаївська — Миколаїв
        'UA-51' => '65000', // Одеська — Одеса
        'UA-53' => '36000', // Полтавська — Полтава
        'UA-56' => '33000', // Рівненська — Рівне
        'UA-59' => '40000', // Сумська — Суми
        'UA-61' => '46000', // Тернопільська — Тернопіль
        'UA-63' => '61000', // Харківська — Харків
        'UA-65' => '73000', // Херсонська — Херсон
        'UA-68' => '29000', // Хмельницька — Хмельницький
        'UA-71' => '18000', // Черкаська — Черкаси
        'UA-74' => '14000', // Чернігівська — Чернігів
        'UA-77' => '58000', // Чернівецька — Чернівці
    ];

    public function getPostcode(string $regionCode): string
    {
        $postcode = self::POSTCODES[trim($regionCode)] ?? null;

        if ($postcode === null) {
            throw new LocalizedException(
                __('No oblast centre postcode is configured for region code "%1".', $regionCode)
            );
        }

        return $postcode;
    }
}

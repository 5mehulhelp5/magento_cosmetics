<?php

declare(strict_types=1);

namespace Uho\Store\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

use function crc32;
/**
 * mageplaza/magento-2-ukrainian-language-pack ships duplicate CSV entries for a handful of
 * phrases (e.g. "Details", "My Account") where a later, untranslated entry overwrites an
 * earlier correct one, and a few phrases (e.g. "Find Order By") are missing a translation
 * entirely. Theme i18n CSVs can't fix this for stores using the stock Magento/luma theme
 * (only Uho/seed-store has one), so these are stored as global inline-translation overrides
 * instead, which apply regardless of the active theme.
 */
class FixUkrainianTranslationPackOverrides implements DataPatchInterface
{
    private const ALL_STORES = 0;
    private const LOCALE_UK_UA = 'uk_UA';

    /**
     * @var array<string, string>
     */
    private const TRANSLATIONS = [
        'Privacy and Cookie Policy' => 'Політика конфіденційності та використання файлів cookie',
        'Short Description' => 'Короткий опис',
        'Billing Last Name' => 'Прізвище платника',
        'Find Order By' => 'Знайти замовлення за',
        'My Account' => 'Мій обліковий запис',
        'Details' => 'Подробиці',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('translation');

        foreach (self::TRANSLATIONS as $string => $translate) {
            $connection->insertOnDuplicate(
                $table,
                [
                    'string' => $string,
                    'store_id' => self::ALL_STORES,
                    'translate' => $translate,
                    'locale' => self::LOCALE_UK_UA,
                    'crc_string' => crc32($string),
                ],
                ['translate']
            );
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Uho\Store\Setup\Patch\Data;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Model\Theme\ThemeProvider;

class AssignHyvaThemeToOlivetsWebsite implements DataPatchInterface
{
    private const string WEBSITE_CODE = 'ol';
    private const string THEME_FULL_PATH = 'frontend/Uho/olivets';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly StoreManagerInterface $storeManager,
        private readonly ThemeProvider $themeProvider,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $configReinit,
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $website = $this->storeManager->getWebsite(self::WEBSITE_CODE);
        $theme = $this->themeProvider->getThemeByFullPath(self::THEME_FULL_PATH);

        if (!$theme->getId()) {
            throw new LocalizedException(__('Theme "%1" is not registered.', self::THEME_FULL_PATH));
        }

        $this->configResource->saveConfig(
            'design/theme/theme_id',
            $theme->getId(),
            'websites',
            (int) $website->getId(),
        );

        $this->moduleDataSetup->endSetup();
        $this->configReinit->reinit();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreateOlivetsStore::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}

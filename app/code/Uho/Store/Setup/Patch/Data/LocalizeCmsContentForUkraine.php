<?php

declare(strict_types=1);

namespace Uho\Store\Setup\Patch\Data;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;

use function file_get_contents;
use function sprintf;
class LocalizeCmsContentForUkraine implements DataPatchInterface
{
    private const string IDENTIFIER_PRIVACY_POLICY = 'privacy-policy-cookie-restriction-mode';
    private const string IDENTIFIER_ENABLE_COOKIES = 'enable-cookies';
    private const string IDENTIFIER_CUSTOMER_SERVICE = 'customer-service';
    private const string IDENTIFIER_NO_ROUTE = 'no-route';
    private const string IDENTIFIER_HOME = 'home';
    private const string IDENTIFIER_ABOUT_US = 'about-us';

    private const string STORE_DEFAULT = 'default';
    private const string STORE_PR = 'pr_ua';
    private const string STORE_ODIAG = 'odiag';

    private const string CONTENT_DIR = __DIR__ . '/content/';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly PageFactory $pageFactory,
        private readonly PageResource $pageResource,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $this->updatePage(self::IDENTIFIER_PRIVACY_POLICY, [
            'title' => 'Політика конфіденційності',
            'content_heading' => 'Політика конфіденційності',
            'meta_title' => 'Політика конфіденційності',
            'meta_description' => 'Політика конфіденційності щодо обробки персональних даних '
                . 'відповідно до законодавства України.',
        ], 'privacy-policy.html');

        $this->updatePage(self::IDENTIFIER_ENABLE_COOKIES, [
            'title' => 'Увімкнення файлів cookie',
            'content_heading' => 'Увімкнення файлів cookie',
            'meta_title' => 'Увімкнення файлів cookie',
        ], 'enable-cookies.html');

        $this->updatePage(self::IDENTIFIER_CUSTOMER_SERVICE, [
            'title' => 'Доставка та повернення',
            'content_heading' => 'Доставка та повернення',
            'meta_title' => 'Доставка та повернення',
            'meta_description' => 'Умови доставки Новою Поштою та повернення товару '
                . 'відповідно до Закону України «Про захист прав споживачів».',
        ], 'delivery-and-returns.html');

        $this->updatePage(self::IDENTIFIER_NO_ROUTE, [
            'title' => 'Сторінку не знайдено',
            'content_heading' => 'Сторінку не знайдено',
            'meta_title' => 'Сторінку не знайдено',
        ], 'no-route.html');

        $this->updateHomeMetaTitle();
        $this->applyAboutUs();

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    private function updatePage(string $identifier, array $data, string $contentFile): void
    {
        $page = $this->loadByIdentifier($identifier);
        if (!$page->getId()) {
            return;
        }

        $page->addData($data);
        $page->setContent($this->readContent($contentFile));
        $this->pageResource->save($page);
    }

    private function updateHomeMetaTitle(): void
    {
        $page = $this->loadByIdentifier(self::IDENTIFIER_HOME);
        if (!$page->getId()) {
            return;
        }

        $page->setMetaTitle('Головна сторінка');
        $this->pageResource->save($page);
    }

    private function applyAboutUs(): void
    {
        $defaultStoreId = $this->resolveStoreId(self::STORE_DEFAULT);
        $prStoreId = $this->resolveStoreId(self::STORE_PR);
        $odiagStoreId = $this->resolveStoreId(self::STORE_ODIAG);

        $page = $this->loadByIdentifier(self::IDENTIFIER_ABOUT_US);
        if ($page->getId()) {
            $page->addData([
                'title' => 'Про нас',
                'content_heading' => 'Про нас',
                'meta_title' => 'Про нас',
            ]);
            $page->setContent($this->readContent('about-us-default.html'));
            if ($defaultStoreId !== null) {
                $page->setData('store_id', [$defaultStoreId]);
            }
            $this->pageResource->save($page);
        }

        if ($prStoreId !== null) {
            $this->createAboutUsPage($prStoreId, 'about-us-pr.html');
        }

        if ($odiagStoreId !== null) {
            $this->createAboutUsPage($odiagStoreId, 'about-us-odiag.html');
        }
    }

    private function createAboutUsPage(int $storeId, string $contentFile): void
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->addData([
            'identifier' => self::IDENTIFIER_ABOUT_US,
            'title' => 'Про нас',
            'content_heading' => 'Про нас',
            'meta_title' => 'Про нас',
            'page_layout' => '1column',
            'is_active' => 1,
            'sort_order' => 0,
            'content' => $this->readContent($contentFile),
            'store_id' => [$storeId],
        ]);
        $this->pageResource->save($page);
    }

    private function loadByIdentifier(string $identifier): Page
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $this->pageResource->load($page, $identifier, 'identifier');

        return $page;
    }

    private function resolveStoreId(string $code): ?int
    {
        try {
            return (int) $this->storeManager->getStore($code)->getId();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function readContent(string $fileName): string
    {
        $path = self::CONTENT_DIR . $fileName;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Unable to read CMS content file: %s', $path));
        }

        return $content;
    }

    public static function getDependencies(): array
    {
        return [CreateProrostokStore::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}

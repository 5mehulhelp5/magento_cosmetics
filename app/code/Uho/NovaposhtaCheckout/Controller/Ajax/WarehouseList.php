<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Uho\NovaposhtaCheckout\Api\Data\WarehouseOptionInterface;
use Uho\NovaposhtaCheckout\Api\WarehouseLocatorInterface;

use function array_map;
/**
 * GET uho_novaposhta/ajax/warehouselist?cityRef=...&q=...&limit=...
 *
 * Reads only the locally cron-synced warehouse table (perspective_novaposhta_catalog_warehouse);
 * never calls the Nova Poshta API. WarehouseLocator filters by the indexed city_ref column, so a
 * request for a city with thousands of warehouses (Kyiv alone has ~7,900) is still a bounded,
 * indexed read. An unknown or malformed cityRef yields an empty list, not an error.
 */
class WarehouseList implements HttpGetActionInterface
{
    private const string PARAM_CITY_REF = 'cityRef';
    private const string PARAM_QUERY = 'q';
    private const string PARAM_LIMIT = 'limit';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly WarehouseLocatorInterface $warehouseLocator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $cityRef = (string) $this->request->getParam(self::PARAM_CITY_REF, '');
        $query = (string) $this->request->getParam(self::PARAM_QUERY, '');
        $limit = $this->readLimit();

        try {
            $options = $cityRef !== ''
                ? $this->warehouseLocator->getForCity($cityRef, $query, $limit)
                : [];
        } catch (Throwable $exception) {
            $this->logger->error(
                'Uho_NovaposhtaCheckout: warehouse list failed.',
                ['exception' => $exception],
            );
            $options = [];
        }

        return $this->resultJsonFactory->create()->setData(
            array_map(
                static fn (WarehouseOptionInterface $option): array => [
                    'ref' => $option->getRef(),
                    'label' => $option->getLabel(),
                    'number' => $option->getNumber(),
                    'siteKey' => $option->getSiteKey(),
                ],
                $options,
            ),
        );
    }

    private function readLimit(): ?int
    {
        $raw = $this->request->getParam(self::PARAM_LIMIT);

        return $raw !== null && $raw !== '' ? (int) $raw : null;
    }
}

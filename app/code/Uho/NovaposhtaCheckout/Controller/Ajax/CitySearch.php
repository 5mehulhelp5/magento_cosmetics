<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Uho\NovaposhtaCheckout\Api\CityLocatorInterface;
use Uho\NovaposhtaCheckout\Api\Data\CitySuggestionInterface;

use function array_map;
/**
 * GET uho_novaposhta/ajax/citysearch?q=...&limit=...
 *
 * Reads only the locally cron-synced city table (perspective_novaposhta_catalog_cities);
 * never calls the Nova Poshta API. CityLocator enforces the minimum query length and clamps
 * $limit against the store's configured maximum, so this controller passes both through as
 * given rather than re-validating them.
 */
class CitySearch implements HttpGetActionInterface
{
    private const string PARAM_QUERY = 'q';
    private const string PARAM_LIMIT = 'limit';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CityLocatorInterface $cityLocator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $query = (string) $this->request->getParam(self::PARAM_QUERY, '');
        $limit = $this->readLimit();

        try {
            $suggestions = $this->cityLocator->search($query, $limit);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Uho_NovaposhtaCheckout: city search failed.',
                ['exception' => $exception],
            );
            $suggestions = [];
        }

        return $this->resultJsonFactory->create()->setData(
            array_map(
                static fn (CitySuggestionInterface $suggestion): array => [
                    'ref' => $suggestion->getRef(),
                    'label' => $suggestion->getLabel(),
                ],
                $suggestions,
            ),
        );
    }

    private function readLimit(): ?int
    {
        $raw = $this->request->getParam(self::PARAM_LIMIT);

        return $raw !== null && $raw !== '' ? (int) $raw : null;
    }
}

<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Sales;

use Magento\Framework\Escaper;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Address\Renderer;

use function sprintf;
/**
 * Prepends the Nova Poshta city/warehouse to every rendered order address.
 *
 * One extension point feeds the admin order view, sales_order_print, and the order
 * confirmation email (see docs/novaposhta-checkout-architecture.md §9) — no email template
 * override, no vendor file touched.
 */
class OrderAddressRendererPlugin
{
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @param Renderer $subject
     * @param string|null $result
     * @param Address $address
     * @param string $type
     * @return string|null
     */
    public function afterFormat(Renderer $subject, ?string $result, Address $address, $type): ?string
    {
        $warehouseRef = (string)$address->getData('uho_np_warehouse_ref');
        if ($warehouseRef === '' || $result === null) {
            return $result;
        }

        $cityName = (string)$address->getData('uho_np_city_name');
        $warehouseName = (string)$address->getData('uho_np_warehouse_name');

        if ($type === 'html') {
            $novaposhtaBlock = sprintf(
                '<strong>%s</strong><br />%s: %s<br />%s: %s<br />',
                $this->escaper->escapeHtml(__('Nova Poshta — Self-Pickup')),
                $this->escaper->escapeHtml(__('City')),
                $this->escaper->escapeHtml($cityName),
                $this->escaper->escapeHtml(__('Warehouse')),
                $this->escaper->escapeHtml($warehouseName)
            );

            return $novaposhtaBlock . $result;
        }

        if ($type === 'oneline') {
            return sprintf(
                '%s, %s: %s, %s: %s, %s',
                (string)__('Nova Poshta — Self-Pickup'),
                (string)__('City'),
                $cityName,
                (string)__('Warehouse'),
                $warehouseName,
                $result
            );
        }

        $novaposhtaBlock = sprintf(
            "%s\n%s: %s\n%s: %s\n",
            (string)__('Nova Poshta — Self-Pickup'),
            (string)__('City'),
            $cityName,
            (string)__('Warehouse'),
            $warehouseName
        );

        return $novaposhtaBlock . $result;
    }
}

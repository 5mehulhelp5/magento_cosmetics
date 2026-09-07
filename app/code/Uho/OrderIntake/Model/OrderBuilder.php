<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\AddressFactory as OrderAddressFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\ItemFactory as OrderItemFactory;
use Magento\Sales\Model\Order\PaymentFactory as OrderPaymentFactory;
use Magento\Sales\Model\Order\ShipmentFactory as OrderShipmentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Uho\NovaposhtaShipping\Model\Carrier\NovaposhtaManual;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;
use Uho\OrderIntake\Api\GetOrderProductInterface;

/**
 * Builds and completes a Magento sales order for a single Uho_OrderIntake row (spec §6).
 *
 * Constructs the Order directly via OrderFactory/OrderRepository — no Quote object is ever created
 * or submitted (spec §5). This is deliberate: Uho\NovaposhtaCheckout\Observer\
 * SalesModelServiceQuoteSubmitBefore is a fail-closed guard on the sales_model_service_quote_submit_
 * before event that blocks placing an order with the Nova Poshta carrier and no composed warehouse
 * ref. That event is only ever dispatched by Quote submission (Magento\Quote\Model\QuoteManagement /
 * Magento\Sales\Model\Service\Quote), so building the Order directly means it structurally cannot
 * fire here — the guard stays fully intact for real checkout/admin order creation.
 */
class OrderBuilder
{
    private const string PAYMENT_METHOD = 'cashondelivery';

    private const string COUNTRY_CODE_UA = 'UA';

    /**
     * @todo Placeholder address values pending a future Nova Poshta warehouse/address-lookup
     *       service (spec §2, §6) — the payload only carries a city name, not a street, warehouse
     *       ref, or postcode.
     */
    private const string PLACEHOLDER_STREET = 'Nova Poshta pickup — address pending lookup';

    private const string PLACEHOLDER_POSTCODE = '00000';

    private const string PLACEHOLDER_REGION = 'Pending lookup';

    private const string XML_PATH_CURRENCY_BASE = 'currency/options/base';

    /**
     * @var string[]
     */
    private const array PLACEHOLDER_SKUS = ['order-misc-1', 'order-misc-2'];

    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly GetOrderProductInterface $getOrderProduct,
        private readonly TotalSplitter $totalSplitter,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderFactory $orderFactory,
        private readonly OrderAddressFactory $orderAddressFactory,
        private readonly OrderItemFactory $orderItemFactory,
        private readonly OrderPaymentFactory $orderPaymentFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderShipmentFactory $orderShipmentFactory,
    ) {
    }

    /**
     * Builds, invoices, ships and completes an order for the given intake row.
     *
     * @return int The new order's entity ID.
     * @throws LocalizedException
     */
    public function build(OrderIntakeInterface $orderIntake): int
    {
        $store = $this->storeRepository->getActiveStoreByCode($orderIntake->getStoreCode());
        $lineItems = $this->resolveLineItems($orderIntake);

        [$firstName, $lastName] = $this->splitCustomerName($orderIntake->getCustomerName());
        $email = $this->buildGuestEmail($orderIntake->getPhone(), $store);

        $order = $this->buildOrder($orderIntake, $store, $lineItems, $firstName, $lastName, $email);
        $this->orderRepository->save($order);

        $this->invoiceOrder($order);
        $this->shipOrder($order, $orderIntake->getTrackingNumber());

        $order->setState(SalesOrder::STATE_COMPLETE);
        $order->setStatus(SalesOrder::STATE_COMPLETE);
        $this->orderRepository->save($order);

        return (int) $order->getEntityId();
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function buildOrder(
        OrderIntakeInterface $orderIntake,
        StoreInterface $store,
        array $lineItems,
        string $firstName,
        string $lastName,
        string $email,
    ): SalesOrder {
        $storeId = (int) $store->getId();

        $order = $this->orderFactory->create();
        $order->setStoreId($storeId);
        $this->applyCurrency($order, $storeId);
        $this->applyCustomer($order, $firstName, $lastName, $email);
        $order->setBillingAddress($this->buildAddress($orderIntake, 'billing', $firstName, $lastName));
        $order->setShippingAddress($this->buildAddress($orderIntake, 'shipping', $firstName, $lastName));
        $this->applyShipping($order);
        $this->applyPayment($order);

        $subtotal = 0.0;
        $qtyOrdered = 0.0;
        foreach ($lineItems as $lineItem) {
            $item = $this->buildOrderItem($lineItem, $storeId);
            $order->addItem($item);
            $subtotal += (float) $item->getRowTotal();
            $qtyOrdered += (float) $item->getQtyOrdered();
        }

        $order->setSubtotal($subtotal);
        $order->setBaseSubtotal($subtotal);
        $order->setGrandTotal($subtotal);
        $order->setBaseGrandTotal($subtotal);
        $order->setTotalQtyOrdered($qtyOrdered);
        $order->setState(SalesOrder::STATE_NEW);
        $order->setStatus(SalesOrder::STATE_NEW);

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveLineItems(OrderIntakeInterface $orderIntake): array
    {
        $total = (int) $orderIntake->getTotal();
        $products = $this->getOrderProduct->execute($orderIntake->getStoreCode(), $total);

        if ($products !== []) {
            return $products;
        }

        return $this->buildPlaceholderLineItems($total);
    }

    /**
     * Fallback used only while GetOrderProductInterface is a stub (spec §2, §6): splits the total
     * across the two placeholder products created by Setup\Patch\Data\CreatePlaceholderProducts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPlaceholderLineItems(int $total): array
    {
        $parts = $this->totalSplitter->split($total);

        $lineItems = [];
        foreach ($parts as $index => $price) {
            $lineItems[] = [
                'sku' => self::PLACEHOLDER_SKUS[$index],
                'qty' => 1.0,
                'price' => (float) $price,
            ];
        }

        return $lineItems;
    }

    /**
     * @param array<string, mixed> $lineItem
     */
    private function buildOrderItem(array $lineItem, int $storeId): OrderItem
    {
        $product = $this->productRepository->get((string) $lineItem['sku'], false, $storeId);
        $qty = (float) $lineItem['qty'];
        $price = (float) $lineItem['price'];
        $rowTotal = round($price * $qty, 4);

        $item = $this->orderItemFactory->create();
        $item->setStoreId($storeId);
        $item->setProductId((int) $product->getId());
        $item->setProductType($product->getTypeId());
        $item->setSku($product->getSku());
        $item->setName($product->getName());
        $item->setIsVirtual(0);
        $item->setWeight(0.0);
        $item->setQtyOrdered($qty);
        $item->setPrice($price);
        $item->setBasePrice($price);
        $item->setOriginalPrice($price);
        $item->setBaseOriginalPrice($price);
        $item->setPriceInclTax($price);
        $item->setBasePriceInclTax($price);
        $item->setRowTotal($rowTotal);
        $item->setBaseRowTotal($rowTotal);
        $item->setRowTotalInclTax($rowTotal);
        $item->setBaseRowTotalInclTax($rowTotal);

        return $item;
    }

    private function buildAddress(
        OrderIntakeInterface $orderIntake,
        string $addressType,
        string $firstName,
        string $lastName,
    ): OrderAddress {
        $address = $this->orderAddressFactory->create();
        $address->setAddressType($addressType);
        $address->setFirstname($firstName);
        $address->setLastname($lastName);
        $address->setCountryId(self::COUNTRY_CODE_UA);
        $address->setCity($orderIntake->getCity());
        $address->setTelephone($orderIntake->getPhone());
        $address->setStreet(self::PLACEHOLDER_STREET);
        $address->setPostcode(self::PLACEHOLDER_POSTCODE);
        $address->setRegion(self::PLACEHOLDER_REGION);

        return $address;
    }

    private function applyCurrency(SalesOrder $order, int $storeId): void
    {
        $currencyCode = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CURRENCY_BASE,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );

        $order->setOrderCurrencyCode($currencyCode);
        $order->setBaseCurrencyCode($currencyCode);
        $order->setGlobalCurrencyCode($currencyCode);
        $order->setStoreCurrencyCode($currencyCode);
    }

    private function applyCustomer(SalesOrder $order, string $firstName, string $lastName, string $email): void
    {
        $order->setCustomerIsGuest(1);
        $order->setCustomerEmail($email);
        $order->setCustomerFirstname($firstName);
        $order->setCustomerLastname($lastName);
    }

    private function applyShipping(SalesOrder $order): void
    {
        $order->setShippingMethod(NovaposhtaManual::CARRIER_CODE . '_' . NovaposhtaManual::METHOD_CODE);
        $order->setShippingDescription('Nova Poshta Pickup');
        $order->setShippingAmount(0.0);
        $order->setBaseShippingAmount(0.0);
    }

    private function applyPayment(SalesOrder $order): void
    {
        $payment = $this->orderPaymentFactory->create();
        $payment->setMethod(self::PAYMENT_METHOD);
        $order->setPayment($payment);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitCustomerName(string $customerName): array
    {
        $parts = explode(' ', trim($customerName), 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Synthesizes a guest email from the phone number, e.g. `380671234567@pr-ua.orders` for store
     * code `pr_ua` (spec §6).
     */
    private function buildGuestEmail(string $phone, StoreInterface $store): string
    {
        $digitsOnly = (string) preg_replace('/\D+/', '', $phone);
        $storeCode = str_replace('_', '-', $store->getCode());

        return sprintf('%s@%s.orders', $digitsOnly, $storeCode);
    }

    /**
     * @throws LocalizedException
     */
    private function invoiceOrder(SalesOrder $order): void
    {
        if (!$order->canInvoice()) {
            throw new LocalizedException(__('Order #%1 cannot be invoiced.', $order->getIncrementId()));
        }

        /** @var Invoice $invoice */
        $invoice = $this->invoiceService->prepareInvoice($order);
        // requested_capture_case/is_in_process below are genuine Magento core fields with no
        // explicit setter on their classes (DataObject magic setters) — setData() is used to keep
        // static analysis accurate rather than calling an "undefined" method.
        $invoice->setData('requested_capture_case', Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $order->setData('is_in_process', true);

        $transaction = $this->transactionFactory->create();
        $transaction->addObject($invoice);
        $transaction->addObject($order);
        $transaction->save();
    }

    /**
     * @throws LocalizedException
     */
    private function shipOrder(SalesOrder $order, string $trackingNumber): void
    {
        if (!$order->canShip()) {
            throw new LocalizedException(__('Order #%1 cannot be shipped.', $order->getIncrementId()));
        }

        $tracks = [[
            'carrier_code' => NovaposhtaManual::CARRIER_CODE,
            'title' => 'Nova Poshta',
            'number' => $trackingNumber,
        ]];

        // An empty $items map means "ship nothing" for non-dummy items (ShipmentFactory's
        // validateItem() rejects items absent from the map), not "ship everything" — the full
        // ordered qty must be passed explicitly per item, mirroring core's
        // Order\Invoice\Save::_prepareShipment().
        $itemsToShip = [];
        foreach ($order->getAllItems() as $orderItem) {
            $itemsToShip[$orderItem->getItemId()] = $orderItem->getQtyOrdered();
        }

        /** @var \Magento\Sales\Model\Order\Shipment $shipment */
        $shipment = $this->orderShipmentFactory->create($order, $itemsToShip, $tracks);
        $shipment->register();

        $transaction = $this->transactionFactory->create();
        $transaction->addObject($shipment);
        $transaction->addObject($order);
        $transaction->save();
    }
}

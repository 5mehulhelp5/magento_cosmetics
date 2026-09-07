<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Accepts an external order-intake payload and stores it for asynchronous processing by the
 * order-intake cron (spec §4, §6). Does not create the Magento order synchronously.
 */
interface OrderIntakeManagementInterface
{
    /**
     * Validates and persists an intake row with status = pending.
     *
     * Required: storeCode, total, customerName, phone, city, trackingNumber. storeCode must
     * resolve to an existing, active store view. trackingNumber must not already exist — a
     * duplicate is rejected and nothing is saved.
     *
     * @return int The new intake row's entity ID.
     * @throws InputException Missing/blank required fields, or a non-positive total.
     * @throws LocalizedException Unknown/inactive storeCode, or a duplicate trackingNumber.
     * @throws CouldNotSaveException
     */
    public function place(
        string $storeCode,
        float $total,
        string $customerName,
        string $phone,
        string $city,
        string $trackingNumber,
        ?string $deliveryMethod = null,
    ): int;
}

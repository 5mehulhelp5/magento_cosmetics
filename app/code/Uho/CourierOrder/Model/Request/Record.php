<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Request;

use Magento\Framework\Model\AbstractModel;

class Record extends AbstractModel
{
    public const string REQUEST_ID = 'request_id';
    public const string TRACKING_NUMBER = 'tracking_number';
    public const string STORE_ID = 'store_id';
    public const string FULL_NAME = 'full_name';
    public const string PHONE = 'phone';
    public const string CITY_NAME_RAW = 'city_name_raw';
    public const string WAREHOUSE_IDENTIFIER_RAW = 'warehouse_identifier_raw';
    public const string RESOLVED_CITY_REF = 'resolved_city_ref';
    public const string RESOLVED_WAREHOUSE_REF = 'resolved_warehouse_ref';
    public const string TOTAL = 'total';
    public const string RECONCILIATION_PLAN = 'reconciliation_plan';
    public const string STATUS = 'status';
    public const string ATTEMPTS = 'attempts';
    public const string NEXT_RETRY_AT = 'next_retry_at';
    public const string CLAIMED_AT = 'claimed_at';
    public const string FAILURE_REASON = 'failure_reason';
    public const string ORDER_INCREMENT_ID = 'order_increment_id';
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_RETRY = 'retry';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_FAILED_PARTIAL = 'failed_partial';

    protected function _construct(): void
    {
        $this->_init(ResourceModel::class);
    }

    public function getRequestId(): ?int
    {
        $id = $this->getData(self::REQUEST_ID);

        return $id !== null ? (int) $id : null;
    }

    public function setRequestId(int $requestId): self
    {
        return $this->setData(self::REQUEST_ID, $requestId);
    }

    public function getTrackingNumber(): string
    {
        return (string) $this->getData(self::TRACKING_NUMBER);
    }

    public function setTrackingNumber(string $trackingNumber): self
    {
        return $this->setData(self::TRACKING_NUMBER, $trackingNumber);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getFullName(): string
    {
        return (string) $this->getData(self::FULL_NAME);
    }

    public function setFullName(string $fullName): self
    {
        return $this->setData(self::FULL_NAME, $fullName);
    }

    public function getPhone(): string
    {
        return (string) $this->getData(self::PHONE);
    }

    public function setPhone(string $phone): self
    {
        return $this->setData(self::PHONE, $phone);
    }

    public function getCityNameRaw(): string
    {
        return (string) $this->getData(self::CITY_NAME_RAW);
    }

    public function setCityNameRaw(string $cityNameRaw): self
    {
        return $this->setData(self::CITY_NAME_RAW, $cityNameRaw);
    }

    public function getWarehouseIdentifierRaw(): string
    {
        return (string) $this->getData(self::WAREHOUSE_IDENTIFIER_RAW);
    }

    public function setWarehouseIdentifierRaw(string $warehouseIdentifierRaw): self
    {
        return $this->setData(self::WAREHOUSE_IDENTIFIER_RAW, $warehouseIdentifierRaw);
    }

    public function getResolvedCityRef(): ?string
    {
        $value = $this->getData(self::RESOLVED_CITY_REF);

        return $value !== null ? (string) $value : null;
    }

    public function setResolvedCityRef(?string $resolvedCityRef): self
    {
        return $this->setData(self::RESOLVED_CITY_REF, $resolvedCityRef);
    }

    public function getResolvedWarehouseRef(): ?string
    {
        $value = $this->getData(self::RESOLVED_WAREHOUSE_REF);

        return $value !== null ? (string) $value : null;
    }

    public function setResolvedWarehouseRef(?string $resolvedWarehouseRef): self
    {
        return $this->setData(self::RESOLVED_WAREHOUSE_REF, $resolvedWarehouseRef);
    }

    public function getTotal(): string
    {
        return (string) $this->getData(self::TOTAL);
    }

    public function setTotal(string $total): self
    {
        return $this->setData(self::TOTAL, $total);
    }

    public function getReconciliationPlan(): ?string
    {
        $value = $this->getData(self::RECONCILIATION_PLAN);

        return $value !== null ? (string) $value : null;
    }

    public function setReconciliationPlan(?string $reconciliationPlan): self
    {
        return $this->setData(self::RECONCILIATION_PLAN, $reconciliationPlan);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getAttempts(): int
    {
        return (int) $this->getData(self::ATTEMPTS);
    }

    public function setAttempts(int $attempts): self
    {
        return $this->setData(self::ATTEMPTS, $attempts);
    }

    public function getNextRetryAt(): ?string
    {
        $value = $this->getData(self::NEXT_RETRY_AT);

        return $value !== null ? (string) $value : null;
    }

    public function setNextRetryAt(?string $nextRetryAt): self
    {
        return $this->setData(self::NEXT_RETRY_AT, $nextRetryAt);
    }

    public function getClaimedAt(): ?string
    {
        $value = $this->getData(self::CLAIMED_AT);

        return $value !== null ? (string) $value : null;
    }

    public function setClaimedAt(?string $claimedAt): self
    {
        return $this->setData(self::CLAIMED_AT, $claimedAt);
    }

    public function getFailureReason(): ?string
    {
        $value = $this->getData(self::FAILURE_REASON);

        return $value !== null ? (string) $value : null;
    }

    public function setFailureReason(?string $failureReason): self
    {
        return $this->setData(self::FAILURE_REASON, $failureReason);
    }

    public function getOrderIncrementId(): ?string
    {
        $value = $this->getData(self::ORDER_INCREMENT_ID);

        return $value !== null ? (string) $value : null;
    }

    public function setOrderIncrementId(?string $orderIncrementId): self
    {
        return $this->setData(self::ORDER_INCREMENT_ID, $orderIncrementId);
    }
}

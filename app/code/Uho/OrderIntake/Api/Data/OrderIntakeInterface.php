<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Api\Data;

interface OrderIntakeInterface
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_COMPLETE = 'complete';
    public const string STATUS_ERROR = 'error';

    public const string ENTITY_ID = 'entity_id';
    public const string STORE_CODE = 'store_code';
    public const string TOTAL = 'total';
    public const string CUSTOMER_NAME = 'customer_name';
    public const string PHONE = 'phone';
    public const string CITY = 'city';
    public const string DELIVERY_METHOD = 'delivery_method';
    public const string TRACKING_NUMBER = 'tracking_number';
    public const string STATUS = 'status';
    public const string ORDER_ID = 'order_id';
    public const string ERROR_MESSAGE = 'error_message';
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';

    /**
     * Inherited from Magento\Framework\Model\AbstractModel — kept untyped to stay
     * compatible with the parent's untyped getEntityId()/setEntityId() signatures.
     *
     * @return int|null
     */
    public function getEntityId();

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId($entityId);

    public function getStoreCode(): string;

    public function setStoreCode(string $storeCode): self;

    public function getTotal(): float;

    public function setTotal(float $total): self;

    public function getCustomerName(): string;

    public function setCustomerName(string $customerName): self;

    public function getPhone(): string;

    public function setPhone(string $phone): self;

    public function getCity(): string;

    public function setCity(string $city): self;

    public function getDeliveryMethod(): ?string;

    public function setDeliveryMethod(?string $deliveryMethod): self;

    public function getTrackingNumber(): string;

    public function setTrackingNumber(string $trackingNumber): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getOrderId(): ?int;

    public function setOrderId(?int $orderId): self;

    public function getErrorMessage(): ?string;

    public function setErrorMessage(?string $errorMessage): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(string $createdAt): self;

    public function getUpdatedAt(): ?string;

    public function setUpdatedAt(string $updatedAt): self;
}

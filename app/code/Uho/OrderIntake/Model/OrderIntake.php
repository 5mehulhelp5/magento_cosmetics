<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model;

use Magento\Framework\Model\AbstractModel;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake as OrderIntakeResource;

class OrderIntake extends AbstractModel implements OrderIntakeInterface
{
    protected function _construct(): void
    {
        $this->_init(OrderIntakeResource::class);
    }

    public function getStoreCode(): string
    {
        return (string) $this->getData(self::STORE_CODE);
    }

    public function setStoreCode(string $storeCode): self
    {
        return $this->setData(self::STORE_CODE, $storeCode);
    }

    public function getTotal(): float
    {
        return (float) $this->getData(self::TOTAL);
    }

    public function setTotal(float $total): self
    {
        return $this->setData(self::TOTAL, $total);
    }

    public function getCustomerName(): string
    {
        return (string) $this->getData(self::CUSTOMER_NAME);
    }

    public function setCustomerName(string $customerName): self
    {
        return $this->setData(self::CUSTOMER_NAME, $customerName);
    }

    public function getPhone(): string
    {
        return (string) $this->getData(self::PHONE);
    }

    public function setPhone(string $phone): self
    {
        return $this->setData(self::PHONE, $phone);
    }

    public function getCity(): string
    {
        return (string) $this->getData(self::CITY);
    }

    public function setCity(string $city): self
    {
        return $this->setData(self::CITY, $city);
    }

    public function getDeliveryMethod(): ?string
    {
        $deliveryMethod = $this->getData(self::DELIVERY_METHOD);

        return $deliveryMethod === null ? null : (string) $deliveryMethod;
    }

    public function setDeliveryMethod(?string $deliveryMethod): self
    {
        return $this->setData(self::DELIVERY_METHOD, $deliveryMethod);
    }

    public function getTrackingNumber(): string
    {
        return (string) $this->getData(self::TRACKING_NUMBER);
    }

    public function setTrackingNumber(string $trackingNumber): self
    {
        return $this->setData(self::TRACKING_NUMBER, $trackingNumber);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getOrderId(): ?int
    {
        $orderId = $this->getData(self::ORDER_ID);

        return $orderId === null ? null : (int) $orderId;
    }

    public function setOrderId(?int $orderId): self
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getErrorMessage(): ?string
    {
        $errorMessage = $this->getData(self::ERROR_MESSAGE);

        return $errorMessage === null ? null : (string) $errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        return $this->setData(self::ERROR_MESSAGE, $errorMessage);
    }

    public function getCreatedAt(): ?string
    {
        $createdAt = $this->getData(self::CREATED_AT);

        return $createdAt === null ? null : (string) $createdAt;
    }

    public function setCreatedAt(string $createdAt): self
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $updatedAt = $this->getData(self::UPDATED_AT);

        return $updatedAt === null ? null : (string) $updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): self
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}

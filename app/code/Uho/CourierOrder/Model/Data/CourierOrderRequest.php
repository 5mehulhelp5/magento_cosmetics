<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Data;

use Uho\CourierOrder\Api\Data\CourierOrderItemInterface;
use Uho\CourierOrder\Api\Data\CourierOrderRequestInterface;

/**
 * Concrete wire-format data object for the webapi ServiceInputProcessor to populate from the
 * POST /V1/courier-orders request body.
 */
class CourierOrderRequest implements CourierOrderRequestInterface
{
    private string $trackingNumber = '';
    private string $fullName = '';
    private string $phone = '';
    private string $cityName = '';
    private string $warehouseNumber = '';
    private string $total = '';
    private string $storeCode = '';
    private ?string $comment = null;
    /** @var CourierOrderItemInterface[]|null */
    private ?array $items = null;

    public function getTrackingNumber(): string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(string $trackingNumber): self
    {
        $this->trackingNumber = $trackingNumber;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getCityName(): string
    {
        return $this->cityName;
    }

    public function setCityName(string $cityName): self
    {
        $this->cityName = $cityName;

        return $this;
    }

    public function getWarehouseNumber(): string
    {
        return $this->warehouseNumber;
    }

    public function setWarehouseNumber(string $warehouseNumber): self
    {
        $this->warehouseNumber = $warehouseNumber;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getStoreCode(): string
    {
        return $this->storeCode;
    }

    public function setStoreCode(string $storeCode): self
    {
        $this->storeCode = $storeCode;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * @return CourierOrderItemInterface[]|null
     */
    public function getItems(): ?array
    {
        return $this->items;
    }

    /**
     * @param CourierOrderItemInterface[]|null $items
     */
    public function setItems(?array $items): self
    {
        $this->items = $items;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Api\Data;

/**
 * Wire-format courier order request payload (input to CourierOrderManagementInterface::submit()).
 */
interface CourierOrderRequestInterface
{
    public const TRACKING_NUMBER = 'tracking_number';
    public const FULL_NAME = 'full_name';
    public const PHONE = 'phone';
    public const CITY_NAME = 'city_name';
    public const WAREHOUSE_NUMBER = 'warehouse_number';
    public const TOTAL = 'total';
    public const STORE_CODE = 'store_code';
    public const ITEMS = 'items';
    public const COMMENT = 'comment';

    /**
     * @return string
     */
    public function getTrackingNumber(): string;

    /**
     * @param string $trackingNumber
     * @return $this
     */
    public function setTrackingNumber(string $trackingNumber): self;

    /**
     * @return string
     */
    public function getFullName(): string;

    /**
     * @param string $fullName
     * @return $this
     */
    public function setFullName(string $fullName): self;

    /**
     * @return string
     */
    public function getPhone(): string;

    /**
     * @param string $phone
     * @return $this
     */
    public function setPhone(string $phone): self;

    /**
     * @return string
     */
    public function getCityName(): string;

    /**
     * @param string $cityName
     * @return $this
     */
    public function setCityName(string $cityName): self;

    /**
     * @return string
     */
    public function getWarehouseNumber(): string;

    /**
     * @param string $warehouseNumber
     * @return $this
     */
    public function setWarehouseNumber(string $warehouseNumber): self;

    /**
     * @return string
     */
    public function getTotal(): string;

    /**
     * @param string $total
     * @return $this
     */
    public function setTotal(string $total): self;

    /**
     * @return string
     */
    public function getStoreCode(): string;

    /**
     * @param string $storeCode
     * @return $this
     */
    public function setStoreCode(string $storeCode): self;

    /**
     * @return string|null
     */
    public function getComment(): ?string;

    /**
     * @param string|null $comment
     * @return $this
     */
    public function setComment(?string $comment): self;

    /**
     * @return CourierOrderItemInterface[]|null
     */
    public function getItems(): ?array;

    /**
     * @param CourierOrderItemInterface[]|null $items
     * @return $this
     */
    public function setItems(?array $items): self;
}

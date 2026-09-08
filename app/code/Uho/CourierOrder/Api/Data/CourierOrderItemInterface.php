<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Api\Data;

/**
 * Optional courier payload item line (if the source system provides SKU-level detail).
 */
interface CourierOrderItemInterface
{
    public const SKU = 'sku';
    public const QTY = 'qty';

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;
}

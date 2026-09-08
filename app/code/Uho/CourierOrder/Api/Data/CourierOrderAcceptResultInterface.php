<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Api\Data;

/**
 * Result returned to the courier system by CourierOrderManagementInterface::submit().
 */
interface CourierOrderAcceptResultInterface
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DUPLICATE_PENDING = 'duplicate_pending';
    public const STATUS_DUPLICATE_PROCESSING = 'duplicate_processing';
    public const STATUS_DUPLICATE_COMPLETED = 'duplicate_completed';
    public const STATUS_DUPLICATE_FAILED = 'duplicate_failed';

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * Internal tracking id (uho_courier_order_request.request_id).
     *
     * @return string
     */
    public function getRequestReference(): string;

    /**
     * Set only when status is STATUS_DUPLICATE_COMPLETED.
     *
     * @return string|null
     */
    public function getOrderIncrementId(): ?string;
}

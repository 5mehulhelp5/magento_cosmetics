<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Uho\CourierOrder\Model\Request\Record;

class RequestStatusOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Record::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => Record::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => Record::STATUS_RETRY, 'label' => __('Retry')],
            ['value' => Record::STATUS_COMPLETED, 'label' => __('Completed')],
            ['value' => Record::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => Record::STATUS_FAILED_PARTIAL, 'label' => __('Failed (Partial — Order Created)')],
        ];
    }
}

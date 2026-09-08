<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;

class OrderIntake extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('uho_order_intake', OrderIntakeInterface::ENTITY_ID);
    }

    public function trackingNumberExists(string $trackingNumber): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), OrderIntakeInterface::ENTITY_ID)
            ->where(OrderIntakeInterface::TRACKING_NUMBER . ' = ?', $trackingNumber)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }
}

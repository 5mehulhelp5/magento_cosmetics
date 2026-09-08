<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Ui\Component\Listing;

use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Uho\CourierOrder\Model\Request\CollectionFactory;

class DataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function addFilter(Filter $filter): void
    {
        if ($filter->getField() === 'fulltext') {
            $value = '%' . $filter->getValue() . '%';
            $this->collection->addFieldToFilter(
                ['tracking_number', 'full_name', 'phone', 'order_increment_id'],
                [
                    ['like' => $value],
                    ['like' => $value],
                    ['like' => $value],
                    ['like' => $value],
                ]
            );

            return;
        }

        parent::addFilter($filter);
    }
}

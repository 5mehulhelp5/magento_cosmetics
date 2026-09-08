<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Controller\Adminhtml\CourierOrderRequest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Uho\CourierOrder\Model\Request\CollectionFactory;
use Uho\CourierOrder\Model\Request\Lifecycle;
use Uho\CourierOrder\Model\Request\Record;

use function in_array;
class MassRequeue extends Action
{
    public const ADMIN_RESOURCE = 'Uho_CourierOrderProcessor::requeue';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly Lifecycle $lifecycle,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $requeued = 0;
        $skipped = 0;

        /** @var Record $record */
        foreach ($collection->getItems() as $record) {
            if (!in_array($record->getStatus(), [Record::STATUS_FAILED, Record::STATUS_FAILED_PARTIAL], true)) {
                $skipped++;

                continue;
            }

            if (!$this->lifecycle->requeue((int) $record->getRequestId(), true)) {
                $skipped++;

                continue;
            }

            $requeued++;
        }

        if ($requeued > 0) {
            $this->messageManager->addSuccessMessage(__('Requeued %1 request(s).', $requeued));
        }

        if ($skipped > 0) {
            $this->messageManager->addWarningMessage(
                __('%1 request(s) skipped — only failed/failed_partial requests can be requeued.', $skipped)
            );
        }

        return $resultRedirect->setPath('*/*/index');
    }
}

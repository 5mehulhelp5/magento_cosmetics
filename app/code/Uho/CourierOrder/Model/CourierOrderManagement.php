<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Uho\CourierOrder\Api\CourierOrderManagementInterface;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterface;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterfaceFactory;
use Uho\CourierOrder\Api\Data\CourierOrderRequestInterface;
use Uho\CourierOrder\Model\Address\AddressResolver;
use Uho\CourierOrder\Model\Address\ResolvedAddress;
use Uho\CourierOrder\Model\Idempotency\DuplicateChecker;
use Uho\CourierOrder\Model\Reconciliation\Plan;
use Uho\CourierOrder\Model\Reconciliation\Planner;
use Uho\CourierOrder\Model\Reconciliation\ReconciliationUsageRecorder;
use Uho\CourierOrder\Model\Request\Record;
use Uho\CourierOrder\Model\Request\RecordFactory;
use Uho\CourierOrder\Model\Request\Repository;
use Uho\CourierOrder\Model\Validator\PayloadValidator;

class CourierOrderManagement implements CourierOrderManagementInterface
{
    public function __construct(
        private readonly PayloadValidator $payloadValidator,
        private readonly DuplicateChecker $duplicateChecker,
        private readonly AddressResolver $resolver,
        private readonly Planner $planner,
        private readonly Repository $requestRepository,
        private readonly RecordFactory $recordFactory,
        private readonly CourierOrderAcceptResultInterfaceFactory $acceptResultFactory,
        private readonly Json $json,
        private readonly ReconciliationUsageRecorder $reconciliationUsageRecorder,
    ) {
    }

    public function submit(CourierOrderRequestInterface $request): CourierOrderAcceptResultInterface
    {
        $duplicate = $this->duplicateChecker->findDuplicate($request->getTrackingNumber());
        if ($duplicate !== null) {
            return $duplicate;
        }

        $storeId = $this->payloadValidator->validate($request);
        $resolvedAddress = $this->resolver->resolve($request->getCityName(), $request->getWarehouseNumber());
        $plan = $this->planner->plan($request->getTotal(), $storeId);

        $record = $this->buildRecord($request, $storeId, $resolvedAddress, $plan);

        try {
            $record = $this->requestRepository->save($record);
        } catch (AlreadyExistsException) {
            $duplicate = $this->duplicateChecker->findDuplicate($request->getTrackingNumber());
            if ($duplicate !== null) {
                return $duplicate;
            }
            throw new AlreadyExistsException(new Phrase('Duplicate tracking_number could not be resolved.'));
        }

        $this->reconciliationUsageRecorder->recordUsage($plan);

        return $this->acceptResultFactory->create([
            'status' => CourierOrderAcceptResultInterface::STATUS_ACCEPTED,
            'requestReference' => (string) $record->getRequestId(),
            'orderIncrementId' => null,
        ]);
    }

    private function buildRecord(
        CourierOrderRequestInterface $request,
        int $storeId,
        ResolvedAddress $resolvedAddress,
        Plan $plan,
    ): Record {
        /** @var Record $record */
        $record = $this->recordFactory->create();
        $record->setTrackingNumber($request->getTrackingNumber())
            ->setStoreId($storeId)
            ->setFullName($request->getFullName())
            ->setPhone($request->getPhone())
            ->setCityNameRaw($request->getCityName())
            ->setWarehouseIdentifierRaw($request->getWarehouseNumber())
            ->setResolvedCityRef($resolvedAddress->getCityRef())
            ->setResolvedWarehouseRef($resolvedAddress->getWarehouseRef())
            ->setTotal($request->getTotal())
            ->setReconciliationPlan($this->serializePlan($plan))
            ->setStatus(Record::STATUS_PENDING)
            ->setAttempts(0);

        return $record;
    }

    private function serializePlan(Plan $plan): string
    {
        $lines = [];
        foreach ($plan->getLines() as $line) {
            $lines[] = [
                'sku' => $line->getSku(),
                'qty' => $line->getQty(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ];
        }

        return $this->json->serialize([
            'lines' => $lines,
            'adjustmentCents' => $plan->getAdjustmentCents(),
        ]);
    }
}

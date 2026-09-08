<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterface;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterfaceFactory;
use Uho\CourierOrder\Api\Data\CourierOrderRequestInterface;
use Uho\CourierOrder\Model\Address\AddressResolver;
use Uho\CourierOrder\Model\Address\ResolvedAddress;
use Uho\CourierOrder\Model\CourierOrderManagement;
use Uho\CourierOrder\Model\Idempotency\DuplicateChecker;
use Uho\CourierOrder\Model\Reconciliation\Plan;
use Uho\CourierOrder\Model\Reconciliation\Planner;
use Uho\CourierOrder\Model\Reconciliation\ReconciliationUsageRecorder;
use Uho\CourierOrder\Model\Request\Record;
use Uho\CourierOrder\Model\Request\RecordFactory;
use Uho\CourierOrder\Model\Request\Repository;
use Uho\CourierOrder\Model\Validator\PayloadValidator;

class CourierOrderManagementTest extends TestCase
{
    private const string TRACKING_NUMBER = '20450123456789';
    private const int STORE_ID = 1;
    private const int REQUEST_ID = 42;

    private PayloadValidator&MockObject $payloadValidator;
    private DuplicateChecker&MockObject $duplicateChecker;
    private AddressResolver&MockObject $resolver;
    private Planner&MockObject $planner;
    private Repository&MockObject $requestRepository;
    private RecordFactory&MockObject $recordFactory;
    private CourierOrderAcceptResultInterfaceFactory&MockObject $acceptResultFactory;
    private ReconciliationUsageRecorder&MockObject $reconciliationUsageRecorder;
    private CourierOrderManagement $management;

    protected function setUp(): void
    {
        $this->payloadValidator = $this->createMock(PayloadValidator::class);
        $this->duplicateChecker = $this->createMock(DuplicateChecker::class);
        $this->resolver = $this->createMock(AddressResolver::class);
        $this->planner = $this->createMock(Planner::class);
        $this->requestRepository = $this->createMock(Repository::class);
        $this->recordFactory = $this->createMock(RecordFactory::class);
        $this->acceptResultFactory = $this->createMock(CourierOrderAcceptResultInterfaceFactory::class);
        $this->reconciliationUsageRecorder = $this->createMock(ReconciliationUsageRecorder::class);

        $this->management = new CourierOrderManagement(
            $this->payloadValidator,
            $this->duplicateChecker,
            $this->resolver,
            $this->planner,
            $this->requestRepository,
            $this->recordFactory,
            $this->acceptResultFactory,
            new Json(),
            $this->reconciliationUsageRecorder,
        );
    }

    public function testRecordUsageIsCalledOnlyAfterASuccessfulSave(): void
    {
        $request = $this->buildRequest();
        $plan = new Plan([], 0);

        $this->duplicateChecker->method('findDuplicate')->with(self::TRACKING_NUMBER)->willReturn(null);
        $this->payloadValidator->method('validate')->with($request)->willReturn(self::STORE_ID);
        $this->resolver->method('resolve')->willReturn(
            new ResolvedAddress('city-ref', 'City', 'wh-ref', 'Warehouse')
        );
        $this->planner->method('plan')->willReturn($plan);

        $record = $this->createMock(Record::class);
        $record->method('getRequestId')->willReturn(self::REQUEST_ID);
        $this->recordFactory->method('create')->willReturn($record);
        $record->method('setTrackingNumber')->willReturnSelf();
        $record->method('setStoreId')->willReturnSelf();
        $record->method('setFullName')->willReturnSelf();
        $record->method('setPhone')->willReturnSelf();
        $record->method('setCityNameRaw')->willReturnSelf();
        $record->method('setWarehouseIdentifierRaw')->willReturnSelf();
        $record->method('setResolvedCityRef')->willReturnSelf();
        $record->method('setResolvedWarehouseRef')->willReturnSelf();
        $record->method('setTotal')->willReturnSelf();
        $record->method('setReconciliationPlan')->willReturnSelf();
        $record->method('setStatus')->willReturnSelf();
        $record->method('setAttempts')->willReturnSelf();

        $this->requestRepository->expects($this->once())
            ->method('save')
            ->with($record)
            ->willReturn($record);

        $this->reconciliationUsageRecorder->expects($this->once())
            ->method('recordUsage')
            ->with($plan);

        $acceptResult = $this->createMock(CourierOrderAcceptResultInterface::class);
        $this->acceptResultFactory->method('create')->willReturn($acceptResult);

        $result = $this->management->submit($request);

        $this->assertSame($acceptResult, $result);
    }

    public function testRecordUsageIsNotCalledOnTheDuplicateTrackingNumberPath(): void
    {
        $request = $this->buildRequest();
        $plan = new Plan([], 0);

        $this->duplicateChecker->method('findDuplicate')->with(self::TRACKING_NUMBER)->willReturnOnConsecutiveCalls(
            null,
            $this->createMock(CourierOrderAcceptResultInterface::class)
        );
        $this->payloadValidator->method('validate')->with($request)->willReturn(self::STORE_ID);
        $this->resolver->method('resolve')->willReturn(
            new ResolvedAddress('city-ref', 'City', 'wh-ref', 'Warehouse')
        );
        $this->planner->method('plan')->willReturn($plan);

        $record = $this->createMock(Record::class);
        $this->recordFactory->method('create')->willReturn($record);
        $record->method('setTrackingNumber')->willReturnSelf();
        $record->method('setStoreId')->willReturnSelf();
        $record->method('setFullName')->willReturnSelf();
        $record->method('setPhone')->willReturnSelf();
        $record->method('setCityNameRaw')->willReturnSelf();
        $record->method('setWarehouseIdentifierRaw')->willReturnSelf();
        $record->method('setResolvedCityRef')->willReturnSelf();
        $record->method('setResolvedWarehouseRef')->willReturnSelf();
        $record->method('setTotal')->willReturnSelf();
        $record->method('setReconciliationPlan')->willReturnSelf();
        $record->method('setStatus')->willReturnSelf();
        $record->method('setAttempts')->willReturnSelf();

        $this->requestRepository->expects($this->once())
            ->method('save')
            ->willThrowException(new AlreadyExistsException());

        $this->reconciliationUsageRecorder->expects($this->never())->method('recordUsage');

        $result = $this->management->submit($request);

        $this->assertInstanceOf(CourierOrderAcceptResultInterface::class, $result);
    }

    private function buildRequest(): CourierOrderRequestInterface&MockObject
    {
        $request = $this->createMock(CourierOrderRequestInterface::class);
        $request->method('getTrackingNumber')->willReturn(self::TRACKING_NUMBER);
        $request->method('getFullName')->willReturn('Jane Doe');
        $request->method('getPhone')->willReturn('+380501234567');
        $request->method('getCityName')->willReturn('Kyiv');
        $request->method('getWarehouseNumber')->willReturn('1');
        $request->method('getTotal')->willReturn('100.00');

        return $request;
    }
}

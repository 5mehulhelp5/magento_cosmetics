<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uho\OrderIntake\Model\TotalSplitter;

class TotalSplitterTest extends TestCase
{
    private const int RANDOMNESS_SAMPLE_SIZE = 25;

    private TotalSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new TotalSplitter();
    }

    public function testSplitReturnsASingleElementArrayWhenTotalIsOne(): void
    {
        // Documented Phase 5 decision: two positive whole-unit parts cannot sum to 1, so a single
        // one-element split is returned instead of throwing or producing an invalid split.
        $this->assertSame([1], $this->splitter->split(1));
    }

    public function testSplitOfTotalTwoAlwaysReturnsOneAndOne(): void
    {
        // The only valid two-positive-whole-unit split of 2 is [1, 1] — deterministic regardless
        // of the random cut point.
        for ($i = 0; $i < self::RANDOMNESS_SAMPLE_SIZE; $i++) {
            $this->assertSame([1, 1], $this->splitter->split(2));
        }
    }

    #[DataProvider('totalsRequiringATwoPartSplitProvider')]
    public function testSplitProducesTwoPositiveWholeUnitPartsSummingToTotal(int $total): void
    {
        for ($i = 0; $i < self::RANDOMNESS_SAMPLE_SIZE; $i++) {
            $parts = $this->splitter->split($total);

            $this->assertCount(2, $parts);
            $this->assertContainsOnlyInt($parts);
            $this->assertGreaterThan(0, $parts[0]);
            $this->assertGreaterThan(0, $parts[1]);
            $this->assertSame($total, $parts[0] + $parts[1]);
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function totalsRequiringATwoPartSplitProvider(): array
    {
        return [
            'total = 2' => [2],
            'total = 3' => [3],
            'total = 5' => [5],
            'total = 10' => [10],
            'total = 99' => [99],
            'total = 1000' => [1000],
        ];
    }

    #[DataProvider('nonPositiveTotalProvider')]
    public function testSplitRejectsANonPositiveTotal(int $total): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->splitter->split($total);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function nonPositiveTotalProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }
}

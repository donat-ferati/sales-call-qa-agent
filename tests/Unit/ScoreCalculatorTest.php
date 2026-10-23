<?php

namespace Tests\Unit;

use App\Services\ScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ScoreCalculatorTest extends TestCase
{
    #[DataProvider('scorecards')]
    public function test_weighted_scores_preserve_applicability_and_uncertainty(array $criteria, array $expected): void
    {
        $this->assertSame($expected, (new ScoreCalculator())->calculate($criteria));
    }

    public static function scorecards(): array
    {
        return [
            'weighted resolved score' => [
                [['weight' => 3, 'decision' => 'PASS'], ['weight' => 1, 'decision' => 'FAIL']],
                ['score' => 75.0, 'provisional_score' => 75.0, 'coverage' => 100.0],
            ],
            'not applicable leaves denominator' => [
                [['weight' => 1, 'decision' => 'PASS'], ['weight' => 99, 'decision' => 'NOT_APPLICABLE']],
                ['score' => 100.0, 'provisional_score' => 100.0, 'coverage' => 100.0],
            ],
            'uncertainty does not silently fail' => [
                [['weight' => 2, 'decision' => 'PASS'], ['weight' => 1, 'decision' => 'FAIL'], ['weight' => 3, 'decision' => 'INSUFFICIENT_EVIDENCE']],
                ['score' => null, 'provisional_score' => 66.67, 'coverage' => 50.0],
            ],
            'all unresolved has no percentage' => [
                [['weight' => 5, 'decision' => 'INSUFFICIENT_EVIDENCE']],
                ['score' => null, 'provisional_score' => null, 'coverage' => 0.0],
            ],
            'all not applicable has no score' => [
                [['weight' => 3, 'decision' => 'NOT_APPLICABLE']],
                ['score' => null, 'provisional_score' => null, 'coverage' => 0],
            ],
            'round once to two decimals' => [
                [['weight' => 1, 'decision' => 'PASS'], ['weight' => 2, 'decision' => 'FAIL']],
                ['score' => 33.33, 'provisional_score' => 33.33, 'coverage' => 100.0],
            ],
            'resolved failures earn zero' => [
                [['weight' => 2, 'decision' => 'FAIL']],
                ['score' => 0.0, 'provisional_score' => 0.0, 'coverage' => 100.0],
            ],
        ];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_weights_are_rejected(mixed $weight): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ScoreCalculator())->calculate([['weight' => $weight, 'decision' => 'PASS']]);
    }

    public static function invalidWeights(): array
    {
        return [[0], [-1], [101], [1.5], ['2'], [null], [true]];
    }

    public function test_unknown_decisions_are_not_coerced_to_fail(): void
    {
        $this->expectException(ValueError::class);
        (new ScoreCalculator())->calculate([['weight' => 1, 'decision' => 'MAYBE']]);
    }
}

<?php

namespace Tests\Unit;

use App\AI\Exceptions\ProcessingException;
use App\AI\TranscriptWindowOrder;
use PHPUnit\Framework\TestCase;

final class TranscriptWindowOrderTest extends TestCase
{
    private array $segments = [
        ['id' => 81, 'text' => 'abcdefghij'],
        ['id' => 12, 'text' => 'klmnopqrst'],
        ['id' => 55, 'text' => 'uvwxyz'],
    ];

    public function test_reused_late_windows_sort_after_new_earlier_windows_using_source_order(): void
    {
        $records = [
            $this->record('copied-late', [['segment_id' => 12, 'text_start' => 0, 'text_end' => 10]]),
            $this->record('new-middle', [['segment_id' => 81, 'text_start' => 5, 'text_end' => 10]]),
            $this->record('new-early', [['segment_id' => 81, 'text_start' => 0, 'text_end' => 5]]),
        ];

        $ordered = (new TranscriptWindowOrder())->sort($records, $this->segments);

        $this->assertSame(['new-early', 'new-middle', 'copied-late'], array_column($ordered, 'name'));
        $this->assertSame($records[0], $ordered[2]);
    }

    public function test_a_window_crossing_another_primary_window_is_rejected(): void
    {
        $records = [
            $this->record('interleaving', [
                ['segment_id' => 81, 'text_start' => 0, 'text_end' => 10],
                ['segment_id' => 55, 'text_start' => 0, 'text_end' => 6],
            ]),
            $this->record('middle', [['segment_id' => 12, 'text_start' => 0, 'text_end' => 10]]),
        ];
        $this->expectExceptionMessage('overlap or interleave');

        (new TranscriptWindowOrder())->sort($records, $this->segments);
    }

    public function test_context_overlap_does_not_count_as_primary_coverage(): void
    {
        $first = $this->record('first', [['segment_id' => 81, 'text_start' => 0, 'text_end' => 10]]);
        $second = $this->record('second', [['segment_id' => 12, 'text_start' => 0, 'text_end' => 10]]);
        $second['metadata']['analyzed_spans'] = [
            ['segment_id' => 81, 'text_start' => 5, 'text_end' => 10, 'overlap' => true],
            ['segment_id' => 12, 'text_start' => 0, 'text_end' => 10, 'overlap' => false],
        ];

        $this->assertSame([$first, $second], (new TranscriptWindowOrder())->sort([$second, $first], $this->segments));
    }

    public function test_remaining_ranges_are_split_at_copied_regions_and_rejoined_only_at_true_boundaries(): void
    {
        $remaining = [
            ['id' => 55, 'text_start' => 0, 'text_end' => 6, 'text' => 'uvwxyz'],
            ['id' => 81, 'text_start' => 7, 'text_end' => 10, 'text' => 'hij'],
            ['id' => 81, 'text_start' => 0, 'text_end' => 3, 'text' => 'abc'],
            ['id' => 12, 'text_start' => 0, 'text_end' => 10, 'text' => 'klmnopqrst'],
        ];

        $groups = (new TranscriptWindowOrder())->groups($remaining, $this->segments);

        $this->assertSame([[$remaining[2]], [$remaining[1], $remaining[3], $remaining[0]]], $groups);
    }

    public function test_a_whole_missing_original_segment_is_a_group_boundary(): void
    {
        $remaining = [
            ['id' => 81, 'text_start' => 0, 'text_end' => 10],
            ['id' => 55, 'text_start' => 0, 'text_end' => 6],
        ];

        $this->assertSame([[$remaining[0]], [$remaining[1]]], (new TranscriptWindowOrder())->groups($remaining, $this->segments));
    }

    public function test_overlapping_remaining_ranges_cannot_be_planned_twice(): void
    {
        $this->expectException(ProcessingException::class);

        (new TranscriptWindowOrder())->groups([
            ['id' => 81, 'text_start' => 0, 'text_end' => 6],
            ['id' => 81, 'text_start' => 5, 'text_end' => 10],
        ], $this->segments);
    }

    public function test_original_unicode_byte_boundaries_and_text_are_enforced(): void
    {
        $this->expectExceptionMessage('unmodified original text');

        (new TranscriptWindowOrder())->groups([
            ['id' => 1, 'text_start' => 1, 'text_end' => 2],
        ], [['id' => 1, 'text' => 'éclair']]);
    }

    private function record(string $name, array $spans): array
    {
        return ['name' => $name, 'metadata' => ['primary_spans' => $spans]];
    }
}

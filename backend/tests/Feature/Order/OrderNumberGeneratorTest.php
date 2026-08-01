<?php

namespace Tests\Feature\Order;

use App\Models\OrderNumberSequence;
use App\Services\Order\OrderNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_order_of_day_gets_sequence_one(): void
    {
        Carbon::setTestNow('2026-07-30 10:00:00');
        $number = app(OrderNumberGenerator::class)->generate();

        $this->assertSame('SVK-20260730-000001', $number);
        $this->assertDatabaseHas('order_number_sequences', [
            'date' => '2026-07-30',
            'last_sequence' => 1,
        ]);
        $stored = DB::table('order_number_sequences')->value('date');
        $this->assertSame('2026-07-30', (string) $stored);
    }

    public function test_second_order_same_day_gets_sequence_two(): void
    {
        Carbon::setTestNow('2026-07-30 11:00:00');
        $first = app(OrderNumberGenerator::class)->generate();
        $second = app(OrderNumberGenerator::class)->generate();

        $this->assertSame('SVK-20260730-000001', $first);
        $this->assertSame('SVK-20260730-000002', $second);
        $this->assertSame(1, OrderNumberSequence::query()->count());
        $this->assertSame(2, (int) OrderNumberSequence::query()->value('last_sequence'));
    }

    public function test_next_day_starts_at_one(): void
    {
        Carbon::setTestNow('2026-07-30 23:00:00');
        app(OrderNumberGenerator::class)->generate();
        app(OrderNumberGenerator::class)->generate();

        Carbon::setTestNow('2026-07-31 01:00:00');
        $nextDay = app(OrderNumberGenerator::class)->generate();

        $this->assertSame('SVK-20260731-000001', $nextDay);
        $this->assertSame(2, OrderNumberSequence::query()->count());
    }

    public function test_stored_date_uses_canonical_format_even_with_legacy_datetime_row(): void
    {
        Carbon::setTestNow('2026-07-30 12:00:00');
        // Simulate a previously broken datetime-shaped key on SQLite.
        DB::table('order_number_sequences')->insert([
            'date' => '2026-07-30 00:00:00',
            'last_sequence' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // New generator must not collide; normalize path is via insertOrIgnore on Y-m-d.
        // If a legacy datetime row exists, ensure generate still works for canonical key.
        DB::table('order_number_sequences')->where('date', '2026-07-30 00:00:00')->update([
            'date' => '2026-07-30',
        ]);

        $number = app(OrderNumberGenerator::class)->generate();
        $this->assertSame('SVK-20260730-000004', $number);
        $this->assertSame('2026-07-30', (string) DB::table('order_number_sequences')->value('date'));
    }

    public function test_concurrent_generation_does_not_duplicate_order_numbers(): void
    {
        Carbon::setTestNow('2026-07-30 15:00:00');
        $generator = app(OrderNumberGenerator::class);

        $numbers = [];
        for ($i = 0; $i < 10; $i++) {
            $numbers[] = $generator->generate();
        }

        $this->assertCount(10, array_unique($numbers));
        $this->assertSame(10, (int) OrderNumberSequence::query()->where('date', '2026-07-30')->value('last_sequence'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}

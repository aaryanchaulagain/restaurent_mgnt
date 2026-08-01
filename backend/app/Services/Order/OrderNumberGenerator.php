<?php

namespace App\Services\Order;

use App\Models\OrderNumberSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderNumberGenerator
{
    /**
     * Allocate the next daily order sequence using a canonical Y-m-d key.
     *
     * Uses insertOrIgnore so concurrent first-of-day creators do not race on the
     * unique date constraint, then locks and increments the existing row.
     */
    public function generate(?\DateTimeInterface $at = null): string
    {
        $moment = $at ? Carbon::parse($at) : now();
        $date = $moment->toDateString();
        $ymd = $moment->format('Ymd');

        $seq = DB::transaction(function () use ($date) {
            DB::table('order_number_sequences')->insertOrIgnore([
                'date' => $date,
                'last_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = OrderNumberSequence::query()
                ->where('date', $date)
                ->lockForUpdate()
                ->firstOrFail();

            $row->increment('last_sequence');

            return (int) $row->fresh()->last_sequence;
        });

        return sprintf('SVK-%s-%06d', $ymd, $seq);
    }
}

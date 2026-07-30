<?php

namespace App\Services\Order;

use App\Models\OrderNumberSequence;
use Illuminate\Support\Facades\DB;

class OrderNumberGenerator
{
    public function generate(): string
    {
        $date = now()->toDateString();

        $seq = DB::transaction(function () use ($date) {
            $row = OrderNumberSequence::query()->lockForUpdate()->where('date', $date)->first();
            if ($row) {
                $row->increment('last_sequence');

                return $row->last_sequence;
            }
            $row = OrderNumberSequence::query()->create(['date' => $date, 'last_sequence' => 1]);

            return 1;
        });

        return sprintf('SVK-%s-%06d', now()->format('Ymd'), $seq);
    }
}

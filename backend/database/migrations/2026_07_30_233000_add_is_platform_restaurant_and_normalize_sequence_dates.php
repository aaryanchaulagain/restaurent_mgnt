<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('restaurants', 'is_platform_restaurant')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('is_platform_restaurant')->default(false)->after('ownership_type');
                $table->index('is_platform_restaurant');
            });
        }

        // Normalize any datetime-shaped sequence keys to canonical Y-m-d and merge duplicates.
        if (Schema::hasTable('order_number_sequences')) {
            $this->normalizeOrderNumberSequenceDates();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurants', 'is_platform_restaurant')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropIndex(['is_platform_restaurant']);
                $table->dropColumn('is_platform_restaurant');
            });
        }
    }

    private function normalizeOrderNumberSequenceDates(): void
    {
        $rows = DB::table('order_number_sequences')->orderByDesc('last_sequence')->orderBy('id')->get();
        $canonical = [];

        foreach ($rows as $row) {
            $raw = (string) $row->date;
            $day = strlen($raw) >= 10 ? substr($raw, 0, 10) : $raw;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }

            if (! isset($canonical[$day])) {
                $canonical[$day] = [
                    'keep_id' => $row->id,
                    'last_sequence' => (int) $row->last_sequence,
                    'duplicate_ids' => [],
                ];
                if ($raw !== $day) {
                    DB::table('order_number_sequences')->where('id', $row->id)->update(['date' => $day]);
                }
                continue;
            }

            $canonical[$day]['last_sequence'] = max(
                $canonical[$day]['last_sequence'],
                (int) $row->last_sequence
            );
            $canonical[$day]['duplicate_ids'][] = $row->id;
        }

        foreach ($canonical as $day => $info) {
            DB::table('order_number_sequences')->where('id', $info['keep_id'])->update([
                'date' => $day,
                'last_sequence' => $info['last_sequence'],
            ]);
            if ($info['duplicate_ids'] !== []) {
                DB::table('order_number_sequences')->whereIn('id', $info['duplicate_ids'])->delete();
            }
        }
    }
};

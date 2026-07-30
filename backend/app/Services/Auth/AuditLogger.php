<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(
        string $action,
        ?User $actor = null,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $restaurantId = null,
        ?array $metadata = null,
        ?Request $request = null,
    ): void {
        $request ??= request();

        AuditLog::query()->create([
            'actor_user_id' => $actor?->id ?? Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'restaurant_id' => $restaurantId,
            'old_values' => $this->scrub($oldValues),
            'new_values' => $this->scrub($newValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $this->scrub($metadata),
            'created_at' => now(),
        ]);
    }

    private function scrub(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $blocked = [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'secret_encrypted',
            'recovery_codes',
            'code',
            'remember_token',
        ];

        foreach ($blocked as $key) {
            unset($values[$key]);
        }

        return $values;
    }
}

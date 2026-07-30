<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class SessionTracker
{
    public function start(User $user, Request $request): UserSession
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : Str::uuid()->toString();
        $key = hash('sha256', $sessionId.'|'.$user->id.'|'.Str::random(8));

        if ($request->hasSession()) {
            $request->session()->put('tracked_session_key', $key);
        } else {
            Session::put('tracked_session_key', $key);
        }

        return UserSession::query()->create([
            'user_id' => $user->id,
            'session_key' => $key,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_label' => $this->deviceLabel($request->userAgent()),
            'last_activity_at' => now(),
        ]);
    }

    public function touch(Request $request): void
    {
        $key = Session::get('tracked_session_key');
        if (! $key) {
            return;
        }

        UserSession::query()
            ->where('session_key', $key)
            ->whereNull('revoked_at')
            ->update(['last_activity_at' => now()]);
    }

    public function revokeCurrent(User $user): void
    {
        $key = Session::get('tracked_session_key');
        if (! $key) {
            return;
        }

        UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_key', $key)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Session::forget('tracked_session_key');
    }

    public function revokeById(User $user, int $sessionId): bool
    {
        $session = UserSession::query()
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->whereNull('revoked_at')
            ->first();

        if (! $session) {
            return false;
        }

        $session->update(['revoked_at' => now()]);

        return true;
    }

    public function revokeAll(User $user, bool $exceptCurrent = false): int
    {
        $query = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at');

        if ($exceptCurrent) {
            $key = Session::get('tracked_session_key');
            if ($key) {
                $query->where('session_key', '!=', $key);
            }
        }

        return $query->update(['revoked_at' => now()]);
    }

    public function deviceLabel(?string $userAgent): string
    {
        $ua = $userAgent ?? 'Unknown device';

        return Str::limit($ua, 80, '…');
    }
}

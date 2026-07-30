<?php

namespace App\Services\Auth;

use App\Models\MfaMethod;
use App\Models\MfaRecoveryCode;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    public function __construct(
        private readonly Google2FA $google2fa = new Google2FA,
        private readonly AuditLogger $auditLogger = new AuditLogger,
    ) {}

    /**
     * @return array{secret: string, qr_svg: string, otpauth_url: string}
     */
    public function beginSetup(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        MfaMethod::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => 'totp'],
            [
                'secret_encrypted' => $secret,
                'is_confirmed' => false,
                'is_primary' => true,
                'confirmed_at' => null,
            ],
        );

        $otpauth = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Suvakamana'),
            $user->email,
            $secret,
        );

        return [
            'secret' => $secret,
            'qr_svg' => $this->qrSvg($otpauth),
            'otpauth_url' => $otpauth,
        ];
    }

    /**
     * @return list<string>
     */
    public function confirmSetup(User $user, string $code): array
    {
        $method = $user->mfaMethod()->firstOrFail();

        if (! $this->google2fa->verifyKey($method->secret_encrypted, $code)) {
            abort(422, 'Invalid authentication code.');
        }

        $method->update([
            'is_confirmed' => true,
            'confirmed_at' => now(),
        ]);

        $codes = $this->generateRecoveryCodes($user);
        $this->auditLogger->log('mfa.enabled', $user, $user);

        return $codes;
    }

    public function verifyCode(User $user, string $code): bool
    {
        $method = $user->mfaMethod()->where('is_confirmed', true)->first();
        if (! $method) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($method->secret_encrypted, $code);
    }

    public function verifyRecoveryCode(User $user, string $plainCode): bool
    {
        $codes = $user->mfaRecoveryCodes()->whereNull('used_at')->get();

        foreach ($codes as $stored) {
            if (Hash::check(strtoupper(trim($plainCode)), $stored->code_hash)) {
                $stored->update(['used_at' => now()]);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes($user);
        $this->auditLogger->log('mfa.recovery_codes_regenerated', $user, $user);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->mfaMethod()->delete();
        $user->mfaRecoveryCodes()->delete();
        $this->auditLogger->log('mfa.disabled', $user, $user);
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(User $user): array
    {
        $user->mfaRecoveryCodes()->delete();

        $plain = [];
        for ($i = 0; $i < 8; $i++) {
            $code = Str::upper(Str::random(4).'-'.Str::random(4));
            $plain[] = $code;
            MfaRecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        return $plain;
    }

    private function qrSvg(string $otpauth): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($otpauth);
    }
}

<?php

namespace App\Support\Verification;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\WhatsAppPaused;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Protege el envío de códigos (WhatsApp y correo) contra bots y abuso: captcha y campo trampa,
 * límites por destino, IP y cuenta, tope diario de WhatsApp y corte automático cuando se piden
 * muchos códigos que nadie usa. La última capa limita el gasto aunque las demás fallen.
 */
class CodeGuard
{
    public const PAUSED_KEY = 'whatsapp_paused';

    /** Detector de abuso: con más de estos envíos en la ventana… */
    public const PUMPING_MIN_SENDS = 20;

    /** …y menos de esta proporción verificada, se pausa WhatsApp. */
    public const PUMPING_MIN_VERIFIED = 0.3;

    /** Códigos que se agotaron (5 intentos mal) por destino y día. */
    public const MAX_FAILED_CODES = 3;

    public function __construct(private Captcha $captcha) {}

    /**
     * Captcha (Turnstile) y campo trampa `website` de un formulario que manda códigos.
     */
    public function ensureHuman(?string $captchaToken, ?string $honeypot = null, string $field = 'captcha_token'): void
    {
        if (filled($honeypot) || ! $this->captcha->passes($captchaToken, request()->ip())) {
            throw ValidationException::withMessages([$field => 'Confirmá que no sos un robot.']);
        }
    }

    /**
     * Lanza `TooManyCodes` si no se puede mandar otro código a ese destino (o desde esa IP o cuenta).
     */
    public function ensureCanSend(string $destination, ?User $user = null): void
    {
        foreach ($this->limits($destination, $user) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw TooManyCodes::wait();
            }
        }

        if (RateLimiter::tooManyAttempts($this->failedKey($destination), self::MAX_FAILED_CODES)) {
            throw TooManyCodes::wait();
        }
    }

    /**
     * Cuenta un intento sin destino (ej. "Olvidé mi contraseña" de un número sin cuenta): solo la IP.
     */
    public function hitIp(): void
    {
        foreach ($this->ipLimits() as [$key, $max, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    public function ensureIpAllowed(): void
    {
        foreach ($this->ipLimits() as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw TooManyCodes::wait();
            }
        }
    }

    /**
     * WhatsApp disponible: no está pausado y no llegó al tope del día.
     */
    public function whatsappAvailable(): bool
    {
        if (self::paused() !== null) {
            return false;
        }

        if ($this->sentToday() >= config('services.whatsapp.daily_limit')) {
            $this->pause('Se llegó al tope diario de '.config('services.whatsapp.daily_limit').' códigos.');

            return false;
        }

        return true;
    }

    /**
     * Registra el envío, suma a los límites y, si es por WhatsApp, revisa el detector de abuso.
     */
    public function record(?User $user, string $channel, string $purpose, string $destination): void
    {
        foreach ($this->limits($destination, $user) as [$key, $max, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        DB::table('verification_sends')->insert([
            'user_id' => $user?->id,
            'channel' => $channel,
            'purpose' => $purpose,
            'destination' => $destination,
            'country' => $channel === 'whatsapp' ? Phone::country($destination) : null,
            'ip' => request()->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($channel === 'whatsapp') {
            $this->detectPumping();
        }
    }

    /**
     * El código mandado a ese destino se usó bien.
     */
    public function markVerified(string $destination): void
    {
        $id = DB::table('verification_sends')
            ->where('destination', $destination)
            ->whereNull('verified_at')
            ->latest('id')
            ->value('id');

        if ($id !== null) {
            DB::table('verification_sends')->where('id', $id)->update(['verified_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Un código se agotó (5 intentos mal): al tercero del día, ese destino queda bloqueado.
     */
    public function codeFailed(string $destination): void
    {
        RateLimiter::hit($this->failedKey($destination), 86400);
    }

    /**
     * @return array{at: string, reason: string}|null
     */
    public static function paused(): ?array
    {
        return PlatformSetting::get(self::PAUSED_KEY);
    }

    public function pause(string $reason): void
    {
        if (self::paused() !== null) {
            return;
        }

        PlatformSetting::put(self::PAUSED_KEY, ['at' => now()->toIso8601String(), 'reason' => $reason]);

        Notification::send(User::query()->where('is_super_admin', true)->get(), new WhatsAppPaused($reason));
    }

    public function resume(): void
    {
        PlatformSetting::forget(self::PAUSED_KEY);
    }

    /**
     * Para el panel de plataforma: envíos de hoy, % verificado y gasto estimado.
     *
     * @return array{whatsapp: int, mail: int, verified_rate: float|null, cost_usd: float}
     */
    public function stats(): array
    {
        $today = DB::table('verification_sends')->where('created_at', '>=', now()->startOfDay());
        $whatsapp = (clone $today)->where('channel', 'whatsapp')->count();
        $total = (clone $today)->count();
        $verified = (clone $today)->whereNotNull('verified_at')->count();

        return [
            'whatsapp' => $whatsapp,
            'mail' => $total - $whatsapp,
            'verified_rate' => $total > 0 ? $verified / $total : null,
            'cost_usd' => round($whatsapp * (float) config('services.whatsapp.cost_per_code'), 2),
        ];
    }

    private function sentToday(): int
    {
        return DB::table('verification_sends')
            ->where('channel', 'whatsapp')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Muchos códigos por WhatsApp que nadie ingresa: la señal típica de bots. Mira los envíos de la
     * última hora con al menos 10 minutos (para dar tiempo a ingresar el código).
     */
    private function detectPumping(): void
    {
        $window = DB::table('verification_sends')
            ->where('channel', 'whatsapp')
            ->whereBetween('created_at', [now()->subHour(), now()->subMinutes(10)]);

        $sent = (clone $window)->count();

        if ($sent <= self::PUMPING_MIN_SENDS) {
            return;
        }

        $verified = (clone $window)->whereNotNull('verified_at')->count();

        if ($verified / $sent < self::PUMPING_MIN_VERIFIED) {
            $this->pause("En la última hora se mandaron {$sent} códigos y se usaron {$verified}.");
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}> [clave, máximo, segundos]
     */
    private function limits(string $destination, ?User $user): array
    {
        $destination = sha1(mb_strtolower($destination));

        return [
            ["codes:dest-minute:{$destination}", 1, 60],
            ["codes:dest-hour:{$destination}", 3, 3600],
            ["codes:dest-day:{$destination}", 5, 86400],
            ...$this->ipLimits(),
            ...($user ? [["codes:user-day:{$user->id}", 5, 86400]] : []),
        ];
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function ipLimits(): array
    {
        $ip = request()->ip() ?? 'unknown';

        return [
            ["codes:ip-hour:{$ip}", 10, 3600],
            ["codes:ip-day:{$ip}", 30, 86400],
        ];
    }

    private function failedKey(string $destination): string
    {
        return 'codes:failed:'.sha1(mb_strtolower($destination));
    }
}

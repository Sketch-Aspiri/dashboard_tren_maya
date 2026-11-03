<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Recuperación cuando alguien pierde su teléfono: borra el secreto 2FA de la
 * cuenta para que vuelva a registrarlo en su siguiente acceso. Solo por
 * consola (SSH por Tailscale), nunca desde la web, y queda en la bitácora.
 */
class ResetTwoFactorCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:reset-two-factor {email : Correo de la cuenta}';

    /**
     * @var string
     */
    protected $description = 'Reinicia el 2FA de una cuenta: en su siguiente acceso deberá registrar de nuevo la aplicación autenticadora.';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->components->error('No existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        $user->forceFill(['google2fa_secret' => null, 'google2fa_enabled_at' => null])->save();

        activity('user')
            ->performedOn($user)
            ->event('two_factor_reset')
            ->withProperties(['channel' => 'console'])
            ->log('2FA reiniciado por consola.');

        $this->components->info("2FA reiniciado para {$user->email}. Deberá registrarlo de nuevo al iniciar sesión.");

        return self::SUCCESS;
    }
}

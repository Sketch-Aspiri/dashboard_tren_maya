<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Areas;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Fields whose values are never written into activity_log.properties —
     * only the fact that they changed is recorded (see tapActivity()). Same
     * redaction technique as Empleado::tapActivity(). Belt-and-suspenders:
     * getActivitylogOptions() already excludes 'password' from logOnly()
     * entirely, so this never fires today, but it guards against a future
     * change accidentally widening logOnly() to include it.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = ['password'];

    private const REDACTED = '[redactado]';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'estacion_id',
        'area',
        'areas_adicionales',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'google2fa_enabled_at' => 'datetime',
            // Encrypted at rest as defense-in-depth: a leaked DB dump alone
            // must not be enough to derive a user's TOTP codes.
            'google2fa_secret' => 'encrypted',
            'sso_vinculado_at' => 'datetime',
            'desactivado_at' => 'datetime',
            'areas_adicionales' => 'array',
        ];
    }

    /**
     * Si cambia el correo, se rompe el vínculo con Tickets (el inicio de sesión
     * único se basa en el correo): hay que volver a vincular con contraseña.
     */
    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('email')) {
                $user->sso_vinculado_at = null;
            }
        });
    }

    /**
     * Whether the user has completed two-factor authentication enrollment.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->google2fa_secret);
    }

    /**
     * The station this account is scoped to (role "Estación" accounts
     * only). Now in $fillable — the "Gestión de usuarios" panel
     * (UserManagementService) is the first place accounts are ever created
     * via a web form, and it is gated by a Form Request + UserPolicy
     * (Administrador-only) instead of the console-only forceFill()
     * protection this comment used to describe. The service still never
     * lets estacion_id through unless the assigned role is "Estación".
     *
     * @return BelongsTo<Estacion, $this>
     */
    /**
     * Dirección de la foto de perfil (lleva `v`, que cambia con cada foto, para el caché del navegador).
     */
    public function urlFoto(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        return route('fotos.show', ['user' => $this->getKey(), 'v' => substr(sha1((string) $this->avatar_path), 0, 10)], false);
    }

    /**
     * ¿Puede esta cuenta usar los módulos del área? Solo restringe a las cuentas Estación y solo con
     * AREAS_ESTRICTAS=true; el resto de roles no tiene área.
     */
    public function puedeUsarArea(?string $area): bool
    {
        if ($area === null || ! config('equipos.estrictas') || ! $this->hasRole(Roles::ESTACION)) {
            return true;
        }

        // El jefe de estación entra a todos los equipos de su estación (el filtro por estación sigue vigente).
        $propias = $this->areas();

        return in_array(Areas::JEFE, $propias, true) || in_array($area, $propias, true);
    }

    /**
     * Todos los equipos de la cuenta: el principal (`area`) y los adicionales.
     *
     * @return list<string>
     */
    public function areas(): array
    {
        return array_values(array_unique(array_filter([$this->area, ...(array) $this->areas_adicionales])));
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * Estación a nombre de la que captura esta cuenta: la suya si es Estación, "Edificio Zonal Este" si es Edificio.
     */
    public function estacionPropiaId(): ?int
    {
        if ($this->hasRole(Roles::ESTACION)) {
            return $this->estacion_id;
        }

        return $this->hasRole(Roles::EDIFICIO) ? Estacion::edificioId() : null;
    }

    /** Ficha de la persona (solo cuentas con rol Personal). */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * Only name/email/estacion_id are diffed automatically — password,
     * google2fa_secret, remember_token and email_verified_at are excluded
     * from logOnly() entirely (not meaningful to audit as "changed", and
     * password must never appear in the log even redacted-by-key). Spatie
     * role changes are a separate pivot table, not a User column, so
     * they're audited manually by UserManagementService instead of by this
     * automatic diffing.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'estacion_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('user');
    }

    /**
     * Defense in depth (see SENSITIVE_FIELDS above): redacts any sensitive
     * field value that ends up in the log entry, while keeping the field
     * key present so "this changed" stays visible in the audit trail.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $properties = $activity->properties;

        foreach (['attributes', 'old'] as $key) {
            if (! $properties->has($key)) {
                continue;
            }

            $values = $properties->get($key);

            foreach (self::SENSITIVE_FIELDS as $field) {
                if (array_key_exists($field, $values)) {
                    $values[$field] = self::REDACTED;
                }
            }

            $properties->put($key, $values);
        }

        $activity->properties = $properties;
    }
}

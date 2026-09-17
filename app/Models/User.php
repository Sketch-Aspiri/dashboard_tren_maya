<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
        ];
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
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
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

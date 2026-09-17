<?php

namespace App\Models;

use App\Enums\EmpleadoEstatus;
use App\Enums\TipoPlaza;
use Carbon\Carbon;
use Database\Factories\EmpleadoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "Agenda Zona Oriente" -> Personal: real personnel directory sourced from
 * the Jefe de Zona's roster (see
 * app/Console/Commands/ImportAgendaZonaOrienteCommand.php). Contains PII —
 * handle per CLAUDE.md non-negotiables (VPN + 2FA only, never logged in
 * plain form, never exposed outside authorized roles).
 */
class Empleado extends Model
{
    /** @use HasFactory<EmpleadoFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'orden_origen',
        'no_empleado',
        'estatus',
        'tipo_plaza',
        'estacion_codigo',
        'estacion_id',
        'plaza_actual',
        'nombre_completo',
        'puesto',
        'nivel_plaza',
        'ultimo_grado_estudios',
        'titulo',
        'cedula',
        'fecha_ingreso',
        'telefono',
        'correo',
        'tipo_sangre',
        'alergias',
        'fecha_nacimiento',
        'lugar_nacimiento',
        'estado_civil',
        'curp',
        'rfc',
        'nss',
        'domicilio',
        'contacto_emergencia_nombre',
        'contacto_emergencia_telefono',
        'desempeno',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden_origen' => 'integer',
            'estatus' => EmpleadoEstatus::class,
            'tipo_plaza' => TipoPlaza::class,
            'fecha_ingreso' => 'date',
            'fecha_nacimiento' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Estacion, $this>
     */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    /**
     * Daily attendance history (Fase 1 — Control de Asistencia Diaria).
     * Callers scope this per-day via a `with(['registrosDiarios' => ...])`
     * eager-load closure (see AsistenciaCapturaService::rosterFor) rather
     * than a denormalized column here.
     *
     * @return HasMany<RegistroDiario, $this>
     */
    public function registrosDiarios(): HasMany
    {
        return $this->hasMany(RegistroDiario::class);
    }

    /**
     * Commission-out entries (Etapa 2 — Control de Asistencia Diaria).
     * Independent from registrosDiarios — an empleado may have both a
     * daily status and a comisión out entry for the same fecha.
     *
     * @return HasMany<ComisionadoFuera, $this>
     */
    public function comisionadosFuera(): HasMany
    {
        return $this->hasMany(ComisionadoFuera::class);
    }

    /**
     * Fields whose values are never written into activity_log.properties —
     * only the fact that they changed is recorded (see tapActivity()). All
     * other fillable fields (estatus, puesto, plaza_actual, etc.) keep
     * full before/after values, matching CLAUDE.md's audit requirement
     * without duplicating identity-document numbers/health data into a
     * second, less-access-controlled table.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = [
        'curp',
        'rfc',
        'nss',
        'domicilio',
        'tipo_sangre',
        'alergias',
        'fecha_nacimiento',
        'contacto_emergencia_nombre',
        'contacto_emergencia_telefono',
    ];

    private const REDACTED = '[redactado]';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('empleado');
    }

    /**
     * Redacts sensitive field values from the activity log entry right
     * before it's saved, while keeping the field key present so "this
     * field changed" is still visible in the audit trail.
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

    /**
     * Current age in years (fractional), computed from fecha_nacimiento.
     * Not stored — derived on read so it never goes stale.
     */
    protected function edadEnNumero(): Attribute
    {
        return Attribute::make(
            get: function (): ?float {
                if ($this->fecha_nacimiento === null) {
                    return null;
                }

                return round(Carbon::parse($this->fecha_nacimiento)->floatDiffInYears(Carbon::now()), 1);
            },
        );
    }

    /**
     * Whole months since fecha_ingreso. Not stored — derived on read.
     */
    protected function mesesEnActivo(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                if ($this->fecha_ingreso === null) {
                    return null;
                }

                return (int) Carbon::parse($this->fecha_ingreso)->diffInMonths(Carbon::now());
            },
        );
    }
}

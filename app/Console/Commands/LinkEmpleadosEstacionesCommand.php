<?php

namespace App\Console\Commands;

use App\Models\Empleado;
use App\Models\Estacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links already-imported Empleado rows (whose only station reference so
 * far is the free-text `plaza_actual`/`estacion_codigo` columns from the
 * Excel roster) to the normalized `estaciones` catalog, by name matching.
 *
 * Never prints PII to the console — only aggregate counts and the numeric
 * `empleado.id` list of anything left unlinked, for manual follow-up.
 */
class LinkEmpleadosEstacionesCommand extends Command
{
    /**
     * Explicit overrides confirmed by the user (Fase 1 plan, decision #6)
     * — never guessed. Keys are the normalized (see normalizePlaza())
     * `plaza_actual` value, values are the target Estacion `nombre`.
     * `null` means "confirmed not a real location, leave unlinked".
     *
     * @var array<string, ?string>
     */
    private const OVERRIDES = [
        'corporativo chetumal' => 'Edificio Zonal Este',
        'limones chacchoben' => 'Limones',
        'comision cgofp' => null,
    ];

    /**
     * `estacion_codigo` free-text values that map directly to a station
     * name, used only as a fallback when `plaza_actual` didn't match.
     *
     * @var array<string, string>
     */
    private const ESTACION_CODIGO_FALLBACKS = [
        'edificio zonal este' => 'Edificio Zonal Este',
        'estacion chetumal' => 'Chetumal',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:link-empleados-estaciones {--dry-run : Solo muestra el resumen sin guardar cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vincula los Empleado existentes a su Estacion normalizada, a partir de plaza_actual/estacion_codigo.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $estacionesByNombre = $this->estacionesIndexedByNormalizedNombre();

        $summary = ['vinculados' => 0, 'sin_vincular' => 0, 'total' => 0];
        $unlinkedIds = [];

        $link = function () use (&$summary, &$unlinkedIds, $estacionesByNombre, $isDryRun) {
            Empleado::query()
                ->whereNull('estacion_id')
                ->each(function (Empleado $empleado) use (&$summary, &$unlinkedIds, $estacionesByNombre, $isDryRun) {
                    $summary['total']++;

                    $estacionId = $this->resolveEstacionId($empleado, $estacionesByNombre);

                    if ($estacionId === null) {
                        $summary['sin_vincular']++;
                        $unlinkedIds[] = $empleado->id;

                        return;
                    }

                    // Skip the actual write entirely in --dry-run mode —
                    // this command only computes and prints in that case.
                    if (! $isDryRun) {
                        $empleado->forceFill(['estacion_id' => $estacionId])->save();
                    }

                    $summary['vinculados']++;
                });
        };

        if ($isDryRun) {
            $link();
        } else {
            DB::transaction($link);
        }

        $this->renderSummary($summary, $unlinkedIds, $isDryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, Estacion>  $estacionesByNombre
     */
    private function resolveEstacionId(Empleado $empleado, array $estacionesByNombre): ?int
    {
        $normalizedPlaza = $this->normalizePlaza($empleado->plaza_actual);

        if ($normalizedPlaza !== null && array_key_exists($normalizedPlaza, self::OVERRIDES)) {
            $overrideNombre = self::OVERRIDES[$normalizedPlaza];

            if ($overrideNombre === null) {
                return null;
            }

            return $estacionesByNombre[$this->normalize($overrideNombre)]?->id;
        }

        if ($normalizedPlaza !== null && isset($estacionesByNombre[$normalizedPlaza])) {
            return $estacionesByNombre[$normalizedPlaza]->id;
        }

        $normalizedCodigo = $this->normalize($empleado->estacion_codigo);

        if ($normalizedCodigo !== null && isset(self::ESTACION_CODIGO_FALLBACKS[$normalizedCodigo])) {
            $fallbackNombre = $this->normalize(self::ESTACION_CODIGO_FALLBACKS[$normalizedCodigo]);

            return $estacionesByNombre[$fallbackNombre]?->id;
        }

        return null;
    }

    /**
     * @return array<string, Estacion>
     */
    private function estacionesIndexedByNormalizedNombre(): array
    {
        $indexed = [];

        foreach (Estacion::query()->get() as $estacion) {
            $indexed[$this->normalize($estacion->nombre)] = $estacion;
        }

        return $indexed;
    }

    /**
     * Normalizes a `plaza_actual` value for matching against `Estacion`
     * names: trim, lowercase, strip accents, collapse whitespace, strip a
     * leading "estación"/"estacion" word, and strip a trailing
     * parenthetical (e.g. "(apoyo estación bacalar)").
     */
    private function normalizePlaza(?string $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/\s*\([^)]*\)\s*$/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^estacion\s+/', '', trim($normalized)) ?? $normalized;

        return trim($normalized) !== '' ? trim($normalized) : null;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(mb_strtolower($value));
        $normalized = strtr($normalized, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
        // Treat hyphens/dashes as word separators too (e.g. the source data
        // has both "Tulum Aeropuerto" and "Tulum-Aeropuerto" for the same
        // station), so they normalize identically instead of missing a match.
        $normalized = preg_replace('/[\-–—]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array{vinculados: int, sin_vincular: int, total: int}  $summary
     * @param  list<int>  $unlinkedIds
     */
    private function renderSummary(array $summary, array $unlinkedIds, bool $isDryRun): void
    {
        if ($isDryRun) {
            $this->components->info('Modo --dry-run: no se guardó ningún cambio.');
        }

        $this->table(
            ['Vinculados', 'Sin vincular', 'Total procesados'],
            [[$summary['vinculados'], $summary['sin_vincular'], $summary['total']]],
        );

        if ($unlinkedIds !== []) {
            $this->components->warn(
                'Empleado.id sin vincular (revisar manualmente): '.implode(', ', $unlinkedIds),
            );
        }
    }
}

<?php

namespace Tests\Concerns;

use App\Enums\TipoColumna;
use App\Models as M;
use App\Models\User;
use App\Notifications\AvisoDashboard;
use Database\Seeders\EstacionSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Datos ficticios con al menos un registro en cada pantalla, para que la revisión de accesibilidad
 * vea las tablas y los controles de cada renglón y no solo los estados vacíos.
 */
trait DatosDeAccesibilidad
{
    /** @var array<string, User> */
    protected array $cuentas = [];

    /** @var array<string, Model> */
    protected array $registros = [];

    /**
     * @param  class-string<Model>  $modelo
     * @param  array<string, mixed>  $datos
     */
    private function crear(string $modelo, array $datos): Model
    {
        $registro = new $modelo;
        $registro->forceFill($datos)->save();

        return $registro;
    }

    protected function sembrarDatos(): void
    {
        $this->seed([RoleSeeder::class, EstacionSeeder::class, MenuSeeder::class]);

        $tulum = M\Estacion::query()->where('nombre', 'Tulum')->firstOrFail();
        $this->registros['estacion'] = $tulum;
        $anio = (int) now('America/Cancun')->year;
        $mes = (int) now('America/Cancun')->month;
        $hoy = now('America/Cancun')->toDateString();

        $empleado = M\Empleado::factory()->create(['estacion_id' => $tulum->id, 'tipo_plaza' => 'permanente', 'fecha_nacimiento' => '1990-'.now('America/Cancun')->format('m-d')]);
        $otro = M\Empleado::factory()->create(['estacion_id' => $tulum->id, 'tipo_plaza' => 'eventual']);
        $this->registros['empleado'] = $empleado;

        foreach (['Jefe de Zona' => null, 'Administrador' => null, 'Edificio' => null, 'Estación' => $tulum->id, 'Personal' => null] as $rol => $estacionId) {
            $usuario = User::factory()->create(['estacion_id' => $estacionId, 'empleado_id' => $rol === 'Personal' ? $empleado->id : null]);
            $usuario->assignRole($rol);
            $this->cuentas[$rol] = $usuario;
        }
        $this->cuentas['Administrador']->assignRole('Jefe de Zona');
        $captura = $this->cuentas['Estación']->id;
        $this->cuentas['Administrador']->notify(new AvisoDashboard('Aviso de ejemplo', 'Texto del aviso', route('dashboard'), 'insumos'));

        // Asistencia y comisionados.
        $this->crear(M\RegistroDiario::class, ['empleado_id' => $empleado->id, 'estacion_id' => $tulum->id, 'fecha' => $hoy, 'estatus' => 'vacaciones', 'turno' => 'primer', 'registrado_por' => $captura, 'fecha_inicio' => $hoy, 'fecha_fin' => $hoy]);
        M\ComisionadoFuera::factory()->create(['empleado_id' => $otro->id, 'estacion_id' => $tulum->id]);
        M\ComisionadoVisitante::factory()->create(['estacion_id' => $tulum->id]);
        M\DocumentoAsistencia::factory()->create(['estacion_id' => $tulum->id]);

        // Horarios.
        $periodo = $this->crear(M\HorarioPeriodo::class, ['anio' => $anio, 'mes' => $mes, 'version' => 1, 'estado' => 'vigente', 'origen' => 'sistema', 'publicado_at' => now()]);
        foreach ([0, 1] as $dias) {
            $turno = $this->crear(M\HorarioTurno::class, ['periodo_id' => $periodo->id, 'estacion_id' => $tulum->id, 'no_empleado' => $empleado->no_empleado, 'empleado_id' => $empleado->id, 'nombre' => $empleado->nombre_completo, 'puesto' => 'Auxiliar', 'fecha' => now('America/Cancun')->startOfMonth()->addDays($dias)->toDateString(), 'jornada' => '07:00 - 15:00', 'comida' => '12:00 - 13:00', 'jornada_oficial' => '15:00 - 23:00', 'comida_oficial' => null, 'origen' => 'oficial']);
        }
        $this->crear(M\HorarioSolicitud::class, ['periodo_id' => $periodo->id, 'estacion_id' => $tulum->id, 'turno_id' => $turno->id, 'solicitante_id' => $captura, 'no_empleado' => $empleado->no_empleado, 'nombre' => $empleado->nombre_completo, 'fecha' => $turno->fecha, 'tipo' => 'cambio_turno', 'jornada_origen' => '07:00 - 15:00', 'nueva_jornada' => '15:00 - 23:00', 'motivo' => 'Cita médica por la mañana.', 'estado' => 'pendiente']);
        $this->crear(M\HorarioConsolidado::class, ['semana_inicio' => now()->startOfWeek()->toDateString(), 'ruta' => 'x.xlsx', 'nombre' => 'x.xlsx', 'turnos' => 2, 'cambios' => 0]);

        $this->sembrarVacaciones($tulum, $empleado, $anio);
        $this->sembrarInsumos($tulum, $anio, $mes, $captura);
        $this->sembrarServicios($tulum, $anio, $mes, $hoy, $captura);
        $this->sembrarSecciones($tulum);

        $this->registros['equipo'] = M\Equipo::query()->first() ?? $this->crear(M\Equipo::class, ['clave' => 'calidad', 'nombre' => 'Calidad']);
    }

    private function sembrarVacaciones(M\Estacion $estacion, M\Empleado $empleado, int $anio): void
    {
        $rol = $this->crear(M\VacacionPeriodo::class, ['anio' => $anio, 'version' => 1, 'estado' => 'vigente', 'publicado_at' => now()]);
        $this->registros['rol'] = $rol;
        $persona = $this->crear(M\VacacionPersona::class, ['periodo_id' => $rol->id, 'estacion_id' => $estacion->id, 'no_empleado' => $empleado->no_empleado, 'empleado_id' => $empleado->id, 'nombre' => $empleado->nombre_completo, 'dias_otorgados' => 4, 'orden' => 1]);
        foreach (range(1, 4) as $t) {
            $inicio = sprintf('%d-%02d-05', $anio, $t * 3 - 1);
            $this->crear(M\VacacionTramo::class, ['periodo_id' => $rol->id, 'persona_id' => $persona->id, 'estacion_id' => $estacion->id, 'trimestre' => $t, 'inicio' => $inicio, 'fin' => $inicio, 'dias' => 5, 'origen' => $t === 1 ? 'ajustado' : 'oficial', 'inicio_oficial' => $inicio, 'fin_oficial' => $inicio, 'dias_oficial' => 5]);
        }

        $siguiente = (int) config('vacaciones.anio_seleccion');
        $catalogo = $this->crear(M\VacacionCatalogoPeriodo::class, ['anio' => $siguiente, 'inicio' => "{$siguiente}-01-11", 'fin' => "{$siguiente}-01-15", 'dias' => 5, 'trimestre' => 1, 'autorizado' => true]);
        $this->crear(M\VacacionDiaInhabil::class, ['anio' => $siguiente, 'fecha' => "{$siguiente}-01-01", 'nombre' => 'Año nuevo']);
        $plan = $this->crear(M\VacacionPlan::class, ['anio' => $siguiente, 'empleado_id' => $empleado->id, 'estacion_id' => $estacion->id, 'dias_correspondientes' => 20, 'estado' => 'enviada', 'enviada_at' => now()]);
        $this->crear(M\VacacionSeleccion::class, ['plan_id' => $plan->id, 'trimestre' => 1, 'catalogo_periodo_id' => $catalogo->id]);
    }

    private function sembrarInsumos(M\Estacion $estacion, int $anio, int $mes, int $captura): void
    {
        $this->crear(M\Proveedor::class, ['nombre' => 'Proveedor de ejemplo', 'iva' => null]);
        $insumo = M\CatalogoInsumo::factory()->create(['partida_presupuestal' => null]);
        M\InventarioEstacion::factory()->create(['estacion_id' => $estacion->id]);

        foreach (['borrador' => $mes, 'enviada' => $mes === 1 ? 12 : $mes - 1] as $estado => $delMes) {
            $solicitud = M\SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $delMes, 'estado' => $estado, 'enviada_at' => $estado === 'enviada' ? now() : null]);
            $partida = $this->crear(M\SolicitudInsumoPartida::class, ['solicitud_insumo_id' => $solicitud->id, 'numero' => 1, 'cantidad' => 2, 'unidad' => 'Pieza', 'descripcion' => $insumo->descripcion, 'justificacion' => 'Mantenimiento', 'precio_unitario' => 10]);
            $this->registros["insumos_{$estado}"] = $solicitud;
        }

        $cotizacion = $this->crear(M\Cotizacion::class, ['anio' => $anio, 'mes' => $mes, 'proveedor' => 'Proveedor de ejemplo', 'folio' => 'C-1', 'validacion' => 'OK', 'leida_con_ia' => true]);
        $this->crear(M\CotizacionRenglon::class, ['cotizacion_id' => $cotizacion->id, 'solicitud_insumo_partida_id' => $partida->id, 'numero' => 1, 'descripcion' => 'Renglón', 'cantidad' => 2, 'precio_unitario' => 12, 'coincidencia' => 'mismo', 'estatus' => 'revisar']);
        $this->registros['cotizacion'] = $cotizacion;

        $factura = $this->crear(M\Factura::class, ['anio' => $anio, 'mes' => $mes, 'estacion_id' => $estacion->id, 'uuid' => (string) Str::uuid(), 'serie' => 'A', 'folio' => '1', 'fecha' => now()->toDateString(), 'emisor_rfc' => 'XAXX010101000', 'emisor_nombre' => 'Emisor de ejemplo', 'receptor_rfc' => 'XAXX010101000', 'subtotal' => 24, 'iva' => 3.84, 'isr_retenido' => 0, 'total' => 27.84, 'estado' => 'ALERTA', 'verificacion_sat' => 'NO_VERIFICADO', 'xml_ruta' => 'x.xml']);
        $concepto = $this->crear(M\FacturaConcepto::class, ['factura_id' => $factura->id, 'numero' => 1, 'descripcion' => 'Concepto', 'cantidad' => 2, 'valor_unitario' => 12, 'importe' => 24]);
        $this->crear(M\FacturaHallazgo::class, ['factura_id' => $factura->id, 'factura_concepto_id' => $concepto->id, 'codigo' => 'EJ-1', 'tipo' => 'ERROR', 'detalle' => 'Hallazgo de ejemplo']);
        $this->registros['factura'] = $factura;
    }

    private function sembrarServicios(M\Estacion $estacion, int $anio, int $mes, string $hoy, int $captura): void
    {
        $articulo = $this->crear(M\CatalogoPapeleria::class, ['descripcion' => 'Hojas carta', 'activo' => true]);
        $papeleria = M\SolicitudPapeleria::factory()->create(['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes, 'estado' => 'borrador']);
        $this->crear(M\SolicitudPapeleriaPartida::class, ['solicitud_papeleria_id' => $papeleria->id, 'numero' => 1, 'catalogo_papeleria_id' => $articulo->id, 'articulo' => 'Hojas carta', 'unidad' => 'Pieza', 'cantidad' => 1]);
        $this->registros['papeleria'] = $papeleria;

        $agua = $this->crear(M\AguaRegistro::class, ['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes, 'estado' => 'borrador', 'frecuencia' => 'semanal', 'consumo_mensual' => 80, 'costo_unitario' => 38, 'garrafones_propios' => 1, 'garrafones_comodato' => 1]);
        $this->crear(M\AguaCompra::class, ['registro_id' => $agua->id, 'fecha' => $hoy, 'garrafones' => 2, 'costo_unitario' => 38, 'monto' => 76]);
        $this->registros['agua'] = $agua;

        $this->crear(M\AfluenciaDiaria::class, ['estacion_id' => $estacion->id, 'fecha' => $hoy, 'abordan' => 10, 'boletos' => 12, 'capturado_por' => $captura]);

        $planta = $this->crear(M\PtarPlanta::class, ['estacion_id' => $estacion->id, 'numero' => 1, 'activa' => true]);
        $ptar = $this->crear(M\PtarRegistro::class, ['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes, 'estado' => 'borrador', 'modo' => 'manual']);
        $this->crear(M\PtarEquipo::class, ['registro_id' => $ptar->id, 'equipo' => 'soplador', 'estatus' => 'parcial']);
        $this->crear(M\PtarActividad::class, ['registro_id' => $ptar->id, 'actividad' => 'Limpieza']);
        $this->crear(M\PtarHallazgo::class, ['planta_id' => $planta->id, 'registro_origen_id' => $ptar->id, 'tipo' => 'problematica', 'descripcion' => 'Ruido en el soplador.', 'prioridad' => 'media', 'estado' => 'abierto', 'fecha_detectado' => $hoy, 'capturo_id' => $captura]);
        $this->registros['ptar'] = $ptar;
    }

    private function sembrarSecciones(M\Estacion $estacion): void
    {
        $seccion = M\Seccion::factory()->create(['acceso_todas' => 'editar']);
        M\Seccion::factory()->create(['menu_grupo_id' => $seccion->menu_grupo_id, 'parent_id' => $seccion->id]);
        $tabla = $seccion->tablas()->create(['nombre' => 'Principal', 'orden' => 1]);
        foreach (['nombre' => TipoColumna::Texto, 'cantidad' => TipoColumna::Numero] as $clave => $tipo) {
            $tabla->columnas()->create(['seccion_id' => $seccion->id, 'nombre' => ucfirst($clave), 'clave' => $clave, 'tipo' => $tipo, 'orden' => 1]);
        }
        $this->crear(M\SeccionFila::class, ['seccion_id' => $seccion->id, 'seccion_tabla_id' => $tabla->id, 'estacion_id' => $estacion->id, 'datos' => ['nombre' => str_repeat('Texto largo ', 8), 'cantidad' => 2]]);
        $seccion->enlaces()->create(['titulo' => 'Enlace de ejemplo', 'url' => 'https://ejemplo.mx']);
        $importacion = new M\SeccionImportacion(['origen' => 'archivo', 'estado' => 'vista_previa', 'tablas' => [
            ['nombre' => 'Hoja 1', 'filas' => [['Nombre', 'Cantidad'], ['uno', '1']]],
            ['nombre' => 'Hoja 2', 'filas' => [['Nombre'], ['dos']]],
        ]]);
        $importacion->forceFill(['seccion_id' => $seccion->id, 'seccion_tabla_id' => $tabla->id])->save();

        $this->registros['seccion'] = $seccion;
        $this->registros['importacion'] = $importacion;
    }
}

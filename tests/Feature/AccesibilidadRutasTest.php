<?php

namespace Tests\Feature;

use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rutas;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\DatosDeAccesibilidad;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\Concerns\RevisaAccesibilidad;
use Tests\TestCase;

/**
 * Recorre todas las pantallas (rutas GET) con cada rol y revisa su estructura accesible. Una ruta nueva
 * debe aparecer aquí: o se revisa, o se anota en SIN_PANTALLA con las que no devuelven HTML.
 */
class AccesibilidadRutasTest extends TestCase
{
    use DatosDeAccesibilidad;
    use InteractsWithTwoFactor;
    use RefreshDatabase;
    use RevisaAccesibilidad;

    /** Descargas, archivos, respuestas JSON y saltos a otros sistemas: no son pantallas. */
    private const SIN_PANTALLA = '~^(ecosistema\.|interno\.|storage\.|sanctum\.|pulse|telescope)|\.(descargar|download|data|archivo|exportar|comprobante|evidencia|summary|buscar|verificar)$|^(fotos\.show|insumos\.(consolidado|formato|ficha|facturas\.comprobacion(\.mes)?)|nube\.(callback|conectar)|notificaciones\.open|secciones\.(archivos\.(show|vista-previa)|fotos\.show|hoja\.show)|sesion\.viva|verification\.verify)$~';

    /** Pantallas sin sesión. */
    private const DE_ACCESO = ['login', 'password.request', 'password.reset'];

    public function test_todas_las_pantallas_cumplen_la_estructura_accesible_en_cada_rol(): void
    {
        Storage::fake('local');
        $this->sembrarDatos();

        $rutas = collect(Rutas::getRoutes()->getRoutesByMethod()['GET'])
            ->filter(fn (Route $r) => $r->getName() !== null && ! preg_match(self::SIN_PANTALLA, $r->getName()) && in_array('web', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => $r->getName())
            ->values();

        $vistas = [];
        $problemas = [];
        $revisar = function (string $quien, string $nombre, string $url) use (&$vistas, &$problemas): void {
            $respuesta = $this->get($url);
            // Solo "…estacion" abre el registro del mes con un salto; en las demás, un salto no cuenta como revisada.
            if ($respuesta->isRedirect() && str_ends_with($nombre, '.estacion')) {
                $respuesta = $this->get((string) $respuesta->headers->get('Location'));
            }
            if ($respuesta->getStatusCode() !== 200 || ! str_contains((string) $respuesta->headers->get('content-type'), 'text/html')) {
                $this->assertLessThan(500, $respuesta->getStatusCode(), "{$quien} {$url}");

                return;
            }
            $vistas[$nombre] = true;
            foreach ($this->problemasDeAccesibilidad((string) $respuesta->getContent()) as $problema) {
                $problemas[$problema][] = "{$quien} {$url}";
            }
        };

        foreach (self::DE_ACCESO as $nombre) {
            $revisar('visitante', $nombre, route($nombre, $nombre === 'password.reset' ? ['token' => 'abc'] : []));
        }

        // Verificación en dos pasos: sin configurar y por comprobar.
        $nueva = $this->cuentas['Edificio']->replicate()->forceFill(['email' => 'nueva@ejemplo.mx']);
        $nueva->save();
        $this->actingAs($nueva);
        $revisar('sin 2FA', 'two-factor.setup', route('two-factor.setup'));
        $nueva->forceFill(['google2fa_secret' => 'TESTSECRETKEYFORSPECS123456', 'google2fa_enabled_at' => now(), 'email_verified_at' => null])->save();
        $revisar('2FA pendiente', 'two-factor.verify', route('two-factor.verify'));
        $this->actingAsTwoFactorVerified($nueva);
        $revisar('sin correo verificado', 'verification.notice', route('verification.notice'));
        $revisar('sin correo verificado', 'password.confirm', route('password.confirm'));

        foreach ($this->cuentas as $rol => $usuario) {
            $this->actingAsTwoFactorVerified($usuario);

            foreach ($rutas as $nombre) {
                if (in_array($nombre, self::DE_ACCESO, true) || str_starts_with($nombre, 'two-factor.') || str_contains($nombre, 'importar.vista') || $nombre === 'agenda.personal.importar.show' || $nombre === 'secciones.origenes.mapeo.edit') {
                    continue;
                }
                foreach ($this->direcciones($nombre) as $url) {
                    $revisar($rol, $nombre, $url);
                }
            }
        }

        $this->revisarVistasPrevias($revisar);

        $reporte = collect($problemas)->map(fn (array $donde, string $problema) => $problema."\n      en ".implode(', ', array_slice(array_unique($donde), 0, 3)))->implode("\n");
        $this->assertSame('', $reporte, "Problemas de accesibilidad:\n{$reporte}");
        $this->assertSame([], $rutas->reject(fn (string $n) => isset($vistas[$n]))->values()->all(), 'Rutas sin revisar con ningún rol');
    }

    /**
     * Direcciones de una ruta con los datos sembrados, más las variantes que cambian la pantalla.
     *
     * @return list<string>
     */
    private function direcciones(string $nombre): array
    {
        $r = $this->registros;
        $parametros = match (true) {
            str_ends_with($nombre, '.estacion') => ['estacion' => $r['estacion']->id],
            $nombre === 'asistencia.zona.show' => ['estacion' => $r['estacion']->id],
            str_starts_with($nombre, 'agenda.personal.') && in_array($nombre, ['agenda.personal.show', 'agenda.personal.edit'], true) => ['empleado' => $r['empleado']->id],
            $nombre === 'agua.show' => ['registro' => $r['agua']->id],
            $nombre === 'ptar.show' => ['registro' => $r['ptar']->id],
            $nombre === 'papeleria.show' => ['solicitud' => $r['papeleria']->id],
            $nombre === 'insumos.show' => ['solicitud' => $r['insumos_borrador']->id],
            in_array($nombre, ['insumos.cotizaciones.show', 'insumos.cotizaciones.edit'], true) => ['cotizacion' => $r['cotizacion']->id],
            $nombre === 'insumos.facturas.show' => ['factura' => $r['factura']->id],
            in_array($nombre, ['equipos.edit'], true) => ['equipo' => $r['equipo']],
            $nombre === 'usuarios.edit' => ['user' => $this->cuentas['Estación']->id],
            in_array($nombre, ['secciones.show', 'secciones.columnas.index'], true) => ['seccion' => $r['seccion']],
            $nombre === 'secciones.importaciones.show' => ['seccion' => $r['seccion'], 'importacion' => $r['importacion']->id],
            default => [],
        };
        $variantes = match ($nombre) {
            'secciones.show' => [['vista' => 'tabla', 'tab' => 'tabla'], ['tab' => 'documentos'], ['q' => 'Texto']],
            'secciones.importaciones.show' => [['libro' => 1], ['tabla' => 0]],
            'horarios.index', 'vacaciones.index' => [['editar' => 1]],
            default => [],
        };
        $urls = array_map(fn (array $extra) => route($nombre, $parametros + $extra), [[], ...$variantes]);

        // La revisión de la Zona de una solicitud enviada es otra pantalla.
        if ($nombre === 'insumos.show') {
            $urls[] = route($nombre, ['solicitud' => $r['insumos_enviada']->id]);
        }

        return $urls;
    }

    /** Pantallas que solo existen después de subir un archivo. */
    private function revisarVistasPrevias(callable $revisar): void
    {
        $this->actingAsTwoFactorVerified($this->cuentas['Edificio']);

        $horarios = $this->post(route('horarios.importar.store'), ['archivo' => $this->libro([
            'Tulum' => [['No', 'No. Empleado', 'Nombre completo ', 'Denominación del Puesto', 'Estación ', 'Fecha', 'Jornada Laboral', 'Horario de comida (1 Hr.)', 'Semana '], [1, 90001, 'Persona Uno', 'Auxiliar', 'Tulum', '2026-01-05', '07:00 a 15:00', '12:00 a 13:00', 'Semana 1']],
        ])]);
        $horarios->assertSessionHasNoErrors();
        $this->assertStringContainsString('/horarios/importar/', (string) $horarios->headers->get('Location'));
        $revisar('Edificio', 'horarios.importar.vista', (string) $horarios->headers->get('Location'));

        $vacaciones = $this->post(route('vacaciones.importar.store'), ['archivo' => $this->libro([
            'TULUM' => $this->hojaDeVacaciones(),
        ])]);
        $vacaciones->assertSessionHasNoErrors();
        $this->assertStringContainsString('/vacaciones/importar/', (string) $vacaciones->headers->get('Location'));
        $revisar('Edificio', 'vacaciones.importar.vista', (string) $vacaciones->headers->get('Location'));

        $personal = array_fill(0, 30, null);
        $this->post(route('agenda.personal.importar.store'), ['archivo' => $this->libro([
            'Base de Datos Zona Oriente' => [array_replace($personal, [3 => 'No. Empleado', 5 => 'Nombre']), array_replace($personal, [3 => '90001', 5 => 'Persona Uno', 6 => 'Auxiliar'])],
        ])]);
        $revisar('Edificio', 'agenda.personal.importar.show', route('agenda.personal.importar.show'));

        $seccion = $this->registros['seccion'];
        $origen = new SeccionOrigen;
        $origen->forceFill(['seccion_id' => $seccion->id, 'tipo' => 'archivo', 'proveedor' => 'dropbox', 'enlace_original' => 'https://ejemplo.mx', 'nombre' => 'datos.xlsx', 'estado' => 'activo'])->save();
        $this->post(route('secciones.archivos.store', $seccion), ['archivos' => [$this->libro(['Hoja1' => [['Folio', 'Equipo'], ['A1', 'uno']]])]]);
        $archivo = $seccion->archivos()->latest('id')->firstOrFail();
        $mapeo = new SeccionMapeo;
        $mapeo->forceFill(['seccion_origen_id' => $origen->id, 'seccion_archivo_id' => $archivo->id, 'seccion_tabla_id' => $seccion->tablas()->firstOrFail()->id, 'hoja' => 'Hoja1', 'fila_encabezado' => 0, 'columnas' => []])->save();
        $this->get(route('secciones.origenes.mapeo.edit', [$seccion, $origen]))->assertOk()->assertSee('<table', false);
        $revisar('Edificio', 'secciones.origenes.mapeo.edit', route('secciones.origenes.mapeo.edit', [$seccion, $origen]));
        // Con el documento ya guardado: Documentos, Cargas y los orígenes de nube.
        $revisar('Edificio', 'secciones.show', route('secciones.show', [$seccion, 'tab' => 'documentos']));
    }

    /**
     * @return list<list<mixed>>
     */
    private function hojaDeVacaciones(): array
    {
        $encabezado = [null, null, 'NO.', 'NO. EMPLEADO', 'Nombre Completo', 'Días otorgados', 'Inicio', 'Termino', 'Días Solicitados', 'Inicio ', 'Termino ', 'Días Solicitados ', 'Inicio  ', 'Termino  ', 'Días Solicitados  ', 'inicio    ', 'Termino    ', 'Días Solicitados    ', 'Total días solicitados', 'Estación', 'Zona', 'Denominación de puesto'];

        return [
            [null, null, null, null, null, null, 'Primer semestre'],
            [null, null, null, null, null, null, 'Primer trimestre'],
            $encabezado,
            [null, null, 1, 90001, 'Persona Uno', 20, 46027, 46031, 5, null, null, 0, null, null, 0, null, null, 0, 5, 'ESTACION', 'Oriente', 'Auxiliar'],
        ];
    }

    /**
     * @param  array<string, list<list<mixed>>>  $hojas
     */
    private function libro(array $hojas): UploadedFile
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);
        foreach ($hojas as $titulo => $filas) {
            $libro->createSheet()->setTitle($titulo)->fromArray($filas, null, 'A1', true);
        }
        $ruta = tempnam(sys_get_temp_dir(), 'a11y');
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'datos.xlsx', null, null, true);
    }
}

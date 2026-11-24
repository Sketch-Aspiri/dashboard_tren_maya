<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\VacacionPeriodo;
use App\Models\VacacionPersona;
use App\Models\VacacionTramo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Capas del calendario oficial del portal (cumpleaños y vacaciones): firma del puente, rango obligatorio y acotado,
 * y que nunca salga el año de nacimiento ni la edad.
 */
class PortalCalendarioPuenteTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-portal-a-dashboard-de-prueba-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.puente.portal.secreto' => self::SECRETO,
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.ips' => [],
        ]);
    }

    /** GET firmado como lo envía el portal (sin cuerpo). */
    private function obtener(string $ruta, array $consulta = [], string $secreto = self::SECRETO): TestResponse
    {
        $fecha = now()->getTimestamp();
        $nonce = Str::random(40);
        ksort($consulta);
        $canonico = implode("\n", ['GET', $ruta, http_build_query($consulta, '', '&', PHP_QUERY_RFC3986), 'portal', (string) $fecha, $nonce, hash('sha256', '')]);
        $cabeceras = [
            'X-Puente-Sistema' => 'portal',
            'X-Puente-Fecha' => (string) $fecha,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonico, $secreto),
            'Accept' => 'application/json',
        ];

        return $this->call('GET', $ruta.($consulta === [] ? '' : '?'.http_build_query($consulta)), [], [], [], $this->transformHeadersToServerVars($cabeceras));
    }

    public function test_sin_firma_valida_no_se_entrega_nada(): void
    {
        $this->obtener('/interno/portal/calendario/cumpleanos', ['desde' => '2026-12-01', 'hasta' => '2026-12-31'], secreto: 'otro-secreto-de-al-menos-treinta-y-dos-caracteres')->assertUnauthorized();
        $this->obtener('/interno/portal/calendario/vacaciones', ['desde' => '2026-12-01', 'hasta' => '2026-12-31'], secreto: 'otro-secreto-de-al-menos-treinta-y-dos-caracteres')->assertUnauthorized();
    }

    public function test_el_rango_es_obligatorio_y_esta_acotado(): void
    {
        $this->obtener('/interno/portal/calendario/cumpleanos')->assertUnprocessable();
        $this->obtener('/interno/portal/calendario/cumpleanos', ['desde' => '2026-12-31', 'hasta' => '2026-12-01'])->assertUnprocessable();
        $this->obtener('/interno/portal/calendario/vacaciones', ['desde' => '2026-01-01', 'hasta' => '2028-01-01'])->assertUnprocessable();
        $this->obtener('/interno/portal/calendario/vacaciones', ['desde' => 'ayer', 'hasta' => '2026-12-01'])->assertUnprocessable();
    }

    public function test_cumpleanos_entrega_solo_nombre_estacion_y_fecha_de_celebracion(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        Empleado::factory()->create(['nombre_completo' => 'Rosa Canul', 'estacion_id' => $estacion->id, 'fecha_nacimiento' => '1990-12-10']);
        Empleado::factory()->create(['nombre_completo' => 'Fuera de rango', 'fecha_nacimiento' => '1985-03-03']);
        Empleado::factory()->vacante()->create(['nombre_completo' => 'Vacante']);

        $respuesta = $this->obtener('/interno/portal/calendario/cumpleanos', ['desde' => '2026-12-01', 'hasta' => '2026-12-31'])->assertOk();

        $respuesta->assertExactJson(['cumpleanos' => [['nombre' => 'Rosa Canul', 'estacion' => 'Tulum', 'fecha' => '2026-12-10']]]);
        $this->assertStringNotContainsString('1990', $respuesta->getContent());
    }

    public function test_el_29_de_febrero_se_celebra_el_28_en_anio_no_bisiesto(): void
    {
        Empleado::factory()->create(['nombre_completo' => 'Bisiesta', 'fecha_nacimiento' => '1992-02-29']);

        $this->obtener('/interno/portal/calendario/cumpleanos', ['desde' => '2027-02-01', 'hasta' => '2027-03-01'])
            ->assertOk()->assertJsonPath('cumpleanos.0.fecha', '2027-02-28')->assertJsonCount(1, 'cumpleanos');
        $this->obtener('/interno/portal/calendario/cumpleanos', ['desde' => '2028-02-01', 'hasta' => '2028-03-01'])
            ->assertOk()->assertJsonPath('cumpleanos.0.fecha', '2028-02-29')->assertJsonCount(1, 'cumpleanos');
    }

    public function test_vacaciones_solo_del_periodo_vigente_y_que_toquen_el_rango(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        $vigente = VacacionPeriodo::create(['anio' => 2027, 'version' => 2, 'estado' => VacacionPeriodo::VIGENTE]);
        $viejo = VacacionPeriodo::create(['anio' => 2027, 'version' => 1, 'estado' => VacacionPeriodo::REEMPLAZADO]);

        $this->tramo($vigente, $estacion, 'A1', 'Ana Uc', 1, '2027-01-10', '2027-01-20');
        $this->tramo($vigente, $estacion, 'B2', 'Fuera de rango', 1, '2027-03-01', '2027-03-10');
        $this->tramo($viejo, $estacion, 'A1', 'Ana Uc (versión vieja)', 1, '2027-01-12', '2027-01-22');

        $this->obtener('/interno/portal/calendario/vacaciones', ['desde' => '2027-01-15', 'hasta' => '2027-02-15'])
            ->assertOk()
            ->assertExactJson(['vacaciones' => [['nombre' => 'Ana Uc', 'estacion' => 'Tulum', 'inicio' => '2027-01-10', 'fin' => '2027-01-20']]]);
    }

    private function tramo(VacacionPeriodo $periodo, Estacion $estacion, string $numero, string $nombre, int $trimestre, string $inicio, string $fin): void
    {
        $persona = VacacionPersona::create(['periodo_id' => $periodo->id, 'estacion_id' => $estacion->id, 'no_empleado' => $numero, 'nombre' => $nombre, 'dias_otorgados' => 10]);
        VacacionTramo::create(['periodo_id' => $periodo->id, 'persona_id' => $persona->id, 'estacion_id' => $estacion->id, 'trimestre' => $trimestre, 'inicio' => $inicio, 'fin' => $fin, 'dias' => 7]);
    }
}

<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use InheritDevs\Dian\Emision\RepositorioDocumentos;
use InheritDevs\Dian\Http\Aplicacion;
use InheritDevs\Dian\Http\Enrutador;
use InheritDevs\Dian\Http\Respuesta;
use InheritDevs\Dian\Http\Solicitud;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de los endpoints REST: emision, consulta, anulacion y listado.
 *
 * Cada prueba arma la peticion a mano y revisa la respuesta del enrutador, sin
 * levantar un servidor. El registro va en memoria para que una prueba no vea
 * los documentos de la anterior.
 */
final class ApiRestTest extends TestCase
{
    private Enrutador $api;

    private RepositorioDocumentos $repositorio;

    protected function setUp(): void
    {
        $this->repositorio = RepositorioDocumentos::enMemoria();
        $this->api = Aplicacion::enrutador($this->repositorio);
    }

    public function testElEstadoDelServicioSeConsultaPorLaRaiz(): void
    {
        $respuesta = $this->api->atender(new Solicitud('GET', '/'));

        $this->assertSame(200, $respuesta->estado());
        $this->assertSame('api-dian-facturacion', $respuesta->cuerpo()['nombre']);
        $this->assertContains('api-rest', $respuesta->cuerpo()['modulos']);
    }

    public function testEmitirUnaFacturaDevuelve201ConElDocumentoValidado(): void
    {
        $respuesta = $this->api->atender($this->peticionEmision($this->factura()));

        $this->assertSame(201, $respuesta->estado(), $respuesta->json());

        $cuerpo = $respuesta->cuerpo();

        $this->assertSame('FV-0001', $cuerpo['documento']['numero']);
        $this->assertSame('factura', $cuerpo['documento']['tipo']);
        $this->assertSame('emitido', $cuerpo['documento']['estado']);
        $this->assertTrue($cuerpo['validacion']['valido']);
        $this->assertSame('29750000.00', $cuerpo['documento']['totales']['total']);
        $this->assertStringContainsString('<cbc:ID>FV-0001</cbc:ID>', $cuerpo['xml']);
        $this->assertSame('/documentos/FV-0001', $respuesta->cabeceras()['Location']);
        $this->assertSame(1, $this->repositorio->contar());
    }

    public function testElMismoNumeroNoSePuedeEmitirDosVeces(): void
    {
        $this->assertSame(201, $this->api->atender($this->peticionEmision($this->factura()))->estado());

        $segunda = $this->api->atender($this->peticionEmision($this->factura()));

        $this->assertSame(409, $segunda->estado());
        $this->assertSame('documento_duplicado', $segunda->cuerpo()['error']['codigo']);
        $this->assertSame(1, $this->repositorio->contar());
    }

    public function testUnaPeticionSinLineasDevuelve422ConElCampoQueFalta(): void
    {
        $datos = $this->factura();
        unset($datos['lineas']);

        $respuesta = $this->api->atender($this->peticionEmision($datos));

        $this->assertSame(422, $respuesta->estado());
        $this->assertSame('peticion_invalida', $respuesta->cuerpo()['error']['codigo']);
        $this->assertArrayHasKey('lineas', $respuesta->cuerpo()['error']['campos']);
        $this->assertSame(0, $this->repositorio->contar());
    }

    public function testUnDocumentoConDatosInvalidosNoSeGuarda(): void
    {
        $datos = $this->factura();
        $datos['moneda'] = 'PES';

        $respuesta = $this->api->atender($this->peticionEmision($datos));

        $this->assertSame(422, $respuesta->estado());
        $this->assertStringContainsString('PES', $respuesta->cuerpo()['error']['campos']['moneda']);
        $this->assertSame(0, $this->repositorio->contar());
    }

    public function testLaEmisionValidaElDocumentoContraElEsquema(): void
    {
        $respuesta = $this->api->atender($this->peticionEmision($this->factura()));

        $this->assertSame(201, $respuesta->estado(), $respuesta->json());
        $this->assertTrue($respuesta->cuerpo()['validacion']['valido']);
        $this->assertSame([], $respuesta->cuerpo()['validacion']['errores']);
    }

    public function testConsultarDevuelveElDocumentoConSuXml(): void
    {
        $this->api->atender($this->peticionEmision($this->factura()));

        $respuesta = $this->api->atender(new Solicitud('GET', '/documentos/FV-0001'));

        $this->assertSame(200, $respuesta->estado());
        $this->assertSame('FV-0001', $respuesta->cuerpo()['documento']['numero']);
        $this->assertStringContainsString('Invoice', $respuesta->cuerpo()['xml']);
    }

    public function testConsultarUnNumeroQueNoExisteDevuelve404(): void
    {
        $respuesta = $this->api->atender(new Solicitud('GET', '/documentos/FV-9999'));

        $this->assertSame(404, $respuesta->estado());
        $this->assertSame('no_encontrado', $respuesta->cuerpo()['error']['codigo']);
        $this->assertStringContainsString('FV-9999', $respuesta->cuerpo()['error']['mensaje']);
    }

    public function testAnularUnDocumentoLoDejaMarcadoSinBorrarlo(): void
    {
        $this->api->atender($this->peticionEmision($this->factura()));

        $respuesta = $this->api->atender(new Solicitud(
            'POST',
            '/documentos/FV-0001/anular',
            [],
            '{"motivo":"Factura emitida por error de digitacion"}'
        ));

        $this->assertSame(200, $respuesta->estado(), $respuesta->json());

        $documento = $respuesta->cuerpo()['documento'];

        $this->assertSame('anulado', $documento['estado']);
        $this->assertSame('Factura emitida por error de digitacion', $documento['motivoAnulacion']);
        $this->assertNotNull($documento['anuladoEn']);
        $this->assertSame(1, $this->repositorio->contar(), 'anular no borra el documento');

        $consulta = $this->api->atender(new Solicitud('GET', '/documentos/FV-0001'));
        $this->assertSame('anulado', $consulta->cuerpo()['documento']['estado']);
    }

    public function testNoSePuedeAnularDosVeces(): void
    {
        $this->api->atender($this->peticionEmision($this->factura()));
        $this->api->atender(new Solicitud('POST', '/documentos/FV-0001/anular', [], '{"motivo":"Duplicada"}'));

        $segunda = $this->api->atender(new Solicitud(
            'POST',
            '/documentos/FV-0001/anular',
            [],
            '{"motivo":"Otra vez"}'
        ));

        $this->assertSame(422, $segunda->estado());
        $this->assertSame('documento_invalido', $segunda->cuerpo()['error']['codigo']);
    }

    public function testAnularSinMotivoDevuelve422(): void
    {
        $this->api->atender($this->peticionEmision($this->factura()));

        $respuesta = $this->api->atender(new Solicitud('POST', '/documentos/FV-0001/anular', [], '{}'));

        $this->assertSame(422, $respuesta->estado());
        $this->assertArrayHasKey('motivo', $respuesta->cuerpo()['error']['campos']);
    }

    public function testElListadoDevuelveLosDocumentosDelMasRecienteAlMasViejo(): void
    {
        $this->api->atender($this->peticionEmision($this->factura(1, '2026-10-22 09:30:00')));
        $this->api->atender($this->peticionEmision($this->factura(2, '2026-10-23 11:00:00')));
        $this->api->atender($this->peticionEmision($this->factura(3, '2026-10-24 16:45:00')));

        $respuesta = $this->api->atender(new Solicitud('GET', '/documentos'));

        $this->assertSame(200, $respuesta->estado());

        $cuerpo = $respuesta->cuerpo();

        $this->assertSame(3, $cuerpo['total']);
        $this->assertSame(
            ['FV-0003', 'FV-0002', 'FV-0001'],
            array_column($cuerpo['documentos'], 'numero')
        );
        $this->assertArrayNotHasKey('xml', $cuerpo['documentos'][0], 'el listado no carga el XML de cada documento');
    }

    public function testElListadoFiltraPorEstadoYTipo(): void
    {
        $this->api->atender($this->peticionEmision($this->factura(1)));
        $this->api->atender($this->peticionEmision($this->factura(2)));
        $this->api->atender(new Solicitud('POST', '/documentos/FV-0002/anular', [], '{"motivo":"Error"}'));

        $anulados = $this->api->atender(new Solicitud('GET', '/documentos', ['estado' => 'anulado']));

        $this->assertSame(1, $anulados->cuerpo()['total']);
        $this->assertSame('FV-0002', $anulados->cuerpo()['documentos'][0]['numero']);

        $notas = $this->api->atender(new Solicitud('GET', '/documentos', ['tipo' => 'nota-credito']));

        $this->assertSame(0, $notas->cuerpo()['total']);
    }

    public function testElListadoRespetaLaPaginacion(): void
    {
        foreach ([1, 2, 3, 4, 5] as $consecutivo) {
            $this->api->atender($this->peticionEmision($this->factura($consecutivo)));
        }

        $respuesta = $this->api->atender(new Solicitud('GET', '/documentos', ['limite' => '2', 'desplazamiento' => '2']));

        $cuerpo = $respuesta->cuerpo();

        $this->assertSame(5, $cuerpo['total']);
        $this->assertSame(2, $cuerpo['limite']);
        $this->assertSame(2, $cuerpo['desplazamiento']);
        $this->assertCount(2, $cuerpo['documentos']);
        $this->assertSame(['FV-0003', 'FV-0002'], array_column($cuerpo['documentos'], 'numero'));
    }

    public function testUnLimiteInvalidoDevuelve422(): void
    {
        $respuesta = $this->api->atender(new Solicitud('GET', '/documentos', ['limite' => 'muchos']));

        $this->assertSame(422, $respuesta->estado());
        $this->assertStringContainsString('limite', $respuesta->cuerpo()['error']['mensaje']);
    }

    public function testUnFiltroDeEstadoQueNoExisteDevuelve422(): void
    {
        $respuesta = $this->api->atender(new Solicitud('GET', '/documentos', ['estado' => 'perdido']));

        $this->assertSame(422, $respuesta->estado());
        $this->assertStringContainsString('perdido', $respuesta->cuerpo()['error']['mensaje']);
    }

    public function testUnMetodoQueLaRutaNoAdmiteDevuelve405(): void
    {
        $respuesta = $this->api->atender(new Solicitud('DELETE', '/documentos/FV-0001'));

        $this->assertSame(405, $respuesta->estado());
        $this->assertSame('GET', $respuesta->cabeceras()['Allow']);
    }

    public function testUnaRutaQueNoExisteDevuelve404(): void
    {
        $respuesta = $this->api->atender(new Solicitud('GET', '/clientes'));

        $this->assertSame(404, $respuesta->estado());
        $this->assertSame('ruta_no_encontrada', $respuesta->cuerpo()['error']['codigo']);
    }

    public function testUnCuerpoQueNoEsJsonDevuelve422(): void
    {
        $respuesta = $this->api->atender(new Solicitud('POST', '/documentos', [], 'esto no es json'));

        $this->assertSame(422, $respuesta->estado());
        $this->assertSame('peticion_invalida', $respuesta->cuerpo()['error']['codigo']);
    }

    public function testUnaNotaCreditoSeEmiteConSuReferenciaALaFactura(): void
    {
        $datos = $this->factura();
        $datos['tipo'] = 'nota-credito';
        $datos['numero'] = 'FV-0100';
        $datos['documentoAjustado'] = 'FV-0001';
        $datos['motivoAjuste'] = 'Devolucion parcial del servicio';
        $datos['motivoCodigo'] = '5';

        $respuesta = $this->api->atender($this->peticionEmision($datos));

        $this->assertSame(201, $respuesta->estado(), $respuesta->json());
        $this->assertSame('nota-credito', $respuesta->cuerpo()['documento']['tipo']);
        $this->assertStringContainsString('<CreditNote', $respuesta->cuerpo()['xml']);
        $this->assertStringContainsString('FV-0001', $respuesta->cuerpo()['xml']);
    }

    public function testLosValoresMonetariosSeAceptanComoTexto(): void
    {
        $datos = $this->factura();
        $datos['lineas'][0]['cantidad'] = 1;
        $datos['lineas'][0]['precioUnitario'] = '1.250.000,50';

        $respuesta = $this->api->atender($this->peticionEmision($datos));

        $this->assertSame(201, $respuesta->estado(), $respuesta->json());
        $this->assertSame('1250000.50', $respuesta->cuerpo()['documento']['totales']['subtotal']);
    }

    /**
     * Respuesta 200 sin cuerpo, para el helper de peticiones.
     */
    private function peticionEmision(array $datos): Solicitud
    {
        return new Solicitud('POST', '/documentos', [], (string) json_encode($datos, JSON_THROW_ON_ERROR));
    }

    /**
     * Cuerpo JSON de una factura de una linea, lista para emitir.
     *
     * @return array<string, mixed>
     */
    private function factura(int $consecutivo = 1, string $fecha = '2026-10-22 09:30:00'): array
    {
        return [
            'tipo' => 'factura',
            'numero' => sprintf('FV-%04d', $consecutivo),
            'fecha' => $fecha,
            'ambiente' => '2',
            'moneda' => 'COP',
            'emisor' => [
                'nit' => '900123456',
                'razonSocial' => 'InheritDevs SAS',
                'regimen' => '48',
                'direccion' => 'Calle 10 # 20-30',
                'ciudad' => 'Medellin',
                'departamento' => 'Antioquia',
                'correo' => 'facturacion@inheritdevs.com',
            ],
            'adquirente' => [
                'nit' => '800987654',
                'razonSocial' => 'Almacen El Progreso SAS',
                'regimen' => '49',
                'direccion' => 'Carrera 45 # 12-08',
                'ciudad' => 'Bogota',
                'departamento' => 'Cundinamarca',
            ],
            'lineas' => [
                [
                    'descripcion' => 'Servicio de desarrollo',
                    'cantidad' => 2,
                    'precioUnitario' => 12500000,
                    'iva' => 19,
                ],
            ],
            'resolucion' => [
                'numero' => '18760000001',
                'prefijo' => 'FV',
                'desde' => 1,
                'hasta' => 5000,
                'vigenciaDesde' => '2026-01-01',
                'vigenciaHasta' => '2027-01-01',
                'claveTecnica' => 'clave-tecnica-de-prueba',
            ],
        ];
    }
}

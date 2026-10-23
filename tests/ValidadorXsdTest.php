<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use DateTimeImmutable;
use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Documento\Linea;
use InheritDevs\Dian\Documento\Resolucion;
use InheritDevs\Dian\Documento\Tercero;
use InheritDevs\Dian\Documento\TipoDocumento;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;
use InheritDevs\Dian\Validacion\ValidadorXsd;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del validador XSD.
 *
 * Cubren la validacion de los tres tipos de documento del perfil, la
 * traduccion de los errores del esquema a frases legibles y los casos en los
 * que el XML ni siquiera se puede leer.
 */
final class ValidadorXsdTest extends TestCase
{
    public function testLaFacturaDelModeloCumpleElEsquema(): void
    {
        $resultado = $this->validador()->validarDocumento($this->factura());

        $this->assertTrue($resultado->valido(), $resultado->reporte());
        $this->assertSame([], $resultado->errores());
        $this->assertSame(0, $resultado->cantidadErrores());
        $this->assertNull($resultado->resumen());
        $this->assertSame('El documento cumple el esquema del perfil.', $resultado->reporte());
    }

    public function testLaNotaCreditoCumpleElEsquema(): void
    {
        $resultado = $this->validador()->validarDocumento($this->notaCredito());

        $this->assertTrue($resultado->valido(), $resultado->reporte());
    }

    public function testLaNotaDebitoCumpleElEsquema(): void
    {
        $resultado = $this->validador()->validarDocumento($this->notaDebito());

        $this->assertTrue($resultado->valido(), $resultado->reporte());
    }

    public function testUnValorFueraDeLaListaDelPerfilSeReportaConLosPermitidos(): void
    {
        $xml = str_replace(
            '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>',
            '<cbc:UBLVersionID>2.0</cbc:UBLVersionID>',
            $this->factura()->xml()
        );

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertSame(1, $resultado->cantidadErrores());

        $error = $resultado->errores()[0];

        $this->assertStringContainsString('UBLVersionID', $error->mensaje());
        $this->assertStringContainsString('"2.0"', $error->mensaje());
        $this->assertStringContainsString('2.1', $error->mensaje());
        $this->assertGreaterThan(0, $error->linea());
        $this->assertNotSame('', $error->codigo());
    }

    public function testUnUuidConFormatoInvalidoSeReportaComoFormato(): void
    {
        $xml = preg_replace(
            '/<cbc:UUID>[0-9A-Fa-f]+<\/cbc:UUID>/',
            '<cbc:UUID>ABC</cbc:UUID>',
            $this->factura()->xml()
        );

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertStringContainsString('UUID', $resultado->errores()[0]->mensaje());
        $this->assertStringContainsString('"ABC"', $resultado->errores()[0]->mensaje());
        $this->assertStringContainsString('formato', $resultado->errores()[0]->mensaje());
    }

    public function testUnaFechaMalEscritaSeReportaConElFormatoEsperado(): void
    {
        $xml = str_replace(
            '<cbc:IssueDate>2026-10-22</cbc:IssueDate>',
            '<cbc:IssueDate>22/10/2026</cbc:IssueDate>',
            $this->factura()->xml()
        );

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertStringContainsString('IssueDate', $resultado->errores()[0]->mensaje());
        $this->assertStringContainsString('fecha', $resultado->errores()[0]->mensaje());
    }

    public function testUnDocumentoSinLineasSeReportaComoElementoFaltante(): void
    {
        $xml = preg_replace('/<cac:InvoiceLine>.*<\/cac:InvoiceLine>/s', '', $this->factura()->xml());

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertStringContainsString('Falta un elemento obligatorio', $resultado->errores()[0]->mensaje());
        $this->assertStringContainsString('InvoiceLine', $resultado->errores()[0]->mensaje());
    }

    public function testUnValorSinMonedaSeReportaConElAtributoQueFalta(): void
    {
        $xml = str_replace(
            '<cbc:LineExtensionAmount currencyID="COP">',
            '<cbc:LineExtensionAmount>',
            $this->factura()->xml()
        );

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertStringContainsString('currencyID', $resultado->errores()[0]->mensaje());
        $this->assertStringContainsString('atributo obligatorio', $resultado->errores()[0]->mensaje());
    }

    public function testUnElementoFueraDeOrdenSeReportaConLoQueSeEsperaba(): void
    {
        $xml = str_replace(
            '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>',
            '<cbc:CustomizationID>10</cbc:CustomizationID>',
            $this->factura()->xml()
        );

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertStringContainsString('fuera de lugar o de orden', $resultado->errores()[0]->mensaje());
        $this->assertStringContainsString('UBLVersionID', $resultado->errores()[0]->mensaje());
    }

    public function testUnPrefijoSinDeclararSeReportaComoXmlIlegible(): void
    {
        $xml = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">'
            . '<cbc:ID>FV-0001</cbc:ID></Invoice>';

        $resultado = $this->validador()->validar($xml, 'factura');

        $this->assertFalse($resultado->valido());
        $this->assertSame('El XML no esta bien formado.', $resultado->resumen());
        $this->assertStringContainsString('cbc', $resultado->errores()[0]->mensaje());
    }

    public function testUnaFacturaValidadaComoNotaCreditoSeReportaComoRaizEquivocada(): void
    {
        $resultado = $this->validador()->validar($this->factura()->xml(), 'nota-credito');

        $this->assertFalse($resultado->valido());
        $this->assertNotSame([], $resultado->errores());
    }

    public function testUnTipoDeDocumentoSinEsquemaSeRechazaConLaListaDeTipos(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessageMatches('/no hay esquema xsd/i');

        $this->validador()->validar('<x/>', 'remision');
    }

    public function testUnXmlVacioSeRechazaAntesDeValidar(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('El XML a validar esta vacio.');

        $this->validador()->validar('   ', 'factura');
    }

    public function testElResultadoSeSerializaParaLaRespuestaHttp(): void
    {
        $xml = str_replace(
            '<cbc:DocumentCurrencyCode>COP</cbc:DocumentCurrencyCode>',
            '<cbc:DocumentCurrencyCode>PESOS</cbc:DocumentCurrencyCode>',
            $this->factura()->xml()
        );

        $resultado = $this->validador()->validar($xml, 'factura');
        $datos = $resultado->jsonSerialize();

        $this->assertFalse($datos['valido']);
        $this->assertIsString($datos['resumen']);
        $this->assertCount(1, $datos['errores']);
        $this->assertSame(
            ['linea', 'columna', 'codigo', 'mensaje'],
            array_keys($datos['errores'][0])
        );
        $this->assertStringContainsString(
            'DocumentCurrencyCode',
            $datos['errores'][0]['mensaje']
        );
        $this->assertSame($datos, json_decode((string) json_encode($resultado), true));
    }

    public function testElReporteDeUnDocumentoInvalidoListaCadaErrorEnSuLinea(): void
    {
        $xml = str_replace(
            '<cbc:DocumentCurrencyCode>COP</cbc:DocumentCurrencyCode>',
            '<cbc:DocumentCurrencyCode>PESOS</cbc:DocumentCurrencyCode>',
            $this->factura()->xml()
        );

        $reporte = $this->validador()->validar($xml, 'factura')->reporte();
        $lineas = explode("\n", $reporte);

        $this->assertSame('El documento tiene 1 error(es) frente al esquema.', $lineas[0]);
        $this->assertCount(2, $lineas);
        $this->assertStringStartsWith('- linea ', $lineas[1]);
    }

    public function testCadaTipoDeDocumentoTieneSuArchivoDeEsquema(): void
    {
        $validador = $this->validador();

        foreach (['factura', 'nota-credito', 'nota-debito'] as $tipo) {
            $this->assertTrue($validador->tieneEsquema($tipo));
            $this->assertFileExists($validador->rutaEsquema($tipo));
        }

        $this->assertFalse($validador->tieneEsquema('remision'));
    }

    public function testUnDirectorioDeEsquemasInexistenteFallaAlConstruir(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se encontro el directorio de esquemas XSD');

        new ValidadorXsd('/ruta/que/no/existe');
    }

    private function validador(): ValidadorXsd
    {
        return new ValidadorXsd();
    }

    private function factura(): DocumentoUbl
    {
        return new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            new Tercero('900123456', 'InheritDevs SAS', '48'),
            new Tercero('800987654', 'Almacen El Progreso SAS', '49'),
            [
                new Linea('Servicio de desarrollo', 2, 12500000, 19),
                new Linea('Soporte mensual', 1, 4000000, 5, 'MON'),
            ],
            $this->resolucion()
        );
    }

    private function notaCredito(): DocumentoUbl
    {
        return new DocumentoUbl(
            TipoDocumento::NotaCredito,
            'FV-0100',
            new DateTimeImmutable('2026-10-22 10:15:00'),
            new Tercero('900123456', 'InheritDevs SAS', '48'),
            new Tercero('800987654', 'Almacen El Progreso SAS', '49'),
            [new Linea('Devolucion de servicio', 1, 5000000, 19)],
            $this->resolucion(),
            DocumentoUbl::AMBIENTE_PRUEBAS,
            'COP',
            'FV-0001',
            'Devolucion parcial',
            '5'
        );
    }

    private function notaDebito(): DocumentoUbl
    {
        return new DocumentoUbl(
            TipoDocumento::NotaDebito,
            'FV-0101',
            new DateTimeImmutable('2026-10-22 10:20:00'),
            new Tercero('900123456', 'InheritDevs SAS', '48'),
            new Tercero('800987654', 'Almacen El Progreso SAS', '49'),
            [new Linea('Mayor valor del servicio', 1, 1000000, 19)],
            $this->resolucion(),
            DocumentoUbl::AMBIENTE_PRODUCCION,
            'COP',
            'FV-0001',
            'Mayor valor',
            '2'
        );
    }

    private function resolucion(): Resolucion
    {
        return new Resolucion(
            '18760000001',
            'FV',
            1,
            5000,
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2027-01-01'),
            'clave-tecnica'
        );
    }
}

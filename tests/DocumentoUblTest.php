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
use InheritDevs\Dian\Servicio;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del modelo de documento UBL 2.1.
 *
 * Cubren el XML de la factura, de la nota credito y de la nota debito, los
 * totales del documento y las reglas que impiden emitir un documento
 * incoherente.
 */
final class DocumentoUblTest extends TestCase
{
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_DIAN = 'dian:gov:co:facturaelectronica:Structures-2-1';

    public function testLaFacturaLlevaLaCabeceraDelPerfilDeLaDian(): void
    {
        $xml = $this->factura()->xml();
        $dom = $this->cargar($xml);

        $this->assertSame('Invoice', $dom->documentElement->localName);
        $this->assertSame(
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            $dom->documentElement->namespaceURI
        );

        $this->assertSame('2.1', $this->texto($dom, 'UBLVersionID'));
        $this->assertSame('10', $this->texto($dom, 'CustomizationID'));
        $this->assertSame('2.0', $this->texto($dom, 'ProfileID'));
        $this->assertSame('1', $this->texto($dom, 'ProfileExecutionID'));
        $this->assertSame('FV-0001', $this->texto($dom, 'ID'));
        $this->assertSame('COP', $this->texto($dom, 'DocumentCurrencyCode'));
        $this->assertSame('2026-10-22', $this->texto($dom, 'IssueDate'));
        $this->assertSame('2', $this->texto($dom, 'LineCountNumeric'));
    }

    public function testElUuidVaEnLaCabeceraYEnLasExtensionesDeLaDian(): void
    {
        $dom = $this->cargar($this->factura()->xml());

        $uuid = $this->texto($dom, 'UUID');

        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $uuid, 'el UUID provisional va en hexadecimal');
        $this->assertSame($uuid, $this->textoDian($dom, 'sts:UUID'));
    }

    public function testLaFacturaDeclaraEmisorYAdquirente(): void
    {
        $dom = $this->cargar($this->factura()->xml());

        $proveedor = $this->nodo($dom, 'cac:AccountingSupplierParty');
        $this->assertNotNull($proveedor);
        $this->assertSame('900123456', $this->textoDe($proveedor, 'cbc:ID'));
        $this->assertSame('31', $this->nodo($proveedor, 'cbc:ID')?->getAttribute('schemeID'));
        $this->assertSame('InheritDevs SAS', $this->textoDe($proveedor, 'cbc:RegistrationName'));
        $this->assertSame('48', $this->textoDe($proveedor, 'cbc:ID', 'cac:TaxScheme'));

        $cliente = $this->nodo($dom, 'cac:AccountingCustomerParty');
        $this->assertNotNull($cliente);
        $this->assertSame('800987654', $this->textoDe($cliente, 'cbc:ID'));
        $this->assertSame('Almacen El Progreso SAS', $this->textoDe($cliente, 'cbc:RegistrationName'));
        $this->assertSame('49', $this->textoDe($cliente, 'cbc:ID', 'cac:TaxScheme'));
    }

    public function testLaFacturaPublicaLaResolucionDeFacturacion(): void
    {
        $dom = $this->cargar($this->factura()->xml());

        $this->assertSame('18760000001', $this->textoDian($dom, 'sts:InvoiceAuthorization'));
        $this->assertSame('FV', $this->textoDian($dom, 'sts:Prefix'));
        $this->assertSame('1', $this->textoDian($dom, 'sts:From'));
        $this->assertSame('5000', $this->textoDian($dom, 'sts:To'));
        $this->assertSame('2026-01-01', $this->textoDian($dom, 'sts:StartDate'));
        $this->assertSame('2027-01-01', $this->textoDian($dom, 'sts:EndDate'));
    }

    public function testLasLineasLlevanCantidadPrecioEIva(): void
    {
        $dom = $this->cargar($this->factura()->xml());

        $lineas = $dom->getElementsByTagNameNS(self::NS_CAC, 'InvoiceLine');
        $this->assertCount(2, $lineas);

        $primera = $lineas->item(0);
        $this->assertNotNull($primera);
        $this->assertSame('1', $this->textoDe($primera, 'cbc:ID'));
        $this->assertSame('2', $this->textoDe($primera, 'cbc:InvoicedQuantity'));
        $this->assertSame('94', $this->nodo($primera, 'cbc:InvoicedQuantity')?->getAttribute('unitCode'));
        $this->assertSame('250000.00', $this->textoDe($primera, 'cbc:LineExtensionAmount'));
        $this->assertSame('47500.00', $this->textoDe($primera, 'cbc:TaxAmount', 'cac:TaxTotal'));
        $this->assertSame('Servicio de desarrollo', $this->textoDe($primera, 'cbc:Description'));
        $this->assertSame('125000.00', $this->textoDe($primera, 'cbc:PriceAmount', 'cac:Price'));

        foreach ($lineas as $linea) {
            $this->assertNotNull($linea);
            $this->assertSame(
                'COP',
                $this->nodo($linea, 'cbc:LineExtensionAmount')?->getAttribute('currencyID')
            );
        }
    }

    public function testLosTotalesSumanLasLineasYDesglosanElIva(): void
    {
        $documento = $this->factura();

        $this->assertSame(29000000, $documento->subtotalCentavos());
        $this->assertSame(4950000, $documento->ivaCentavos());
        $this->assertSame(33950000, $documento->totalCentavos());
        $this->assertSame([5 => 4000000, 19 => 25000000], $documento->basesPorTarifa());
        $this->assertSame([5 => 200000, 19 => 4750000], $documento->ivaPorTarifa());

        $dom = $this->cargar($documento->xml());

        $this->assertSame('290000.00', $this->texto($dom, 'LineExtensionAmount', 'cac:LegalMonetaryTotal'));
        $this->assertSame('339500.00', $this->texto($dom, 'PayableAmount', 'cac:LegalMonetaryTotal'));
        $this->assertSame('49500.00', $this->texto($dom, 'TaxAmount', 'cac:TaxTotal'));

        $subtotales = $dom->getElementsByTagNameNS(self::NS_CAC, 'TaxSubtotal');
        $this->assertCount(4, $subtotales, 'dos del total del documento y uno por linea');
    }

    public function testLaNotaCreditoReferenciaLaFacturaQueCorrige(): void
    {
        $nota = $this->nota(TipoDocumento::NotaCredito);
        $dom = $this->cargar($nota->xml());

        $this->assertSame('CreditNote', $dom->documentElement->localName);
        $this->assertSame(
            'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
            $dom->documentElement->namespaceURI
        );
        $this->assertSame('20', $this->texto($dom, 'CustomizationID'));
        $this->assertSame('FV-0001', $this->texto($dom, 'ID', 'cac:InvoiceDocumentReference'));
        $this->assertSame('01', $this->texto($dom, 'DocumentTypeCode', 'cac:InvoiceDocumentReference'));
        $this->assertSame('5', $this->texto($dom, 'ResponseCode', 'cac:DiscrepancyResponse'));
        $this->assertSame('Devolucion parcial', $this->texto($dom, 'Description', 'cac:DiscrepancyResponse'));

        $this->assertSame(
            1,
            $dom->getElementsByTagNameNS(self::NS_CAC, 'CreditNoteLine')->length
        );
        $this->assertSame(
            0,
            $dom->getElementsByTagNameNS(self::NS_CAC, 'InvoiceLine')->length,
            'la nota credito no usa las lineas de la factura'
        );
    }

    public function testLaNotaDebitoUsaSusPropiasLineasYSuCodigoDeOperacion(): void
    {
        $nota = $this->nota(TipoDocumento::NotaDebito, '3', 'Intereses de mora');
        $dom = $this->cargar($nota->xml());

        $this->assertSame('DebitNote', $dom->documentElement->localName);
        $this->assertSame('30', $this->texto($dom, 'CustomizationID'));
        $this->assertSame('Intereses de mora', $this->texto($dom, 'Description', 'cac:DiscrepancyResponse'));

        $lineas = $dom->getElementsByTagNameNS(self::NS_CAC, 'DebitNoteLine');
        $this->assertSame(1, $lineas->length);

        $linea = $lineas->item(0);
        $this->assertNotNull($linea);
        $this->assertSame(
            '1',
            $this->textoDe($linea, 'cbc:DebitedQuantity'),
            'la nota debito debita la cantidad'
        );
    }

    public function testElServicioRegistraElModuloDelDocumento(): void
    {
        $this->assertContains('documento-ubl', Servicio::estado()['modulos']);
    }

    public function testLasCantidadesAceptanDecimales(): void
    {
        $linea = new Linea('Hora de consultoria', 1.5, 8000000, 19, 'HUR');
        $dom = $this->cargar($this->documento($linea)->xml());

        $this->assertSame('1.5', $this->texto($dom, 'InvoicedQuantity', 'cac:InvoiceLine'));
        $this->assertSame('120000.00', $this->texto($dom, 'LineExtensionAmount', 'cac:InvoiceLine'));
        $this->assertSame(12000000, $linea->subtotalCentavos());
    }

    public function testElTextoDelDocumentoVaEscapado(): void
    {
        $linea = new Linea('Mantenimiento <urgente> & "express"', 1, 10000000);
        $dom = $this->cargar($this->documento($linea)->xml());

        $this->assertSame(
            'Mantenimiento <urgente> & "express"',
            $this->texto($dom, 'Description', 'cac:InvoiceLine')
        );
    }

    public function testElDocumentoSinLineasSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('al menos una linea');

        new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [],
            $this->resolucion()
        );
    }

    public function testElConsecutivoFueraDelRangoSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('fuera del rango');

        new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-9000',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Asesoria', 1, 100000)],
            $this->resolucion()
        );
    }

    public function testElNumeroSinElPrefijoDeLaResolucionSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('no lleva el prefijo');

        new DocumentoUbl(
            TipoDocumento::Factura,
            '0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Asesoria', 1, 100000)],
            $this->resolucion()
        );
    }

    public function testLaNotaSinDocumentoAjustadoSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('debe indicar el numero de la factura que ajusta');

        new DocumentoUbl(
            TipoDocumento::NotaCredito,
            'FV-0100',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Asesoria', 1, 100000)],
            $this->resolucion()
        );
    }

    public function testLaNotaSinMotivoSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('debe explicar el motivo del ajuste');

        new DocumentoUbl(
            TipoDocumento::NotaDebito,
            'FV-0101',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Asesoria', 1, 100000)],
            $this->resolucion(),
            DocumentoUbl::AMBIENTE_PRODUCCION,
            'COP',
            'FV-0001'
        );
    }

    public function testElEmisorNoResponsableDeIvaNoCobraIva(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('no responsable de IVA');

        new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            new Tercero('900123456', 'InheritDevs SAS', '49'),
            $this->adquirente(),
            [new Linea('Asesoria', 1, 100000, 19)],
            $this->resolucion()
        );
    }

    public function testElAmbienteInvalidoSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('no es valido');

        new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Asesoria', 1, 100000)],
            $this->resolucion(),
            '3'
        );
    }

    public function testLosDocumentosEnPruebasLlevanElAmbienteDos(): void
    {
        $dom = $this->cargar(
            $this->documento(new Linea('Asesoria', 1, 100000), DocumentoUbl::AMBIENTE_PRUEBAS)->xml()
        );

        $this->assertSame('2', $this->texto($dom, 'ProfileExecutionID'));
    }

    /**
     * Factura de dos lineas: una gravada al 19 % y otra al 5 %.
     */
    private function factura(): DocumentoUbl
    {
        return new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [
                new Linea('Servicio de desarrollo', 2, 12500000, 19, '94', 'SRV-01'),
                new Linea('Soporte mensual', 1, 4000000, 5, 'MON', 'SRV-02'),
            ],
            $this->resolucion()
        );
    }

    /**
     * Documento de una sola linea, para las pruebas que no dependen de los
     * totales.
     */
    private function documento(Linea $linea, string $ambiente = DocumentoUbl::AMBIENTE_PRODUCCION): DocumentoUbl
    {
        return new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            [$linea],
            $this->resolucion(),
            $ambiente
        );
    }

    private function nota(
        TipoDocumento $tipo,
        string $codigo = '5',
        string $motivo = 'Devolucion parcial'
    ): DocumentoUbl {
        return new DocumentoUbl(
            $tipo,
            'FV-0100',
            new DateTimeImmutable('2026-10-22 10:15:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Ajuste de servicio', 1, 50000, 19)],
            $this->resolucion(),
            DocumentoUbl::AMBIENTE_PRODUCCION,
            'COP',
            'FV-0001',
            $motivo,
            $codigo
        );
    }

    private function emisor(): Tercero
    {
        return new Tercero(
            '900123456',
            'InheritDevs SAS',
            '48',
            '31',
            'Calle 10 # 20-30',
            'Medellin',
            'Antioquia',
            'facturacion@inheritdevs.com'
        );
    }

    private function adquirente(): Tercero
    {
        return new Tercero('800987654', 'Almacen El Progreso SAS', '49', '31', 'Carrera 45 # 12-08', 'Bogota', 'Cundinamarca');
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
            'clave-tecnica-de-prueba'
        );
    }

    private function cargar(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'el documento generado debe ser XML valido');

        return $dom;
    }

    /**
     * Texto del primer elemento con ese nombre en cualquier parte del
     * documento, opcionalmente dentro de un contenedor.
     */
    private function texto(\DOMDocument $dom, string $nombre, ?string $contenedor = null): string
    {
        if ($contenedor === null) {
            return $this->primero($dom, $nombre);
        }

        return $this->textoDe($this->nodo($dom, $contenedor), $nombre);
    }

    /**
     * Texto de un elemento de las extensiones de la DIAN.
     */
    private function textoDian(\DOMDocument $dom, string $nombre): string
    {
        $partes = explode(':', $nombre, 2);
        $local = count($partes) === 2 ? $partes[1] : $partes[0];

        // Las fechas de la resolucion viven en el periodo de autorizacion,
        // dentro de las extensiones de la DIAN, pero en el espacio comun de
        // UBL: se buscan primero ahi.
        $periodo = $dom->getElementsByTagNameNS(self::NS_DIAN, 'AuthorizationPeriod')->item(0);

        if ($periodo instanceof \DOMElement) {
            $enPeriodo = $periodo->getElementsByTagNameNS(self::NS_CBC, $local);

            if ($enPeriodo->length > 0) {
                return trim((string) $enPeriodo->item(0)?->textContent);
            }
        }

        $extensiones = $dom->getElementsByTagNameNS(self::NS_DIAN, $local);
        $this->assertGreaterThan(0, $extensiones->length, 'falta el elemento ' . $nombre);

        return trim((string) $extensiones->item(0)?->textContent);
    }

    /**
     * Texto del primer descendiente con ese nombre dentro de un nodo.
     */
    private function textoDe(?\DOMNode $nodo, string $nombre, ?string $contenedor = null): string
    {
        $this->assertNotNull($nodo, 'no se encontro el contenedor de ' . $nombre);

        $contenedorNodo = $contenedor === null ? $nodo : $this->nodo($nodo, $contenedor);

        return $this->primero($contenedorNodo, $nombre);
    }

    private function nodo(\DOMNode $nodo, string $nombre): ?\DOMElement
    {
        // Los nombres sin prefijo son de los componentes basicos (cbc).
        $partes = explode(':', $nombre, 2);
        $prefijo = count($partes) === 2 ? $partes[0] : 'cbc';
        $local = count($partes) === 2 ? $partes[1] : $partes[0];

        $lista = $nodo instanceof \DOMDocument
            ? $nodo->getElementsByTagNameNS($this->espacio($prefijo), $local)
            : $this->descendientes($nodo, $this->espacio($prefijo), $local);

        $elemento = $lista->item(0);

        return $elemento instanceof \DOMElement ? $elemento : null;
    }

    private function primero(\DOMNode $nodo, string $nombre): string
    {
        $elemento = $this->nodo($nodo, $nombre);
        $this->assertNotNull($elemento, 'falta el elemento ' . $nombre);

        return trim((string) $elemento->textContent);
    }

    /**
     * @return \DOMNodeList<\DOMElement>
     */
    private function descendientes(\DOMNode $nodo, string $espacio, string $nombre): \DOMNodeList
    {
        $xpath = new \DOMXPath($nodo instanceof \DOMDocument ? $nodo : $nodo->ownerDocument);

        $prefijo = $espacio === self::NS_CBC ? 'cbc' : 'cac';
        $xpath->registerNamespace($prefijo, $espacio);

        $resultado = $xpath->query('.//' . $prefijo . ':' . $nombre, $nodo);
        $this->assertNotFalse($resultado);

        return $resultado;
    }

    private function espacio(string $prefijo): string
    {
        return match ($prefijo) {
            'cbc' => self::NS_CBC,
            'cac' => self::NS_CAC,
            default => self::NS_DIAN,
        };
    }
}

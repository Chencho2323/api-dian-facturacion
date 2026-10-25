<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use DateTimeImmutable;
use InheritDevs\Dian\Calculo\Redondeo;
use InheritDevs\Dian\Calculo\Retencion;
use InheritDevs\Dian\Calculo\TipoRetencion;
use InheritDevs\Dian\Calculo\Totales;
use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Documento\Linea;
use InheritDevs\Dian\Documento\Resolucion;
use InheritDevs\Dian\Documento\Tercero;
use InheritDevs\Dian\Documento\TipoDocumento;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;
use InheritDevs\Dian\Servicio;
use InheritDevs\Dian\Validacion\ValidadorXsd;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del calculo de IVA, retenciones y totales.
 *
 * Cubren los redondeos del protocolo (cada linea al centavo antes de sumar),
 * el descuento de las retenciones en el valor a pagar y el bloque
 * `WithholdingTaxTotal` del XML, que el esquema tambien tiene que aceptar.
 */
final class CalculoTest extends TestCase
{
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public function testElRedondeoSubeLaMitadHaciaArriba(): void
    {
        // Un centavo partido por la mitad sube; no baja ni se va al par.
        $this->assertSame(1, Redondeo::mitadArriba(1, 2));
        $this->assertSame(2, Redondeo::mitadArriba(3, 2));
        $this->assertSame(3, Redondeo::mitadArriba(5, 2));
        $this->assertSame(0, Redondeo::mitadArriba(1, 3));
        $this->assertSame(1, Redondeo::mitadArriba(2, 3));
    }

    public function testElRedondeoEsSimetricoParaLosNegativos(): void
    {
        // Las notas credito llevan valores negativos y se redondean igual,
        // alejandose del cero: el ajuste no se queda con medio centavo.
        $this->assertSame(-1, Redondeo::mitadArriba(-1, 2));
        $this->assertSame(-3, Redondeo::mitadArriba(-5, 2));
        $this->assertSame(0, Redondeo::mitadArriba(0, 7));
    }

    public function testElRedondeoRechazaUnDivisorNoPositivo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('divisor del redondeo');

        Redondeo::mitadArriba(10, 0);
    }

    public function testElPorcentajeSeConvierteAPartesPorMillon(): void
    {
        $this->assertSame(190000, Redondeo::desdePorcentaje(19.0));
        $this->assertSame(25000, Redondeo::desdePorcentaje(2.5));
        $this->assertSame(9660, Redondeo::desdePorcentaje(0.966));
        $this->assertSame(0, Redondeo::desdePorcentaje(0.0));
    }

    public function testElPorcentajeFueraDeRangoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entre 0 y 100');

        Redondeo::desdePorcentaje(120.0);
    }

    public function testElPorcentajeSeEscribeSinCerosSobrantes(): void
    {
        $this->assertSame('19', Redondeo::porcentaje(190000));
        $this->assertSame('2.5', Redondeo::porcentaje(25000));
        $this->assertSame('0.966', Redondeo::porcentaje(9660));
        $this->assertSame('0', Redondeo::porcentaje(0));
    }

    public function testLosTotalesSumanLasLineasConSuIva(): void
    {
        $totales = new Totales($this->lineas());

        $this->assertSame(29000000, $totales->subtotalCentavos());
        $this->assertSame(4950000, $totales->ivaCentavos());
        $this->assertSame(33950000, $totales->totalCentavos());
        $this->assertSame(0, $totales->retencionesCentavos());
        $this->assertSame(33950000, $totales->aPagarCentavos());
    }

    public function testElDesgloseAgrupaPorTarifaEnOrdenAscendente(): void
    {
        $totales = new Totales($this->lineas());

        $this->assertSame(
            [
                5 => ['base' => 4000000, 'iva' => 200000],
                19 => ['base' => 25000000, 'iva' => 4750000],
            ],
            $totales->desgloseIva()
        );
        $this->assertSame([5 => 4000000, 19 => 25000000], $totales->basesPorTarifa());
        $this->assertSame([5 => 200000, 19 => 4750000], $totales->ivaPorTarifa());
    }

    public function testElIvaSeRedondeaLineaPorLineaYNoSobreLaBaseAgrupada(): void
    {
        // Cada linea de 1,05 pesos al 5 % deja 5,25 centavos, que redondean a
        // 5. Sumadas dan 15. Si se agrupara la base (3,15 pesos) el impuesto
        // daria 15,75 y redondearia a 16: un centavo que la linea no declara.
        $lineas = [
            new Linea('Servicio uno', 1, 105, 5),
            new Linea('Servicio dos', 1, 105, 5),
            new Linea('Servicio tres', 1, 105, 5),
        ];

        $totales = new Totales($lineas);

        $this->assertSame(315, $totales->subtotalCentavos());
        $this->assertSame(15, $totales->ivaCentavos());
        $this->assertSame([5 => ['base' => 315, 'iva' => 15]], $totales->desgloseIva());
    }

    public function testLaLineaSinIvaNoEntraEnElDesglose(): void
    {
        $totales = new Totales([
            new Linea('Libro', 1, 5000000, 0),
            new Linea('Servicio', 1, 1000000, 19),
        ]);

        $this->assertSame([19 => ['base' => 1000000, 'iva' => 190000]], $totales->desgloseIva());
        $this->assertSame(6000000, $totales->subtotalCentavos());
        $this->assertSame(190000, $totales->ivaCentavos());
    }

    public function testLaRetencionEnLaFuenteSeCalculaSobreElSubtotal(): void
    {
        $retencion = new Retencion(TipoRetencion::Fuente, 2.5);
        $totales = new Totales($this->lineas(), [$retencion]);

        $this->assertSame(29000000, $totales->baseDe($retencion));
        $this->assertSame(725000, $totales->valorDe($retencion));
        $this->assertSame(725000, $totales->retencionesCentavos());
        $this->assertSame(33225000, $totales->aPagarCentavos());
    }

    public function testLaRetencionDeIvaSeCalculaSobreElIvaDelDocumento(): void
    {
        $retencion = new Retencion(TipoRetencion::Iva, 15);
        $totales = new Totales($this->lineas(), [$retencion]);

        $this->assertSame(4950000, $totales->baseDe($retencion));
        $this->assertSame(742500, $totales->valorDe($retencion));
        $this->assertSame(33207500, $totales->aPagarCentavos());
    }

    public function testLaRetencionDeIcaAceptaTresDecimales(): void
    {
        $retencion = new Retencion(TipoRetencion::Ica, 0.966);
        $totales = new Totales($this->lineas(), [$retencion]);

        $this->assertSame(9660, $retencion->tarifa());
        $this->assertSame('0.966', $retencion->tarifaTexto());
        $this->assertSame(280140, $totales->valorDe($retencion));
    }

    public function testVariasRetencionesSeDescuentanJuntas(): void
    {
        $totales = new Totales($this->lineas(), [
            new Retencion(TipoRetencion::Fuente, 2.5),
            new Retencion(TipoRetencion::Iva, 15),
            new Retencion(TipoRetencion::Ica, 0.966),
        ]);

        $this->assertSame(725000 + 742500 + 280140, $totales->retencionesCentavos());
        $this->assertSame(
            $totales->totalCentavos() - 1747640,
            $totales->aPagarCentavos()
        );
    }

    public function testElResumenTraeLosTotalesDelDocumento(): void
    {
        $totales = new Totales($this->lineas(), [new Retencion(TipoRetencion::Fuente, 2.5)]);

        $this->assertSame(
            [
                'subtotal' => 29000000,
                'iva' => 4950000,
                'total' => 33950000,
                'retenciones' => 725000,
                'aPagar' => 33225000,
                'ivaPorTarifa' => [
                    5 => ['base' => 4000000, 'iva' => 200000],
                    19 => ['base' => 25000000, 'iva' => 4750000],
                ],
            ],
            $totales->resumen()
        );
    }

    public function testLaRetencionConTarifaCeroSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('debe ser mayor que cero');

        new Retencion(TipoRetencion::Fuente, 0.0);
    }

    public function testLaRetencionDeIvaSinIvaEnElDocumentoSeRechaza(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('no se puede practicar retencion de IVA');

        new Totales(
            [new Linea('Libro', 1, 5000000, 0)],
            [new Retencion(TipoRetencion::Iva, 15)]
        );
    }

    public function testLasRetencionesQueSuperanElTotalSeRechazan(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('superan el total del documento');

        new Totales(
            [new Linea('Servicio', 1, 100000, 0)],
            [
                new Retencion(TipoRetencion::Fuente, 100),
                new Retencion(TipoRetencion::Ica, 100),
            ]
        );
    }

    public function testLosTotalesSinLineasSeRechazan(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->expectExceptionMessage('al menos una linea');

        new Totales([]);
    }

    public function testElDocumentoPublicaElValorAPagarConLasRetencionesDescontadas(): void
    {
        $documento = $this->documento([
            new Retencion(TipoRetencion::Fuente, 2.5),
        ]);

        $this->assertSame(33950000, $documento->totalCentavos());
        $this->assertSame(725000, $documento->retencionesCentavos());
        $this->assertSame(33225000, $documento->aPagarCentavos());

        $dom = $this->cargar($documento->xml());

        $this->assertSame('339500.00', $this->texto($dom, 'TaxInclusiveAmount', 'cac:LegalMonetaryTotal'));
        $this->assertSame('332250.00', $this->texto($dom, 'PayableAmount', 'cac:LegalMonetaryTotal'));
    }

    public function testElXmlLlevaUnWithholdingTaxTotalPorRetencion(): void
    {
        $documento = $this->documento([
            new Retencion(TipoRetencion::Fuente, 2.5),
            new Retencion(TipoRetencion::Iva, 15),
        ]);

        $dom = $this->cargar($documento->xml());

        $bloques = $dom->getElementsByTagNameNS(self::NS_CAC, 'WithholdingTaxTotal');
        $this->assertSame(2, $bloques->length);

        $primero = $bloques->item(0);
        $this->assertNotNull($primero);
        $this->assertSame('7250.00', $this->textoDe($primero, 'cbc:TaxAmount'));
        $this->assertSame('290000.00', $this->textoDe($primero, 'cbc:TaxableAmount', 'cac:TaxSubtotal'));
        $this->assertSame('2.5', $this->textoDe($primero, 'cbc:Percent', 'cac:TaxSubtotal/cac:TaxCategory'));
        $this->assertSame('06', $this->textoDe($primero, 'cbc:ID', 'cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme'));
        $this->assertSame(
            'ReteFuente',
            $this->textoDe($primero, 'cbc:Name', 'cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme')
        );

        $segundo = $bloques->item(1);
        $this->assertNotNull($segundo);
        $this->assertSame('7425.00', $this->textoDe($segundo, 'cbc:TaxAmount'));
        $this->assertSame('49500.00', $this->textoDe($segundo, 'cbc:TaxableAmount', 'cac:TaxSubtotal'));
        $this->assertSame('05', $this->textoDe($segundo, 'cbc:ID', 'cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme'));
        $this->assertSame(
            'ReteIVA',
            $this->textoDe($segundo, 'cbc:Name', 'cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme')
        );
    }

    public function testElDocumentoSinRetencionesNoLlevaElBloque(): void
    {
        $dom = $this->cargar($this->documento()->xml());

        $this->assertSame(
            0,
            $dom->getElementsByTagNameNS(self::NS_CAC, 'WithholdingTaxTotal')->length
        );
    }

    public function testLaFacturaConRetencionesCumpleElEsquema(): void
    {
        $resultado = (new ValidadorXsd())->validarDocumento(
            $this->documento([new Retencion(TipoRetencion::Fuente, 2.5)])
        );

        $this->assertTrue($resultado->valido(), $resultado->reporte());
    }

    public function testLaNotaCreditoConRetencionesCumpleElEsquema(): void
    {
        $nota = new DocumentoUbl(
            TipoDocumento::NotaCredito,
            'FV-0100',
            new DateTimeImmutable('2026-10-22 10:15:00'),
            $this->emisor(),
            $this->adquirente(),
            [new Linea('Devolucion de servicio', 1, 5000000, 19)],
            $this->resolucion(),
            DocumentoUbl::AMBIENTE_PRUEBAS,
            'COP',
            'FV-0001',
            'Devolucion parcial',
            '5',
            null,
            [new Retencion(TipoRetencion::Fuente, 2.5)]
        );

        $resultado = (new ValidadorXsd())->validarDocumento($nota);
        $this->assertTrue($resultado->valido(), $resultado->reporte());

        $dom = $this->cargar($nota->xml());
        $this->assertSame(
            1,
            $dom->getElementsByTagNameNS(self::NS_CAC, 'WithholdingTaxTotal')->length
        );
    }

    public function testElUuidCambiaCuandoCambianLasRetenciones(): void
    {
        $sinRetencion = $this->documento();
        $conRetencion = $this->documento([new Retencion(TipoRetencion::Fuente, 2.5)]);

        $this->assertNotSame($sinRetencion->xml(), $conRetencion->xml());
        $this->assertNotSame(
            $this->texto($this->cargar($sinRetencion->xml()), 'UUID'),
            $this->texto($this->cargar($conRetencion->xml()), 'UUID'),
            'el valor a pagar forma parte de la semilla del UUID'
        );
    }

    public function testElCalculoQuedaRegistradoComoModuloDelServicio(): void
    {
        $this->assertContains('calculo', Servicio::estado()['modulos']);
    }

    /**
     * Factura de dos lineas: una al 19 % y otra al 5 %.
     *
     * @return list<Linea>
     */
    private function lineas(): array
    {
        return [
            new Linea('Servicio de desarrollo', 2, 12500000, 19, '94', 'SRV-01'),
            new Linea('Soporte mensual', 1, 4000000, 5, 'MON', 'SRV-02'),
        ];
    }

    /**
     * @param list<Retencion> $retenciones
     */
    private function documento(array $retenciones = []): DocumentoUbl
    {
        return new DocumentoUbl(
            TipoDocumento::Factura,
            'FV-0001',
            new DateTimeImmutable('2026-10-22 09:30:00'),
            $this->emisor(),
            $this->adquirente(),
            $this->lineas(),
            $this->resolucion(),
            DocumentoUbl::AMBIENTE_PRODUCCION,
            'COP',
            null,
            null,
            null,
            null,
            $retenciones
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
            'Bogota',
            'Cundinamarca'
        );
    }

    private function adquirente(): Tercero
    {
        return new Tercero(
            '800987654',
            'Almacen El Progreso SAS',
            '49',
            '31',
            'Carrera 5 # 12-40',
            'Medellin',
            'Antioquia'
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

    private function cargar(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));

        return $dom;
    }

    private function texto(\DOMDocument $dom, string $nombre, string $contexto = ''): ?string
    {
        $nodo = $this->nodo($dom, $nombre, $contexto);

        return $nodo?->textContent;
    }

    private function textoDe(\DOMNode $padre, string $nombre, string $contexto = ''): ?string
    {
        return $this->nodo($padre, $nombre, $contexto)?->textContent;
    }

    private function nodo(\DOMNode $padre, string $consulta, string $contexto = ''): ?\DOMElement
    {
        $xpath = new \DOMXPath($padre instanceof \DOMDocument ? $padre : $padre->ownerDocument);

        foreach ([self::NS_CBC => 'cbc', self::NS_CAC => 'cac'] as $espacio => $prefijo) {
            $xpath->registerNamespace($prefijo, $espacio);
        }

        // Los nombres sin prefijo son de los componentes basicos (cbc).
        $pasos = array_map(
            static fn (string $paso): string => str_contains($paso, ':') ? $paso : 'cbc:' . $paso,
            array_filter(explode('/', $consulta), static fn (string $paso): bool => $paso !== '')
        );
        $ruta = implode('/', $pasos);

        if ($contexto !== '') {
            $ruta = $contexto . '/' . $ruta;
        }

        $resultado = $xpath->query(
            $ruta,
            $padre instanceof \DOMDocument ? $padre->documentElement : $padre
        );

        $this->assertNotFalse($resultado);

        $nodo = $resultado->item(0);

        return $nodo instanceof \DOMElement ? $nodo : null;
    }
}

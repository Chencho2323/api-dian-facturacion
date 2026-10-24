<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Documento;

use DateTimeImmutable;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;
use RuntimeException;

/**
 * Documento UBL 2.1 listo para validar y firmar: factura, nota credito o nota
 * debito.
 *
 * Arma el XML con los espacios de nombres de UBL, los datos del emisor y del
 * adquirente, las lineas, los totales y el bloque `DianExtensions` con el
 * UUID, la resolucion de facturacion y la referencia al documento que se
 * ajusta. No calcula retenciones ni valida contra el XSD: esas tareas llegan
 * despues.
 */
final class DocumentoUbl
{
    /** Espacio de nombres de los componentes comunes de UBL 2.1. */
    public const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** Espacio de nombres de los componentes agregados de UBL 2.1. */
    public const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /** Espacio de nombres de las extensiones de la DIAN. */
    public const NS_STS = 'dian:gov:co:facturaelectronica:Structures-2-1';

    /** Version de UBL que se emite. */
    public const VERSION_UBL = '2.1';

    /** Version del anexo tecnico de la DIAN que se esta implementando. */
    public const PERFIL = '2.0';

    /** Ambiente de produccion de la DIAN. */
    public const AMBIENTE_PRODUCCION = '1';

    /** Ambiente de pruebas de la DIAN. */
    public const AMBIENTE_PRUEBAS = '2';

    /**
     * Tipos de documento de la DIAN que se pueden referenciar.
     */
    public const TIPO_REFERENCIA_FACTURA = '01';
    public const TIPO_REFERENCIA_NOTA_CREDITO = '02';
    public const TIPO_REFERENCIA_NOTA_DEBITO = '03';

    /**
     * @param TipoDocumento     $tipo            Tipo de documento a emitir.
     * @param string            $numero          Numero completo con prefijo.
     * @param DateTimeImmutable $fechaEmision    Fecha y hora de emision.
     * @param Tercero           $emisor          Quien emite el documento.
     * @param Tercero           $adquirente      Quien recibe el documento.
     * @param list<Linea>       $lineas          Lineas del documento.
     * @param Resolucion        $resolucion      Resolucion que autoriza la numeracion.
     * @param string            $ambiente        Ambiente de la DIAN.
     * @param string            $moneda          Codigo ISO de la moneda.
     * @param string|null       $documentoAjustado Numero del documento que se ajusta.
     * @param string|null       $motivoAjuste      Motivo de la nota credito o debito.
     * @param string|null       $motivoCodigo      Codigo de correccion de la DIAN.
     * @param string|null       $uuid              CUFE o CUDE ya calculado.
     */
    public function __construct(
        private readonly TipoDocumento $tipo,
        private readonly string $numero,
        private readonly DateTimeImmutable $fechaEmision,
        private readonly Tercero $emisor,
        private readonly Tercero $adquirente,
        private readonly array $lineas,
        private readonly Resolucion $resolucion,
        private readonly string $ambiente = self::AMBIENTE_PRODUCCION,
        private readonly string $moneda = 'COP',
        private readonly ?string $documentoAjustado = null,
        private readonly ?string $motivoAjuste = null,
        private readonly ?string $motivoCodigo = null,
        private readonly ?string $uuid = null,
    ) {
        $this->validar();
    }

    public function tipo(): TipoDocumento
    {
        return $this->tipo;
    }

    public function numero(): string
    {
        return $this->numero;
    }

    public function fechaEmision(): DateTimeImmutable
    {
        return $this->fechaEmision;
    }

    public function emisor(): Tercero
    {
        return $this->emisor;
    }

    public function adquirente(): Tercero
    {
        return $this->adquirente;
    }

    /**
     * @return list<Linea>
     */
    public function lineas(): array
    {
        return $this->lineas;
    }

    public function resolucion(): Resolucion
    {
        return $this->resolucion;
    }

    public function ambiente(): string
    {
        return $this->ambiente;
    }

    public function moneda(): string
    {
        return $this->moneda;
    }

    public function documentoAjustado(): ?string
    {
        return $this->documentoAjustado;
    }

    public function motivoAjuste(): ?string
    {
        return $this->motivoAjuste;
    }

    /**
     * Suma de las lineas sin impuestos.
     */
    public function subtotalCentavos(): int
    {
        $total = 0;

        foreach ($this->lineas as $linea) {
            $total += $linea->subtotalCentavos();
        }

        return $total;
    }

    /**
     * Suma del IVA de todas las lineas.
     */
    public function ivaCentavos(): int
    {
        $total = 0;

        foreach ($this->lineas as $linea) {
            $total += $linea->ivaCentavos();
        }

        return $total;
    }

    /**
     * Total del documento con impuestos.
     */
    public function totalCentavos(): int
    {
        return $this->subtotalCentavos() + $this->ivaCentavos();
    }

    /**
     * IVA agrupado por tarifa, en el orden en que aparecen las lineas.
     *
     * @return array<int, int> Tarifa => base gravable en centavos.
     */
    public function basesPorTarifa(): array
    {
        $bases = [];

        foreach ($this->lineas as $linea) {
            $tarifa = $linea->tarifaIva();

            if ($tarifa === 0) {
                continue;
            }

            $bases[$tarifa] = ($bases[$tarifa] ?? 0) + $linea->subtotalCentavos();
        }

        ksort($bases);

        return $bases;
    }

    /**
     * IVA agrupado por tarifa.
     *
     * @return array<int, int> Tarifa => IVA en centavos.
     */
    public function ivaPorTarifa(): array
    {
        $impuestos = [];

        foreach ($this->lineas as $linea) {
            $tarifa = $linea->tarifaIva();

            if ($tarifa === 0) {
                continue;
            }

            $impuestos[$tarifa] = ($impuestos[$tarifa] ?? 0) + $linea->ivaCentavos();
        }

        ksort($impuestos);

        return $impuestos;
    }

    /**
     * Arma el XML del documento.
     *
     * @throws RuntimeException Si el DOM no se puede construir o serializar.
     */
    public function xml(): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $raiz = $dom->createElementNS(
            $this->espacioDeNombresRaiz(),
            $this->tipo->raiz()
        );
        $raiz->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cbc',
            self::NS_CBC
        );
        $raiz->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cac',
            self::NS_CAC
        );
        $raiz->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:sts',
            self::NS_STS
        );

        $dom->appendChild($raiz);

        $this->agregarExtensionesDian($dom, $raiz);

        $this->agregar($dom, $raiz, self::NS_CBC, 'UBLVersionID', self::VERSION_UBL);
        $this->agregar($dom, $raiz, self::NS_CBC, 'CustomizationID', $this->tipo->codigoOperacion());
        $this->agregar($dom, $raiz, self::NS_CBC, 'ProfileID', self::PERFIL);
        $this->agregar($dom, $raiz, self::NS_CBC, 'ProfileExecutionID', $this->ambiente);
        $this->agregar($dom, $raiz, self::NS_CBC, 'ID', $this->numero);
        $this->agregar($dom, $raiz, self::NS_CBC, 'UUID', $this->uuid ?? $this->uuidProvisional());
        $this->agregar(
            $dom,
            $raiz,
            self::NS_CBC,
            'IssueDate',
            $this->fechaEmision->format('Y-m-d')
        );
        $this->agregar(
            $dom,
            $raiz,
            self::NS_CBC,
            'IssueTime',
            $this->fechaEmision->format('H:i:sP')
        );
        $this->agregar($dom, $raiz, self::NS_CBC, 'DocumentCurrencyCode', $this->moneda);
        $this->agregar($dom, $raiz, self::NS_CBC, 'LineCountNumeric', (string) count($this->lineas));

        $this->agregarReferenciaAjustada($dom, $raiz);
        $this->agregarPartes($dom, $raiz);
        $this->agregarTotales($dom, $raiz);
        $this->agregarLineas($dom, $raiz);

        $xml = $dom->saveXML();

        if ($xml === false) {
            throw new RuntimeException('No fue posible serializar el documento UBL.');
        }

        return $xml;
    }

    /**
     * Espacio de nombres del elemento raiz, que depende del tipo de documento.
     */
    public function espacioDeNombresRaiz(): string
    {
        return match ($this->tipo) {
            TipoDocumento::Factura => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            TipoDocumento::NotaCredito => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
            TipoDocumento::NotaDebito => 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2',
        };
    }

    /**
     * UUID provisional: 64 caracteres hexadecimales derivados de los datos del
     * documento. El CUFE real se calcula con la firma y la clave tecnica en la
     * tarea de emision.
     */
    private function uuidProvisional(): string
    {
        $semilla = implode('|', [
            $this->tipo->value,
            $this->numero,
            $this->fechaEmision->format('YmdHis'),
            $this->emisor->identificacion(),
            $this->adquirente->identificacion(),
            (string) $this->totalCentavos(),
        ]);

        return strtoupper(hash('sha256', $semilla));
    }

    /**
     * Referencia al documento que se ajusta. Solo la llevan las notas.
     */
    private function agregarReferenciaAjustada(\DOMDocument $dom, \DOMElement $raiz): void
    {
        if (!$this->tipo->ajustaOtroDocumento()) {
            return;
        }

        $referencia = $dom->createElementNS(self::NS_CAC, 'cac:BillingReference');

        $respuesta = $dom->createElementNS(self::NS_CAC, 'cac:DiscrepancyResponse');
        $raiz->appendChild($respuesta);
        $this->agregar($dom, $respuesta, self::NS_CBC, 'ReferenceID', (string) $this->documentoAjustado);
        $this->agregar($dom, $respuesta, self::NS_CBC, 'ResponseCode', $this->motivoCodigo ?? '1');
        $this->agregar($dom, $respuesta, self::NS_CBC, 'Description', (string) $this->motivoAjuste);

        $raiz->appendChild($referencia);

        $factura = $dom->createElementNS(self::NS_CAC, 'cac:InvoiceDocumentReference');
        $referencia->appendChild($factura);

        $this->agregar($dom, $factura, self::NS_CBC, 'ID', (string) $this->documentoAjustado);
        $this->agregar($dom, $factura, self::NS_CBC, 'DocumentTypeCode', self::TIPO_REFERENCIA_FACTURA);
        $this->agregar(
            $dom,
            $factura,
            self::NS_CBC,
            'DocumentType',
            'Factura electronica de venta'
        );
    }

    /**
     * Bloque `DianExtensions`: UUID, resolucion y control de la factura.
     */
    private function agregarExtensionesDian(\DOMDocument $dom, \DOMElement $raiz): void
    {
        $extensiones = $dom->createElementNS(self::NS_CAC, 'cac:UBLExtensions');
        $raiz->appendChild($extensiones);

        $extension = $dom->createElementNS(self::NS_CAC, 'cac:UBLExtension');
        $extensiones->appendChild($extension);

        $contenido = $dom->createElementNS(self::NS_CAC, 'cac:ExtensionContent');
        $extension->appendChild($contenido);

        $dian = $dom->createElementNS(self::NS_STS, 'sts:DianExtensions');
        $contenido->appendChild($dian);

        $factura = $dom->createElementNS(self::NS_STS, 'sts:InvoiceControl');
        $dian->appendChild($factura);

        $this->agregar($dom, $factura, self::NS_STS, 'sts:InvoiceAuthorization', $this->resolucion->numero());

        $periodo = $dom->createElementNS(self::NS_STS, 'sts:AuthorizationPeriod');
        $factura->appendChild($periodo);
        $this->agregar(
            $dom,
            $periodo,
            self::NS_CBC,
            'cbc:StartDate',
            $this->resolucion->vigenciaDesde()->format('Y-m-d')
        );
        $this->agregar(
            $dom,
            $periodo,
            self::NS_CBC,
            'cbc:EndDate',
            $this->resolucion->vigenciaHasta()->format('Y-m-d')
        );

        $autorizado = $dom->createElementNS(self::NS_STS, 'sts:AuthorizedInvoices');
        $factura->appendChild($autorizado);
        $this->agregar($dom, $autorizado, self::NS_STS, 'sts:Prefix', $this->resolucion->prefijo());
        $this->agregar($dom, $autorizado, self::NS_STS, 'sts:From', (string) $this->resolucion->desde());
        $this->agregar($dom, $autorizado, self::NS_STS, 'sts:To', (string) $this->resolucion->hasta());

        $this->agregar($dom, $dian, self::NS_STS, 'sts:UUID', $this->uuid ?? $this->uuidProvisional());
    }

    /**
     * Emisor y adquirente con su identificacion y su regimen.
     */
    private function agregarPartes(\DOMDocument $dom, \DOMElement $raiz): void
    {
        $this->agregarParte($dom, $raiz, 'cac:AccountingSupplierParty', $this->emisor);
        $this->agregarParte($dom, $raiz, 'cac:AccountingCustomerParty', $this->adquirente);
    }

    private function agregarParte(
        \DOMDocument $dom,
        \DOMElement $raiz,
        string $elemento,
        Tercero $tercero
    ): void {
        $parte = $dom->createElementNS(self::NS_CAC, $elemento);
        $raiz->appendChild($parte);

        $sujeto = $dom->createElementNS(self::NS_CAC, 'cac:Party');
        $parte->appendChild($sujeto);

        $identificacion = $dom->createElementNS(self::NS_CAC, 'cac:PartyIdentification');
        $sujeto->appendChild($identificacion);
        $id = $dom->createElementNS(self::NS_CBC, 'cbc:ID');
        $id->appendChild($dom->createTextNode($tercero->identificacion()));
        $id->setAttribute('schemeID', $tercero->tipoIdentificacion());
        $id->setAttribute('schemeName', Tercero::TIPOS_IDENTIFICACION[$tercero->tipoIdentificacion()]);
        $identificacion->appendChild($id);

        $nombre = $dom->createElementNS(self::NS_CAC, 'cac:PartyName');
        $sujeto->appendChild($nombre);
        $this->agregar($dom, $nombre, self::NS_CBC, 'Name', $tercero->razonSocial());

        $this->agregar(
            $dom,
            $sujeto,
            self::NS_CBC,
            'RegistrationName',
            $tercero->razonSocial()
        );

        $regimen = $dom->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
        $sujeto->appendChild($regimen);
        $esquema = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $regimen->appendChild($esquema);
        $this->agregar($dom, $esquema, self::NS_CBC, 'ID', $tercero->regimen());
        $this->agregar($dom, $esquema, self::NS_CBC, 'Name', $tercero->nombreRegimen());

        $direccion = $dom->createElementNS(self::NS_CAC, 'cac:PhysicalLocation');
        $sujeto->appendChild($direccion);
        $lugar = $dom->createElementNS(self::NS_CAC, 'cac:Address');
        $direccion->appendChild($lugar);
        $this->agregar($dom, $lugar, self::NS_CBC, 'StreetName', $tercero->direccion());
        $this->agregar($dom, $lugar, self::NS_CBC, 'CityName', $tercero->ciudad());
        $this->agregar($dom, $lugar, self::NS_CBC, 'CountrySubentity', $tercero->departamento());
    }

    /**
     * Totales del documento y desglose de impuestos por tarifa.
     */
    private function agregarTotales(\DOMDocument $dom, \DOMElement $raiz): void
    {
        $monetario = $dom->createElementNS(self::NS_CAC, 'cac:LegalMonetaryTotal');
        $raiz->appendChild($monetario);

        $this->agregarMoneda($dom, $monetario, 'LineExtensionAmount', $this->subtotalCentavos());

        if ($this->ivaCentavos() > 0) {
            $this->agregarMoneda($dom, $monetario, 'TaxExclusiveAmount', $this->subtotalCentavos());
            $this->agregarMoneda($dom, $monetario, 'TaxInclusiveAmount', $this->totalCentavos());
        }

        $this->agregarMoneda($dom, $monetario, 'PayableAmount', $this->totalCentavos());

        $impuestoTotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxTotal');
        $raiz->appendChild($impuestoTotal);
        $this->agregarMoneda($dom, $impuestoTotal, 'TaxAmount', $this->ivaCentavos());

        $bases = $this->basesPorTarifa();
        $impuestos = $this->ivaPorTarifa();

        foreach ($impuestos as $tarifa => $valor) {
            $subtotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxSubtotal');
            $impuestoTotal->appendChild($subtotal);
            $this->agregarMoneda($dom, $subtotal, 'TaxableAmount', $bases[$tarifa] ?? 0);
            $this->agregarMoneda($dom, $subtotal, 'TaxAmount', $valor);

            $categoria = $dom->createElementNS(self::NS_CAC, 'cac:TaxCategory');
            $subtotal->appendChild($categoria);
            $this->agregar($dom, $categoria, self::NS_CBC, 'Percent', (string) $tarifa);

            $esquema = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
            $categoria->appendChild($esquema);
            $this->agregar($dom, $esquema, self::NS_CBC, 'ID', '01');
            $this->agregar($dom, $esquema, self::NS_CBC, 'Name', 'IVA');
        }
    }

    /**
     * Lineas del documento con su cantidad, su precio y su IVA.
     */
    private function agregarLineas(\DOMDocument $dom, \DOMElement $raiz): void
    {
        $consecutivo = 0;

        foreach ($this->lineas as $linea) {
            $consecutivo++;

            $elemento = $dom->createElementNS(self::NS_CAC, 'cac:' . $this->tipo->elementoLinea());
            $raiz->appendChild($elemento);

            $this->agregar($dom, $elemento, self::NS_CBC, 'ID', (string) $consecutivo);

            if ($linea->codigo() !== '') {
                $this->agregar($dom, $elemento, self::NS_CBC, 'Note', $linea->codigo());
            }

            $cantidad = $dom->createElementNS(
                self::NS_CBC,
                'cbc:' . $this->tipo->elementoCantidad()
            );
            $cantidad->appendChild($dom->createTextNode($linea->cantidadUbl()));
            $cantidad->setAttribute('unitCode', $linea->unidadMedida());
            $elemento->appendChild($cantidad);

            $this->agregarMoneda($dom, $elemento, 'LineExtensionAmount', $linea->subtotalCentavos());

            $impuesto = $dom->createElementNS(self::NS_CAC, 'cac:TaxTotal');
            $elemento->appendChild($impuesto);
            $this->agregarMoneda($dom, $impuesto, 'TaxAmount', $linea->ivaCentavos());

            $subtotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxSubtotal');
            $impuesto->appendChild($subtotal);
            $this->agregarMoneda($dom, $subtotal, 'TaxableAmount', $linea->subtotalCentavos());
            $this->agregarMoneda($dom, $subtotal, 'TaxAmount', $linea->ivaCentavos());

            $categoria = $dom->createElementNS(self::NS_CAC, 'cac:TaxCategory');
            $subtotal->appendChild($categoria);
            $this->agregar($dom, $categoria, self::NS_CBC, 'Percent', (string) $linea->tarifaIva());

            $esquema = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
            $categoria->appendChild($esquema);
            $this->agregar($dom, $esquema, self::NS_CBC, 'ID', '01');
            $this->agregar($dom, $esquema, self::NS_CBC, 'Name', 'IVA');

            $item = $dom->createElementNS(self::NS_CAC, 'cac:Item');
            $elemento->appendChild($item);
            $this->agregar($dom, $item, self::NS_CBC, 'Description', $linea->descripcion());

            $precio = $dom->createElementNS(self::NS_CAC, 'cac:Price');
            $elemento->appendChild($precio);
            $this->agregarMoneda(
                $dom,
                $precio,
                'PriceAmount',
                $linea->precioUnitarioCentavos()
            );
        }
    }

    /**
     * Agrega un valor monetario con los dos decimales del protocolo.
     */
    private function agregarMoneda(
        \DOMDocument $dom,
        \DOMElement $padre,
        string $nombre,
        int $centavos
    ): void {
        $elemento = $this->agregar($dom, $padre, self::NS_CBC, $nombre, Linea::formatear($centavos));
        $elemento->setAttribute('currencyID', $this->moneda);
    }

    /**
     * Agrega un elemento de texto a un padre, en el espacio de nombres dado.
     */
    private function agregar(
        \DOMDocument $dom,
        \DOMElement $padre,
        string $espacio,
        string $nombre,
        string $valor
    ): \DOMElement {
        $elemento = $dom->createElementNS($espacio, $nombre);
        $elemento->appendChild($dom->createTextNode($valor));
        $padre->appendChild($elemento);

        return $elemento;
    }

    /**
     * Revisa la coherencia de los datos antes de armar el XML.
     *
     * @throws DocumentoInvalido
     */
    private function validar(): void
    {
        if (trim($this->numero) === '') {
            throw new DocumentoInvalido('El documento necesita un numero de facturacion.');
        }

        if ($this->lineas === []) {
            throw new DocumentoInvalido('El documento necesita al menos una linea.');
        }

        if (!in_array($this->ambiente, [self::AMBIENTE_PRODUCCION, self::AMBIENTE_PRUEBAS], true)) {
            throw new DocumentoInvalido(sprintf(
                'El ambiente "%s" no es valido. Use %s para produccion o %s para pruebas.',
                $this->ambiente,
                self::AMBIENTE_PRODUCCION,
                self::AMBIENTE_PRUEBAS
            ));
        }

        if (preg_match('/^[A-Z]{3}$/', $this->moneda) !== 1) {
            throw new DocumentoInvalido(
                'La moneda debe ser un codigo ISO de tres letras, por ejemplo COP.'
            );
        }

        if ($this->emisor->clave() === $this->adquirente->clave()) {
            throw new DocumentoInvalido('El emisor y el adquirente no pueden ser el mismo sujeto.');
        }

        if (!$this->emisor->cobraIva() && $this->ivaCentavos() > 0) {
            throw new DocumentoInvalido(sprintf(
                'El emisor esta en el regimen "%s" (%s) y el documento cobra IVA: un regimen no responsable de IVA no puede discriminarlo.',
                $this->emisor->nombreRegimen(),
                $this->emisor->regimen()
            ));
        }

        $this->validarConsecutivo();

        if ($this->tipo->ajustaOtroDocumento()) {
            if ($this->documentoAjustado === null || trim($this->documentoAjustado) === '') {
                throw new DocumentoInvalido(sprintf(
                    'La %s debe indicar el numero de la factura que ajusta.',
                    $this->tipo->etiqueta()
                ));
            }

            if ($this->motivoAjuste === null || trim($this->motivoAjuste) === '') {
                throw new DocumentoInvalido(sprintf(
                    'La %s debe explicar el motivo del ajuste.',
                    $this->tipo->etiqueta()
                ));
            }
        }
    }

    /**
     * El numero completo debe ser prefijo mas un consecutivo que caiga dentro
     * del rango autorizado por la resolucion.
     *
     * @throws DocumentoInvalido
     */
    private function validarConsecutivo(): void
    {
        $prefijo = $this->resolucion->prefijo();
        $numero = $this->numero;

        if (preg_match('/^(.*?)([0-9]+)$/', $numero, $partes) !== 1) {
            throw new DocumentoInvalido(sprintf(
                'El numero "%s" no termina en un consecutivo numerico.',
                $numero
            ));
        }

        // El prefijo se puede separar del consecutivo con guion, espacio o
        // nada: FV-0001, FV 0001 y FV0001 son la misma numeracion.
        $prefijoDocumento = rtrim($partes[1], "- _.");

        if ($prefijo !== '' && $prefijoDocumento !== $prefijo) {
            throw new DocumentoInvalido(sprintf(
                'El numero "%s" no lleva el prefijo "%s" de la resolucion %s.',
                $numero,
                $prefijo,
                $this->resolucion->numero()
            ));
        }

        $consecutivo = (int) $partes[2];

        if (!$this->resolucion->cubre($consecutivo)) {
            throw new DocumentoInvalido(sprintf(
                'El consecutivo %d esta fuera del rango %d a %d de la resolucion %s.',
                $consecutivo,
                $this->resolucion->desde(),
                $this->resolucion->hasta(),
                $this->resolucion->numero()
            ));
        }
    }
}

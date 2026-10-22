<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Documento;

/**
 * Tipos de documento UBL 2.1 que el servicio sabe armar.
 *
 * Cada caso conoce el nombre del elemento raiz del esquema UBL, el nombre del
 * elemento de linea y el codigo de operacion que la DIAN espera en
 * `cbc:CustomizationID`.
 */
enum TipoDocumento: string
{
    /** Factura electronica de venta. */
    case Factura = 'factura';

    /** Nota credito: corrige o disminuye una factura ya emitida. */
    case NotaCredito = 'nota-credito';

    /** Nota debito: aumenta el valor de una factura ya emitida. */
    case NotaDebito = 'nota-debito';

    /**
     * Nombre del elemento raiz del documento en el esquema UBL 2.1.
     */
    public function raiz(): string
    {
        return match ($this) {
            self::Factura => 'Invoice',
            self::NotaCredito => 'CreditNote',
            self::NotaDebito => 'DebitNote',
        };
    }

    /**
     * Nombre del elemento que describe cada linea del documento.
     */
    public function elementoLinea(): string
    {
        return match ($this) {
            self::Factura => 'InvoiceLine',
            self::NotaCredito => 'CreditNoteLine',
            self::NotaDebito => 'DebitNoteLine',
        };
    }

    /**
     * Nombre del elemento de cantidad de la linea. La factura factura
     * cantidad, la nota credito acredita y la nota debito debita.
     */
    public function elementoCantidad(): string
    {
        return match ($this) {
            self::Factura => 'InvoicedQuantity',
            self::NotaCredito => 'CreditedQuantity',
            self::NotaDebito => 'DebitedQuantity',
        };
    }

    /**
     * Codigo de operacion para `cbc:CustomizationID` segun el anexo tecnico
     * de la DIAN: 10 factura de venta, 20 nota credito, 30 nota debito.
     */
    public function codigoOperacion(): string
    {
        return match ($this) {
            self::Factura => '10',
            self::NotaCredito => '20',
            self::NotaDebito => '30',
        };
    }

    /**
     * Indica si el documento ajusta a otro y por lo tanto exige referencia.
     */
    public function ajustaOtroDocumento(): bool
    {
        return $this !== self::Factura;
    }

    /**
     * Nombre del tipo en espanol, para mensajes de error y bitacoras.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Factura => 'factura electronica de venta',
            self::NotaCredito => 'nota credito',
            self::NotaDebito => 'nota debito',
        };
    }
}

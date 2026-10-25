<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Calculo;

/**
 * Tipos de retencion que el servicio sabe practicar.
 *
 * Cada caso conoce el codigo del anexo tecnico de la DIAN, el nombre que va en
 * el XML y sobre que valor se calcula la base: las retenciones de renta e ICA
 * se calculan sobre el subtotal, y la de IVA sobre el IVA del documento.
 */
enum TipoRetencion: string
{
    /** Retencion en la fuente por renta. */
    case Fuente = 'fuente';

    /** Retencion de IVA. */
    case Iva = 'iva';

    /** Retencion de industria y comercio. */
    case Ica = 'ica';

    /**
     * Codigo del impuesto en el anexo tecnico de la DIAN.
     */
    public function codigo(): string
    {
        return match ($this) {
            self::Fuente => '06',
            self::Iva => '05',
            self::Ica => '07',
        };
    }

    /**
     * Nombre del impuesto tal como viaja en el XML.
     */
    public function nombre(): string
    {
        return match ($this) {
            self::Fuente => 'ReteFuente',
            self::Iva => 'ReteIVA',
            self::Ica => 'ReteICA',
        };
    }

    /**
     * Etiqueta para los mensajes de error y los reportes.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Fuente => 'retencion en la fuente',
            self::Iva => 'retencion de IVA',
            self::Ica => 'retencion de ICA',
        };
    }

    /**
     * Indica si la base de la retencion es el IVA del documento.
     */
    public function seCalculaSobreElIva(): bool
    {
        return $this === self::Iva;
    }
}

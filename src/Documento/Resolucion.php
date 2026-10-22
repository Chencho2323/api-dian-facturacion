<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Documento;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Resolucion de facturacion que autoriza a emitir documentos numerados.
 *
 * La DIAN entrega un rango de numeracion con vigencia y un prefijo. El
 * documento UBL lo publica en `sts:InvoiceControl`, dentro del bloque
 * `DianExtensions`, para que el adquirente pueda validar la numeracion.
 */
final class Resolucion
{
    /**
     * @param string            $numero       Numero de la resolucion.
     * @param string            $prefijo      Prefijo de la numeracion autorizada.
     * @param int               $desde        Primer numero del rango.
     * @param int               $hasta        Ultimo numero del rango.
     * @param DateTimeImmutable $vigenciaDesde Inicio de la vigencia.
     * @param DateTimeImmutable $vigenciaHasta Fin de la vigencia.
     * @param string            $claveTecnica Clave tecnica que la DIAN asigna al rango.
     */
    public function __construct(
        private readonly string $numero,
        private readonly string $prefijo,
        private readonly int $desde,
        private readonly int $hasta,
        private readonly DateTimeImmutable $vigenciaDesde,
        private readonly DateTimeImmutable $vigenciaHasta,
        private readonly string $claveTecnica,
    ) {
        if (trim($numero) === '') {
            throw new InvalidArgumentException('La resolucion necesita un numero.');
        }

        if ($desde < 1 || $hasta < $desde) {
            throw new InvalidArgumentException('El rango de numeracion de la resolucion esta invertido.');
        }

        if ($vigenciaHasta < $vigenciaDesde) {
            throw new InvalidArgumentException('La vigencia de la resolucion termina antes de empezar.');
        }

        if (trim($claveTecnica) === '') {
            throw new InvalidArgumentException('La resolucion necesita la clave tecnica de la DIAN.');
        }
    }

    public function numero(): string
    {
        return $this->numero;
    }

    public function prefijo(): string
    {
        return $this->prefijo;
    }

    public function desde(): int
    {
        return $this->desde;
    }

    public function hasta(): int
    {
        return $this->hasta;
    }

    public function vigenciaDesde(): DateTimeImmutable
    {
        return $this->vigenciaDesde;
    }

    public function vigenciaHasta(): DateTimeImmutable
    {
        return $this->vigenciaHasta;
    }

    public function claveTecnica(): string
    {
        return $this->claveTecnica;
    }

    /**
     * Indica si un numero consecutivo cae dentro del rango autorizado.
     */
    public function cubre(int $consecutivo): bool
    {
        return $consecutivo >= $this->desde && $consecutivo <= $this->hasta;
    }
}

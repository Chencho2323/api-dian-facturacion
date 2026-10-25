<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Calculo;

use InvalidArgumentException;

/**
 * Retencion que practica el adquirente sobre un documento.
 *
 * Guarda la tarifa en partes por millon para que el porcentaje sea exacto y no
 * arrastre los errores de un flotante. El valor se calcula cuando la retencion
 * conoce su documento, porque la base depende de los totales.
 */
final class Retencion
{
    private readonly int $tarifa;

    /**
     * @param TipoRetencion $tipo       Impuesto que se retiene.
     * @param float         $porcentaje Tarifa entre 0 y 100.
     */
    public function __construct(
        private readonly TipoRetencion $tipo,
        float $porcentaje,
    ) {
        $this->tarifa = Redondeo::desdePorcentaje($porcentaje);

        if ($this->tarifa <= 0) {
            throw new InvalidArgumentException(sprintf(
                'La tarifa de la %s debe ser mayor que cero.',
                $tipo->etiqueta()
            ));
        }
    }

    public function tipo(): TipoRetencion
    {
        return $this->tipo;
    }

    /**
     * Tarifa en partes por millon.
     */
    public function tarifa(): int
    {
        return $this->tarifa;
    }

    /**
     * Tarifa como texto, con los decimales justos: 2.5, 0.966, 15.
     */
    public function tarifaTexto(): string
    {
        return Redondeo::porcentaje($this->tarifa);
    }

    /**
     * Base sobre la que se practica la retencion, en centavos.
     */
    public function baseCentavos(int $subtotalCentavos, int $ivaCentavos): int
    {
        return $this->tipo->seCalculaSobreElIva() ? $ivaCentavos : $subtotalCentavos;
    }

    /**
     * Valor retenido, redondeado al centavo.
     */
    public function valorCentavos(int $subtotalCentavos, int $ivaCentavos): int
    {
        return Redondeo::aplicarTarifa(
            $this->baseCentavos($subtotalCentavos, $ivaCentavos),
            $this->tarifa
        );
    }
}

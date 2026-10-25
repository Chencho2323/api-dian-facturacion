<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Calculo;

use InvalidArgumentException;

/**
 * Redondeos del protocolo de facturacion.
 *
 * La DIAN exige que cada valor monetario quede en centavos con la mitad hacia
 * arriba: medio centavo sube, no baja ni se redondea al par. Las tarifas se
 * guardan en partes por millon, asi el 19 %, el 2,5 % y el 0,966 % del ICA
 * caben exactos. Todo el calculo es entero; el flotante solo aparece al leer
 * un porcentaje escrito por el usuario.
 */
final class Redondeo
{
    /** Partes por millon de una unidad: 1 000 000 es el 100 %. */
    public const PARTES_POR_MILLON = 1000000;

    /** Partes por millon que equivalen a un punto porcentual. */
    public const PARTES_POR_PUNTO = 10000;

    private function __construct()
    {
    }

    /**
     * Divide y redondea la mitad hacia arriba, alejandose del cero.
     *
     * @param int $numerador   Dividendo entero.
     * @param int $denominador Divisor entero positivo.
     *
     * @throws InvalidArgumentException Si el divisor no es positivo.
     */
    public static function mitadArriba(int $numerador, int $denominador): int
    {
        if ($denominador <= 0) {
            throw new InvalidArgumentException(
                'El divisor del redondeo debe ser un entero positivo.'
            );
        }

        if ($numerador < 0) {
            return -self::mitadArriba(-$numerador, $denominador);
        }

        // 2n + d contra 2d compara el resto con la mitad exacta del divisor,
        // tambien cuando el divisor es impar.
        return intdiv(2 * $numerador + $denominador, 2 * $denominador);
    }

    /**
     * Aplica una tarifa en partes por millon a una base en centavos.
     *
     * @param int $baseCentavos Base gravable o base de retencion.
     * @param int $tarifa       Tarifa en partes por millon: 25000 es el 2,5 %.
     */
    public static function aplicarTarifa(int $baseCentavos, int $tarifa): int
    {
        return self::mitadArriba($baseCentavos * $tarifa, self::PARTES_POR_MILLON);
    }

    /**
     * Convierte un porcentaje escrito con decimales a partes por millon.
     *
     * @param float $porcentaje Porcentaje entre 0 y 100: 19, 2.5 o 0.966.
     *
     * @throws InvalidArgumentException Si el porcentaje esta fuera de rango.
     */
    public static function desdePorcentaje(float $porcentaje): int
    {
        if (!is_finite($porcentaje) || $porcentaje < 0 || $porcentaje > 100) {
            throw new InvalidArgumentException(
                'La tarifa debe ser un porcentaje entre 0 y 100.'
            );
        }

        return (int) round($porcentaje * self::PARTES_POR_PUNTO);
    }

    /**
     * Porcentaje legible y estable, sin ceros sobrantes: 19, 2.5, 0.966.
     */
    public static function porcentaje(int $tarifa): string
    {
        $entero = intdiv($tarifa, self::PARTES_POR_PUNTO);
        $decimales = $tarifa % self::PARTES_POR_PUNTO;

        if ($decimales === 0) {
            return (string) $entero;
        }

        return rtrim(
            rtrim(sprintf('%d.%04d', $entero, $decimales), '0'),
            '.'
        );
    }
}

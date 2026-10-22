<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Documento;

use InvalidArgumentException;

/**
 * Linea de un documento UBL.
 *
 * Los valores monetarios se manejan en centavos (enteros) para que las sumas
 * no arrastren el error de las representaciones binarias de los flotantes. El
 * documento los formatea con dos decimales al armar el XML.
 */
final class Linea
{
    /**
     * Cantidad de la linea en milesimas de unidad.
     */
    private readonly int $cantidadMil;

    /**
     * Codigos de unidad de medida de la DIAN que el servicio usa por defecto.
     *
     * @var array<string, string>
     */
    public const UNIDADES = [
        '94' => 'Unidad',
        'KGM' => 'Kilogramo',
        'LTR' => 'Litro',
        'MTR' => 'Metro',
        'HUR' => 'Hora',
        'MON' => 'Mes',
    ];

    /**
     * Porcentajes de IVA vigentes en Colombia.
     *
     * @var list<int>
     */
    public const TARIFAS_IVA = [0, 5, 19];

    /**
     * @param string $descripcion            Descripcion del bien o servicio.
     * @param float  $cantidad               Cantidad vendida; admite hasta tres decimales (horas, kilos).
     * @param int    $precioUnitarioCentavos Precio unitario sin impuestos.
     * @param int    $tarifaIva              Porcentaje de IVA: 0, 5 o 19.
     * @param string $unidadMedida           Codigo de unidad de medida.
     * @param string $codigo                 Codigo interno del producto.
     */
    public function __construct(
        private readonly string $descripcion,
        float $cantidad,
        private readonly int $precioUnitarioCentavos,
        private readonly int $tarifaIva = 19,
        private readonly string $unidadMedida = '94',
        private readonly string $codigo = '',
    ) {
        if (trim($descripcion) === '') {
            throw new InvalidArgumentException('La linea necesita una descripcion.');
        }

        // Las cantidades se guardan en milesimas de unidad: asi 1,5 horas y
        // 0,25 kilos caben sin flotantes en los calculos de dinero.
        $cantidadMil = (int) round($cantidad * 1000);

        if ($cantidadMil < 1) {
            throw new InvalidArgumentException('La cantidad de la linea debe ser mayor que cero.');
        }

        $this->cantidadMil = $cantidadMil;

        if ($precioUnitarioCentavos < 0) {
            throw new InvalidArgumentException('El precio unitario de la linea no puede ser negativo.');
        }

        if (!in_array($tarifaIva, self::TARIFAS_IVA, true)) {
            throw new InvalidArgumentException(sprintf(
                'La tarifa de IVA %d no es valida. Tarifas admitidas: %s.',
                $tarifaIva,
                implode(', ', self::TARIFAS_IVA)
            ));
        }

        if (!array_key_exists($unidadMedida, self::UNIDADES)) {
            throw new InvalidArgumentException(sprintf(
                'La unidad de medida "%s" no existe en el catalogo. Valores validos: %s.',
                $unidadMedida,
                implode(', ', array_keys(self::UNIDADES))
            ));
        }
    }

    public function descripcion(): string
    {
        return $this->descripcion;
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    /**
     * Cantidad de la linea, en unidades.
     */
    public function cantidad(): float
    {
        return $this->cantidadMil / 1000;
    }

    /**
     * Cantidad de la linea en milesimas de unidad, para los calculos.
     */
    public function cantidadMil(): int
    {
        return $this->cantidadMil;
    }

    /**
     * Cantidad con el formato que pide UBL: hasta tres decimales, sin ceros
     * sobrantes.
     */
    public function cantidadUbl(): string
    {
        if ($this->cantidadMil % 1000 === 0) {
            return (string) intdiv($this->cantidadMil, 1000);
        }

        return rtrim(rtrim(sprintf('%.3F', $this->cantidad()), '0'), '.');
    }

    public function unidadMedida(): string
    {
        return $this->unidadMedida;
    }

    public function precioUnitarioCentavos(): int
    {
        return $this->precioUnitarioCentavos;
    }

    public function tarifaIva(): int
    {
        return $this->tarifaIva;
    }

    /**
     * Valor de la linea sin impuestos, redondeado al centavo.
     */
    public function subtotalCentavos(): int
    {
        return intdiv($this->cantidadMil * $this->precioUnitarioCentavos + 500, 1000);
    }

    /**
     * IVA de la linea, redondeado al centavo.
     */
    public function ivaCentavos(): int
    {
        return intdiv($this->subtotalCentavos() * $this->tarifaIva + 50, 100);
    }

    /**
     * Valor de la linea con impuestos.
     */
    public function totalCentavos(): int
    {
        return $this->subtotalCentavos() + $this->ivaCentavos();
    }

    /**
     * Formatea una cantidad de centavos como valor monetario de UBL.
     */
    public static function formatear(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return sprintf('%s%d.%02d', $signo, intdiv($absoluto, 100), $absoluto % 100);
    }
}

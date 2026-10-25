<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Calculo;

use InheritDevs\Dian\Documento\Linea;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;

/**
 * Calculo de los totales de un documento.
 *
 * Es el unico lugar donde se suman los dineros del documento. Redondea en el
 * mismo punto que el anexo tecnico: primero cada linea a su centavo, despues
 * la suma. Redondear el total de golpe daria centavos distintos a los que
 * declara la linea y la DIAN rechaza esa incoherencia.
 *
 * Sobre esa base se calculan las retenciones que practica el adquirente y el
 * valor a pagar del documento.
 */
final class Totales
{
    /**
     * @param list<Linea>      $lineas      Lineas del documento.
     * @param list<Retencion> $retenciones Retenciones que practica el adquirente.
     *
     * @throws DocumentoInvalido Si las retenciones no caben en el documento.
     */
    public function __construct(
        private readonly array $lineas,
        private readonly array $retenciones = [],
    ) {
        foreach ($retenciones as $retencion) {
            if (!$retencion instanceof Retencion) {
                throw new DocumentoInvalido(
                    'Las retenciones del documento deben ser instancias de Retencion.'
                );
            }
        }

        $this->validar();
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
     * Suma del IVA de todas las lineas, ya redondeado linea por linea.
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
     * Valor del documento con impuestos y sin descontar retenciones.
     */
    public function totalCentavos(): int
    {
        return $this->subtotalCentavos() + $this->ivaCentavos();
    }

    /**
     * Suma de las retenciones practicadas.
     */
    public function retencionesCentavos(): int
    {
        $total = 0;

        foreach ($this->retenciones as $retencion) {
            $total += $this->valorDe($retencion);
        }

        return $total;
    }

    /**
     * Valor a pagar: total con impuestos menos retenciones.
     */
    public function aPagarCentavos(): int
    {
        return $this->totalCentavos() - $this->retencionesCentavos();
    }

    /**
     * @return list<Retencion>
     */
    public function retenciones(): array
    {
        return $this->retenciones;
    }

    /**
     * Base de una retencion segun los totales del documento.
     */
    public function baseDe(Retencion $retencion): int
    {
        return $retencion->baseCentavos($this->subtotalCentavos(), $this->ivaCentavos());
    }

    /**
     * Valor de una retencion segun los totales del documento.
     */
    public function valorDe(Retencion $retencion): int
    {
        return $retencion->valorCentavos($this->subtotalCentavos(), $this->ivaCentavos());
    }

    /**
     * IVA agrupado por tarifa, en orden ascendente.
     *
     * La base y el impuesto de cada tarifa salen de sumar las lineas que la
     * usan, no de recalcular el impuesto sobre la base agrupada: por la regla
     * anterior, la suma de los centavos de cada linea es la que vale.
     *
     * @return array<int, array{base: int, iva: int}> Tarifa => base e IVA en centavos.
     */
    public function desgloseIva(): array
    {
        $desglose = [];

        foreach ($this->lineas as $linea) {
            $tarifa = $linea->tarifaIva();

            if ($tarifa === 0) {
                continue;
            }

            $acumulado = $desglose[$tarifa] ?? ['base' => 0, 'iva' => 0];
            $desglose[$tarifa] = [
                'base' => $acumulado['base'] + $linea->subtotalCentavos(),
                'iva' => $acumulado['iva'] + $linea->ivaCentavos(),
            ];
        }

        ksort($desglose);

        return $desglose;
    }

    /**
     * Bases gravables por tarifa, sin el IVA.
     *
     * @return array<int, int>
     */
    public function basesPorTarifa(): array
    {
        return array_map(
            static fn (array $fila): int => $fila['base'],
            $this->desgloseIva()
        );
    }

    /**
     * IVA por tarifa.
     *
     * @return array<int, int>
     */
    public function ivaPorTarifa(): array
    {
        return array_map(
            static fn (array $fila): int => $fila['iva'],
            $this->desgloseIva()
        );
    }

    /**
     * Resumen del documento, listo para un reporte o una respuesta JSON.
     *
     * @return array{
     *     subtotal: int,
     *     iva: int,
     *     total: int,
     *     retenciones: int,
     *     aPagar: int,
     *     ivaPorTarifa: array<int, array{base: int, iva: int}>
     * }
     */
    public function resumen(): array
    {
        return [
            'subtotal' => $this->subtotalCentavos(),
            'iva' => $this->ivaCentavos(),
            'total' => $this->totalCentavos(),
            'retenciones' => $this->retencionesCentavos(),
            'aPagar' => $this->aPagarCentavos(),
            'ivaPorTarifa' => $this->desgloseIva(),
        ];
    }

    /**
     * @throws DocumentoInvalido
     */
    private function validar(): void
    {
        if ($this->lineas === []) {
            throw new DocumentoInvalido(
                'El documento necesita al menos una linea para calcular sus totales.'
            );
        }

        if ($this->retenciones === []) {
            return;
        }

        if ($this->ivaCentavos() === 0) {
            foreach ($this->retenciones as $retencion) {
                if ($retencion->tipo()->seCalculaSobreElIva()) {
                    throw new DocumentoInvalido(
                        'El documento no cobra IVA: no se puede practicar retencion de IVA.'
                    );
                }
            }
        }

        if ($this->retencionesCentavos() > $this->totalCentavos()) {
            throw new DocumentoInvalido(sprintf(
                'Las retenciones (%s) superan el total del documento (%s).',
                Linea::formatear($this->retencionesCentavos()),
                Linea::formatear($this->totalCentavos())
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Validacion;

/**
 * Un error de validacion ya traducido para quien lee la respuesta.
 *
 * Guarda la linea, la columna y el elemento del XML donde se rompio la regla,
 * el codigo interno del esquema y una explicacion en espanol de que se esperaba.
 */
final class ErrorValidacion
{
    public function __construct(
        private readonly int $linea,
        private readonly int $columna,
        private readonly string $mensaje,
        private readonly string $codigo,
    ) {
    }

    /**
     * Linea del XML donde esta el problema. Cero si el error no se pudo ubicar.
     */
    public function linea(): int
    {
        return $this->linea;
    }

    public function columna(): int
    {
        return $this->columna;
    }

    /**
     * Explicacion del error en espanol, sin jerga de libxml.
     */
    public function mensaje(): string
    {
        return $this->mensaje;
    }

    /**
     * Codigo interno del error del esquema (por ejemplo, 1872).
     */
    public function codigo(): string
    {
        return $this->codigo;
    }

    /**
     * Linea de texto con la ubicacion y el mensaje.
     */
    public function aTexto(): string
    {
        if ($this->linea > 0) {
            return sprintf('linea %d, columna %d: %s', $this->linea, $this->columna, $this->mensaje);
        }

        return $this->mensaje;
    }

    /**
     * @return array{linea: int, columna: int, codigo: string, mensaje: string}
     */
    public function aArreglo(): array
    {
        return [
            'linea' => $this->linea,
            'columna' => $this->columna,
            'codigo' => $this->codigo,
            'mensaje' => $this->mensaje,
        ];
    }
}

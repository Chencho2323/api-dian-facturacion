<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Excepcion;

use RuntimeException;

/**
 * Se lanza cuando la maquina donde corre el servicio no cumple con los
 * requisitos minimos para ejecutarlo.
 */
final class RequisitoNoCumplido extends RuntimeException
{
    /**
     * @param list<string> $requisitos Descripcion de lo que falta, en espanol.
     */
    public function __construct(private readonly array $requisitos)
    {
        parent::__construct(
            'El entorno no cumple los requisitos del servicio: '
            . implode(' ', $requisitos)
        );
    }

    /**
     * Requisitos que el entorno no cumple.
     *
     * @return list<string>
     */
    public function requisitos(): array
    {
        return $this->requisitos;
    }
}

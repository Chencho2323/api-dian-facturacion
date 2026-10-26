<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Excepcion;

use RuntimeException;

/**
 * Se lanza cuando se pide un documento que el servicio no tiene registrado.
 */
final class DocumentoNoEncontrado extends RuntimeException
{
    public function __construct(private readonly string $numero)
    {
        parent::__construct(sprintf('No hay ningun documento con el numero "%s".', $numero));
    }

    public function numero(): string
    {
        return $this->numero;
    }
}

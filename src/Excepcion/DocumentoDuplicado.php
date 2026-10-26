<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Excepcion;

use RuntimeException;

/**
 * Se lanza cuando se intenta emitir un documento con un numero que ya existe.
 * La numeracion de la DIAN es unica por prefijo: repetirla rompe la cadena.
 */
final class DocumentoDuplicado extends RuntimeException
{
    public function __construct(private readonly string $numero)
    {
        parent::__construct(sprintf(
            'Ya existe un documento con el numero "%s". La numeracion no se puede repetir.',
            $numero
        ));
    }

    public function numero(): string
    {
        return $this->numero;
    }
}

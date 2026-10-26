<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Excepcion;

use InvalidArgumentException;

/**
 * La peticion HTTP viene mal armada: falta un dato, sobra un campo o el JSON
 * no se puede leer. El mensaje esta escrito para quien consume el servicio.
 */
final class PeticionInvalida extends InvalidArgumentException
{
    /**
     * @param string                $mensaje Texto para la respuesta.
     * @param array<string, string> $campos  Errores por campo, cuando se conocen.
     */
    public function __construct(
        string $mensaje,
        private readonly array $campos = [],
    ) {
        parent::__construct($mensaje);
    }

    /**
     * Errores por campo: nombre del campo => explicacion.
     *
     * @return array<string, string>
     */
    public function campos(): array
    {
        return $this->campos;
    }
}

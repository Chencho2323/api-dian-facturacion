<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Excepcion;

use InvalidArgumentException;

/**
 * Se lanza cuando se busca un sujeto por NIT y no esta en el registro.
 *
 * El mensaje lleva el NIT normalizado y la lista de sujetos cargados, porque
 * quien atiende la peticion necesita saber a quien le falta el dato.
 */
final class SujetoNoEncontrado extends InvalidArgumentException
{
    /**
     * @param string       $nit     NIT buscado, ya normalizado.
     * @param list<string> $cargados Claves de los sujetos disponibles.
     */
    public function __construct(private readonly string $nit, private readonly array $cargados = [])
    {
        parent::__construct(sprintf(
            'No hay un sujeto registrado con el NIT %s.%s',
            $nit,
            $cargados === []
                ? ' El registro esta vacio.'
                : ' Sujetos cargados: ' . implode(', ', $cargados) . '.'
        ));
    }

    public function nit(): string
    {
        return $this->nit;
    }

    /**
     * Claves de los sujetos que si estan cargados.
     *
     * @return list<string>
     */
    public function cargados(): array
    {
        return $this->cargados;
    }
}

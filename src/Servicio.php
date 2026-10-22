<?php

declare(strict_types=1);

namespace InheritDevs\Dian;

use InheritDevs\Dian\Excepcion\RequisitoNoCumplido;

/**
 * Arranque del servicio.
 *
 * Punto de entrada unico: valida el entorno y deja el servicio listo para
 * atender peticiones. Las tareas siguientes (documento UBL, validacion XSD,
 * emision) se registraran aqui como modulos adicionales.
 */
final class Servicio
{
    /**
     * Modulos que el servicio ya tiene construidos, en orden de aparicion.
     * Cada modulo nuevo se agrega aqui en orden de aparicion.
     *
     * @var list<string>
     */
    private const MODULOS = [
        'proyecto',
        'documento-ubl',
    ];

    /**
     * Verifica el entorno y devuelve el estado del servicio.
     *
     * @throws RequisitoNoCumplido Si el entorno no cumple los requisitos.
     *
     * @return array{
     *     nombre: string,
     *     version: string,
     *     espacio: string,
     *     modulos: list<string>,
     *     entorno: array{listo: bool, faltantes: list<string>}
     * }
     */
    public static function iniciar(): array
    {
        $faltantes = Proyecto::requisitosFaltantes();

        if ($faltantes !== []) {
            throw new RequisitoNoCumplido($faltantes);
        }

        return self::estado();
    }

    /**
     * Describe el estado actual del servicio sin arrancar nada.
     *
     * @return array{
     *     nombre: string,
     *     version: string,
     *     espacio: string,
     *     modulos: list<string>,
     *     entorno: array{listo: bool, faltantes: list<string>}
     * }
     */
    public static function estado(): array
    {
        return [
            'nombre' => Proyecto::nombre(),
            'version' => Proyecto::version(),
            'espacio' => Proyecto::espacio(),
            'modulos' => self::MODULOS,
            'entorno' => [
                'listo' => Proyecto::entornoListo(),
                'faltantes' => Proyecto::requisitosFaltantes(),
            ],
        ];
    }
}

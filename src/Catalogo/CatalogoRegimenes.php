<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Catalogo;

use InvalidArgumentException;

/**
 * Catalogo unico de regimenes tributarios de la DIAN.
 *
 * Es la fuente de verdad del servicio: `Tercero` valida contra este catalogo y
 * `DocumentoUbl` pregunta aqui si el emisor puede cobrar IVA. Los codigos son
 * los del anexo tecnico y no se inventan nuevos: si la DIAN agrega uno, se
 * agrega aqui y en ningun otro lado.
 */
final class CatalogoRegimenes
{
    /**
     * Regimenes vigentes, indexados por codigo.
     */
    public const REGIMENES = [
        '48' => [
            'nombre' => 'Responsable de IVA',
            'sigla' => 'RIV',
            'responsable' => true,
            'cobraIva' => true,
            'descripcion' => 'Persona natural o juridica que cobra IVA en sus operaciones.',
        ],
        '49' => [
            'nombre' => 'No responsable de IVA',
            'sigla' => 'NRIV',
            'responsable' => false,
            'cobraIva' => false,
            'descripcion' => 'Regimen sin cobro de IVA; el documento no puede discriminar impuesto.',
        ],
        '04' => [
            'nombre' => 'Regimen simple de tributacion',
            'sigla' => 'RST',
            'responsable' => false,
            'cobraIva' => true,
            'descripcion' => 'Regimen simple: declara IVA en la factura cuando la actividad lo exige.',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Codigos validos del catalogo, en el orden de la DIAN.
     *
     * @return list<string>
     */
    public static function codigos(): array
    {
        return array_map('strval', array_keys(self::REGIMENES));
    }

    public static function existe(string $codigo): bool
    {
        return array_key_exists($codigo, self::REGIMENES);
    }

    /**
     * @throws InvalidArgumentException Si el codigo no existe en el catalogo.
     */
    public static function obtener(string $codigo): Regimen
    {
        if (!self::existe($codigo)) {
            throw new InvalidArgumentException(sprintf(
                'El regimen tributario "%s" no existe en el catalogo de la DIAN. Valores validos: %s.',
                $codigo,
                implode(', ', self::codigos())
            ));
        }

        $datos = self::REGIMENES[$codigo];

        return new Regimen(
            $codigo,
            $datos['nombre'],
            $datos['sigla'],
            $datos['responsable'],
            $datos['cobraIva'],
            $datos['descripcion'],
        );
    }

    /**
     * Nombre del regimen, o una cadena vacia si el codigo no existe.
     */
    public static function nombre(string $codigo): string
    {
        return self::REGIMENES[$codigo]['nombre'] ?? '';
    }

    /**
     * Indica si el regimen cobra IVA aparte en la factura.
     */
    public static function cobraIva(string $codigo): bool
    {
        return self::REGIMENES[$codigo]['cobraIva'] ?? false;
    }

    /**
     * Catalogo completo, ya resuelto a objetos.
     *
     * @return array<string, Regimen>
     */
    public static function todos(): array
    {
        $regimenes = [];

        foreach (self::codigos() as $codigo) {
            $regimenes[$codigo] = self::obtener($codigo);
        }

        return $regimenes;
    }
}

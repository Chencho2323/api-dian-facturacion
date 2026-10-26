<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Http;

use InheritDevs\Dian\Emision\RepositorioDocumentos;
use InheritDevs\Dian\Emision\ServicioDocumentos;

/**
 * Armado de la aplicacion HTTP.
 *
 * Junta el registro de documentos con los casos de uso y el enrutador. La ruta
 * de la base se puede cambiar para las pruebas; en produccion sale de la
 * variable de entorno DIAN_BASEDATOS y, si no esta, de `var/documentos.sqlite`.
 */
final class Aplicacion
{
    /**
     * Enrutador listo para atender peticiones.
     */
    public static function enrutador(?RepositorioDocumentos $repositorio = null): Enrutador
    {
        return new Enrutador(new ServicioDocumentos($repositorio ?? self::repositorio()));
    }

    /**
     * Registro de documentos en el archivo configurado.
     */
    public static function repositorio(): RepositorioDocumentos
    {
        return RepositorioDocumentos::enArchivo(self::rutaBase());
    }

    /**
     * Ruta del archivo de la base de datos.
     */
    public static function rutaBase(): string
    {
        $configurada = getenv('DIAN_BASEDATOS');

        if (is_string($configurada) && trim($configurada) !== '') {
            return $configurada;
        }

        return dirname(__DIR__, 2) . '/var/documentos.sqlite';
    }
}

<?php

declare(strict_types=1);

namespace InheritDevs\Dian;

/**
 * Metadatos del proyecto y chequeo del entorno de ejecucion.
 *
 * La clase vive en el esqueleto del proyecto porque es la unica pieza que
 * todos los demas modulos necesitan: expone el nombre del paquete, la version
 * y el espacio de nombres raiz, y verifica que la maquina donde corre el
 * servicio tenga lo minimo indispensable.
 */
final class Proyecto
{
    /**
     * Nombre del paquete en Composer.
     */
    public const NOMBRE = 'api-dian-facturacion';

    /**
     * Espacio de nombres raiz de los componentes del servicio.
     */
    public const ESPACIO = 'InheritDevs\\Dian';

    /**
     * Version del paquete.
     */
    public const VERSION = '0.1.0';

    /**
     * Version minima de PHP que el servicio soporta.
     */
    public const PHP_MINIMO = '8.3.0';

    /**
     * Extensiones de PHP que el servicio necesita.
     *
     * @var list<string>
     */
    public const EXTENSIONES = [
        'dom',
        'json',
        'libxml',
        'mbstring',
        'openssl',
        'pdo_sqlite',
    ];

    /**
     * La clase es una fuente de metadatos: no hay instancias.
     */
    private function __construct()
    {
    }

    /**
     * Nombre del paquete.
     */
    public static function nombre(): string
    {
        return self::NOMBRE;
    }

    /**
     * Version del paquete.
     */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * Espacio de nombres raiz de los componentes del servicio.
     */
    public static function espacio(): string
    {
        return self::ESPACIO;
    }

    /**
     * Requisitos del entorno que no se cumplen en esta maquina.
     *
     * La lista esta vacia cuando el entorno sirve para ejecutar el servicio.
     * Cada entrada describe el problema en espanol, en una sola linea.
     *
     * @return list<string>
     */
    public static function requisitosFaltantes(): array
    {
        $faltantes = [];

        if (version_compare(PHP_VERSION, self::PHP_MINIMO, '<')) {
            $faltantes[] = sprintf(
                'Se requiere PHP %s o superior y esta maquina tiene PHP %s.',
                self::PHP_MINIMO,
                PHP_VERSION
            );
        }

        foreach (self::EXTENSIONES as $extension) {
            if (!extension_loaded($extension)) {
                $faltantes[] = sprintf('Falta la extension de PHP "%s".', $extension);
            }
        }

        return $faltantes;
    }

    /**
     * Indica si el entorno cumple con todos los requisitos.
     */
    public static function entornoListo(): bool
    {
        return self::requisitosFaltantes() === [];
    }
}

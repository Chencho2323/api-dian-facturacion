<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pruebas del punto de entrada HTTP.
 *
 * El arranque se hace en un subproceso porque `public/index.php` emite
 * cabeceras: en el mismo proceso de PHPUnit eso contaminaria la salida de
 * las pruebas.
 */
final class PuntoDeEntradaTest extends TestCase
{
    public function testElPuntoDeEntradaRespondeConElEstadoDelServicio(): void
    {
        $salida = $this->ejecutar('public/index.php');

        $this->assertSame(0, $salida['codigo'], 'el punto de entrada debe terminar sin error');

        $cuerpo = json_decode($salida['salida'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($cuerpo);
        $this->assertSame('api-dian-facturacion', $cuerpo['servicio']);
        $this->assertSame('0.1.0', $cuerpo['version']);
    }

    /**
     * Corre un archivo PHP del proyecto en un subproceso.
     *
     * @return array{codigo: int, salida: string}
     */
    private function ejecutar(string $relativo): array
    {
        $raiz = dirname(__DIR__);
        $ruta = $raiz . DIRECTORY_SEPARATOR . $relativo;

        $this->assertFileExists($ruta);

        $comando = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($ruta) . ' 2>&1';
        $lineas = [];
        $codigo = 0;
        exec($comando, $lineas, $codigo);

        return [
            'codigo' => $codigo,
            'salida' => implode("\n", $lineas),
        ];
    }
}

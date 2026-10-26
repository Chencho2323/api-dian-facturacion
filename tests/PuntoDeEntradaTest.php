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
        $this->assertSame('api-dian-facturacion', $cuerpo['nombre']);
        $this->assertSame('0.1.0', $cuerpo['version']);
        $this->assertContains('api-rest', $cuerpo['modulos']);
        $this->assertTrue($cuerpo['entorno']['listo']);
    }

    public function testElPuntoDeEntradaPublicaLasRutasDeDocumentos(): void
    {
        $base = sys_get_temp_dir() . '/dian-prueba-' . bin2hex(random_bytes(6)) . '.sqlite';

        try {
            $salida = $this->ejecutar('public/index.php', [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/documentos',
                'DIAN_BASEDATOS' => $base,
            ]);
        } finally {
            if (is_file($base)) {
                unlink($base);
            }
        }

        $cuerpo = json_decode($salida['salida'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $salida['codigo']);
        $this->assertArrayHasKey('documentos', $cuerpo);
        $this->assertArrayHasKey('total', $cuerpo);
        $this->assertSame(0, $cuerpo['total']);
    }

    /**
     * Corre un archivo PHP del proyecto en un subproceso.
     *
     * @param array<string, string> $entorno Variables que se pasan al proceso.
     *
     * @return array{codigo: int, salida: string}
     */
    private function ejecutar(string $relativo, array $entorno = []): array
    {
        $raiz = dirname(__DIR__);
        $ruta = $raiz . DIRECTORY_SEPARATOR . $relativo;

        $this->assertFileExists($ruta);

        $comando = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($ruta) . ' 2>&1';

        $previas = [];
        $asignadas = [];

        foreach ($entorno as $nombre => $valor) {
            $previas[$nombre] = getenv($nombre);
            $asignadas[$nombre] = $valor;
        }

        foreach ($asignadas as $nombre => $valor) {
            putenv(sprintf('%s=%s', $nombre, $valor));
            $_SERVER[$nombre] = $valor;
        }

        try {
            $lineas = [];
            $codigo = 0;
            exec($comando, $lineas, $codigo);
        } finally {
            foreach ($previas as $nombre => $valor) {
                putenv($valor === false ? $nombre : sprintf('%s=%s', $nombre, $valor));
            }
        }

        return [
            'codigo' => $codigo,
            'salida' => implode("\n", $lineas),
        ];
    }
}

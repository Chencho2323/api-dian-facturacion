<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use PHPUnit\Framework\TestCase;
use InheritDevs\Dian\Proyecto;
use InheritDevs\Dian\Servicio;

/**
 * Pruebas del esqueleto del proyecto.
 *
 * Comprueban que la estructura quedo completa y coherente con lo que las
 * tareas siguientes van a construir encima: autoload PSR-4, metadatos del
 * paquete y arranque del servicio.
 */
final class EstructuraTest extends TestCase
{
    public function testElPaqueteDeclaraLosDatosBasicos(): void
    {
        $paquete = $this->leerPaquete();

        // El nombre del paquete es "vendor/paquete": la parte del paquete es
        // la que usan los codigos y el README.
        $this->assertSame('api-dian-facturacion', $this->partePaquete($paquete['name']));
        $this->assertSame('MIT', $paquete['license']);
        $this->assertNotEmpty($paquete['description']);
        $this->assertArrayHasKey('php', $paquete['require']);
    }

    public function testElPaqueteDeclaraElAutoloadPsr4(): void
    {
        $paquete = $this->leerPaquete();

        $this->assertSame(
            ['InheritDevs\\Dian\\' => 'src/'],
            $paquete['autoload']['psr-4'],
            'el codigo de la aplicacion se carga desde src/ con PSR-4'
        );
        $this->assertSame(
            ['InheritDevs\\Dian\\Tests\\' => 'tests/'],
            $paquete['autoload-dev']['psr-4'],
            'las pruebas se cargan desde tests/ con PSR-4'
        );
    }

    public function testElPaqueteDeclaraElComandoDePruebas(): void
    {
        $paquete = $this->leerPaquete();

        $this->assertArrayHasKey('test', $paquete['scripts']);
        $this->assertStringContainsString('phpunit', $paquete['scripts']['test']);
    }

    public function testLaAplicacionNoUsaDependenciasDeTerceros(): void
    {
        $paquete = $this->leerPaquete();

        $this->assertArrayNotHasKey(
            'dependencies',
            $paquete,
            'el servicio no debe depender de librerias de terceros en produccion'
        );
    }

    public function testLaVersionDePhpExigidaEsLaDelServicio(): void
    {
        $paquete = $this->leerPaquete();

        $this->assertSame('>=8.3', $paquete['require']['php']);
    }

    public function testLosDirectoriosDelEsqueletoExisten(): void
    {
        $raiz = $this->raiz();

        foreach (['src', 'tests', 'public'] as $directorio) {
            $this->assertDirectoryExists($raiz . DIRECTORY_SEPARATOR . $directorio);
        }
    }

    public function testElAutoloadPsr4ResuelveLasClasesDelServicio(): void
    {
        // Si el PSR-4 no estuviera bien declarado, el autoload de Composer no
        // encontraria estas clases y la prueba fallaria aqui.
        $this->assertTrue(class_exists(Proyecto::class));
        $this->assertTrue(class_exists(Servicio::class));
    }

    public function testElProyectoExponeSusMetadatos(): void
    {
        $this->assertSame('api-dian-facturacion', Proyecto::nombre());
        $this->assertSame('0.1.0', Proyecto::version());
        $this->assertSame('InheritDevs\\Dian', Proyecto::espacio());
    }

    public function testElEntornoCumpleLosRequisitos(): void
    {
        $this->assertSame(
            [],
            Proyecto::requisitosFaltantes(),
            'el entorno de pruebas debe cumplir todos los requisitos del servicio'
        );
        $this->assertTrue(Proyecto::entornoListo());
    }

    public function testElServicioArrancaYDescribeSuEstado(): void
    {
        $estado = Servicio::iniciar();

        $this->assertSame('api-dian-facturacion', $estado['nombre']);
        $this->assertSame('0.1.0', $estado['version']);
        $this->assertTrue($estado['entorno']['listo']);
        $this->assertSame([], $estado['entorno']['faltantes']);
        $this->assertContains('proyecto', $estado['modulos']);
    }

    public function testElEstadoDevuelveUnaCopiaDeLosModulos(): void
    {
        $estado = Servicio::estado();
        $estado['modulos'][] = 'inventado';

        $this->assertNotContains('inventado', Servicio::estado()['modulos']);
    }

    /**
     * Lee el composer.json de la raiz del proyecto.
     *
     * @return array<string, mixed>
     */
    private function leerPaquete(): array
    {
        $contenido = file_get_contents($this->raiz() . '/composer.json');

        $this->assertIsString($contenido);

        $paquete = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($paquete);

        return $paquete;
    }

    /**
     * Extrae el nombre del paquete de un nombre completo de Composer.
     */
    private function partePaquete(string $nombre): string
    {
        $partes = explode('/', $nombre);

        return (string) end($partes);
    }

    /**
     * Raiz del proyecto, dos niveles arriba de esta prueba.
     */
    private function raiz(): string
    {
        return dirname(__DIR__);
    }
}

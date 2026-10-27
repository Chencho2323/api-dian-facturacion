<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use InheritDevs\Dian\Emision\RepositorioDocumentos;
use InheritDevs\Dian\Http\Aplicacion;
use InheritDevs\Dian\Http\Enrutador;
use InheritDevs\Dian\Http\Solicitud;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la documentacion.
 *
 * El README es la puerta de entrada al proyecto: si los ejemplos dejan de
 * compilar o la API cambia y el texto no, la documentacion miente. Estas
 * pruebas ejecutan los bloques de codigo del README en un subproceso, igual
 * que lo haria alguien que copia y pega, y emiten por el enrutador el mismo
 * documento del ejemplo con `curl`.
 */
final class ReadmeTest extends TestCase
{
    /**
     * Bloques de codigo PHP del README, en el orden en que aparecen.
     *
     * @return list<string>
     */
    private function bloques(string $lenguaje): array
    {
        $contenido = file_get_contents($this->raiz() . '/README.md');

        $this->assertIsString($contenido, 'el README debe existir');

        preg_match_all(
            '/^```' . preg_quote($lenguaje, '/') . '\n(.*?)^```$/ms',
            $contenido,
            $coincidencias
        );

        return $coincidencias[1];
    }

    public function testElReadmeTraeEjemplosEnPhpYEnConsola(): void
    {
        $this->assertGreaterThanOrEqual(4, count($this->bloques('php')), 'faltan ejemplos de PHP');
        $this->assertGreaterThanOrEqual(3, count($this->bloques('bash')), 'faltan ejemplos de consola');
    }

    public function testElReadmeExplicaInstalacionPruebasYEstructura(): void
    {
        $texto = (string) file_get_contents($this->raiz() . '/README.md');

        $this->assertStringContainsString('composer install', $texto);
        $this->assertStringContainsString('vendor/bin/phpunit', $texto);
        $this->assertStringContainsString('./verificar.sh', $texto);
        $this->assertStringContainsString('## Instalacion', $texto);
        $this->assertStringContainsString('## Pruebas', $texto);
        $this->assertStringContainsString('## Estructura', $texto);
    }

    public function testLosDirectoriosDeLaEstructuraExisten(): void
    {
        $texto = (string) file_get_contents($this->raiz() . '/README.md');

        preg_match_all('#^(src/[A-Za-z]+/|recursos/xsd/|public/|tests/)#m', $texto, $coincidencias);

        $this->assertNotEmpty($coincidencias[0], 'la seccion de estructura debe nombrar los directorios');

        foreach (array_unique($coincidencias[0]) as $directorio) {
            $this->assertDirectoryExists(
                $this->raiz() . '/' . rtrim($directorio, '/'),
                sprintf('el README nombra "%s" y ese directorio no existe', $directorio)
            );
        }
    }

    public function testLosEjemplosDePhpDelReadmeSeEjecutan(): void
    {
        $guion = "<?php\ndeclare(strict_types=1);\n\nrequire " . var_export($this->raiz() . '/vendor/autoload.php', true) . ";\n\n";

        foreach ($this->bloques('php') as $bloque) {
            $guion .= $bloque . "\n\n";
        }

        // Comprobaciones que el lector espera despues de copiar los bloques.
        $guion .= <<<'PHP'
echo "\n--RESULTADOS--\n";
echo 'factura: ', $documento->totalCentavos(), "\n";
echo 'sujeto: ', $registro->resolver('9001234568')->razonSocial(), "\n";
echo 'nota: ', $nota->documentoAjustado(), "\n";
echo 'retenciones: ', $documento->retencionesCentavos(), "\n";
PHP;

        $directorio = sys_get_temp_dir() . '/readme-' . bin2hex(random_bytes(6));

        $this->assertTrue(mkdir($directorio, 0775, true));

        $archivo = $directorio . '/ejemplos.php';

        try {
            file_put_contents($archivo, $guion);

            $anterior = getcwd();
            chdir($directorio);

            try {
                $lineas = [];
                $codigo = 0;
                exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($archivo) . ' 2>&1', $lineas, $codigo);
            } finally {
                chdir((string) $anterior);
            }

            $salida = implode("\n", $lineas);

            $this->assertSame(0, $codigo, "los ejemplos del README no corren:\n" . $salida);
            $this->assertStringContainsString('900.123.456-8', $salida);
            $this->assertStringContainsString('Regimen simple de tributacion', $salida);
            $this->assertStringContainsString('--RESULTADOS--', $salida);
            $this->assertFileExists($directorio . '/factura.xml', 'el ejemplo debe dejar el XML en disco');
        } finally {
            foreach (glob($directorio . '/*') ?: [] as $sobrante) {
                @unlink($sobrante);
            }

            @rmdir($directorio);
        }
    }

    public function testElCurlDeEjemploEmiteUnDocumentoValido(): void
    {
        $datos = $this->cuerpoDelCurlDeEmision();
        $api = Aplicacion::enrutador(RepositorioDocumentos::enMemoria());

        $respuesta = $api->atender(new Solicitud('POST', '/documentos', [], json_encode($datos, JSON_THROW_ON_ERROR)));

        $this->assertSame(201, $respuesta->estado(), $respuesta->json());

        $cuerpo = $respuesta->cuerpo();

        $this->assertSame('FV-0001', $cuerpo['documento']['numero']);
        $this->assertTrue($cuerpo['validacion']['valido']);
        $this->assertSame('29750000.00', $cuerpo['documento']['totales']['total']);
        $this->assertSame('/documentos/FV-0001', $respuesta->cabeceras()['Location']);

        // Las otras dos llamadas del README, sobre el documento que dejo el POST.
        $this->assertSame(200, $api->atender(new Solicitud('GET', '/documentos/FV-0001'))->estado());
        $this->assertSame(
            200,
            $api->atender(new Solicitud('GET', '/documentos', ['estado' => 'emitido', 'limite' => '10']))->estado()
        );
        $this->assertSame(
            200,
            $api->atender(new Solicitud(
                'POST',
                '/documentos/FV-0001/anular',
                [],
                '{"motivo": "Error de digitacion"}'
            ))->estado()
        );
    }

    public function testElReadmeNoPrometeLaFirmaNiElEnvioALaDian(): void
    {
        $texto = (string) file_get_contents($this->raiz() . '/README.md');

        // El servicio deja el documento listo para firmar; si algun dia
        // incorpora la firma, esta prueba se reemplaza por la que la cubra.
        $this->assertStringContainsString('no estan en este repositorio', $texto);
        $this->assertStringNotContainsString('los firma', $texto);
    }

    /**
     * Cuerpo JSON del ejemplo de `curl` que emite la factura.
     *
     * @return array<string, mixed>
     */
    private function cuerpoDelCurlDeEmision(): array
    {
        foreach ($this->bloques('bash') as $bloque) {
            if (!str_contains($bloque, '/documentos')
                || !str_contains($bloque, "'Content-Type: application/json'")
                || !str_contains($bloque, '-d ')) {
                continue;
            }

            if (preg_match("/-d '(\\{.*?\\})'/s", $bloque, $partes) !== 1) {
                continue;
            }

            $datos = json_decode($partes[1], true, 64, JSON_THROW_ON_ERROR);

            $this->assertIsArray($datos);

            return $datos;
        }

        $this->fail('el README debe traer un curl de emision con su cuerpo JSON');
    }

    private function raiz(): string
    {
        return dirname(__DIR__);
    }
}

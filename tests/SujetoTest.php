<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Tests;

use InheritDevs\Dian\Catalogo\CatalogoRegimenes;
use InheritDevs\Dian\Catalogo\Nit;
use InheritDevs\Dian\Documento\Tercero;
use InheritDevs\Dian\Excepcion\SujetoNoEncontrado;
use InheritDevs\Dian\Sujeto\RegistroSujetos;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la resolucion de sujeto por NIT y del catalogo de regimenes.
 *
 * Cubren el digito de verificacion, la normalizacion de las distintas formas
 * de escribir un NIT, el catalogo de la DIAN y el registro que resuelve emisor
 * y adquirente.
 */
final class SujetoTest extends TestCase
{
    public function testElDigitoDeVerificacionSigueElAlgoritmoDeLaDian(): void
    {
        // NIT de prueba con digito conocido.
        $this->assertSame(8, Nit::calcularDigito('900123456'));
        $this->assertSame(4, Nit::calcularDigito('800987654'));
        $this->assertSame(8, Nit::calcularDigito('890903938'));
        $this->assertSame(1, Nit::calcularDigito('860002530'));
    }

    public function testElNitSeNormalizaSinImportarComoLlegue(): void
    {
        $formas = [
            '900123456',
            '900.123.456',
            '900123456-8',
            '900.123.456-8',
            ' 900 123 456 - 8 ',
        ];

        foreach ($formas as $forma) {
            $nit = Nit::desde($forma);

            $this->assertSame('900123456', $nit->numero(), $forma);
            $this->assertSame(8, $nit->digitoVerificacion(), $forma);
            $this->assertSame('9001234568', $nit->completo(), $forma);
            $this->assertSame('900.123.456-8', $nit->formateado(), $forma);
            $this->assertSame('9001234568', (string) $nit, $forma);
        }
    }

    public function testUnDigitoDeVerificacionEquivocadoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('el digito de verificacion es 8 y llego 3');

        Nit::desde('900123456-3');
    }

    public function testUnNitConLetrasSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('solo digitos');

        Nit::desde('90012345A');
    }

    public function testUnNitDemasiadoCortoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('debe tener entre 9 y 10');

        Nit::desde('12345');
    }

    public function testElCatalogoTraeLosRegimenesDeLaDian(): void
    {
        $this->assertSame(['48', '49', '04'], CatalogoRegimenes::codigos());

        $responsable = CatalogoRegimenes::obtener('48');
        $this->assertSame('Responsable de IVA', $responsable->nombre());
        $this->assertTrue($responsable->responsableIva());
        $this->assertTrue($responsable->cobraIva());

        $noResponsable = CatalogoRegimenes::obtener('49');
        $this->assertSame('No responsable de IVA', $noResponsable->nombre());
        $this->assertFalse($noResponsable->responsableIva());
        $this->assertFalse($noResponsable->cobraIva());

        $simple = CatalogoRegimenes::obtener('04');
        $this->assertSame('Regimen simple de tributacion', $simple->nombre());
        $this->assertTrue($simple->cobraIva());
    }

    public function testElCatalogoCompletoSeResuelveAObjetos(): void
    {
        $todos = CatalogoRegimenes::todos();

        $this->assertCount(3, $todos);
        $this->assertArrayHasKey('49', $todos);
        $this->assertInstanceOf(\InheritDevs\Dian\Catalogo\Regimen::class, $todos['49']);
    }

    public function testUnRegimenFueraDelCatalogoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no existe en el catalogo de la DIAN');

        CatalogoRegimenes::obtener('99');
    }

    public function testElTerceroValidaElRegimenContraElCatalogo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Valores validos: 48, 49, 04');

        new Tercero('900123456', 'InheritDevs SAS', '50');
    }

    public function testElTerceroExigeUnNitValido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('el digito de verificacion');

        new Tercero('900123456-1', 'InheritDevs SAS', '48');
    }

    public function testElTerceroNoExigeNitSiElDocumentoNoEsTributario(): void
    {
        $tercero = new Tercero('1234567', 'Ana Perez', '49', '13');

        $this->assertFalse($tercero->esNit());
        $this->assertNull($tercero->nit());
        $this->assertSame('13:1234567', $tercero->clave());
        $this->assertSame('Cedula de ciudadania', $tercero->nombreTipoIdentificacion());
    }

    public function testElTerceroPublicaSuNitNormalizadoYSuRegimen(): void
    {
        $tercero = new Tercero('900.123.456-8', 'InheritDevs SAS', '48');

        // La identificacion se publica sin digito de verificacion, como la
        // pide el perfil; el NIT completo queda en `nit()`.
        $this->assertSame('900123456', $tercero->identificacion());
        $this->assertSame('900123456-8', $tercero->clave());
        $this->assertTrue($tercero->esNit());
        $this->assertSame('900123456', $tercero->nit()?->numero());
        $this->assertSame('9001234568', $tercero->nit()?->completo());
        $this->assertSame('Responsable de IVA', $tercero->nombreRegimen());
        $this->assertTrue($tercero->cobraIva());
    }

    public function testDosFormasDelMismoNitDanLaMismaClave(): void
    {
        $uno = new Tercero('900123456', 'InheritDevs SAS', '48');
        $otro = new Tercero('900.123.456-8', 'InheritDevs SAS', '48');

        $this->assertSame($uno->clave(), $otro->clave());
    }

    public function testElRegistroResuelveUnSujetoPorSuNit(): void
    {
        $registro = RegistroSujetos::vacio()
            ->agregar($this->emisor())
            ->agregar($this->adquirente());

        $this->assertSame(2, $registro->contar());

        $encontrado = $registro->resolver('900.123.456');

        $this->assertSame('InheritDevs SAS', $encontrado->razonSocial());
        $this->assertSame('900123456-8', $encontrado->clave());
        $this->assertTrue($registro->contiene('900123456-8'));
    }

    public function testElRegistroDistingueSujetosPorElDigito(): void
    {
        $registro = RegistroSujetos::vacio()->agregar($this->emisor());

        // Mismo numero de base, digito equivocado: no existe.
        $this->assertNull($registro->buscar('900123456-2'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('el digito de verificacion');
        $registro->resolver('900123456-2');
    }

    public function testElRegistroInformaCuandoNoEncuentraElNit(): void
    {
        $registro = RegistroSujetos::vacio()->agregar($this->emisor());

        $this->expectException(SujetoNoEncontrado::class);
        $this->expectExceptionMessage('No hay un sujeto registrado con el NIT 800987654-4');

        $registro->resolver('800987654');
    }

    public function testElRegistroVacioLoDiceEnElMensaje(): void
    {
        $this->expectException(SujetoNoEncontrado::class);
        $this->expectExceptionMessage('El registro esta vacio.');

        RegistroSujetos::vacio()->resolver('900123456');
    }

    public function testElRegistroNoAdmiteDosSujetosConElMismoNit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya hay un sujeto registrado');

        RegistroSujetos::vacio()
            ->agregar($this->emisor())
            ->agregar(new Tercero('900.123.456-8', 'Otra razon social', '48'));
    }

    public function testElRegistroRechazaSujetosSinNit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('solo se indexan sujetos con NIT');

        RegistroSujetos::vacio()->agregar(new Tercero('1234567', 'Ana Perez', '49', '13'));
    }

    public function testGuardarReemplazaLosDatosDelMismoNit(): void
    {
        $registro = RegistroSujetos::vacio()->agregar($this->emisor());

        $registro->guardar(new Tercero('900123456', 'InheritDevs SAS', '48', '31', 'Calle 50 # 10-20', 'Medellin', 'Antioquia'));

        $this->assertSame(1, $registro->contar());
        $this->assertSame('Calle 50 # 10-20', $registro->resolver('9001234568')->direccion());
    }

    public function testElRegistroFiltraPorRegimen(): void
    {
        $registro = RegistroSujetos::vacio()
            ->agregar($this->emisor())
            ->agregar($this->adquirente());

        $noResponsables = $registro->porRegimen('49');

        $this->assertCount(1, $noResponsables);
        $this->assertSame('Almacen El Progreso SAS', $noResponsables[0]->razonSocial());
    }

    public function testUnNitDeDiezDigitosSePruebaEnSusDosLecturas(): void
    {
        // 9001234568 puede ser una base de diez digitos o la base 900123456
        // con el digito 8 pegado. El registro acepta las dos.
        $claves = Nit::clavesPosibles('9001234568');

        $this->assertContains('9001234568-8', $claves);
        $this->assertContains('900123456-8', $claves);

        $registro = RegistroSujetos::vacio()->agregar($this->emisor());

        $this->assertSame('InheritDevs SAS', $registro->resolver('9001234568')->razonSocial());
    }

    public function testElRegimenSimpleNoEsElUnicoQueCobraIva(): void    {
        $this->assertTrue(CatalogoRegimenes::cobraIva('48'));
        $this->assertTrue(CatalogoRegimenes::cobraIva('04'));
        $this->assertFalse(CatalogoRegimenes::cobraIva('49'));
        $this->assertFalse(CatalogoRegimenes::cobraIva('99'));
    }

    private function emisor(): Tercero
    {
        return new Tercero(
            '900123456',
            'InheritDevs SAS',
            '48',
            '31',
            'Calle 10 # 20-30',
            'Medellin',
            'Antioquia',
            'facturacion@inheritdevs.com'
        );
    }

    private function adquirente(): Tercero
    {
        return new Tercero('800987654', 'Almacen El Progreso SAS', '49');
    }
}

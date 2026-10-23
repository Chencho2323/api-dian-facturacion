# API de facturacion electronica ante la DIAN

Servicio REST que arma documentos UBL 2.1, los valida contra el XSD de la DIAN, los firma y expone el estado del envio. Incluye resolucion de sujeto por NIT y calcula IVA e IVA retenido.

![CI](https://github.com/Chencho2323/api-dian-facturacion/actions/workflows/ci.yml/badge.svg)
![Licencia](https://img.shields.io/badge/licencia-MIT-03A8FA?style=flat-square)

**Stack:** PHP 8.3 · SQLite · PHPUnit

## Que resuelve

Este repositorio es parte del portafolio publico de
[InheritDevs](https://inheritdevs.com/): software empresarial que funciona en
produccion. El codigo esta aqui para que se pueda leer, ejecutar y auditar.

- Codigo completo, no un ejemplo recortado.
- Pruebas que corren en cada push (`npm test`, `phpunit` o `pytest`, segun el
  stack) y en cada pull request.
- Sin dependencias ocultas: lo que dice el README es lo que hace falta.

## Instalacion

```bash
git clone https://github.com/Chencho2323/api-dian-facturacion.git
cd api-dian-facturacion
composer install
```

Requiere PHP 8.3 o superior con las extensiones `dom`, `json`, `libxml`,
`mbstring`, `openssl` y `pdo_sqlite`.

## Uso

Arma una factura UBL 2.1 con dos lineas, una gravada al 19 % y otra al 5 %:

```php
use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Documento\Linea;
use InheritDevs\Dian\Documento\Resolucion;
use InheritDevs\Dian\Documento\Tercero;
use InheritDevs\Dian\Documento\TipoDocumento;

$documento = new DocumentoUbl(
    TipoDocumento::Factura,
    'FV-0001',
    new DateTimeImmutable('2026-10-22 09:30:00'),
    new Tercero('900123456', 'InheritDevs SAS', '48'),
    new Tercero('800987654', 'Almacen El Progreso SAS', '49'),
    [
        new Linea('Servicio de desarrollo', 2, 12500000, 19),
        new Linea('Soporte mensual', 1, 4000000, 5, 'MON'),
    ],
    new Resolucion(
        '18760000001',
        'FV',
        1,
        5000,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
        'clave-tecnica'
    )
);

file_put_contents('factura.xml', $documento->xml());
```

Las notas credito y debito usan la misma clase con `TipoDocumento::NotaCredito` o
`TipoDocumento::NotaDebito`, y exigen el numero del documento que ajustan y el motivo:

```php
$nota = new DocumentoUbl(
    TipoDocumento::NotaCredito,
    'FV-0100',
    new DateTimeImmutable('2026-10-22 10:15:00'),
    $emisor,
    $adquirente,
    [new Linea('Devolucion de servicio', 1, 5000000, 19)],
    $resolucion,
    DocumentoUbl::AMBIENTE_PRUEBAS,
    'COP',
    'FV-0001',
    'Devolucion parcial',
    '5'
);
```

Los dineros se manejan en centavos enteros y el documento los publica con dos decimales. El
emisor en regimen no responsable de IVA no puede emitir documentos que cobren IVA; el numero debe
llevar el prefijo de la resolucion y caer dentro del rango autorizado.

## Validacion contra el esquema

Antes de firmar, el documento se valida contra los XSD del perfil 2.1, que viven en
`recursos/xsd`. El resultado trae los errores ya traducidos, con la linea, el elemento y que
esperaba el esquema:

```php
use InheritDevs\Dian\Validacion\ValidadorXsd;

$resultado = (new ValidadorXsd())->validarDocumento($documento);

echo $resultado->reporte();
```

Un documento invalido devuelve un reporte asi:

```
El documento tiene 1 error(es) frente al esquema.
- linea 24, columna 0: El elemento UBLVersionID no admite el valor "2.0". Valores permitidos: 2.1.
```

El resultado se puede entregar tal cual en la respuesta HTTP: `valido`, `resumen` y la lista de
`errores` con su `linea`, `columna`, `codigo` y `mensaje`.

Ademas el servicio expone su estado y comprueba que la maquina cumple con los requisitos minimos
para facturar:

```bash
php -r "require 'vendor/autoload.php'; var_export(InheritDevs\Dian\Servicio::iniciar());"
```

La capa HTTP vive en `public/index.php`:

```bash
php -S localhost:8080 -t public
```

## Pruebas

```bash
composer test
```

O, con el binario de Composer ya instalado:

```bash
vendor/bin/phpunit
```

## Estructura

```
src/            codigo de la aplicacion (PSR-4, prefijo InheritDevs\Dian\)
src/Documento/  modelo del documento UBL 2.1: factura, nota credito y nota debito
src/Validacion/ validador contra los XSD del perfil y reporte de errores
recursos/xsd/   esquemas del perfil 2.1: cabecera, agregados, extensiones y cada documento
public/         punto de entrada HTTP
tests/          pruebas automaticas (PHPUnit)
```

## Licencia

MIT. Ver `LICENSE`.

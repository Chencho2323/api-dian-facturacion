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

Por ahora el servicio expone su estado y comprueba que la maquina cumple con
los requisitos minimos para facturar:

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
src/        codigo de la aplicacion (PSR-4, prefijo InheritDevs\Dian\)
public/     punto de entrada HTTP
tests/      pruebas automaticas (PHPUnit)
```

## Licencia

MIT. Ver `LICENSE`.

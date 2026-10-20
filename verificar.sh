#!/bin/sh
# Verificacion local del proyecto PHP.
# Instala las dependencias si hace falta y corre la suite de PHPUnit.
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

if [ ! -f composer.json ]; then
  echo "SIN_SUITE: todavia no hay composer.json (tarea de estructura pendiente)."
  exit 0
fi

if [ ! -d vendor ]; then
  echo "Instalando dependencias de Composer..."
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f phpunit.xml ] && [ ! -f phpunit.xml.dist ]; then
  echo "SIN_SUITE: todavia no hay configuracion de PHPUnit (tarea de estructura pendiente)."
  exit 0
fi

echo "Verificando sintaxis..."
find src public tests -name '*.php' -print0 2>/dev/null | xargs -0 -r -n1 php -l > /dev/null

echo "Corriendo pruebas..."
# --fail-on-empty-test-suite: una suite vacia es un fallo, no un exito.
vendor/bin/phpunit --colors=never --fail-on-empty-test-suite

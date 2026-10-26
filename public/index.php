<?php

declare(strict_types=1);

/**
 * Punto de entrada HTTP del servicio.
 *
 * Todas las rutas pasan por el enrutador: estado, emision, consulta,
 * anulacion y listado. El servidor solo publica la respuesta que el enrutador
 * devuelve.
 */

use InheritDevs\Dian\Http\Aplicacion;
use InheritDevs\Dian\Http\Solicitud;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['error' => ['codigo' => 'entorno', 'mensaje' => 'Las dependencias del servicio no estan instaladas.']],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

require $autoload;

$respuesta = Aplicacion::enrutador()->atender(Solicitud::desdeGlobales());
$respuesta->enviar();

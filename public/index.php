<?php

declare(strict_types=1);

/**
 * Punto de entrada HTTP del servicio.
 *
 * Por ahora solo expone el estado del servicio: los endpoints de emision,
 * consulta, anulacion y listado se incorporan en la tarea que construye la
 * capa REST.
 */

use InheritDevs\Dian\Excepcion\RequisitoNoCumplido;
use InheritDevs\Dian\Servicio;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['error' => 'Las dependencias del servicio no estan instaladas.'],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

require $autoload;

header('Content-Type: application/json; charset=utf-8');

try {
    $estado = Servicio::iniciar();
} catch (RequisitoNoCumplido $excepcion) {
    http_response_code(500);
    echo json_encode(
        ['error' => $excepcion->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

echo json_encode(
    ['servicio' => $estado['nombre'], 'version' => $estado['version']],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

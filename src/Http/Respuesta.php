<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Http;

/**
 * Respuesta HTTP en memoria.
 *
 * El enrutador devuelve una de estas y el punto de entrada la escribe. Asi las
 * pruebas pueden revisar el codigo de estado y el cuerpo sin tocar cabeceras.
 */
final class Respuesta
{
    /**
     * @param int                  $estado  Codigo de estado HTTP.
     * @param array<string, mixed> $cuerpo  Cuerpo que se serializa a JSON.
     * @param array<string, string> $cabeceras Cabeceras adicionales.
     */
    public function __construct(
        private readonly int $estado,
        private readonly array $cuerpo,
        private readonly array $cabeceras = [],
    ) {
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    public static function ok(array $cuerpo, array $cabeceras = []): self
    {
        return new self(200, $cuerpo, $cabeceras);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    public static function creado(array $cuerpo, array $cabeceras = []): self
    {
        return new self(201, $cuerpo, $cabeceras);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    public static function error(int $estado, array $cuerpo): self
    {
        return new self($estado, $cuerpo);
    }

    public function estado(): int
    {
        return $this->estado;
    }

    /**
     * @return array<string, mixed>
     */
    public function cuerpo(): array
    {
        return $this->cuerpo;
    }

    /**
     * @return array<string, string>
     */
    public function cabeceras(): array
    {
        return $this->cabeceras;
    }

    /**
     * Cuerpo serializado a JSON, sin escapar acentos ni simbolos.
     */
    public function json(): string
    {
        return (string) json_encode(
            $this->cuerpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    /**
     * Escribe la respuesta en la salida del proceso.
     */
    public function enviar(): void
    {
        if (!headers_sent()) {
            http_response_code($this->estado);
            header('Content-Type: application/json; charset=utf-8');

            foreach ($this->cabeceras as $nombre => $valor) {
                header(sprintf('%s: %s', $nombre, $valor));
            }
        }

        echo $this->json();
    }
}

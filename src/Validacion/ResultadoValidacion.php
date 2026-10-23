<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Validacion;

use JsonSerializable;

/**
 * Resultado de validar un documento contra el esquema XSD.
 *
 * Un documento valido devuelve `valido()` en true y la lista de errores vacia.
 * Un documento invalido trae los errores ya traducidos y un resumen corto que
 * se puede mostrar tal cual al usuario del servicio.
 */
final class ResultadoValidacion implements JsonSerializable
{
    /**
     * @param list<ErrorValidacion> $errores Errores encontrados, en el orden del XML.
     * @param string|null           $resumen Explicacion corta; null si el documento es valido.
     */
    public function __construct(
        private readonly bool $valido,
        private readonly array $errores = [],
        private readonly ?string $resumen = null,
    ) {
    }

    public function valido(): bool
    {
        return $this->valido;
    }

    /**
     * @return list<ErrorValidacion>
     */
    public function errores(): array
    {
        return $this->errores;
    }

    public function cantidadErrores(): int
    {
        return count($this->errores);
    }

    public function resumen(): ?string
    {
        return $this->resumen;
    }

    /**
     * Reporte de varias lineas, listo para mostrar en consola o en un log.
     */
    public function reporte(): string
    {
        if ($this->valido) {
            return 'El documento cumple el esquema del perfil.';
        }

        $lineas = [$this->resumen ?? 'El documento no cumple el esquema del perfil.'];

        foreach ($this->errores as $error) {
            $lineas[] = '- ' . $error->aTexto();
        }

        return implode("\n", $lineas);
    }

    /**
     * @return array{valido: bool, resumen: string|null, errores: list<array{linea: int, columna: int, codigo: string, mensaje: string}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'valido' => $this->valido,
            'resumen' => $this->resumen,
            'errores' => array_map(
                static fn (ErrorValidacion $error): array => $error->aArreglo(),
                $this->errores
            ),
        ];
    }
}

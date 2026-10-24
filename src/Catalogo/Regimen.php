<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Catalogo;

/**
 * Regimen tributario del anexo tecnico de la DIAN.
 *
 * Cada entrada del catalogo trae el codigo que viaja en el XML, el nombre que
 * se publica junto al `TaxScheme` y las dos condiciones que el servicio
 * necesita para decidir si un documento puede llevar IVA.
 */
final class Regimen
{
    /**
     * @param string $codigo        Codigo del catalogo de la DIAN.
     * @param string $nombre        Nombre oficial del regimen.
     * @param string $sigla         Sigla corta para reportes y mensajes.
     * @param bool   $responsableIva Si el regimen es responsable de IVA.
     * @param bool   $cobraIva      Si el documento puede cobrar IVA aparte.
     * @param string $descripcion   Explicacion en una linea.
     */
    public function __construct(
        private readonly string $codigo,
        private readonly string $nombre,
        private readonly string $sigla,
        private readonly bool $responsableIva,
        private readonly bool $cobraIva,
        private readonly string $descripcion,
    ) {
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function sigla(): string
    {
        return $this->sigla;
    }

    public function responsableIva(): bool
    {
        return $this->responsableIva;
    }

    public function cobraIva(): bool
    {
        return $this->cobraIva;
    }

    public function descripcion(): string
    {
        return $this->descripcion;
    }
}

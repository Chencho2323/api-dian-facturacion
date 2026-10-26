<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Emision;

use JsonSerializable;

/**
 * Documento ya emitido, tal como quedo guardado en el registro.
 *
 * Guarda el XML completo porque la DIAN exige conservar el documento original
 * sin cambios, y guarda los totales en centavos para que el listado no tenga
 * que volver a leer y calcular el XML de cada fila.
 */
final class DocumentoEmitido implements JsonSerializable
{
    /** El documento esta vigente. */
    public const ESTADO_EMITIDO = 'emitido';

    /** El documento fue anulado y ya no tiene efecto. */
    public const ESTADO_ANULADO = 'anulado';

    /** Estados que admite el registro. */
    public const ESTADOS = [self::ESTADO_EMITIDO, self::ESTADO_ANULADO];

    public function __construct(
        private readonly string $numero,
        private readonly string $tipo,
        private readonly string $fechaEmision,
        private readonly string $emisorNit,
        private readonly string $adquirenteNit,
        private readonly string $uuid,
        private readonly string $xml,
        private readonly int $subtotalCentavos,
        private readonly int $ivaCentavos,
        private readonly int $totalCentavos,
        private readonly int $retencionesCentavos,
        private readonly int $aPagarCentavos,
        private readonly string $estado = self::ESTADO_EMITIDO,
        private readonly ?string $motivoAnulacion = null,
        private readonly ?string $anuladoEn = null,
    ) {
    }

    public function numero(): string
    {
        return $this->numero;
    }

    public function tipo(): string
    {
        return $this->tipo;
    }

    public function estado(): string
    {
        return $this->estado;
    }

    public function fechaEmision(): string
    {
        return $this->fechaEmision;
    }

    public function emisorNit(): string
    {
        return $this->emisorNit;
    }

    public function adquirenteNit(): string
    {
        return $this->adquirenteNit;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function xml(): string
    {
        return $this->xml;
    }

    public function subtotalCentavos(): int
    {
        return $this->subtotalCentavos;
    }

    public function ivaCentavos(): int
    {
        return $this->ivaCentavos;
    }

    public function retencionesCentavos(): int
    {
        return $this->retencionesCentavos;
    }

    public function totalCentavos(): int
    {
        return $this->totalCentavos;
    }

    public function aPagarCentavos(): int
    {
        return $this->aPagarCentavos;
    }

    public function motivoAnulacion(): ?string
    {
        return $this->motivoAnulacion;
    }

    public function anuladoEn(): ?string
    {
        return $this->anuladoEn;
    }

    public function estaAnulado(): bool
    {
        return $this->estado === self::ESTADO_ANULADO;
    }

    /**
     * Copia del documento con el estado de anulacion aplicado.
     */
    public function anulado(string $motivo, string $fecha): self
    {
        return new self(
            $this->numero,
            $this->tipo,
            $this->fechaEmision,
            $this->emisorNit,
            $this->adquirenteNit,
            $this->uuid,
            $this->xml,
            $this->subtotalCentavos,
            $this->ivaCentavos,
            $this->totalCentavos,
            $this->retencionesCentavos,
            $this->aPagarCentavos,
            self::ESTADO_ANULADO,
            $motivo,
            $fecha,
        );
    }

    /**
     * Resumen sin el XML, para las respuestas y los listados.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        return [
            'numero' => $this->numero,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'fechaEmision' => $this->fechaEmision,
            'uuid' => $this->uuid,
            'emisor' => $this->emisorNit,
            'adquirente' => $this->adquirenteNit,
            'totales' => [
                'subtotal' => $this->formatear($this->subtotalCentavos),
                'iva' => $this->formatear($this->ivaCentavos),
                'total' => $this->formatear($this->totalCentavos),
                'retenciones' => $this->formatear($this->retencionesCentavos),
                'aPagar' => $this->formatear($this->aPagarCentavos),
            ],
            'motivoAnulacion' => $this->motivoAnulacion,
            'anuladoEn' => $this->anuladoEn,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->resumen();
    }

    private function formatear(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}

<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Documento;

use InvalidArgumentException;

/**
 * Sujeto que participa en el documento: emisor o adquirente.
 *
 * Solo guarda los datos que UBL necesita para identificar a la parte. La
 * resolucion del sujeto por NIT contra el registro de la DIAN llega en la
 * tarea del catalogo de regimenes; aqui los datos entran ya resueltos.
 */
final class Tercero
{
    /**
     * Regimenes tributarios del anexo tecnico de la DIAN.
     *
     * @var array<string, string>
     */
    public const REGIMENES = [
        '48' => 'Responsable de IVA',
        '49' => 'No responsable de IVA',
        '04' => 'Regimen simple de tributacion',
    ];

    /**
     * Tipos de documento de identificacion de la DIAN.
     *
     * @var array<string, string>
     */
    public const TIPOS_IDENTIFICACION = [
        '11' => 'Registro civil',
        '12' => 'Tarjeta de identidad',
        '13' => 'Cedula de ciudadania',
        '31' => 'NIT',
        '41' => 'Pasaporte',
    ];

    /**
     * @param string $identificacion Numero de documento sin digito de verificacion.
     * @param string $razonSocial     Nombre o razon social.
     * @param string $regimen         Codigo de regimen tributario (clave de REGIMENES).
     * @param string $tipoIdentificacion Codigo de tipo de documento (clave de TIPOS_IDENTIFICACION).
     * @param string $direccion       Direccion fiscal.
     * @param string $ciudad          Nombre de la ciudad.
     * @param string $departamento    Codigo o nombre del departamento.
     * @param string $correo          Correo de notificacion.
     */
    public function __construct(
        private readonly string $identificacion,
        private readonly string $razonSocial,
        private readonly string $regimen,
        private readonly string $tipoIdentificacion = '31',
        private readonly string $direccion = '',
        private readonly string $ciudad = '',
        private readonly string $departamento = '',
        private readonly string $correo = '',
    ) {
        if (trim($identificacion) === '') {
            throw new InvalidArgumentException('El tercero necesita un numero de identificacion.');
        }

        if (trim($razonSocial) === '') {
            throw new InvalidArgumentException('El tercero necesita una razon social.');
        }

        if (!array_key_exists($regimen, self::REGIMENES)) {
            throw new InvalidArgumentException(sprintf(
                'El regimen tributario "%s" no existe en el catalogo de la DIAN. Valores validos: %s.',
                $regimen,
                implode(', ', array_keys(self::REGIMENES))
            ));
        }

        if (!array_key_exists($tipoIdentificacion, self::TIPOS_IDENTIFICACION)) {
            throw new InvalidArgumentException(sprintf(
                'El tipo de identificacion "%s" no existe en el catalogo de la DIAN.',
                $tipoIdentificacion
            ));
        }
    }

    public function identificacion(): string
    {
        return $this->identificacion;
    }

    public function razonSocial(): string
    {
        return $this->razonSocial;
    }

    public function regimen(): string
    {
        return $this->regimen;
    }

    public function nombreRegimen(): string
    {
        return self::REGIMENES[$this->regimen];
    }

    public function tipoIdentificacion(): string
    {
        return $this->tipoIdentificacion;
    }

    public function direccion(): string
    {
        return $this->direccion;
    }

    public function ciudad(): string
    {
        return $this->ciudad;
    }

    public function departamento(): string
    {
        return $this->departamento;
    }

    public function correo(): string
    {
        return $this->correo;
    }
}

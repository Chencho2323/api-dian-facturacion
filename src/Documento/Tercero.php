<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Documento;

use InheritDevs\Dian\Catalogo\CatalogoRegimenes;
use InheritDevs\Dian\Catalogo\Nit;
use InvalidArgumentException;

/**
 * Sujeto que participa en el documento: emisor o adquirente.
 *
 * Guarda los datos que UBL necesita para identificar a la parte. El regimen se
 * valida contra el catalogo de la DIAN y, cuando la identificacion es un NIT,
 * se comprueba el digito de verificacion para que no entren sujetos con un
 * numero mal escrito.
 */
final class Tercero
{
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

    /** Codigo del tipo de identificacion tributaria. */
    public const TIPO_NIT = '31';

    /** Identificacion ya normalizada. */
    private readonly string $identificacion;

    /** NIT resuelto, solo cuando la identificacion es de tipo NIT. */
    private readonly ?Nit $nit;

    /**
     * @param string $identificacion Numero de documento; si es NIT admite puntos y guion.
     * @param string $razonSocial     Nombre o razon social.
     * @param string $regimen         Codigo de regimen tributario (clave de CatalogoRegimenes).
     * @param string $tipoIdentificacion Codigo de tipo de documento (clave de TIPOS_IDENTIFICACION).
     * @param string $direccion       Direccion fiscal.
     * @param string $ciudad          Nombre de la ciudad.
     * @param string $departamento    Codigo o nombre del departamento.
     * @param string $correo          Correo de notificacion.
     */
    public function __construct(
        string $identificacion,
        private readonly string $razonSocial,
        private readonly string $regimen,
        private readonly string $tipoIdentificacion = self::TIPO_NIT,
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

        if (!CatalogoRegimenes::existe($regimen)) {
            throw new InvalidArgumentException(sprintf(
                'El regimen tributario "%s" no existe en el catalogo de la DIAN. Valores validos: %s.',
                $regimen,
                implode(', ', CatalogoRegimenes::codigos())
            ));
        }

        if (!array_key_exists($tipoIdentificacion, self::TIPOS_IDENTIFICACION)) {
            throw new InvalidArgumentException(sprintf(
                'El tipo de identificacion "%s" no existe en el catalogo de la DIAN.',
                $tipoIdentificacion
            ));
        }

        // El NIT se normaliza aqui: dos formas de escribirlo no pueden quedar
        // como dos sujetos distintos en el registro. En el XML viaja el numero
        // sin digito de verificacion, que es como lo pide el perfil.
        $this->nit = $tipoIdentificacion === self::TIPO_NIT
            ? Nit::desde($identificacion)
            : null;

        $this->identificacion = $this->nit?->numero() ?? trim($identificacion);
    }

    public function identificacion(): string
    {
        return $this->identificacion;
    }

    /**
     * NIT ya normalizado, o null si la identificacion no es tributaria.
     */
    public function nit(): ?Nit
    {
        return $this->nit;
    }

    public function esNit(): bool
    {
        return $this->nit !== null;
    }

    /**
     * Clave de identidad del sujeto: el NIT con su digito cuando existe y, si
     * no, el tipo y el numero de documento.
     *
     * Sirve para comparar sujetos sin depender de puntos, guiones ni espacios.
     */
    public function clave(): string
    {
        if ($this->nit !== null) {
            return $this->nit->clave();
        }

        return $this->tipoIdentificacion . ':' . $this->identificacion;
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
        return CatalogoRegimenes::nombre($this->regimen);
    }

    /**
     * Indica si el sujeto cobra IVA aparte en sus documentos.
     */
    public function cobraIva(): bool
    {
        return CatalogoRegimenes::cobraIva($this->regimen);
    }

    public function tipoIdentificacion(): string
    {
        return $this->tipoIdentificacion;
    }

    public function nombreTipoIdentificacion(): string
    {
        return self::TIPOS_IDENTIFICACION[$this->tipoIdentificacion];
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

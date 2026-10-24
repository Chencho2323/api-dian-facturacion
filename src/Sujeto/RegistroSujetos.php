<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Sujeto;

use InheritDevs\Dian\Catalogo\CatalogoRegimenes;
use InheritDevs\Dian\Catalogo\Nit;
use InheritDevs\Dian\Documento\Tercero;
use InheritDevs\Dian\Excepcion\SujetoNoEncontrado;
use InvalidArgumentException;

/**
 * Registro de sujetos en memoria, indexado por NIT normalizado.
 *
 * Resuelve quien es el emisor o el adquirente de un documento a partir del
 * numero que llega en la peticion. La busqueda no distingue puntos ni guiones:
 * `900.123.456`, `900123456-8` y `9001234568` son el mismo sujeto. Un NIT con
 * el digito de verificacion equivocado nunca resuelve, aunque este el numero.
 */
final class RegistroSujetos
{
    /**
     * Sujetos indexados por NIT completo (numero mas digito).
     *
     * @var array<string, Tercero>
     */
    private array $sujetos = [];

    /**
     * Agrega un sujeto al registro.
     *
     * @throws InvalidArgumentException Si el sujeto no tiene NIT o si ya hay
     *                                  otro sujeto con el mismo NIT.
     */
    public function agregar(Tercero $sujeto): self
    {
        if (!$sujeto->esNit()) {
            throw new InvalidArgumentException(sprintf(
                'El sujeto "%s" no se puede registrar: solo se indexan sujetos con NIT.',
                $sujeto->razonSocial()
            ));
        }

        $clave = $sujeto->clave();

        if (isset($this->sujetos[$clave])) {
            throw new InvalidArgumentException(sprintf(
                'Ya hay un sujeto registrado con el NIT %s: %s.',
                $clave,
                $this->sujetos[$clave]->razonSocial()
            ));
        }

        $this->sujetos[$clave] = $sujeto;

        return $this;
    }

    /**
     * Reemplaza el sujeto que tenga ese NIT, o lo agrega si no existe.
     *
     * Se usa para actualizar los datos de contacto de un cliente sin perder su
     * historial de documentos.
     */
    public function guardar(Tercero $sujeto): self
    {
        if (!$sujeto->esNit()) {
            throw new InvalidArgumentException(sprintf(
                'El sujeto "%s" no se puede registrar: solo se indexan sujetos con NIT.',
                $sujeto->razonSocial()
            ));
        }

        $this->sujetos[$sujeto->clave()] = $sujeto;

        return $this;
    }

    /**
     * Busca un sujeto por cualquier representacion valida de su NIT.
     *
     * Un numero de diez digitos se prueba en sus dos lecturas posibles: como
     * base de diez digitos y como base de nueve con el digito de verificacion
     * pegado. Asi `9001234568` y `900123456-8` llegan al mismo sujeto.
     *
     * @throws InvalidArgumentException Si el numero no es un NIT valido.
     * @throws SujetoNoEncontrado        Si no hay sujeto con ese NIT.
     */
    public function resolver(string $nit): Tercero
    {
        $claves = Nit::clavesPosibles($nit);

        foreach ($claves as $clave) {
            if (isset($this->sujetos[$clave])) {
                return $this->sujetos[$clave];
            }
        }

        throw new SujetoNoEncontrado($claves[0], $this->claves());
    }

    /**
     * Busca un sujeto y devuelve null cuando no existe, sin lanzar excepcion.
     */
    public function buscar(string $nit): ?Tercero
    {
        try {
            return $this->resolver($nit);
        } catch (SujetoNoEncontrado|InvalidArgumentException) {
            return null;
        }
    }

    public function contiene(string $nit): bool
    {
        return $this->buscar($nit) !== null;
    }

    public function contar(): int
    {
        return count($this->sujetos);
    }

    /**
     * Sujetos registrados, indexados por NIT completo.
     *
     * @return array<string, Tercero>
     */
    public function todos(): array
    {
        return $this->sujetos;
    }

    /**
     * NIT completos registrados, en el orden en que entraron.
     *
     * @return list<string>
     */
    public function claves(): array
    {
        return array_keys($this->sujetos);
    }

    /**
     * Sujetos que pertenecen a un regimen tributario.
     *
     * @return list<Tercero>
     */
    public function porRegimen(string $regimen): array
    {
        if (!CatalogoRegimenes::existe($regimen)) {
            throw new InvalidArgumentException(sprintf(
                'El regimen tributario "%s" no existe en el catalogo de la DIAN. Valores validos: %s.',
                $regimen,
                implode(', ', CatalogoRegimenes::codigos())
            ));
        }

        return array_values(array_filter(
            $this->sujetos,
            static fn (Tercero $sujeto): bool => $sujeto->regimen() === $regimen
        ));
    }

    /**
     * Registro vacio.
     */
    public static function vacio(): self
    {
        return new self();
    }
}

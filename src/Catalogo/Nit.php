<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Catalogo;

use InvalidArgumentException;

/**
 * Numero de identificacion tributaria (NIT) con su digito de verificacion.
 *
 * El NIT llega de mil formas: con puntos, con guion, con el digito pegado o
 * separado. Esta clase deja una sola forma canonica, sin separadores y con el
 * digito de verificacion calculado segun el algoritmo de la DIAN, para que dos
 * representaciones del mismo NIT nunca se traten como sujetos distintos.
 */
final class Nit
{
    /**
     * Pesos del algoritmo oficial, de derecha a izquierda.
     *
     * @var list<int>
     */
    private const PESOS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    private function __construct(
        private readonly string $numero,
        private readonly int $digitoVerificacion,
    ) {
    }

    /**
     * Construye el NIT a partir de la representacion que traiga el usuario.
     *
     * Acepta `900123456`, `900.123.456`, `900123456-8` y `900.123.456-8`. Si
     * viene el digito, se comprueba que corresponda; si no viene, se calcula.
     *
     * @throws InvalidArgumentException Si el numero no es un NIT valido.
     */
    public static function desde(string $representacion): self
    {
        $limpio = preg_replace('/[\s.]/u', '', trim($representacion)) ?? '';
        $partes = explode('-', $limpio);

        if (count($partes) > 2) {
            throw new InvalidArgumentException(sprintf(
                'El NIT "%s" tiene mas de un digito de verificacion separado.',
                $representacion
            ));
        }

        $numero = $partes[0];

        if (preg_match('/^\d+$/', $numero) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El NIT "%s" debe contener solo digitos.',
                $representacion
            ));
        }

        // El NIT colombiano tiene entre 9 y 10 digitos en la parte del numero.
        $largo = strlen($numero);

        if ($largo < 9 || $largo > 10) {
            throw new InvalidArgumentException(sprintf(
                'El NIT "%s" tiene %d digitos; debe tener entre 9 y 10.',
                $representacion,
                $largo
            ));
        }

        $calculado = self::calcularDigito($numero);

        if (!isset($partes[1])) {
            return new self($numero, $calculado);
        }

        if (preg_match('/^\d$/', $partes[1]) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El digito de verificacion de "%s" debe ser un solo numero.',
                $representacion
            ));
        }

        $declarado = (int) $partes[1];

        if ($declarado !== $calculado) {
            throw new InvalidArgumentException(sprintf(
                'El NIT "%s" no es valido: el digito de verificacion es %d y llego %d.',
                $representacion,
                $calculado,
                $declarado
            ));
        }

        return new self($numero, $calculado);
    }

    /**
     * Calcula el digito de verificacion de un NIT sin digito.
     *
     * @throws InvalidArgumentException Si la base no es numerica.
     */
    public static function calcularDigito(string $base): int
    {
        if (preg_match('/^\d+$/', $base) !== 1) {
            throw new InvalidArgumentException('La base del NIT debe contener solo digitos.');
        }

        $suma = 0;
        $indice = 0;

        for ($posicion = strlen($base) - 1; $posicion >= 0; $posicion--) {
            if (!isset(self::PESOS[$indice])) {
                throw new InvalidArgumentException('La base del NIT es demasiado larga para el algoritmo.');
            }

            $suma += ((int) $base[$posicion]) * self::PESOS[$indice];
            $indice++;
        }

        $residuo = $suma % 11;

        return $residuo > 1 ? 11 - $residuo : $residuo;
    }

    /**
     * Numero sin digito de verificacion.
     */
    public function numero(): string
    {
        return $this->numero;
    }

    /**
     * Clave canonica del NIT: numero, guion y digito de verificacion.
     *
     * No lleva puntos ni espacios y no se puede confundir con un NIT de diez
     * digitos, que es el motivo por el que el registro indexa con esta forma y
     * no con el numero pegado.
     */
    public function clave(): string
    {
        return $this->numero . '-' . $this->digitoVerificacion;
    }

    /**
     * Representaciones canonicas posibles de un NIT escrito de cualquier forma.
     *
     * Un numero de diez digitos es ambiguo: puede ser una base de diez digitos
     * o una base de nueve con el digito de verificacion pegado. Se devuelven
     * las dos lecturas validas, la de base completa primero, para que el
     * registro pruebe ambas antes de decir que el sujeto no existe.
     *
     * @return list<string>
     */
    public static function clavesPosibles(string $representacion): array
    {
        $limpio = preg_replace('/[\s.]/u', '', trim($representacion)) ?? '';

        if (str_contains($limpio, '-')) {
            return [self::desde($limpio)->clave()];
        }

        if (preg_match('/^\d+$/', $limpio) !== 1) {
            // Que `desde()` produzca el mensaje de error.
            return [self::desde($representacion)->clave()];
        }

        $claves = [self::desde($limpio)->clave()];

        if (strlen($limpio) >= 10) {
            $base = substr($limpio, 0, -1);
            $digito = (int) substr($limpio, -1);

            if (strlen($base) >= 9 && self::calcularDigito($base) === $digito) {
                $claves[] = $base . '-' . $digito;
            }
        }

        return array_values(array_unique($claves));
    }

    public function digitoVerificacion(): int
    {
        return $this->digitoVerificacion;
    }

    /**
     * Numero con el digito pegado, como lo publica la DIAN en el XML.
     */
    public function completo(): string
    {
        return $this->numero . $this->digitoVerificacion;
    }

    /**
     * Forma legible con separadores de miles y el digito tras el guion.
     */
    public function formateado(): string
    {
        return number_format((float) $this->numero, 0, ',', '.') . '-' . $this->digitoVerificacion;
    }

    public function __toString(): string
    {
        return $this->completo();
    }
}

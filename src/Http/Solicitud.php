<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Http;

use InheritDevs\Dian\Excepcion\PeticionInvalida;

/**
 * Peticion HTTP ya normalizada.
 *
 * El enrutador trabaja con esta clase y no con las variables globales de PHP,
 * asi la capa se puede probar sin levantar un servidor: basta construir una
 * solicitud a mano.
 */
final class Solicitud
{
    /**
     * @param string                $metodo  Metodo HTTP, en mayusculas.
     * @param string                $ruta    Ruta ya normalizada, sin barra final.
     * @param array<string, string> $consulta Parametros de la cadena de consulta.
     * @param string                $cuerpo  Cuerpo crudo de la peticion.
     */
    public function __construct(
        private readonly string $metodo,
        string $ruta,
        private readonly array $consulta = [],
        private readonly string $cuerpo = '',
    ) {
        $this->ruta = self::normalizar($ruta);
    }

    private readonly string $ruta;

    /**
     * Construye la solicitud a partir de las variables que llena el servidor.
     */
    public static function desdeGlobales(): self
    {
        $metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $ruta = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        $consulta = [];

        foreach ($_GET as $clave => $valor) {
            if (is_scalar($valor)) {
                $consulta[(string) $clave] = (string) $valor;
            }
        }

        $cuerpo = (string) file_get_contents('php://input');

        return new self($metodo, $ruta, $consulta, $cuerpo);
    }

    public function metodo(): string
    {
        return strtoupper($this->metodo);
    }

    public function ruta(): string
    {
        return $this->ruta;
    }

    public function cuerpo(): string
    {
        return $this->cuerpo;
    }

    /**
     * Parametro de la cadena de consulta, con valor por defecto.
     */
    public function parametro(string $nombre, ?string $defecto = null): ?string
    {
        return $this->consulta[$nombre] ?? $defecto;
    }

    /**
     * Cuerpo de la peticion interpretado como objeto JSON.
     *
     * @throws PeticionInvalida Si el cuerpo no es JSON o no es un objeto.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        if (trim($this->cuerpo) === '') {
            throw new PeticionInvalida('La peticion necesita un cuerpo JSON con los datos del documento.');
        }

        try {
            $datos = json_decode($this->cuerpo, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $excepcion) {
            throw new PeticionInvalida(sprintf(
                'El cuerpo de la peticion no es JSON valido: %s.',
                $excepcion->getMessage()
            ));
        }

        // Se acepta tambien la lista vacia porque `{}` decodifica a un array
        // sin claves: el cuerpo existe, solo que sin campos.
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            throw new PeticionInvalida('El cuerpo de la peticion debe ser un objeto JSON.');
        }

        return $datos;
    }

    /**
     * Deja la ruta en la forma /recurso, sin dominio, sin consulta y sin
     * barra final, para que dos formas de escribir lo mismo enruten igual.
     */
    private static function normalizar(string $ruta): string
    {
        $sinConsulta = explode('?', $ruta, 2)[0];
        $recortada = '/' . trim($sinConsulta, '/');

        return $recortada === '' ? '/' : $recortada;
    }
}

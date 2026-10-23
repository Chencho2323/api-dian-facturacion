<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Validacion;

use DOMDocument;
use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;
use LibXMLError;
use RuntimeException;

/**
 * Valida un XML contra los esquemas XSD del perfil de facturacion electronica.
 *
 * Los esquemas viven en `recursos/xsd` y cada tipo de documento tiene el suyo:
 * Invoice-2.xsd, CreditNote-2.xsd y DebitNote-2.xsd, que importan los
 * componentes comunes. Un documento mal formado no lanza excepcion: devuelve un
 * `ResultadoValidacion` con los errores ya traducidos a espanol, para que quien
 * consuma el servicio sepa que corregir.
 */
final class ValidadorXsd
{
    /** @var array<string, string> Tipo de documento => archivo del esquema. */
    private const ESQUEMAS = [
        'factura' => 'Invoice-2.xsd',
        'nota-credito' => 'CreditNote-2.xsd',
        'nota-debito' => 'DebitNote-2.xsd',
    ];

    private readonly string $directorio;

    /**
     * @param string|null $directorio Ruta de los esquemas; por defecto, `recursos/xsd`.
     */
    public function __construct(?string $directorio = null)
    {
        $este = $directorio ?? dirname(__DIR__, 2) . '/recursos/xsd';

        if (!is_dir($este)) {
            throw new RuntimeException(sprintf(
                'No se encontro el directorio de esquemas XSD "%s".',
                $este
            ));
        }

        $this->directorio = rtrim($este, '/');
    }

    /**
     * Valida el XML de un documento del modelo.
     *
     * @throws DocumentoInvalido Si el tipo de documento no tiene esquema.
     */
    public function validarDocumento(DocumentoUbl $documento): ResultadoValidacion
    {
        return $this->validar($documento->xml(), $documento->tipo()->value);
    }

    /**
     * Valida un XML contra el esquema del tipo de documento indicado.
     *
     * @param string $xml  Documento XML completo.
     * @param string $tipo Clave del tipo: factura, nota-credito o nota-debito.
     *
     * @throws DocumentoInvalido Si el tipo no tiene esquema o el XML esta vacio.
     */
    public function validar(string $xml, string $tipo): ResultadoValidacion
    {
        $esquema = $this->rutaEsquema($tipo);

        if (trim($xml) === '') {
            throw new DocumentoInvalido('El XML a validar esta vacio.');
        }

        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument();
            $cargado = $dom->loadXML($xml, LIBXML_NONET);

            if (!$cargado) {
                $errores = $this->traducir(libxml_get_errors());

                return new ResultadoValidacion(
                    false,
                    $errores,
                    $errores === []
                        ? 'El XML no esta bien formado y no se pudo leer.'
                        : 'El XML no esta bien formado.'
                );
            }

            $delParseo = $this->traducir(libxml_get_errors());

            // Un prefijo de espacio de nombres sin declarar deja el documento
            // sin leer: no tiene sentido seguir con el esquema.
            foreach ($delParseo as $error) {
                if (str_contains($error->mensaje(), 'no esta declarado')) {
                    return new ResultadoValidacion(
                        false,
                        $delParseo,
                        'El XML no esta bien formado.'
                    );
                }
            }

            libxml_clear_errors();
            $valido = $dom->schemaValidate($esquema);
            $errores = $this->traducir(libxml_get_errors());

            if ($valido && $errores === []) {
                return new ResultadoValidacion(true, [], null);
            }

            return new ResultadoValidacion(
                false,
                $errores,
                $errores === []
                    ? 'El documento no cumple el esquema del perfil.'
                    : sprintf('El documento tiene %d error(es) frente al esquema.', count($errores))
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /**
     * Indica si un tipo de documento tiene esquema disponible.
     */
    public function tieneEsquema(string $tipo): bool
    {
        return array_key_exists($tipo, self::ESQUEMAS);
    }

    /**
     * Ruta absoluta del esquema de un tipo de documento.
     *
     * @throws DocumentoInvalido
     */
    public function rutaEsquema(string $tipo): string
    {
        if (!$this->tieneEsquema($tipo)) {
            throw new DocumentoInvalido(sprintf(
                'No hay esquema XSD para el tipo de documento "%s". Tipos con esquema: %s.',
                $tipo,
                implode(', ', array_keys(self::ESQUEMAS))
            ));
        }

        $ruta = $this->directorio . '/' . self::ESQUEMAS[$tipo];

        if (!is_file($ruta)) {
            throw new RuntimeException(sprintf(
                'Falta el archivo de esquema "%s" en %s.',
                self::ESQUEMAS[$tipo],
                $this->directorio
            ));
        }

        return $ruta;
    }

    /**
     * Convierte los errores de libxml en errores legibles.
     *
     * @param list<LibXMLError> $errores
     *
     * @return list<ErrorValidacion>
     */
    private function traducir(array $errores): array
    {
        $traducidos = [];

        foreach ($errores as $error) {
            // Avisos y errores recuperables no impiden validar el documento.
            if ($error->level < LIBXML_ERR_ERROR) {
                continue;
            }

            $traducidos[] = new ErrorValidacion(
                max(0, (int) $error->line),
                max(0, (int) $error->column),
                $this->explicar(trim($error->message)),
                (string) $error->code
            );
        }

        return $traducidos;
    }

    /**
     * Traduce el mensaje de libxml a una frase en espanol con el dato concreto.
     */
    private function explicar(string $mensaje): string
    {
        // Falta un atributo obligatorio.
        if (preg_match("/The attribute '([^']+)' is required but missing/", $mensaje, $partes) === 1) {
            return sprintf(
                'Falta el atributo obligatorio "%s" en %s.',
                $partes[1],
                $this->sujeto($mensaje)
            );
        }

        // Falta un elemento obligatorio dentro de otro.
        if (preg_match('/Missing child element\(s\)\. Expected is \(/', $mensaje) === 1) {
            return sprintf(
                'Falta un elemento obligatorio en %s. El esquema espera: %s.',
                $this->sujeto($mensaje),
                $this->esperados($mensaje)
            );
        }

        // El valor no esta en la lista cerrada que admite el perfil.
        if (preg_match('/\[facet \'enumeration\'\]/', $mensaje) === 1) {
            return sprintf(
                '%s no admite el valor %s. Valores permitidos: %s.',
                ucfirst($this->sujeto($mensaje)),
                $this->valor($mensaje),
                $this->permitidos($mensaje)
            );
        }

        // El valor no cumple un patron de formato.
        if (preg_match('/\[facet \'pattern\'\]/', $mensaje) === 1) {
            return sprintf(
                '%s no admite el valor %s: no tiene el formato que exige el perfil.',
                ucfirst($this->sujeto($mensaje)),
                $this->valor($mensaje)
            );
        }

        // El valor no corresponde al tipo de dato del esquema.
        if (preg_match("/is not a valid value of the atomic type '([^']+)'/", $mensaje, $partes) === 1) {
            return sprintf(
                '%s no admite el valor %s: se esperaba un dato de tipo %s.',
                ucfirst($this->sujeto($mensaje)),
                $this->valor($mensaje),
                $this->tipoLegible($partes[1])
            );
        }

        // El elemento llego donde no va o fuera de orden.
        if (preg_match('/This element is not expected\./', $mensaje) === 1) {
            return sprintf(
                '%s esta fuera de lugar o de orden. El esquema espera: %s.',
                ucfirst($this->sujeto($mensaje)),
                $this->esperados($mensaje)
            );
        }

        // La raiz no corresponde al tipo de documento.
        if (preg_match('/No matching global declaration available|The document is not schema valid/', $mensaje) === 1) {
            return 'La raiz del XML no corresponde al tipo de documento que se esta validando.';
        }

        // Prefijo de espacio de nombres sin declarar: el XML no se puede leer.
        if (preg_match('/Namespace prefix ([A-Za-z0-9_]+) on \w+ is not defined/', $mensaje, $partes) === 1) {
            return sprintf(
                'El prefijo "%s" se usa en el XML pero no esta declarado con xmlns:%s.',
                $partes[1],
                $partes[1]
            );
        }

        // XML mal formado: se conserva el aviso del parser, ya en espanol.
        if (preg_match('/Premature end of data|StartTag: invalid element name|Extra content at the end/', $mensaje) === 1) {
            return 'El XML esta incompleto o tiene etiquetas mal cerradas.';
        }

        return 'El documento no cumple el esquema del perfil.';
    }

    /**
     * Sujeto de la frase: "el elemento X" o "el documento".
     */
    private function sujeto(string $mensaje): string
    {
        $nombre = $this->nombreElemento($mensaje);

        return $nombre === null ? 'el documento' : 'el elemento ' . $nombre;
    }

    /**
     * Nombre del elemento implicado, sin el espacio de nombres largo.
     */
    private function nombreElemento(string $mensaje): ?string
    {
        if (preg_match("/Element '(?:\{[^}]*\})?([^']+)'/", $mensaje, $partes) === 1) {
            return $partes[1];
        }

        return null;
    }

    /**
     * Valor recibido, si el mensaje lo trae entre comillas simples.
     */
    private function valor(string $mensaje): string
    {
        if (preg_match("/The value '([^']*)'/", $mensaje, $partes) === 1) {
            return '"' . $partes[1] . '"';
        }

        return 'recibido';
    }

    /**
     * Valores que el esquema admite en un campo de lista cerrada.
     */
    private function permitidos(string $mensaje): string
    {
        if (preg_match('/is not an element of the set \{([^}]*)\}/', $mensaje, $partes) === 1) {
            $valores = array_map(
                static fn (string $valor): string => trim($valor, " '\""),
                explode(',', $partes[1])
            );

            $valores = array_filter($valores, static fn (string $valor): bool => $valor !== '');

            if ($valores !== []) {
                return implode(', ', $valores);
            }
        }

        return 'los que define el anexo tecnico';
    }

    /**
     * Elementos que el esquema espera en ese punto del documento.
     */
    private function esperados(string $mensaje): string
    {
        if (preg_match('/Expected is one of \(([^)]*)\)/', $mensaje, $partes) === 1
            || preg_match('/Expected is \(([^)]*)\)/', $mensaje, $partes) === 1) {
            $nombres = [];

            preg_match_all('/(?:\{[^}]*\})?([A-Za-z][A-Za-z0-9]*)/', $partes[1], $encontrados);

            foreach ($encontrados[1] as $nombre) {
                $nombres[$nombre] = true;
            }

            if ($nombres !== []) {
                return implode(', ', array_keys($nombres));
            }
        }

        return 'otro elemento del perfil';
    }

    /**
     * Nombre legible de un tipo de dato del esquema.
     */
    private function tipoLegible(string $tipo): string
    {
        return match ($tipo) {
            'xs:date' => 'fecha (AAAA-MM-DD)',
            'xs:time' => 'hora (HH:MM:SS)',
            'xs:decimal' => 'numero decimal',
            'xs:positiveInteger' => 'entero positivo',
            'xs:integer' => 'numero entero',
            default => $tipo,
        };
    }
}

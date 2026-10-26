<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Emision;

use DateTimeImmutable;
use DateTimeZone;
use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;
use InheritDevs\Dian\Excepcion\DocumentoNoEncontrado;
use InheritDevs\Dian\Excepcion\PeticionInvalida;
use InheritDevs\Dian\Validacion\ResultadoValidacion;
use InheritDevs\Dian\Validacion\ValidadorXsd;

/**
 * Casos de uso de la emision: emitir, consultar, anular y listar.
 *
 * El documento se valida contra el esquema antes de guardarlo: si el XML no
 * cumple el perfil de la DIAN, no entra al registro. La anulacion no borra: el
 * documento queda marcado como anulado con su motivo, porque la DIAN exige
 * conservar el original.
 */
final class ServicioDocumentos
{
    private readonly ValidadorXsd $validador;

    private readonly FabricaDocumento $fabrica;

    public function __construct(
        private readonly RepositorioDocumentos $repositorio,
        ?ValidadorXsd $validador = null,
        ?FabricaDocumento $fabrica = null,
    ) {
        $this->validador = $validador ?? new ValidadorXsd();
        $this->fabrica = $fabrica ?? new FabricaDocumento();
    }

    /**
     * Emite un documento a partir del cuerpo JSON de la peticion.
     *
     * @param array<string, mixed> $datos
     *
     * @throws PeticionInvalida Si los datos no alcanzan o el documento no cumple el perfil.
     *
     * @return array{documento: DocumentoEmitido, validacion: ResultadoValidacion}
     */
    public function emitir(array $datos): array
    {
        $documento = $this->fabrica->construir($datos);

        if ($this->repositorio->existe($documento->numero())) {
            throw new \InheritDevs\Dian\Excepcion\DocumentoDuplicado($documento->numero());
        }

        $validacion = $this->validador->validarDocumento($documento);

        if (!$validacion->valido()) {
            throw new PeticionInvalida(
                'El documento no cumple el esquema de la DIAN y no se puede emitir. '
                . ($validacion->resumen() ?? ''),
                ['esquema' => $validacion->reporte()],
            );
        }

        $xml = $documento->xml();

        $emitido = new DocumentoEmitido(
            $documento->numero(),
            $documento->tipo()->value,
            $documento->fechaEmision()->format('Y-m-d H:i:s'),
            $documento->emisor()->identificacion(),
            $documento->adquirente()->identificacion(),
            $this->uuidDe($xml),
            $xml,
            $documento->subtotalCentavos(),
            $documento->ivaCentavos(),
            $documento->totalCentavos(),
            $documento->retencionesCentavos(),
            $documento->aPagarCentavos(),
        );

        $this->repositorio->guardar($emitido);

        return ['documento' => $emitido, 'validacion' => $validacion];
    }

    /**
     * Documento guardado por numero.
     *
     * @throws DocumentoNoEncontrado
     */
    public function consultar(string $numero): DocumentoEmitido
    {
        $this->exigirNumero($numero);

        return $this->repositorio->buscar($numero);
    }

    /**
     * Anula un documento emitido.
     *
     * @throws DocumentoNoEncontrado Si no existe.
     * @throws DocumentoInvalido    Si ya estaba anulado o el motivo no sirve.
     */
    public function anular(string $numero, string $motivo): DocumentoEmitido
    {
        $this->exigirNumero($numero);

        if (trim($motivo) === '') {
            throw new DocumentoInvalido('La anulacion necesita un motivo.');
        }

        $documento = $this->repositorio->buscar($numero);

        if ($documento->estaAnulado()) {
            throw new DocumentoInvalido(sprintf(
                'El documento "%s" ya esta anulado desde %s.',
                $numero,
                (string) $documento->anuladoEn()
            ));
        }

        $anulado = $documento->anulado(
            trim($motivo),
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
        );

        $this->repositorio->actualizar($anulado);

        return $anulado;
    }

    /**
     * Listado paginado con los filtros que llegan por la cadena de consulta.
     *
     * @param array<string, string|null> $parametros
     *
     * @throws PeticionInvalida Si un filtro o la paginacion no tienen sentido.
     *
     * @return array{documentos: list<array<string, mixed>>, total: int, limite: int, desplazamiento: int}
     */
    public function listar(array $parametros): array
    {
        $filtros = [];
        $tipo = $this->limpio($parametros['tipo'] ?? null);
        $estado = $this->limpio($parametros['estado'] ?? null);

        if ($tipo !== null) {
            if (\InheritDevs\Dian\Documento\TipoDocumento::tryFrom($tipo) === null) {
                throw new PeticionInvalida(sprintf(
                    'El filtro tipo "%s" no existe. Valores validos: factura, nota-credito, nota-debito.',
                    $tipo
                ));
            }

            $filtros['tipo'] = $tipo;
        }

        if ($estado !== null) {
            if (!in_array($estado, DocumentoEmitido::ESTADOS, true)) {
                throw new PeticionInvalida(sprintf(
                    'El filtro estado "%s" no existe. Valores validos: %s.',
                    $estado,
                    implode(', ', DocumentoEmitido::ESTADOS)
                ));
            }

            $filtros['estado'] = $estado;
        }

        foreach (['emisor' => 'emisor', 'desde' => 'desde', 'hasta' => 'hasta'] as $origen => $destino) {
            $valor = $this->limpio($parametros[$origen] ?? null);

            if ($valor !== null) {
                $filtros[$destino] = $valor;
            }
        }

        $limite = $this->entero($parametros['limite'] ?? null, RepositorioDocumentos::LIMITE_POR_DEFECTO, 'limite');
        $desplazamiento = $this->entero($parametros['desplazamiento'] ?? null, 0, 'desplazamiento');

        $resultado = $this->repositorio->listar($filtros, $limite, $desplazamiento);

        return [
            'documentos' => array_map(
                static fn (DocumentoEmitido $documento): array => $documento->resumen(),
                $resultado['documentos']
            ),
            'total' => $resultado['total'],
            'limite' => $resultado['limite'],
            'desplazamiento' => $resultado['desplazamiento'],
        ];
    }

    /**
     * Indica cuantos documentos hay guardados.
     */
    public function contar(): int
    {
        return $this->repositorio->contar();
    }

    private function exigirNumero(string $numero): void
    {
        if (trim($numero) === '') {
            throw new PeticionInvalida('Falta el numero del documento en la ruta.');
        }
    }

    private function limpio(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    private function entero(?string $valor, int $defecto, string $campo): int
    {
        $valor = $this->limpio($valor);

        if ($valor === null) {
            return $defecto;
        }

        if (preg_match('/^\d+$/', $valor) !== 1) {
            throw new PeticionInvalida(sprintf(
                'El parametro "%s" debe ser un entero no negativo.',
                $campo
            ));
        }

        return (int) $valor;
    }

    /**
     * UUID del documento ya armado. Se lee del XML porque la DIAN lo exige
     * igual en la cabecera y en las extensiones, y el modelo lo calcula.
     */
    private function uuidDe(string $xml): string
    {
        if (preg_match('#<cbc:UUID>([0-9A-Fa-f]+)</cbc:UUID>#', $xml, $coincidencias) === 1) {
            return strtoupper($coincidencias[1]);
        }

        return '';
    }
}

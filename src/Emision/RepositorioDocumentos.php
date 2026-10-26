<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Emision;

use InheritDevs\Dian\Excepcion\DocumentoDuplicado;
use InheritDevs\Dian\Excepcion\DocumentoNoEncontrado;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Registro de documentos emitidos, sobre SQLite.
 *
 * Guarda el XML original sin tocarlo y un resumen de los totales en centavos.
 * La numeracion es la clave primaria: la DIAN no admite dos documentos con el
 * mismo numero y el registro lo impone desde el esquema, no desde el codigo.
 */
final class RepositorioDocumentos
{
    private const TABLA = 'documentos';

    /** Filas que devuelve el listado cuando no se pide otra cosa. */
    public const LIMITE_POR_DEFECTO = 20;

    /** Tope de filas por pagina, para que nadie pida la tabla entera. */
    public const LIMITE_MAXIMO = 100;

    private function __construct(private readonly PDO $conexion)
    {
        $this->conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->crearEsquema();
    }

    /**
     * Abre el registro en un archivo, creando el directorio si hace falta.
     */
    public static function enArchivo(string $ruta): self
    {
        $directorio = dirname($ruta);

        if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new RuntimeException(sprintf(
                'No se pudo crear el directorio "%s" donde va la base de datos.',
                $directorio
            ));
        }

        return new self(new PDO('sqlite:' . $ruta));
    }

    /**
     * Registro en memoria, para pruebas y para corridas sin persistencia.
     */
    public static function enMemoria(): self
    {
        return new self(new PDO('sqlite::memory:'));
    }

    /**
     * Guarda un documento nuevo.
     *
     * @throws DocumentoDuplicado Si ya hay un documento con ese numero.
     */
    public function guardar(DocumentoEmitido $documento): void
    {
        $sql = sprintf(
            'INSERT INTO %s (numero, tipo, fecha_emision, emisor_nit, adquirente_nit, uuid, xml,
                subtotal, iva, total, retenciones, a_pagar, estado, motivo_anulacion, anulado_en)
             VALUES (:numero, :tipo, :fecha, :emisor, :adquirente, :uuid, :xml,
                :subtotal, :iva, :total, :retenciones, :a_pagar, :estado, :motivo, :anulado)',
            self::TABLA
        );

        try {
            $sentencia = $this->conexion->prepare($sql);
            $sentencia->execute([
                ':numero' => $documento->numero(),
                ':tipo' => $documento->tipo(),
                ':fecha' => $documento->fechaEmision(),
                ':emisor' => $documento->emisorNit(),
                ':adquirente' => $documento->adquirenteNit(),
                ':uuid' => $documento->uuid(),
                ':xml' => $documento->xml(),
                ':subtotal' => $documento->subtotalCentavos(),
                ':iva' => $documento->ivaCentavos(),
                ':total' => $documento->totalCentavos(),
                ':retenciones' => $documento->retencionesCentavos(),
                ':a_pagar' => $documento->aPagarCentavos(),
                ':estado' => $documento->estado(),
                ':motivo' => $documento->motivoAnulacion(),
                ':anulado' => $documento->anuladoEn(),
            ]);
        } catch (PDOException $excepcion) {
            if ($this->esClaveDuplicada($excepcion)) {
                throw new DocumentoDuplicado($documento->numero());
            }

            throw $excepcion;
        }
    }

    /**
     * Documento por numero.
     *
     * @throws DocumentoNoEncontrado Si no existe.
     */
    public function buscar(string $numero): DocumentoEmitido
    {
        $sentencia = $this->conexion->prepare(
            sprintf('SELECT * FROM %s WHERE numero = :numero', self::TABLA)
        );
        $sentencia->execute([':numero' => $numero]);
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            throw new DocumentoNoEncontrado($numero);
        }

        return $this->armar($fila);
    }

    /**
     * Indica si el numero ya esta usado.
     */
    public function existe(string $numero): bool
    {
        $sentencia = $this->conexion->prepare(
            sprintf('SELECT 1 FROM %s WHERE numero = :numero', self::TABLA)
        );
        $sentencia->execute([':numero' => $numero]);

        return $sentencia->fetchColumn() !== false;
    }

    /**
     * Reemplaza el documento por su version anulada.
     *
     * @throws DocumentoNoEncontrado Si no existe.
     */
    public function actualizar(DocumentoEmitido $documento): void
    {
        $sql = sprintf(
            'UPDATE %s SET estado = :estado, motivo_anulacion = :motivo, anulado_en = :anulado
             WHERE numero = :numero',
            self::TABLA
        );

        $sentencia = $this->conexion->prepare($sql);
        $sentencia->execute([
            ':estado' => $documento->estado(),
            ':motivo' => $documento->motivoAnulacion(),
            ':anulado' => $documento->anuladoEn(),
            ':numero' => $documento->numero(),
        ]);

        if ($sentencia->rowCount() === 0) {
            throw new DocumentoNoEncontrado($documento->numero());
        }
    }

    /**
     * Listado paginado, del mas reciente al mas antiguo.
     *
     * @param array{tipo?: string, estado?: string, emisor?: string, desde?: string, hasta?: string} $filtros
     *
     * @return array{documentos: list<DocumentoEmitido>, total: int, limite: int, desplazamiento: int}
     */
    public function listar(array $filtros = [], int $limite = self::LIMITE_POR_DEFECTO, int $desplazamiento = 0): array
    {
        $condiciones = [];
        $parametros = [];

        if (($filtros['tipo'] ?? '') !== '') {
            $condiciones[] = 'tipo = :tipo';
            $parametros[':tipo'] = $filtros['tipo'];
        }

        if (($filtros['estado'] ?? '') !== '') {
            $condiciones[] = 'estado = :estado';
            $parametros[':estado'] = $filtros['estado'];
        }

        if (($filtros['emisor'] ?? '') !== '') {
            $condiciones[] = 'emisor_nit = :emisor';
            $parametros[':emisor'] = $filtros['emisor'];
        }

        if (($filtros['desde'] ?? '') !== '') {
            $condiciones[] = 'fecha_emision >= :desde';
            $parametros[':desde'] = $filtros['desde'];
        }

        if (($filtros['hasta'] ?? '') !== '') {
            $condiciones[] = 'fecha_emision <= :hasta';
            $parametros[':hasta'] = $filtros['hasta'];
        }

        $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        $conteo = $this->conexion->prepare(sprintf('SELECT COUNT(*) FROM %s%s', self::TABLA, $donde));
        $conteo->execute($parametros);
        $total = (int) $conteo->fetchColumn();

        $limite = max(1, min(self::LIMITE_MAXIMO, $limite));
        $desplazamiento = max(0, $desplazamiento);

        $sentencia = $this->conexion->prepare(sprintf(
            'SELECT * FROM %s%s ORDER BY fecha_emision DESC, numero DESC LIMIT %d OFFSET %d',
            self::TABLA,
            $donde,
            $limite,
            $desplazamiento
        ));
        $sentencia->execute($parametros);

        $documentos = [];

        foreach ($sentencia->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $documentos[] = $this->armar($fila);
        }

        return [
            'documentos' => $documentos,
            'total' => $total,
            'limite' => $limite,
            'desplazamiento' => $desplazamiento,
        ];
    }

    /**
     * Cantidad de documentos guardados.
     */
    public function contar(): int
    {
        return (int) $this->conexion->query(sprintf('SELECT COUNT(*) FROM %s', self::TABLA))->fetchColumn();
    }

    /**
     * @param array<string, mixed> $fila
     */
    private function armar(array $fila): DocumentoEmitido
    {
        return new DocumentoEmitido(
            (string) $fila['numero'],
            (string) $fila['tipo'],
            (string) $fila['fecha_emision'],
            (string) $fila['emisor_nit'],
            (string) $fila['adquirente_nit'],
            (string) $fila['uuid'],
            (string) $fila['xml'],
            (int) $fila['subtotal'],
            (int) $fila['iva'],
            (int) $fila['total'],
            (int) $fila['retenciones'],
            (int) $fila['a_pagar'],
            (string) $fila['estado'],
            $fila['motivo_anulacion'] === null ? null : (string) $fila['motivo_anulacion'],
            $fila['anulado_en'] === null ? null : (string) $fila['anulado_en'],
        );
    }

    private function crearEsquema(): void
    {
        $this->conexion->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                numero TEXT PRIMARY KEY,
                tipo TEXT NOT NULL,
                fecha_emision TEXT NOT NULL,
                emisor_nit TEXT NOT NULL,
                adquirente_nit TEXT NOT NULL,
                uuid TEXT NOT NULL,
                xml TEXT NOT NULL,
                subtotal INTEGER NOT NULL,
                iva INTEGER NOT NULL,
                total INTEGER NOT NULL,
                retenciones INTEGER NOT NULL,
                a_pagar INTEGER NOT NULL,
                estado TEXT NOT NULL,
                motivo_anulacion TEXT,
                anulado_en TEXT
            )',
            self::TABLA
        ));

        $this->conexion->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS idx_%1$s_fecha ON %1$s (fecha_emision)',
            self::TABLA
        ));
        $this->conexion->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS idx_%1$s_estado ON %1$s (estado)',
            self::TABLA
        ));
        $this->conexion->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS idx_%1$s_emisor ON %1$s (emisor_nit)',
            self::TABLA
        ));
    }

    private function esClaveDuplicada(PDOException $excepcion): bool
    {
        return $excepcion->getCode() === '23000'
            || str_contains($excepcion->getMessage(), 'UNIQUE constraint failed');
    }
}

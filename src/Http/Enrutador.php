<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Http;

use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Emision\DocumentoEmitido;
use InheritDevs\Dian\Emision\ServicioDocumentos;
use InheritDevs\Dian\Excepcion\DocumentoDuplicado;
use InheritDevs\Dian\Excepcion\DocumentoInvalido;
use InheritDevs\Dian\Excepcion\DocumentoNoEncontrado;
use InheritDevs\Dian\Excepcion\PeticionInvalida;
use InheritDevs\Dian\Excepcion\RequisitoNoCumplido;
use InheritDevs\Dian\Servicio;
use Throwable;

/**
 * Enrutador de la API.
 *
 * Rutas:
 *   GET  /                       estado del servicio
 *   POST /documentos             emite un documento
 *   GET  /documentos             lista documentos con filtros y paginacion
 *   GET  /documentos/{numero}    consulta un documento
 *   POST /documentos/{numero}/anular  anula un documento
 *
 * El enrutador no escribe nada por si mismo: devuelve una `Respuesta` y el
 * punto de entrada la publica. Eso permite probar cada ruta sin servidor.
 */
final class Enrutador
{
    public function __construct(private readonly ServicioDocumentos $servicio)
    {
    }

    /**
     * Atiende una peticion y devuelve la respuesta.
     */
    public function atender(Solicitud $solicitud): Respuesta
    {
        try {
            return $this->despachar($solicitud);
        } catch (PeticionInvalida $excepcion) {
            return Respuesta::error(422, $this->cuerpoError(
                'peticion_invalida',
                $excepcion->getMessage(),
                $excepcion->campos()
            ));
        } catch (DocumentoInvalido $excepcion) {
            return Respuesta::error(422, $this->cuerpoError('documento_invalido', $excepcion->getMessage()));
        } catch (DocumentoNoEncontrado $excepcion) {
            return Respuesta::error(404, $this->cuerpoError('no_encontrado', $excepcion->getMessage()));
        } catch (DocumentoDuplicado $excepcion) {
            return Respuesta::error(409, $this->cuerpoError('documento_duplicado', $excepcion->getMessage()));
        } catch (RequisitoNoCumplido $excepcion) {
            return Respuesta::error(500, $this->cuerpoError('entorno', $excepcion->getMessage()));
        } catch (Throwable $excepcion) {
            return Respuesta::error(500, $this->cuerpoError(
                'error_interno',
                'El servicio no pudo completar la operacion.',
                ['detalle' => $excepcion->getMessage()]
            ));
        }
    }

    private function despachar(Solicitud $solicitud): Respuesta
    {
        $metodo = $solicitud->metodo();
        $ruta = $solicitud->ruta();

        if ($ruta === '/' || $ruta === '/estado') {
            if ($metodo !== 'GET') {
                return $this->noPermitido(['GET']);
            }

            return Respuesta::ok(Servicio::iniciar());
        }

        if ($ruta === '/documentos') {
            return match ($metodo) {
                'POST' => $this->emitir($solicitud),
                'GET' => $this->listar($solicitud),
                default => $this->noPermitido(['GET', 'POST']),
            };
        }

        if (preg_match('#^/documentos/([^/]+)$#', $ruta, $partes) === 1) {
            if ($metodo !== 'GET') {
                return $this->noPermitido(['GET']);
            }

            return $this->consultar(rawurldecode($partes[1]));
        }

        if (preg_match('#^/documentos/([^/]+)/anular$#', $ruta, $partes) === 1) {
            if ($metodo !== 'POST') {
                return $this->noPermitido(['POST']);
            }

            return $this->anular(rawurldecode($partes[1]), $solicitud);
        }

        return Respuesta::error(404, $this->cuerpoError(
            'ruta_no_encontrada',
            sprintf('La ruta "%s" no existe en el servicio.', $ruta)
        ));
    }

    private function emitir(Solicitud $solicitud): Respuesta
    {
        $resultado = $this->servicio->emitir($solicitud->datos());
        $documento = $resultado['documento'];

        return Respuesta::creado(
            [
                'documento' => $documento->resumen(),
                'validacion' => [
                    'valido' => true,
                    'errores' => [],
                ],
                'xml' => $documento->xml(),
            ],
            ['Location' => '/documentos/' . rawurlencode($documento->numero())]
        );
    }

    private function consultar(string $numero): Respuesta
    {
        $documento = $this->servicio->consultar($numero);

        return Respuesta::ok([
            'documento' => $documento->resumen(),
            'xml' => $documento->xml(),
        ]);
    }

    private function anular(string $numero, Solicitud $solicitud): Respuesta
    {
        $datos = $solicitud->datos();
        $motivo = $datos['motivo'] ?? null;

        if (!is_string($motivo)) {
            throw new PeticionInvalida(
                'La anulacion necesita un motivo en el cuerpo: {"motivo": "..."}.',
                ['motivo' => 'Falta el motivo de la anulacion.']
            );
        }

        $documento = $this->servicio->anular($numero, $motivo);

        return Respuesta::ok([
            'documento' => $documento->resumen(),
        ]);
    }

    private function listar(Solicitud $solicitud): Respuesta
    {
        $resultado = $this->servicio->listar([
            'tipo' => $solicitud->parametro('tipo'),
            'estado' => $solicitud->parametro('estado'),
            'emisor' => $solicitud->parametro('emisor'),
            'desde' => $solicitud->parametro('desde'),
            'hasta' => $solicitud->parametro('hasta'),
            'limite' => $solicitud->parametro('limite'),
            'desplazamiento' => $solicitud->parametro('desplazamiento'),
        ]);

        return Respuesta::ok([
            'total' => $resultado['total'],
            'limite' => $resultado['limite'],
            'desplazamiento' => $resultado['desplazamiento'],
            'documentos' => $resultado['documentos'],
        ]);
    }

    /**
     * @param list<string> $permitidos
     */
    private function noPermitido(array $permitidos): Respuesta
    {
        return new Respuesta(
            405,
            $this->cuerpoError(
                'metodo_no_permitido',
                sprintf('Este recurso solo admite %s.', implode(' y ', $permitidos))
            ),
            ['Allow' => implode(', ', $permitidos)]
        );
    }

    /**
     * @param array<string, string> $campos
     *
     * @return array<string, mixed>
     */
    private function cuerpoError(string $codigo, string $mensaje, array $campos = []): array
    {
        $cuerpo = [
            'error' => [
                'codigo' => $codigo,
                'mensaje' => $mensaje,
            ],
        ];

        if ($campos !== []) {
            $cuerpo['error']['campos'] = $campos;
        }

        return $cuerpo;
    }
}

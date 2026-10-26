<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Emision;

use DateTimeImmutable;
use InheritDevs\Dian\Calculo\Retencion;
use InheritDevs\Dian\Calculo\TipoRetencion;
use InheritDevs\Dian\Documento\DocumentoUbl;
use InheritDevs\Dian\Documento\Linea;
use InheritDevs\Dian\Documento\Resolucion;
use InheritDevs\Dian\Documento\Tercero;
use InheritDevs\Dian\Documento\TipoDocumento;
use InheritDevs\Dian\Excepcion\PeticionInvalida;
use Throwable;

/**
 * Arma un `DocumentoUbl` a partir del JSON que llega por la API.
 *
 * Toda la traduccion de la entrada vive aqui: el documento no sabe de JSON y
 * la capa HTTP no sabe de UBL. Los errores se acumulan por campo para que el
 * cliente reciba de una vez todo lo que tiene que corregir.
 */
final class FabricaDocumento
{
    /**
     * Monedas que el servicio acepta. La DIAN factura en pesos; las otras dos
     * estan porque el perfil UBL las admite en documentos de exportacion.
     *
     * @var list<string>
     */
    public const MONEDAS = ['COP', 'USD', 'EUR'];

    /**
     * @param array<string, mixed> $datos Cuerpo de la peticion ya decodificado.
     *
     * @throws PeticionInvalida Si falta un dato o no tiene la forma esperada.
     */
    public function construir(array $datos): DocumentoUbl
    {
        $problemas = [];

        $tipo = $this->tipo($datos, $problemas);
        $numero = $this->textoObligatorio($datos, 'numero', $problemas);
        $fecha = $this->fecha($datos, $problemas);
        $emisor = $this->tercero($datos, 'emisor', $problemas);
        $adquirente = $this->tercero($datos, 'adquirente', $problemas);
        $lineas = $this->lineas($datos, $problemas);
        $resolucion = $this->resolucion($datos, $problemas);
        $retenciones = $this->retenciones($datos, $problemas);
        $ambiente = $this->ambiente($datos, $problemas);
        $moneda = $this->moneda($datos, $problemas);

        if ($problemas !== []) {
            throw new PeticionInvalida($this->resumenProblemas($problemas), $problemas);
        }

        /** @var TipoDocumento $tipo */
        /** @var DateTimeImmutable $fecha */
        /** @var Tercero $emisor */
        /** @var Tercero $adquirente */
        /** @var list<Linea> $lineas */
        /** @var Resolucion $resolucion */
        /** @var list<Retencion> $retenciones */

        try {
            return new DocumentoUbl(
                $tipo,
                $numero,
                $fecha,
                $emisor,
                $adquirente,
                $lineas,
                $resolucion,
                $ambiente,
                $moneda,
                $this->textoOpcional($datos, 'documentoAjustado'),
                $this->textoOpcional($datos, 'motivoAjuste'),
                $this->textoOpcional($datos, 'motivoCodigo'),
                null,
                $retenciones,
            );
        } catch (Throwable $excepcion) {
            throw new PeticionInvalida(
                sprintf('El documento no es valido: %s', $excepcion->getMessage())
            );
        }
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function tipo(array $datos, array &$problemas): ?TipoDocumento
    {
        $valor = $datos['tipo'] ?? TipoDocumento::Factura->value;

        if (!is_string($valor)) {
            $problemas['tipo'] = 'El tipo debe ser texto: factura, nota-credito o nota-debito.';

            return null;
        }

        $tipo = TipoDocumento::tryFrom(trim($valor));

        if ($tipo === null) {
            $problemas['tipo'] = sprintf(
                'El tipo "%s" no existe. Valores validos: %s.',
                $valor,
                implode(', ', array_column(TipoDocumento::cases(), 'value'))
            );
        }

        return $tipo;
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function fecha(array $datos, array &$problemas): ?DateTimeImmutable
    {
        $valor = $datos['fecha'] ?? $datos['fechaEmision'] ?? null;

        if ($valor === null || $valor === '') {
            $problemas['fecha'] = 'Falta la fecha y hora de emision (formato AAAA-MM-DD HH:MM:SS).';

            return null;
        }

        if (!is_string($valor)) {
            $problemas['fecha'] = 'La fecha de emision debe venir como texto.';

            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($valor));
        $error = DateTimeImmutable::getLastErrors();

        if ($fecha === false || ($error !== false && ($error['warning_count'] > 0 || $error['error_count'] > 0))) {
            $problemas['fecha'] = sprintf(
                'La fecha "%s" no tiene el formato AAAA-MM-DD HH:MM:SS.',
                $valor
            );

            return null;
        }

        return $fecha;
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function tercero(array $datos, string $campo, array &$problemas): ?Tercero
    {
        $valor = $datos[$campo] ?? null;

        if (!is_array($valor) || array_is_list($valor)) {
            $problemas[$campo] = sprintf('Falta el objeto "%s" con los datos del sujeto.', $campo);

            return null;
        }

        $nit = $valor['nit'] ?? $valor['identificacion'] ?? null;
        $razonSocial = $valor['razonSocial'] ?? $valor['nombre'] ?? null;
        $regimen = $valor['regimen'] ?? null;

        if (!is_string($nit) || trim($nit) === '') {
            $problemas[$campo . '.nit'] = 'Falta el NIT del sujeto.';
        }

        if (!is_string($razonSocial) || trim($razonSocial) === '') {
            $problemas[$campo . '.razonSocial'] = 'Falta la razon social del sujeto.';
        }

        if (!is_string($regimen) || trim($regimen) === '') {
            $problemas[$campo . '.regimen'] = 'Falta el regimen tributario del sujeto.';
        }

        if (isset($problemas[$campo . '.nit']) || isset($problemas[$campo . '.razonSocial']) || isset($problemas[$campo . '.regimen'])) {
            return null;
        }

        try {
            return new Tercero(
                (string) $nit,
                (string) $razonSocial,
                (string) $regimen,
                $this->textoOpcional($valor, 'tipoIdentificacion') ?? Tercero::TIPO_NIT,
                $this->textoOpcional($valor, 'direccion') ?? '',
                $this->textoOpcional($valor, 'ciudad') ?? '',
                $this->textoOpcional($valor, 'departamento') ?? '',
                $this->textoOpcional($valor, 'correo') ?? '',
            );
        } catch (Throwable $excepcion) {
            $problemas[$campo] = $excepcion->getMessage();

            return null;
        }
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     *
     * @return list<Linea>|null
     */
    private function lineas(array $datos, array &$problemas): ?array
    {
        $valor = $datos['lineas'] ?? null;

        if (!is_array($valor) || $valor === []) {
            $problemas['lineas'] = 'El documento necesita al menos una linea.';

            return null;
        }

        $lineas = [];

        foreach ($valor as $indice => $linea) {
            $clave = sprintf('lineas.%s', $indice);

            if (!is_array($linea) || array_is_list($linea)) {
                $problemas[$clave] = 'Cada linea debe ser un objeto.';

                continue;
            }

            $descripcion = $this->textoOpcional($linea, 'descripcion');

            if ($descripcion === null) {
                $problemas[$clave . '.descripcion'] = 'Falta la descripcion de la linea.';

                continue;
            }

            $cantidad = $linea['cantidad'] ?? null;

            if (!is_int($cantidad) && !is_float($cantidad) && !(is_string($cantidad) && is_numeric($cantidad))) {
                $problemas[$clave . '.cantidad'] = 'La cantidad de la linea debe ser un numero.';

                continue;
            }

            $precio = $linea['precioUnitario'] ?? $linea['precio'] ?? null;
            $centavos = $this->centavos($precio);

            if ($centavos === null) {
                $problemas[$clave . '.precioUnitario'] = 'El precio unitario debe ser un numero mayor o igual a cero.';

                continue;
            }

            $tarifa = $linea['iva'] ?? $linea['tarifaIva'] ?? 19;

            if (!is_int($tarifa) && !(is_string($tarifa) && ctype_digit($tarifa))) {
                $problemas[$clave . '.iva'] = 'La tarifa de IVA debe ser un numero entero.';

                continue;
            }

            try {
                $lineas[] = new Linea(
                    $descripcion,
                    (float) $cantidad,
                    $centavos,
                    (int) $tarifa,
                    $this->textoOpcional($linea, 'unidad') ?? $this->textoOpcional($linea, 'unidadMedida') ?? '94',
                    $this->textoOpcional($linea, 'codigo') ?? '',
                );
            } catch (Throwable $excepcion) {
                $problemas[$clave] = $excepcion->getMessage();
            }
        }

        if ($problemas !== [] && $lineas === []) {
            return null;
        }

        return $lineas;
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function resolucion(array $datos, array &$problemas): ?Resolucion
    {
        $valor = $datos['resolucion'] ?? null;

        if (!is_array($valor) || array_is_list($valor)) {
            $problemas['resolucion'] = 'Falta el objeto "resolucion" que autoriza la numeracion.';

            return null;
        }

        $numero = $this->textoOpcional($valor, 'numero');
        $prefijo = $this->textoOpcional($valor, 'prefijo');
        $clave = $this->textoOpcional($valor, 'claveTecnica');
        $desde = $valor['desde'] ?? null;
        $hasta = $valor['hasta'] ?? null;
        $vigenciaDesde = $this->fechaSimple($valor['vigenciaDesde'] ?? null, 'resolucion.vigenciaDesde', $problemas);
        $vigenciaHasta = $this->fechaSimple($valor['vigenciaHasta'] ?? null, 'resolucion.vigenciaHasta', $problemas);

        if ($numero === null) {
            $problemas['resolucion.numero'] = 'Falta el numero de la resolucion.';
        }

        if ($clave === null) {
            $problemas['resolucion.claveTecnica'] = 'Falta la clave tecnica de la resolucion.';
        }

        if (!is_int($desde) && !(is_string($desde) && ctype_digit($desde))) {
            $problemas['resolucion.desde'] = 'El primer numero del rango debe ser un entero.';
        }

        if (!is_int($hasta) && !(is_string($hasta) && ctype_digit($hasta))) {
            $problemas['resolucion.hasta'] = 'El ultimo numero del rango debe ser un entero.';
        }

        if ($problemas !== []) {
            return null;
        }

        try {
            return new Resolucion(
                (string) $numero,
                (string) ($prefijo ?? ''),
                (int) $desde,
                (int) $hasta,
                $vigenciaDesde ?? new DateTimeImmutable(),
                $vigenciaHasta ?? new DateTimeImmutable(),
                (string) $clave,
            );
        } catch (Throwable $excepcion) {
            $problemas['resolucion'] = $excepcion->getMessage();

            return null;
        }
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     *
     * @return list<Retencion>
     */
    private function retenciones(array $datos, array &$problemas): array
    {
        $valor = $datos['retenciones'] ?? [];

        if ($valor === null) {
            return [];
        }

        if (!is_array($valor) || !array_is_list($valor)) {
            $problemas['retenciones'] = 'Las retenciones deben venir como una lista.';

            return [];
        }

        $retenciones = [];

        foreach ($valor as $indice => $retencion) {
            $clave = sprintf('retenciones.%s', $indice);

            if (!is_array($retencion)) {
                $problemas[$clave] = 'Cada retencion debe ser un objeto con tipo y porcentaje.';

                continue;
            }

            $tipo = TipoRetencion::tryFrom((string) ($retencion['tipo'] ?? ''));
            $porcentaje = $retencion['porcentaje'] ?? $retencion['tarifa'] ?? null;

            if ($tipo === null) {
                $problemas[$clave . '.tipo'] = sprintf(
                    'El tipo de retencion debe ser uno de: %s.',
                    implode(', ', array_column(TipoRetencion::cases(), 'value'))
                );

                continue;
            }

            if (!is_int($porcentaje) && !is_float($porcentaje) && !(is_string($porcentaje) && is_numeric($porcentaje))) {
                $problemas[$clave . '.porcentaje'] = 'El porcentaje de la retencion debe ser un numero.';

                continue;
            }

            try {
                $retenciones[] = new Retencion($tipo, (float) $porcentaje);
            } catch (Throwable $excepcion) {
                $problemas[$clave] = $excepcion->getMessage();
            }
        }

        return $retenciones;
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function ambiente(array $datos, array &$problemas): string
    {
        $valor = $datos['ambiente'] ?? DocumentoUbl::AMBIENTE_PRODUCCION;

        if (!is_string($valor) || !in_array($valor, [DocumentoUbl::AMBIENTE_PRODUCCION, DocumentoUbl::AMBIENTE_PRUEBAS], true)) {
            $problemas['ambiente'] = sprintf(
                'El ambiente debe ser %s (produccion) o %s (pruebas).',
                DocumentoUbl::AMBIENTE_PRODUCCION,
                DocumentoUbl::AMBIENTE_PRUEBAS
            );

            return DocumentoUbl::AMBIENTE_PRODUCCION;
        }

        return $valor;
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function moneda(array $datos, array &$problemas): string
    {
        $valor = $datos['moneda'] ?? 'COP';

        if (!is_string($valor) || !in_array($valor, self::MONEDAS, true)) {
            $problemas['moneda'] = sprintf(
                'La moneda "%s" no esta admitida. Valores validos: %s.',
                is_string($valor) ? $valor : '',
                implode(', ', self::MONEDAS)
            );

            return 'COP';
        }

        return $valor;
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function fechaSimple(mixed $valor, string $campo, array &$problemas): ?DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            $problemas[$campo] = 'Falta la fecha en formato AAAA-MM-DD.';

            return null;
        }

        if (!is_string($valor)) {
            $problemas[$campo] = 'La fecha debe venir como texto en formato AAAA-MM-DD.';

            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', trim($valor));

        if ($fecha === false) {
            $problemas[$campo] = sprintf('La fecha "%s" no tiene el formato AAAA-MM-DD.', $valor);

            return null;
        }

        return $fecha;
    }

    /**
     * Convierte un valor monetario escrito como numero o texto a centavos.
     *
     * Acepta 125000, 125000.00 y "125.000,00", que es como se escribe un valor
     * en Colombia. Los dos ultimos digitos siempre son centavos.
     */
    private function centavos(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 0 ? $valor * 100 : null;
        }

        if (is_float($valor)) {
            return $valor >= 0 ? (int) round($valor * 100) : null;
        }

        if (!is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        if ($texto === '') {
            return null;
        }

        $texto = str_replace([' ', '$'], '', $texto);

        // Si trae coma decimal y puntos de miles, se normaliza al formato
        // ingles antes de convertirlo: 1.250.000,50 queda 1250000.50.
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        } else {
            $texto = str_replace(',', '.', $texto);
        }

        if (!is_numeric($texto)) {
            return null;
        }

        return (int) round((float) $texto * 100);
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $problemas
     */
    private function textoObligatorio(array $datos, string $campo, array &$problemas): string
    {
        $valor = $this->textoOpcional($datos, $campo);

        if ($valor === null) {
            $problemas[$campo] = sprintf('Falta el campo "%s".', $campo);

            return '';
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function textoOpcional(array $datos, string $campo): ?string
    {
        $valor = $datos[$campo] ?? null;

        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        return trim($valor);
    }

    /**
     * @param array<string, string> $problemas
     */
    private function resumenProblemas(array $problemas): string
    {
        $primero = array_key_first($problemas);

        return sprintf(
            'La peticion tiene %d dato(s) por corregir. Primero: %s',
            count($problemas),
            $problemas[$primero] ?? ''
        );
    }
}

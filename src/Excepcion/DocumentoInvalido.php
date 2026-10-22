<?php

declare(strict_types=1);

namespace InheritDevs\Dian\Excepcion;

use InvalidArgumentException;

/**
 * Se lanza cuando los datos con los que se quiere armar un documento UBL no
 * son coherentes. El mensaje esta escrito para que lo lea quien consume el
 * servicio, no quien programa el servicio.
 */
final class DocumentoInvalido extends InvalidArgumentException
{
}

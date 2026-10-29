<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * Excepción base del SDK. Todas las excepciones lanzadas por el SDK la extienden,
 * por lo que basta con capturar esta clase para manejar cualquier error.
 */
class AfipException extends \RuntimeException
{
}

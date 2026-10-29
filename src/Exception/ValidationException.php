<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

use RoyaltyFusion\Afip\Validator\ValidationResult;

/**
 * El comprobante no superó las validaciones locales y NO fue enviado a ARCA.
 */
class ValidationException extends AfipException
{
    public function __construct(private readonly ValidationResult $result)
    {
        $lines = [];
        foreach ($result->getErrors() as $error) {
            $lines[] = (string) $error;
        }
        parent::__construct("El comprobante tiene errores de validación y no fue enviado:\n- " . implode("\n- ", $lines));
    }

    public function getResult(): ValidationResult
    {
        return $this->result;
    }
}

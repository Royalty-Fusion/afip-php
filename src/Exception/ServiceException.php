<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

use RoyaltyFusion\Afip\Model\Response\Message;

/**
 * El web service respondió con errores (nodo Errors / FEXErr) en una operación
 * que no es de autorización (consultas, parámetros, etc.).
 */
class ServiceException extends AfipException
{
    /**
     * @param Message[] $errors
     */
    public function __construct(string $operation, private readonly array $errors)
    {
        $parts = array_map(static fn (Message $m): string => (string) $m, $errors);
        parent::__construct(sprintf('%s: %s', $operation, implode(' | ', $parts) ?: 'error desconocido'));
    }

    /**
     * @return Message[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrorCode(int $code): bool
    {
        foreach ($this->errors as $error) {
            if ($error->getCode() === $code) {
                return true;
            }
        }

        return false;
    }
}

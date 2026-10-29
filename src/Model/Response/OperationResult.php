<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

/**
 * Resultado de una operación que no autoriza comprobantes (FCE: aceptar, rechazar,
 * informar cancelación; constatación de comprobantes, etc.).
 */
final class OperationResult
{
    /**
     * @param Message[] $observaciones
     * @param Message[] $errors
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private readonly string $resultado,
        private readonly array $observaciones,
        private readonly array $errors,
        private readonly array $raw = []
    ) {
    }

    /**
     * A = aprobado, O = aprobado con observaciones, R = rechazado.
     */
    public function getResultado(): string
    {
        return $this->resultado;
    }

    public function isSuccess(): bool
    {
        return in_array($this->resultado, ['A', 'O'], true) && $this->errors === [];
    }

    /**
     * @return Message[]
     */
    public function getObservaciones(): array
    {
        return $this->observaciones;
    }

    /**
     * @return Message[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorMessage(): string
    {
        return implode(' | ', array_map(static fn (Message $m): string => (string) $m, [...$this->errors, ...$this->observaciones]));
    }

    public function hasCode(int $code): bool
    {
        foreach ([...$this->errors, ...$this->observaciones] as $message) {
            if ($message->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
}

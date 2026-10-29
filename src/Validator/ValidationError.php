<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Validator;

/**
 * Error (o advertencia) detectado por la validación local.
 */
final class ValidationError
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param string   $code     código interno estable (ej. "CBTE_TIPO_INVALIDO")
     * @param string   $field    campo afectado (ej. "ivas[0].importe")
     * @param int|null $afipCode código de error equivalente de ARCA, cuando se conoce
     */
    public function __construct(
        private readonly string $code,
        private readonly string $field,
        private readonly string $message,
        private readonly string $level = self::ERROR,
        private readonly ?int $afipCode = null
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    public function getAfipCode(): ?int
    {
        return $this->afipCode;
    }

    /**
     * @return array{code: string, field: string, message: string, level: string, afipCode: ?int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'message' => $this->message,
            'level' => $this->level,
            'afipCode' => $this->afipCode,
        ];
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s: %s', $this->code, $this->field, $this->message);
    }
}

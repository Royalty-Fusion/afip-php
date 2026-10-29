<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Validator;

/**
 * Resultado de la validación local.
 */
final class ValidationResult
{
    /** @var ValidationError[] */
    private array $items = [];

    public function add(ValidationError $error): void
    {
        $this->items[] = $error;
    }

    public function error(string $code, string $field, string $message, ?int $afipCode = null): void
    {
        $this->add(new ValidationError($code, $field, $message, ValidationError::ERROR, $afipCode));
    }

    public function warning(string $code, string $field, string $message, ?int $afipCode = null): void
    {
        $this->add(new ValidationError($code, $field, $message, ValidationError::WARNING, $afipCode));
    }

    public function merge(ValidationResult $other, string $fieldPrefix = ''): void
    {
        foreach ($other->all() as $item) {
            $this->add($fieldPrefix === '' ? $item : new ValidationError(
                $item->getCode(),
                $fieldPrefix . $item->getField(),
                $item->getMessage(),
                $item->getLevel(),
                $item->getAfipCode()
            ));
        }
    }

    public function isValid(): bool
    {
        return $this->getErrors() === [];
    }

    /**
     * @return ValidationError[]
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return ValidationError[]
     */
    public function getErrors(): array
    {
        return array_values(array_filter($this->items, static fn (ValidationError $e): bool => $e->isError()));
    }

    /**
     * @return ValidationError[]
     */
    public function getWarnings(): array
    {
        return array_values(array_filter($this->items, static fn (ValidationError $e): bool => !$e->isError()));
    }

    public function hasCode(string $code): bool
    {
        foreach ($this->items as $item) {
            if ($item->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (ValidationError $e): array => $e->toArray(), $this->items);
    }
}

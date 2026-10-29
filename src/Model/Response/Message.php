<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

/**
 * Error, observación o evento devuelto por ARCA (Code / Msg).
 */
final class Message
{
    public function __construct(private readonly int $code, private readonly string $message)
    {
    }

    /**
     * @param mixed $node nodo {Code, Msg} (o {ErrCode, ErrMsg} / {EventCode, EventMsg} en WSFEX)
     */
    public static function fromArray(mixed $node): self
    {
        if (!is_array($node)) {
            return new self(0, (string) $node);
        }
        $code = $node['Code'] ?? $node['ErrCode'] ?? $node['EventCode'] ?? $node['codigo'] ?? 0;
        $msg = $node['Msg'] ?? $node['ErrMsg'] ?? $node['EventMsg'] ?? $node['descripcion'] ?? '';

        return new self((int) $code, is_string($msg) ? trim($msg) : '');
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array{code: int, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }

    public function __toString(): string
    {
        return sprintf('%d: %s', $this->code, $this->message);
    }
}

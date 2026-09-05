<?php

declare(strict_types=1);

namespace App;

/**
 * Exact money arithmetic in integer cents — avoids the binary
 * floating-point drift that repeated multiplication/summation of currency
 * floats produces (docs/ROADMAP.md's v1.7.0 "Money representation").
 *
 * fromReais() is the only place a float/string reais amount is rounded into
 * cents — done once, on entry, never repeated across an accumulation. Every
 * other operation is pure integer arithmetic.
 */
final class Money
{
    private function __construct(private readonly int $cents)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromReais(float|int|string $reais): self
    {
        // A non-numeric string previously became a silent (float) cast to
        // 0.0 — a bad price ("grátis") would create a real R$0,00 item with
        // no error (code review fix). A non-scalar (array/object) already
        // fails fast with a TypeError at the parameter boundary, unchanged.
        if (is_string($reais) && !is_numeric($reais)) {
            throw new \InvalidArgumentException("Valor monetário inválido: \"{$reais}\"");
        }
        return new self((int) round(((float) $reais) * 100));
    }

    public function plus(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function getCents(): int
    {
        return $this->cents;
    }

    /** Decimal reais value, for storage in a DECIMAL(10,2) column. */
    public function toReais(): float
    {
        return $this->cents / 100;
    }

    /** Brazilian-format string for display/printing, e.g. "19,90". */
    public function format(): string
    {
        return number_format($this->toReais(), 2, ',', '');
    }
}

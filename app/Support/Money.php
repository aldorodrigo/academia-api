<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Monto en la unidad mínima de la moneda (guaraníes enteros).
 */
final readonly class Money
{
    /** @var array<string, array{symbol: string, decimals: int}> */
    private const CURRENCIES = [
        'PYG' => ['symbol' => '₲', 'decimals' => 0],
    ];

    public function __construct(
        public int $amount,
        public string $currency = 'PYG',
    ) {
        if (! isset(self::CURRENCIES[$currency])) {
            throw new InvalidArgumentException("Moneda no soportada: {$currency}");
        }
    }

    public static function pyg(int $amount): self
    {
        return new self($amount, 'PYG');
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Porcentaje redondeado a la unidad entera más cercana.
     */
    public function percentage(float $percent): self
    {
        return new self((int) round($this->amount * $percent / 100), $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function format(): string
    {
        $config = self::CURRENCIES[$this->currency];
        $sign = $this->amount < 0 ? '-' : '';

        return $sign.$config['symbol'].' '.number_format(abs($this->amount), $config['decimals'], ',', '.');
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('No se pueden operar montos de distinta moneda.');
        }
    }
}

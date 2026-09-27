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

    /**
     * Monto en letras para el recibo: 150000 → "ciento cincuenta mil guaraníes".
     */
    public function inWords(): string
    {
        $words = $this->amount === 0 ? 'cero' : self::words(abs($this->amount));

        return ($this->amount < 0 ? 'menos ' : '').$words.' guaraníes';
    }

    private static function words(int $n): string
    {
        $units = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
            'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
            'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
        $tens = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];
        $hundreds = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
            6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

        return match (true) {
            $n < 30 => $units[$n],
            $n < 100 => $tens[intdiv($n, 10)].($n % 10 ? ' y '.$units[$n % 10] : ''),
            $n === 100 => 'cien',
            $n < 1000 => $hundreds[intdiv($n, 100)].($n % 100 ? ' '.self::words($n % 100) : ''),
            $n < 1_000_000 => (intdiv($n, 1000) === 1 ? 'mil' : self::apocope(self::words(intdiv($n, 1000))).' mil')
                .($n % 1000 ? ' '.self::words($n % 1000) : ''),
            default => (intdiv($n, 1_000_000) === 1 ? 'un millón' : self::apocope(self::words(intdiv($n, 1_000_000))).' millones')
                .($n % 1_000_000 ? ' '.self::words($n % 1_000_000) : ''),
        };
    }

    /**
     * "veintiuno mil" → "veintiún mil", "treinta y uno mil" → "treinta y un mil".
     */
    private static function apocope(string $words): string
    {
        return preg_replace(['/veintiuno$/u', '/uno$/u'], ['veintiún', 'un'], $words);
    }
}

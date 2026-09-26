<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GuardianRelationship: string implements HasLabel
{
    case Father = 'padre';
    case Mother = 'madre';
    case Guardian = 'tutor';
    case Grandparent = 'abuelo';
    case Other = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Father => 'Padre',
            self::Mother => 'Madre',
            self::Guardian => 'Tutor',
            self::Grandparent => 'Abuelo/a',
            self::Other => 'Otro',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * Acepta el valor o la etiqueta ("Madre", "madre"); lo desconocido queda como tutor.
     */
    public static function parse(?string $value): self
    {
        $value = mb_strtolower(trim((string) $value));

        return self::tryFrom($value)
            ?? collect(self::cases())->first(fn (self $case) => mb_strtolower($case->label()) === $value)
            ?? self::Guardian;
    }
}

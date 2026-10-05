<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GuardianRelationship: string implements HasLabel
{
    case Father = 'padre';
    case Mother = 'madre';
    case Guardian = 'tutor';
    case Grandparent = 'abuelo';
    case Grandmother = 'abuela';
    case Uncle = 'tio';
    case Aunt = 'tia';
    case Other = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Father => 'Padre',
            self::Mother => 'Madre',
            self::Guardian => 'Tutor/a',
            self::Grandparent => 'Abuelo',
            self::Grandmother => 'Abuela',
            self::Uncle => 'Tío',
            self::Aunt => 'Tía',
            self::Other => 'Otro',
        };
    }

    /**
     * El género del tutor que se deduce del parentesco (no se le pregunta): Madre, Abuela, Tía → femenino;
     * Padre, Abuelo, Tío → masculino; Tutor/a y Otro → sin especificar.
     */
    public function gender(): ?Gender
    {
        return match ($this) {
            self::Mother, self::Grandmother, self::Aunt => Gender::Female,
            self::Father, self::Grandparent, self::Uncle => Gender::Male,
            self::Guardian, self::Other => null,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * Acepta el valor o la etiqueta ("Madre", "madre"); lo desconocido queda como tutor.
     */
    public static function parse(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $value = mb_strtolower(trim((string) $value));

        return self::tryFrom($value)
            ?? collect(self::cases())->first(fn (self $case) => mb_strtolower($case->label()) === $value)
            ?? match ($value) {
                // Lo que se escribe en una planilla: "Abuelo/a" (la etiqueta de antes), "tía", "tío", "tutora".
                'abuelo/a', 'abuela/o' => self::Grandparent,
                'tía' => self::Aunt,
                'tío' => self::Uncle,
                'tutor', 'tutora' => self::Guardian,
                default => self::Guardian,
            };
    }
}

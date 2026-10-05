<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Club = 'club';
    case Academy = 'academy';
    case School = 'school';
    case ParentsAssociation = 'parents_association';

    public function label(): string
    {
        return match ($this) {
            self::Club => 'Club / asociación',
            self::Academy => 'Academia',
            self::School => 'Escuela / instituto',
            self::ParentsAssociation => 'Comisión de padres (ACE)',
        };
    }

    /**
     * Qué es, para los textos de la guía: "Configurá tu academia".
     */
    public function noun(): string
    {
        return match ($this) {
            self::Club => 'club',
            self::Academy => 'academia',
            self::School => 'escuela',
            self::ParentsAssociation => 'comisión',
        };
    }

    /**
     * "el club", "la academia".
     */
    public function withArticle(): string
    {
        return ($this === self::Club ? 'el ' : 'la ').$this->noun();
    }
}

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
}

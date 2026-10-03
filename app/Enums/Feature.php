<?php

namespace App\Enums;

/**
 * Módulos opcionales que cada organización activa o no.
 */
enum Feature: string
{
    case Board = 'board';
    case Fundraising = 'fundraising';
    case Apparel = 'apparel';
    case Tournaments = 'tournaments';
    case Evaluations = 'evaluations';
    case ElectronicInvoicing = 'electronic_invoicing';
    case PrivateLessons = 'private_lessons';

    public function label(): string
    {
        return match ($this) {
            self::Board => 'Comisión, actas y resoluciones',
            self::Fundraising => 'Rifas y recaudación',
            self::Apparel => 'Indumentaria',
            self::Tournaments => 'Torneos',
            self::Evaluations => 'Evaluaciones',
            self::ElectronicInvoicing => 'Factura electrónica (SIFEN)',
            self::PrivateLessons => 'Clases particulares (reservas y paquetes)',
        };
    }
}

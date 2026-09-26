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
}

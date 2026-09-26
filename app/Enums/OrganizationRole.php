<?php

namespace App\Enums;

use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Roles base de cada organización (spatie/permission, teams = organización).
 *
 * El super admin de la plataforma no es un rol: es users.is_super_admin.
 * Las descripciones mencionan funcionalidades futuras para dejar claro el
 * alcance de cada rol desde ahora (ver business-logic.md §2).
 */
enum OrganizationRole: string
{
    case Admin = 'admin';
    case President = 'presidente';
    case VicePresident = 'vicepresidente';
    case Secretary = 'secretario';
    case DeputySecretary = 'prosecretario';
    case Treasurer = 'tesorero';
    case DeputyTreasurer = 'protesorero';
    case Member = 'vocal';
    case Auditor = 'sindico';
    case Instructor = 'instructor';
    case Guardian = 'tutor';

    /**
     * Etiqueta de cualquier rol: los base según el enum, los creados a mano en
     * Shield a partir de su nombre.
     */
    public static function labelFor(string $name, ?Organization $organization = null): string
    {
        return self::tryFrom($name)?->label($organization) ?? Str::headline($name);
    }

    public function label(?Organization $organization = null): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::President => 'Presidente',
            self::VicePresident => 'Vicepresidente',
            self::Secretary => 'Secretario',
            self::DeputySecretary => 'Prosecretario',
            self::Treasurer => 'Tesorero',
            self::DeputyTreasurer => 'Protesorero',
            self::Member => 'Vocal',
            self::Auditor => 'Síndico',
            self::Instructor => $organization?->term('instructor') ?? Organization::DEFAULT_TERMINOLOGY['instructor'],
            self::Guardian => $organization?->term('guardian') ?? Organization::DEFAULT_TERMINOLOGY['guardian'],
        };
    }

    /**
     * Qué puede hacer en el sistema.
     */
    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Administra la organización: configuración, miembros, invitaciones, roles y permisos; académico, finanzas y avisos.',
            self::President => 'Ve todo. Aprueba resoluciones, becas y gastos sobre el umbral (con el tesorero). Firma actas.',
            self::VicePresident => 'Ve todo. Reemplaza al presidente cuando corresponde.',
            self::Secretary => 'Reuniones, actas, resoluciones, avisos y comunicados. Ve miembros y alumnos.',
            self::DeputySecretary => 'Asiste y suple al secretario, con sus mismas tareas.',
            self::Treasurer => 'Cuentas, tarifas, cuotas, becas y mora. Registra pagos, emite recibos, carga gastos, valida comprobantes y aprueba gastos sobre el umbral (con el presidente). Informes.',
            self::DeputyTreasurer => 'Suple al tesorero, salvo aprobar gastos sobre el umbral.',
            self::Member => 'Lee actas, resoluciones e informes. Vota en las reuniones.',
            self::Auditor => 'Controla a la comisión: lee todo lo financiero y el registro de actividad, sin modificar nada.',
            self::Instructor => 'Sus grupos: alumnos, asistencia, convocatorias y avisos al grupo. Ficha médica de sus alumnos (solo lectura).',
            self::Guardian => 'Sus hijos: ficha, inscripciones, estado de cuenta, recibos y avisos. Confirma asistencia y sube comprobantes.',
        };
    }

    /**
     * Cargo de comisión: exige mandato con fecha de fin.
     */
    public function isBoardPosition(): bool
    {
        return in_array($this, [
            self::President, self::VicePresident, self::Secretary, self::DeputySecretary,
            self::Treasurer, self::DeputyTreasurer, self::Member, self::Auditor,
        ], true);
    }
}

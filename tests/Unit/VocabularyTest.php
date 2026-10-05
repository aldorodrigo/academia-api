<?php

use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Support\Vocabulary;

describe('género de la palabra', function () {
    it('femeninas, masculinas y de género común', function (string $word, string $gender) {
        expect(Vocabulary::gender($word))->toBe($gender);
    })->with([
        ['Categoría', 'f'], ['Clase', 'f'], ['Sala', 'f'], ['Pileta', 'f'], ['Aula', 'f'], ['Cancha', 'f'],
        ['Actividad', 'f'], ['Disciplina', 'f'], ['Comisión', 'f'], ['academia', 'f'], ['escuela', 'f'],
        ['Grupo', 'm'], ['Nivel', 'm'], ['Espacio', 'm'], ['Deporte', 'm'], ['Taller', 'm'], ['Programa', 'm'],
        ['club', 'm'], ['Salón', 'm'], ['Jugador', 'm'], ['Técnico', 'm'], ['Tutor', 'm'], ['Encargado', 'm'],
        ['Atleta', 'c'], ['Deportista', 'c'], ['Estudiante', 'c'], ['Docente', 'c'], ['Responsable', 'c'],
        // La primera palabra manda.
        ['Grupo de entrenamiento', 'm'], ['Clase de natación', 'f'],
    ]);

    it('las de género común concuerdan en masculino (salvo una persona concreta)', function () {
        expect(Vocabulary::isFeminine('Atleta'))->toBeFalse()
            ->and(Vocabulary::the('Atleta'))->toBe('el atleta')
            ->and(Vocabulary::the('Atleta', person: Gender::Female))->toBe('la atleta')
            ->and(Vocabulary::to('Responsable', person: Gender::Female))->toBe('a la responsable')
            ->and(Vocabulary::gendered('Atleta', 'otro', 'otra'))->toBe('otro');
    });
});

describe('artículos y contracciones', function () {
    it('"el aula" pero "las aulas" y "esta aula"', function () {
        expect(Vocabulary::the('Aula'))->toBe('el aula')
            ->and(Vocabulary::a('Aula'))->toBe('un aula')
            ->and(Vocabulary::of('Aula'))->toBe('del aula')
            ->and(Vocabulary::the('Aula', plural: true))->toBe('las aulas')
            ->and(Vocabulary::gendered('Aula', 'este', 'esta'))->toBe('esta');
    });

    it('con Categoría y con Grupo', function () {
        expect(Vocabulary::the('Categoría'))->toBe('la categoría')
            ->and(Vocabulary::the('Grupo'))->toBe('el grupo')
            ->and(Vocabulary::a('Categoría'))->toBe('una categoría')
            ->and(Vocabulary::a('Grupo'))->toBe('un grupo')
            ->and(Vocabulary::of('Categoría'))->toBe('de la categoría')
            ->and(Vocabulary::of('Grupo'))->toBe('del grupo')
            ->and(Vocabulary::to('Técnico'))->toBe('al técnico')
            ->and(Vocabulary::to('Profesora'))->toBe('a la profesora')
            ->and(Vocabulary::the('Nivel', plural: true))->toBe('los niveles')
            ->and(Vocabulary::of('club'))->toBe('del club')
            ->and(Vocabulary::of('comisión'))->toBe('de la comisión');
    });
});

it('plurales', function (string $word, string $plural) {
    expect(Vocabulary::plural($word))->toBe($plural);
})->with([
    ['Categoría', 'Categorías'], ['Tutor', 'Tutores'], ['Nivel', 'Niveles'], ['Clase', 'Clases'], ['Salón', 'Salones'],
    ['Bailarín', 'Bailarines'], ['club', 'clubes'], ['Actividad', 'Actividades'], ['comisión', 'comisiones'],
    ['Grupo de entrenamiento', 'Grupos de entrenamiento'], ['Luz', 'Luces'], ['Joven', 'Jóvenes'],
]);

describe('formas de persona', function () {
    it('femenina y masculina', function (string $word, string $feminine, string $masculine) {
        expect(Vocabulary::feminine($word))->toBe($feminine)
            ->and(Vocabulary::masculine($word))->toBe($masculine);
    })->with([
        ['Jugador', 'Jugadora', 'Jugador'], ['Técnico', 'Técnica', 'Técnico'], ['Profesor', 'Profesora', 'Profesor'],
        ['Alumno', 'Alumna', 'Alumno'], ['Instructor', 'Instructora', 'Instructor'], ['Entrenador', 'Entrenadora', 'Entrenador'],
        ['Tutor', 'Tutora', 'Tutor'], ['Encargado', 'Encargada', 'Encargado'], ['Bailarín', 'Bailarina', 'Bailarín'],
        ['Alumna', 'Alumna', 'Alumno'], ['Profesora', 'Profesora', 'Profesor'],
        ['Atleta', 'Atleta', 'Atleta'], ['Responsable', 'Responsable', 'Responsable'], ['Estudiante', 'Estudiante', 'Estudiante'],
        ['Presidente', 'Presidenta', 'Presidente'], ['Profesor de baile', 'Profesora de baile', 'Profesor de baile'],
    ]);

    it('una persona: su forma si se sabe el género; si no, la palabra del club', function () {
        expect(Vocabulary::forPerson('Técnico', Gender::Female))->toBe('Técnica')
            ->and(Vocabulary::forPerson('Técnico', null))->toBe('Técnico')
            ->and(Vocabulary::forPerson('Profesora', Gender::Male))->toBe('Profesor')
            ->and(Vocabulary::forPerson('Profesora', null))->toBe('Profesora')
            // La forma que ajustó la organización gana sobre la regla.
            ->and(Vocabulary::forPerson('Coach', Gender::Female, 'Entrenadora'))->toBe('Entrenadora')
            ->and(Vocabulary::agree('Jugador', Gender::Female, 'inscripto', 'inscripta'))->toBe('inscripta')
            ->and(Vocabulary::agree('Jugador', null, 'inscripto', 'inscripta'))->toBe('inscripto');
    });

    it('un grupo: femenino solo si son todas mujeres', function () {
        expect(Vocabulary::forPeople('Jugador', [Gender::Female, Gender::Female]))->toBe('Jugadoras')
            ->and(Vocabulary::forPeople('Jugador', [Gender::Female, Gender::Male]))->toBe('Jugadores')
            ->and(Vocabulary::forPeople('Jugador', [Gender::Female, null]))->toBe('Jugadores')
            ->and(Vocabulary::forPeople('Alumna', [Gender::Female, null]))->toBe('Alumnas')
            ->and(Vocabulary::forPeople('Alumna', [Gender::Female, Gender::Male]))->toBe('Alumnos')
            ->and(Vocabulary::forPeople('Jugador', []))->toBe('Jugadores');
    });
});

it('el género del tutor sale del parentesco', function () {
    expect(GuardianRelationship::Mother->gender())->toBe(Gender::Female)
        ->and(GuardianRelationship::Grandmother->gender())->toBe(Gender::Female)
        ->and(GuardianRelationship::Aunt->gender())->toBe(Gender::Female)
        ->and(GuardianRelationship::Father->gender())->toBe(Gender::Male)
        ->and(GuardianRelationship::Guardian->gender())->toBeNull()
        ->and(GuardianRelationship::Other->gender())->toBeNull()
        ->and(GuardianRelationship::parse('Tía'))->toBe(GuardianRelationship::Aunt)
        ->and(GuardianRelationship::parse('Abuelo/a'))->toBe(GuardianRelationship::Grandparent);
});

it('el género de una planilla', function () {
    expect(Gender::parse('F'))->toBe(Gender::Female)
        ->and(Gender::parse('femenino'))->toBe(Gender::Female)
        ->and(Gender::parse('Varón'))->toBe(Gender::Male)
        ->and(Gender::parse('male'))->toBe(Gender::Male)
        ->and(Gender::parse(''))->toBeNull()
        ->and(Gender::parse('x'))->toBeNull();
});

it('los roles nombran a la persona según su género', function () {
    $organization = new Organization(['terminology' => ['instructor' => 'Profesor', 'guardian' => 'Encargado']]);

    expect(OrganizationRole::Treasurer->label($organization, Gender::Female))->toBe('Tesorera')
        ->and(OrganizationRole::President->label($organization, Gender::Female))->toBe('Presidenta')
        ->and(OrganizationRole::Admin->label($organization, Gender::Female))->toBe('Administradora')
        ->and(OrganizationRole::Member->label($organization, Gender::Female))->toBe('Vocal')
        ->and(OrganizationRole::Instructor->label($organization, Gender::Female))->toBe('Profesora')
        ->and(OrganizationRole::Guardian->label($organization, Gender::Female))->toBe('Encargada')
        ->and(OrganizationRole::Instructor->label($organization))->toBe('Profesor')
        ->and(OrganizationRole::Treasurer->label($organization))->toBe('Tesorero');
});

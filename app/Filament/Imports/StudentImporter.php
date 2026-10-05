<?php

namespace App\Filament\Imports;

use App\Actions\Students\ImportStudentRow;
use App\Exceptions\ImportRowException;
use App\Models\Organization;
use App\Models\Student;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Number;

/**
 * Planilla de alumnos: una fila por alumno con hasta dos tutores.
 * La lógica de cada fila está en ImportStudentRow; acá solo se mapean columnas.
 * Programa y grupo son opcionales: si faltan, se usa la categoría que corresponde por edad.
 */
class StudentImporter extends Importer
{
    protected static ?string $model = Student::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('first_name')->label('Nombre')->exampleHeader('nombre')->guess(['nombre', 'nombres'])->requiredMapping()->example('Mateo'),
            ImportColumn::make('last_name')->label('Apellido')->exampleHeader('apellido')->guess(['apellido', 'apellidos'])->requiredMapping()->example('Benítez'),
            ImportColumn::make('document')->label('Documento')->exampleHeader('documento')->guess(['documento', 'ci', 'cedula', 'cédula'])->example('6123456'),
            ImportColumn::make('birth_date')->label('Fecha de nacimiento')->exampleHeader('fecha_nacimiento')->guess(['fecha_nacimiento', 'nacimiento'])->requiredMapping()->example('14/03/2016'),
            ImportColumn::make('gender')->label('Género')->exampleHeader('genero')->guess(['genero', 'género', 'sexo'])->example('M'),
            ImportColumn::make('shirt_size')->label('Talle')->exampleHeader('talle')->example('12'),
            ImportColumn::make('position')->label('Posición')->exampleHeader('posicion')->guess(['posicion', 'posición'])->example('Arquero'),
            ImportColumn::make('program')->label('Programa')->exampleHeader('programa')->guess(['programa', 'disciplina', 'deporte'])->example('Fútbol'),
            ImportColumn::make('group')->label('Grupo')->exampleHeader('grupo')->guess(['grupo', 'categoria', 'categoría'])->example('Sub-10'),
            ImportColumn::make('season')->label('Temporada')->exampleHeader('temporada')->guess(['temporada'])->example(''),
            ImportColumn::make('status')->label('Estado')->exampleHeader('estado')->example('activo'),
            ...self::guardianColumns(1, ['Ana', 'Benítez', 'ana@example.com', '0981 123 456', 'madre']),
            ...self::guardianColumns(2, ['Luis', 'Benítez', '', '', 'padre']),
        ];
    }

    /**
     * @param  list<string>  $examples
     * @return list<ImportColumn>
     */
    private static function guardianColumns(int $n, array $examples): array
    {
        $fields = ['first_name' => 'nombre', 'last_name' => 'apellido', 'email' => 'email', 'phone' => 'telefono', 'relationship' => 'parentesco'];

        return collect(array_keys($fields))->map(fn (string $field, int $i) => ImportColumn::make("guardian{$n}_{$field}")
            ->label("Tutor {$n}: {$fields[$field]}")
            ->exampleHeader("tutor{$n}_{$fields[$field]}")
            ->guess(["tutor{$n}_{$fields[$field]}"])
            ->example($examples[$i]))
            ->all();
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Toggle::make('invite')
                ->label('Enviar la invitación a la app a los tutores con correo')
                ->helperText('Al aceptarla ven a sus hijos directamente.'),
        ];
    }

    /**
     * Toda la fila se procesa acá (alumno, tutores, familia e inscripción).
     */
    public function resolveRecord(): Student
    {
        $organization = Organization::query()->findOrFail($this->options['organization_id']);
        $data = $this->data;

        try {
            return app(ImportStudentRow::class)->handle($organization, [
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'document' => isset($data['document']) ? (string) $data['document'] : null,
                'birth_date' => $data['birth_date'] ?? null,
                'gender' => isset($data['gender']) ? (string) $data['gender'] : null,
                'shirt_size' => isset($data['shirt_size']) ? (string) $data['shirt_size'] : null,
                'position' => $data['position'] ?? null,
                'program' => $data['program'] ?? null,
                'group' => $data['group'] ?? null,
                'season' => isset($data['season']) ? (string) $data['season'] : null,
                'status' => $data['status'] ?? null,
                'guardians' => collect([1, 2])->map(fn (int $n) => [
                    'first_name' => $data["guardian{$n}_first_name"] ?? null,
                    'last_name' => $data["guardian{$n}_last_name"] ?? null,
                    'email' => $data["guardian{$n}_email"] ?? null,
                    'phone' => isset($data["guardian{$n}_phone"]) ? (string) $data["guardian{$n}_phone"] : null,
                    'relationship' => $data["guardian{$n}_relationship"] ?? null,
                ])->all(),
            ], (bool) ($this->options['invite'] ?? false), $this->import->user);
        } catch (ImportRowException $e) {
            throw new RowImportFailedException($e->getMessage());
        }
    }

    // El registro ya quedó guardado en resolveRecord().
    public function fillRecord(): void {}

    public function saveRecord(): void {}

    public static function getCompletedNotificationTitle(Import $import): string
    {
        return 'Importación terminada';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Se importaron '.Number::format($import->successful_rows).' '.($import->successful_rows === 1 ? 'fila' : 'filas').'.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.($failed === 1 ? 'fila falló' : 'filas fallaron').': descargá el detalle.';
        }

        return $body;
    }
}

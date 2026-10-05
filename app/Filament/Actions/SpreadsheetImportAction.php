<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Importar Excel": la importación de Filament acepta además .xlsx (se lee la primera hoja y se pasa a
 * CSV antes de importar; el resto es igual) y el ejemplo para descargar es un Excel.
 */
class SpreadsheetImportAction extends ImportAction
{
    public const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->schema;

        // El mismo formulario, con el archivo Excel entre los permitidos.
        $this->schema(fn (ImportAction $action): array => collect($action->evaluate($schema))
            ->map(fn ($component) => $component instanceof FileUpload && $component->getName() === 'file'
                ? $component->acceptedFileTypes([...$component->getAcceptedFileTypes() ?? [], self::XLSX_MIME, 'application/zip', 'application/octet-stream'])
                : $component)
            ->all());

        $this->registerModalActions([
            Action::make('downloadExample')
                ->label('Descargar el Excel de ejemplo')
                ->link()
                ->action(fn (): StreamedResponse => $this->downloadExample()),
        ]);
    }

    /**
     * El archivo como CSV: un .xlsx se convierte (primera hoja); un CSV queda igual.
     *
     * @return resource|false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return parent::getUploadedFileStream($file);
        }

        return self::xlsxToCsv($file->getRealPath());
    }

    /**
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        return array_map(
            fn ($rule) => $rule === 'extensions:csv,txt' ? 'extensions:csv,txt,xlsx' : $rule,
            parent::getFileValidationRules(),
        );
    }

    /**
     * La primera hoja de un Excel, como CSV (las fechas como AAAA-MM-DD).
     *
     * @return resource
     */
    public static function xlsxToCsv(string $path)
    {
        $csv = fopen('php://temp', 'r+');
        $reader = new Reader;
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($value) => match (true) {
                    $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                    is_bool($value) => $value ? '1' : '0',
                    default => (string) $value,
                }, $row->toArray());

                // Las filas vacías del final de la hoja no son registros.
                if (array_filter($values, fn (string $value) => trim($value) !== '') !== []) {
                    fputcsv($csv, $values, ',', '"', '\\');
                }
            }

            break;
        }

        $reader->close();
        rewind($csv);

        return $csv;
    }

    private function downloadExample(): StreamedResponse
    {
        $columns = $this->getImporter()::getColumns();
        $examples = array_map(fn (ImportColumn $column): array => $column->getExamples(), $columns);
        $rows = max(array_map('count', $examples) ?: [0]);
        $name = (string) str($this->getImporter())->classBasename()->beforeLast('Importer')->kebab();

        return response()->streamDownload(function () use ($columns, $examples, $rows): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_map(fn (ImportColumn $column): string => $column->getExampleHeader(), $columns)));

            for ($i = 0; $i < $rows; $i++) {
                $writer->addRow(Row::fromValues(array_map(fn (array $values) => $values[$i] ?? '', $examples)));
            }

            $writer->close();
        }, "ejemplo-{$name}.xlsx", ['Content-Type' => self::XLSX_MIME]);
    }
}

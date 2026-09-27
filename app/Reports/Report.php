<?php

namespace App\Reports;

use App\Models\Organization;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Informe exportable: los datos para la API/panel y las secciones que usan el PDF y el Excel.
 */
abstract class Report
{
    public const LINK_MINUTES = 30;

    public function __construct(protected Organization $organization) {}

    /** Clave en la URL de descarga (balance, saldos, morosos). */
    abstract public static function key(): string;

    abstract public function title(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function data(): array;

    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int>>, money: list<int>}>
     */
    abstract public function sections(): array;

    /**
     * Parámetros que viajan firmados en el link de descarga.
     *
     * @return array<string, string|int>
     */
    abstract public function parameters(): array;

    /**
     * @return array{pdf_url: string, xlsx_url: string}
     */
    public function links(): array
    {
        $link = fn (string $format) => URL::temporarySignedRoute('reports.download', now()->addMinutes(self::LINK_MINUTES), [
            'report' => static::key(),
            'format' => $format,
            'organization' => $this->organization->id,
            ...$this->parameters(),
        ]);

        return ['pdf_url' => $link('pdf'), 'xlsx_url' => $link('xlsx')];
    }

    public function pdf(): Response
    {
        return Pdf::loadView('reports.show', [
            'organization' => $this->organization,
            'title' => $this->title(),
            'sections' => $this->formattedSections(),
        ])->stream($this->fileName().'.pdf');
    }

    public function xlsx(): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'informe').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();

        $writer->addRow(Row::fromValues([$this->organization->name.' — '.$this->title()], $bold));

        foreach ($this->sections() as $section) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$section['title']], $bold));
            $writer->addRow(Row::fromValues($section['headers'], $bold));
            foreach ($section['rows'] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return response()->download($path, $this->fileName().'.xlsx')->deleteFileAfterSend();
    }

    /**
     * Para el PDF: los montos formateados en guaraníes.
     *
     * @return list<array{title: string, headers: list<string>, rows: list<list<string>>, money: list<int>}>
     */
    private function formattedSections(): array
    {
        return array_map(fn (array $section) => [
            ...$section,
            'rows' => array_map(fn (array $row) => array_map(
                fn ($value, int $i) => in_array($i, $section['money'], true) && is_int($value) ? Money::pyg($value)->format() : (string) $value,
                $row,
                array_keys($row),
            ), $section['rows']),
        ], $this->sections());
    }

    private function fileName(): string
    {
        return str($this->title())->slug()->toString();
    }
}

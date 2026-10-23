<?php

namespace App\Services\Pdf;

use App\Models\Student;
use App\Services\RecyclingReportService;
use Dompdf\Dompdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Presentation-ready recycling report PDFs.
 *
 * The header shows the product name only (there is no school brand in
 * EcoStation). The palette lives in protected constants so the UI-skin
 * task can change print colors in one place.
 */
class RecyclingReportPdf
{
    protected const PRIMARY = '#1F4E3D';

    protected const GOLD = '#F5CB5C';

    protected const SAGE = '#CFDBD5';

    protected const PAPER = '#E8EDDF';

    protected const MUTED = '#6B6F6D';

    protected const TEXT = '#242423';

    public function __construct(
        private readonly RecyclingReportService $reports,
    ) {}

    /** Range summary: totals, material mix and class totals. */
    public function summary(string $from, string $to, ?array $classIds = null): Response
    {
        $trend = $this->reports->trend($from, $to, $classIds);
        $mix = $this->reports->byMaterial($from, $to, $classIds);
        $byClass = $this->reports->byClass($from, $to, $classIds);

        $items = array_sum(array_column($trend, 'items'));
        $points = array_sum(array_column($trend, 'points'));

        $body = $this->summaryRow(
            'Depósitos', (string) $items,
            'Puntos', (string) $points,
            'Periodo', $this->prettyDate($from).' — '.$this->prettyDate($to),
        );

        $body .= $this->tableSection('Mezcla de materiales', [
            ['Material', 'Ítems', 'Puntos', 'Valor'],
        ], array_map(fn ($material) => [
            (string) $material,
            (string) $mix[$material]['items'],
            (string) $mix[$material]['points'],
            $mix[$material]['rate'].' pts',
        ], array_keys($mix)));

        $body .= $this->tableSection('Cursos', [
            ['Curso', 'Ítems', 'Puntos'],
        ], array_map(fn (array $row) => [
            $row['class_name'], (string) $row['items'], (string) $row['points'],
        ], $byClass));

        return $this->render('Reporte de reciclaje', $this->prettyDate($from).' — '.$this->prettyDate($to), $body);
    }

    /** Per-student report: totals + per-deposit history. */
    public function student(Student $student): Response
    {
        $history = $this->reports->studentHistory($student, 30);

        $body = '<p style="margin:0 0 12px;font-size:14px;color:'.self::TEXT.';">'
            .e($student->name)
            .' <span style="color:'.self::MUTED.';">· '.e((string) ($student->schoolClass?->name ?? 'Sin curso')).'</span></p>';

        $body .= $this->summaryRow(
            'Depósitos', (string) $history['totals']['items'],
            'Puntos', (string) $history['totals']['points'],
            'Periodo', 'Últimos 30 días',
        );

        $rows = array_map(fn (array $row) => [
            $row['date'].' '.$row['time'],
            $row['material'],
            (string) $row['points'],
            $row['is_recyclable'] ? 'Sí' : 'No',
            (string) ($row['reader'] ?? '—'),
        ], $history['deposits']);

        $body .= $this->tableSection('Historial de depósitos', [
            ['Fecha', 'Material', 'Puntos', 'Reciclable', 'Estación'],
        ], $rows);

        return $this->render(
            'Reporte de '.$student->name,
            'Últimos 30 días',
            $body,
        );
    }

    private function render(string $title, string $period, string $body): Response
    {
        $html = '<html><head><meta charset="utf-8">'
            .'<style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:'.self::TEXT.';}</style>'
            .'</head><body>'
            .$this->header($title, $period)
            .$body
            .$this->footer()
            .'</body></html>';

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $slug = 'reporte-'.str_replace(' ', '-', strtolower($title));

        // dompdf's stream() echoes directly (bypassing Laravel's response
        // pipeline); output() + a real Response keeps headers honest.
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$slug.'.pdf"',
        ]);
    }

    private function header(string $title, string $period): string
    {
        return '<table style="width:100%;background-color:'.self::PRIMARY.';border-collapse:collapse;" cellpadding="10"><tr>'
            .'<td style="width:30%;"><span style="color:'.self::GOLD.';font-size:20px;font-weight:bold;">'
            .e((string) config('app.name')).'</span></td>'
            .'<td style="color:'.self::PAPER.';font-size:14px;text-align:right;">'
            .e($title).'<br><span style="font-size:10px;color:'.self::SAGE.';">'.e($period).'</span></td>'
            .'</tr></table><div style="height:14px;"></div>';
    }

    private function summaryRow(string $labelA, string $valueA, string $labelB, string $valueB, string $labelC, string $valueC): string
    {
        $cells = '';
        foreach ([$labelA => $valueA, $labelB => $valueB, $labelC => $valueC] as $label => $value) {
            $cells .= '<td style="background-color:'.self::SAGE.';padding:10px;width:33%;border:1px solid '.self::PAPER.';">'
                .'<span style="font-size:9px;color:'.self::MUTED.';">'.e($label).'</span><br>'
                .'<span style="font-size:20px;color:'.self::TEXT.';">'.e($value).'</span></td>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-bottom:14px;"><tr>'.$cells.'</tr></table>';
    }

    private function section(string $title, string $content): string
    {
        return '<p style="margin:0 0 6px;font-size:12px;border-bottom:2px solid '.self::GOLD.';padding-bottom:3px;">'
            .e($title).'</p>'.$content.'<div style="height:12px;"></div>';
    }

    /**
     * @param  array<int, array<int, string>>  $headerRow
     * @param  array<int, array<int, string>>  $rows
     */
    private function tableSection(string $title, array $headerRow, array $rows): string
    {
        $rowsHtml = '';
        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 1 ? self::PAPER : '#FFFFFF';
            $cells = '';
            foreach ($row as $cell) {
                $cells .= '<td style="padding:5px 8px;border:1px solid '.self::SAGE.';font-size:10px;">'.e((string) $cell).'</td>';
            }
            $rowsHtml .= '<tr style="background-color:'.$bg.';">'.$cells.'</tr>';
        }

        if ($rows === []) {
            $rowsHtml = '<tr><td colspan="'.max(1, count($headerRow[0])).'" style="padding:8px;border:1px solid '.self::SAGE
                .';color:'.self::MUTED.';font-size:10px;">Sin datos para el periodo.</td></tr>';
        }

        $headCells = '';
        foreach ($headerRow[0] as $cell) {
            $headCells .= '<td style="padding:5px 8px;background-color:'.self::PRIMARY.';color:'.self::PAPER
                .';font-size:9px;border:1px solid '.self::PRIMARY.';">'.e((string) $cell).'</td>';
        }

        return $this->section($title, '<table style="width:100%;border-collapse:collapse;"><tr>'.$headCells.'</tr>'.$rowsHtml.'</table>');
    }

    private function footer(): string
    {
        return '<div style="height:18px;"></div><table style="width:100%;border-collapse:collapse;"><tr>'
            .'<td style="font-size:8px;color:'.self::MUTED.';">'.e((string) config('app.name')).' · Reciclaje</td>'
            .'<td style="font-size:8px;color:'.self::MUTED.';text-align:right;">'
            .e(now()->format('Y-m-d H:i')).'</td></tr></table>';
    }

    private function prettyDate(string $date): string
    {
        try {
            return Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return $date;
        }
    }
}

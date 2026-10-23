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
 * Brand palette and the horizontal EcoStation logo live in this single
 * class: the header carries the product brand only (there is no school
 * brand in EcoStation), and material cells keep the on-screen colors so
 * a printed report still reads like the dashboard.
 */
class RecyclingReportPdf
{
    protected const PRIMARY = '#0B7A47';

    protected const SUN = '#FFC93C';

    protected const INK = '#0F2A2E';

    protected const SLATE = '#4A6468';

    protected const MINT = '#F2FAF6';

    protected const MIST = '#DCEBE6';

    protected const WHITE = '#FFFFFF';

    /** @var array<string, string> */
    protected const MATERIAL_LABELS = [
        'plastic' => 'Plástico',
        'metal' => 'Metal',
        'glass' => 'Vidrio',
        'paper' => 'Papel',
        'other' => 'Otro',
    ];

    /** @var array<string, array{0: string, 1: string}> foreground / background */
    protected const MATERIAL_COLORS = [
        'plastic' => ['#2A78D6', '#E3EEFB'],
        'metal' => ['#EB6834', '#FDEAE1'],
        'glass' => ['#14B8B0', '#E2F5EE'],
        'paper' => ['#EDA100', '#FFF4D6'],
        'other' => ['#E87BA4', '#FCEAF1'],
    ];

    private ?string $logoCache = null;

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

        $body .= $this->materialMixTable($mix);

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

        $body = '<p style="margin:0 0 12px;font-size:14px;color:'.self::INK.';">'
            .e($student->name)
            .' <span style="color:'.self::SLATE.';">· '.e((string) ($student->schoolClass?->name ?? 'Sin curso')).'</span></p>';

        $body .= $this->summaryRow(
            'Depósitos', (string) $history['totals']['items'],
            'Puntos', (string) $history['totals']['points'],
            'Periodo', 'Últimos 30 días',
        );

        $body .= $this->studentHistoryTable($history['deposits']);

        return $this->render(
            'Reporte de '.$student->name,
            'Últimos 30 días',
            $body,
        );
    }

    private function render(string $title, string $period, string $body): Response
    {
        $html = '<html><head><meta charset="utf-8">'
            .'<style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:'.self::INK.';line-height:1.45;}'
            .'table{border-collapse:collapse;}'
            .'span,td,th{word-wrap:break-word;}'
            .'</style>'
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
        $logo = $this->logoDataUri();

        $logoHtml = $logo !== null
            ? '<img src="'.$logo.'" alt="EcoStation" style="height:42px;width:auto;">'
            : '<span style="color:'.self::PRIMARY.';font-size:20px;font-weight:bold;">EcoStation</span>';

        return '<table style="width:100%;background-color:'.self::MINT
            .';border-bottom:3px solid '.self::PRIMARY.';" cellpadding="0" cellspacing="0"><tr>'
            .'<td style="width:46%;padding:14px 16px;">'.$logoHtml.'</td>'
            .'<td style="padding:14px 16px;text-align:right;">'
            .'<span style="color:'.self::INK.';font-size:18px;font-weight:bold;">'.e($title).'</span><br>'
            .'<span style="color:'.self::SLATE.';font-size:10px;">'.e($period).'</span>'
            .'</td></tr></table><div style="height:16px;"></div>';
    }

    private function summaryRow(string $labelA, string $valueA, string $labelB, string $valueB, string $labelC, string $valueC): string
    {
        $cells = '';

        foreach ([
            [$labelA, $valueA, 20],
            [$labelB, $valueB, 20],
            [$labelC, $valueC, 13],
        ] as [$label, $value, $size]) {
            $cells .= '<td style="background-color:'.self::MINT.';padding:12px;width:33%;border:1px solid '.self::MIST.';">'
                .'<span style="font-size:9px;color:'.self::SLATE.';text-transform:uppercase;letter-spacing:0.06em;">'
                .e($label).'</span><br>'
                .'<span style="font-family:DejaVu Sans Mono,monospace;font-size:'.$size.'px;color:'.self::INK.';">'
                .e($value).'</span></td>';
        }

        return '<table style="width:100%;margin-bottom:16px;"><tr>'.$cells.'</tr></table>';
    }

    /**
     * Material mix with the same tinted chips the web table uses.
     *
     * @param  array<string, array{items: int, points: int, rate: int}>  $mix
     */
    private function materialMixTable(array $mix): string
    {
        $rows = '';

        foreach ($mix as $material => $data) {
            $rows .= '<tr style="background-color:'.self::WHITE.';">'
                .'<td style="padding:6px 8px;border:1px solid '.self::MIST.';font-size:10px;">'
                .$this->materialBadge((string) $material).'</td>'
                .'<td style="padding:6px 8px;border:1px solid '.self::MIST.';font-size:10px;">'.e((string) $data['items']).'</td>'
                .'<td style="padding:6px 8px;border:1px solid '.self::MIST.';font-size:10px;">'.e((string) $data['points']).'</td>'
                .'<td style="padding:6px 8px;border:1px solid '.self::MIST.';font-size:10px;">'.e($data['rate'].' pts').'</td>'
                .'</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="padding:8px;border:1px solid '.self::MIST
                .';color:'.self::SLATE.';font-size:10px;">Sin datos para el periodo.</td></tr>';
        }

        return $this->section('Mezcla de materiales', '<table style="width:100%;">'
            .$this->tableHead(['Material', 'Ítems', 'Puntos', 'Valor'])
            .$rows.'</table>');
    }

    /**
     * @param  array<int, array{deposit_id: int, date: string, time: string, class_name: ?string, material: string, points: int, is_bottle: bool, is_recyclable: bool, has_photo: bool, reader: ?string}>  $deposits
     */
    private function studentHistoryTable(array $deposits): string
    {
        $rows = '';

        foreach ($deposits as $index => $row) {
            $background = $index % 2 === 1 ? self::MINT : self::WHITE;

            $rows .= '<tr style="background-color:'.$background.';">'
                .'<td style="padding:5px 8px;border:1px solid '.self::MIST.';font-size:9px;">'.e($row['date'].' '.$row['time']).'</td>'
                .'<td style="padding:5px 8px;border:1px solid '.self::MIST.';font-size:9px;">'.$this->materialBadge($row['material']).'</td>'
                .'<td style="padding:5px 8px;border:1px solid '.self::MIST.';font-size:9px;">'.e((string) $row['points']).'</td>'
                .'<td style="padding:5px 8px;border:1px solid '.self::MIST.';font-size:9px;">'.($row['is_recyclable'] ? 'Sí' : 'No').'</td>'
                .'<td style="padding:5px 8px;border:1px solid '.self::MIST.';font-size:9px;">'.e((string) ($row['reader'] ?? '—')).'</td>'
                .'</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" style="padding:8px;border:1px solid '.self::MIST
                .';color:'.self::SLATE.';font-size:10px;">Sin datos para el periodo.</td></tr>';
        }

        return $this->section('Historial de depósitos', '<table style="width:100%;">'
            .$this->tableHead(['Fecha', 'Material', 'Puntos', 'Reciclable', 'Estación'])
            .$rows.'</table>');
    }

    private function section(string $title, string $content): string
    {
        return '<p style="margin:0 0 6px;font-size:12px;color:'.self::INK
            .';border-bottom:2px solid '.self::SUN.';padding-bottom:3px;font-weight:bold;">'
            .e($title).'</p>'.$content.'<div style="height:14px;"></div>';
    }

    /**
     * @param  array<int, array<int, string>>  $headerRow
     * @param  array<int, array<int, string>>  $rows
     */
    private function tableSection(string $title, array $headerRow, array $rows): string
    {
        $rowsHtml = '';

        foreach ($rows as $index => $row) {
            $background = $index % 2 === 1 ? self::MINT : self::WHITE;
            $cells = '';

            foreach ($row as $cell) {
                $cells .= '<td style="padding:5px 8px;border:1px solid '.self::MIST.';font-size:10px;">'
                    .e((string) $cell).'</td>';
            }

            $rowsHtml .= '<tr style="background-color:'.$background.';">'.$cells.'</tr>';
        }

        if ($rows === []) {
            $rowsHtml = '<tr><td colspan="'.max(1, count($headerRow[0])).'" style="padding:8px;border:1px solid '.self::MIST
                .';color:'.self::SLATE.';font-size:10px;">Sin datos para el periodo.</td></tr>';
        }

        return $this->section($title, '<table style="width:100%;">'
            .$this->tableHead($headerRow[0]).$rowsHtml.'</table>');
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function tableHead(array $cells): string
    {
        $html = '';

        foreach ($cells as $cell) {
            $html .= '<td style="padding:6px 8px;background-color:'.self::PRIMARY.';color:'.self::WHITE
                .';font-size:9px;font-weight:bold;border:1px solid '.self::PRIMARY.';">'.e((string) $cell).'</td>';
        }

        return '<tr>'.$html.'</tr>';
    }

    private function materialBadge(string $material): string
    {
        $label = self::MATERIAL_LABELS[$material] ?? mb_convert_case($material, MB_CASE_TITLE, 'UTF-8');
        [$foreground, $background] = self::MATERIAL_COLORS[$material] ?? [self::SLATE, self::MIST];

        return '<span style="display:inline-block;padding:2px 7px;border-radius:8px;background-color:'
            .$background.';color:'.$foreground.';font-size:9px;font-weight:bold;">'.e($label).'</span>';
    }

    private function footer(): string
    {
        return '<div style="height:18px;"></div><table style="width:100%;border-top:1px solid '.self::MIST.';">'
            .'<tr><td style="padding-top:6px;font-size:8px;color:'.self::SLATE.';">EcoStation · America/Bogota</td>'
            .'<td style="padding-top:6px;font-size:8px;color:'.self::SLATE.';text-align:right;">'
            .'Generado '.e(now()->timezone((string) config('app.timezone'))->format('d/m/Y H:i')).'</td></tr></table>';
    }

    /** Embed the horizontal brand logo without granting dompdf remote access. */
    private function logoDataUri(): ?string
    {
        if ($this->logoCache !== null) {
            return $this->logoCache;
        }

        $path = public_path('img/ecostation-horizontal.png');

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        return $this->logoCache = 'data:image/png;base64,'.base64_encode($contents);
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

<?php

namespace App\Http\Controllers;

use App\Support\ReportExports;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function csv(Request $request, string $type): StreamedResponse
    {
        $report = $this->report($request, $type);
        $filename = 'wynn-mart-'.$type.'-'.now('Asia/Shanghai')->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            foreach (['Report' => $report['title'], 'Shop' => $report['shop'], 'Generated' => $report['generated'], 'Currency' => 'PHP', 'Selected period' => $report['period'], 'Scope' => $report['scope']] as $label => $value) {
                fputcsv($stream, [$label, ReportExports::formatted(['value' => $value, 'kind' => 'text'], true)], ',', '"', '', "\r\n");
            }
            foreach ($report['notes'] as $note) {
                fputcsv($stream, ['Note', $note], ',', '"', '', "\r\n");
            }
            fputcsv($stream, [], ',', '"', '', "\r\n");
            fputcsv($stream, $report['columns'], ',', '"', '', "\r\n");
            foreach ($report['rows'] as $row) {
                fputcsv($stream, array_map(fn (array $cell): string => ReportExports::formatted($cell, true), $row), ',', '"', '', "\r\n");
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function preview(Request $request, string $type): View
    {
        return view('reports.print', ['report' => $this->report($request, $type)]);
    }

    private function report(Request $request, string $type): array
    {
        abort_unless(isset(ReportExports::TYPES[$type]), 404);
        $validated = $request->validate(['days' => 'sometimes|required|integer|in:7,30,90']);

        return ReportExports::build($type, (int) ($validated['days'] ?? 7));
    }
}

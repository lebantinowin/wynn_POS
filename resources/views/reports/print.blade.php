<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $report['title'] }} · {{ $report['shop'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/report-print.css') }}">
</head>
<body>
    <nav class="print-controls" aria-label="Report actions"><a href="{{ route('workspace', ['section' => 'reports']) }}">← Back to reports</a><button type="button" onclick="window.print()">Print / Save PDF</button></nav>
    <main class="report-sheet">
        <header><p class="eyebrow">{{ $report['shop'] }} · Management report</p><h1>{{ $report['title'] }}</h1>
            <div class="report-meta"><p><strong>Selected period</strong>{{ $report['period'] }}</p><p><strong>Generated</strong>{{ $report['generated'] }}</p><p><strong>Currency</strong>PHP / ₱</p></div>
            <p class="scope">{{ $report['scope'] }}</p>
        </header>
        <div class="report-table-wrap"><table class="{{ count($report['columns']) > 6 ? 'wide' : '' }}"><caption>{{ count($report['rows']) }} {{ count($report['rows']) === 1 ? 'row' : 'rows' }} · Recorded data</caption><thead><tr>@foreach($report['columns'] as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead><tbody>
            @forelse($report['rows'] as $row)<tr>@foreach($row as $cell)<td class="{{ $cell['kind'] !== 'text' ? 'numeric' : '' }} {{ $cell['value'] === null ? 'unknown' : '' }}">{{ \App\Support\ReportExports::formatted($cell) }}</td>@endforeach</tr>
            @empty<tr><td colspan="{{ count($report['columns']) }}" class="empty">No matching records. Review the scope and selected period above.</td></tr>@endforelse
        </tbody></table></div>
        <footer><h2>Reading this report</h2><ul>@foreach($report['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul></footer>
    </main>
</body>
</html>

<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

/**
 * Renders a self-contained HTML report (table + bar chart) for a benchmark run.
 *
 * The chart uses Chart.js loaded from a CDN — no build step, no local assets.
 * The output file is openable from `file://` and works offline once the CDN
 * script is cached by the browser.
 */
class HtmlReportGenerator
{
    private const CHART_JS_CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js';

    /**
     * @param array{
     *     items: int,
     *     host: string,
     *     port: int,
     *     tags: list<string>,
     *     generatedAt?: \DateTimeImmutable
     * } $context
     * @param list<BenchmarkResult> $basicResults
     * @param list<TaggedBenchmarkResult> $taggedResults
     */
    public function generate(
        string $outputPath,
        array $context,
        array $basicResults = [],
        array $taggedResults = [],
    ): void {
        $generatedAt = $context['generatedAt'] ?? new \DateTimeImmutable();
        $html = $this->render($context, $generatedAt, $basicResults, $taggedResults);

        $bytesWritten = file_put_contents($outputPath, $html);
        if ($bytesWritten === false) {
            throw new \RuntimeException(sprintf('Unable to write HTML report to %s', $outputPath));
        }
    }

    /**
     * @param array{items: int, host: string, port: int, tags: list<string>} $context
     * @param list<BenchmarkResult> $basicResults
     * @param list<TaggedBenchmarkResult> $taggedResults
     */
    private function render(
        array $context,
        \DateTimeImmutable $generatedAt,
        array $basicResults,
        array $taggedResults,
    ): string {
        $css = $this->css();
        $body = $this->header($context, $generatedAt);

        if ($taggedResults !== []) {
            $body .= $this->section(
                title: 'Tagged Benchmark',
                description: 'Tagged SET/GET operations — items are stored under a tag and bulk-retrieved by tag.',
                results: array_map(fn(TaggedBenchmarkResult $r) => $r->toArray(), $taggedResults),
                chartIdPrefix: 'tagged',
                includeAccuracyColumn: true,
            );
        }

        if ($basicResults !== []) {
            $body .= $this->section(
                title: 'Basic Benchmark',
                description: 'Plain key/value SET and GET without tagging.',
                results: array_map(fn(BenchmarkResult $r) => $r->toArray(), $basicResults),
                chartIdPrefix: 'basic',
                includeAccuracyColumn: false,
            );
        }

        if ($taggedResults === [] && $basicResults === []) {
            $body .= '<p class="empty">No benchmark results captured.</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IDCT Rapid Cache — Benchmark Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="{$this->h(self::CHART_JS_CDN)}"></script>
    <style>{$css}</style>
</head>
<body>
<main>
{$body}
</main>
</body>
</html>
HTML;
    }

    /**
     * @param array{items: int, host: string, port: int, tags: list<string>} $context
     */
    private function header(array $context, \DateTimeImmutable $generatedAt): string
    {
        $tags = $this->h(implode(', ', $context['tags']));
        $host = $this->h($context['host']);
        $port = $context['port'];
        $items = number_format($context['items']);
        $when = $this->h($generatedAt->format('Y-m-d H:i:s T'));

        return <<<HTML
<header>
    <h1>IDCT Rapid Cache — Benchmark Report</h1>
    <dl class="meta">
        <dt>Generated</dt><dd>{$when}</dd>
        <dt>Items per adapter</dt><dd>{$items}</dd>
        <dt>Redis endpoint</dt><dd>{$host}:{$port}</dd>
        <dt>Tags</dt><dd>{$tags}</dd>
    </dl>
</header>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function section(
        string $title,
        string $description,
        array $results,
        string $chartIdPrefix,
        bool $includeAccuracyColumn,
    ): string {
        $table = $this->renderTable($results, $includeAccuracyColumn);
        $titleH = $this->h($title);
        $descH = $this->h($description);

        // Three small charts side-by-side, one per metric. Each chart owns its
        // own y-axis so SET (≈ thousands) doesn't get steamrolled by GET
        // (≈ hundreds of thousands) on the tagged side.
        $charts = [
            ['id' => "{$chartIdPrefix}SetChart",   'metric' => 'set_throughput',   'label' => 'SET ops/sec',   'color' => 'blue'],
            ['id' => "{$chartIdPrefix}GetChart",   'metric' => 'get_throughput',   'label' => 'GET ops/sec',   'color' => 'green'],
            ['id' => "{$chartIdPrefix}TotalChart", 'metric' => 'total_throughput', 'label' => 'Total ops/sec', 'color' => 'amber'],
        ];

        $chartCanvases = '';
        $chartScripts = '';
        foreach ($charts as $chart) {
            $idH = $this->h($chart['id']);
            $labelH = $this->h($chart['label']);
            $chartCanvases .= <<<HTML
        <figure class="chart-card">
            <figcaption>{$labelH}</figcaption>
            <div class="chart-wrapper"><canvas id="{$idH}"></canvas></div>
        </figure>

HTML;
            $chartScripts .= $this->renderSingleMetricChartScript(
                $chart['id'],
                $results,
                $chart['metric'],
                $chart['label'],
                $chart['color'],
            ) . "\n";
        }

        return <<<HTML
<section>
    <h2>{$titleH}</h2>
    <p class="description">{$descH}</p>
    {$table}
    <div class="chart-grid">
{$chartCanvases}    </div>
    <script>{$chartScripts}</script>
</section>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function renderTable(array $results, bool $includeAccuracyColumn): string
    {
        // Sort by total throughput desc — fastest first, same as the CLI output.
        usort($results, fn(array $a, array $b) => $b['total_throughput'] <=> $a['total_throughput']);

        $accuracyHeader = $includeAccuracyColumn ? '<th>Accuracy %</th>' : '';
        $headers = <<<HTML
<thead>
<tr>
    <th>Adapter</th>
    <th>SET ops/sec</th>
    <th>GET ops/sec</th>
    <th>Total ops/sec</th>
    <th>Memory MB</th>
    <th>Peak MB</th>
    {$accuracyHeader}
</tr>
</thead>
HTML;

        $rows = '';
        foreach ($results as $row) {
            $accuracyCell = '';
            if ($includeAccuracyColumn) {
                $accuracy = isset($row['details']['retrieval_accuracy'])
                    ? number_format((float) $row['details']['retrieval_accuracy'], 1)
                    : '—';
                $accuracyCell = "<td class=\"num\">{$this->h($accuracy)}</td>";
            }

            $rows .= sprintf(
                "<tr><th scope=\"row\">%s</th><td class=\"num\">%s</td><td class=\"num\">%s</td><td class=\"num\">%s</td><td class=\"num\">%s</td><td class=\"num\">%s</td>%s</tr>\n",
                $this->h((string) $row['adapter']),
                number_format((float) $row['set_throughput']),
                number_format((float) $row['get_throughput']),
                number_format((float) $row['total_throughput']),
                number_format((float) $row['memory_usage_mb'], 2),
                number_format((float) $row['peak_memory_mb'], 2),
                $accuracyCell,
            );
        }

        return "<table>{$headers}<tbody>\n{$rows}</tbody></table>";
    }

    /**
     * Renders a single-metric bar chart on a linear y-axis (one bar per adapter).
     *
     * @param list<array<string, mixed>> $results
     * @param 'blue'|'green'|'amber' $color
     */
    private function renderSingleMetricChartScript(
        string $chartId,
        array $results,
        string $metricKey,
        string $label,
        string $color,
    ): string {
        // Sort by the metric being plotted so the fastest is always the
        // leftmost (most prominent) bar — makes the "profit" visually obvious.
        usort($results, fn(array $a, array $b) => $b[$metricKey] <=> $a[$metricKey]);

        $palette = [
            'blue'  => ['bg' => 'rgba(59, 130, 246, 0.75)', 'border' => 'rgb(37, 99, 235)'],
            'green' => ['bg' => 'rgba(16, 185, 129, 0.75)', 'border' => 'rgb(5, 150, 105)'],
            'amber' => ['bg' => 'rgba(245, 158, 11, 0.75)', 'border' => 'rgb(217, 119, 6)'],
        ];
        $colors = $palette[$color];

        $config = [
            'type' => 'bar',
            'data' => [
                'labels' => array_map(fn(array $r) => $r['adapter'], $results),
                'datasets' => [[
                    'label' => $label,
                    'data' => array_map(fn(array $r) => $r[$metricKey], $results),
                    'backgroundColor' => $colors['bg'],
                    'borderColor' => $colors['border'],
                    'borderWidth' => 1,
                ]],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'indexAxis' => 'y',
                'plugins' => [
                    'legend' => ['display' => false],
                    'title' => ['display' => false],
                ],
                'scales' => [
                    'x' => [
                        'type' => 'linear',
                        'beginAtZero' => true,
                        'title' => ['display' => true, 'text' => 'Operations per second'],
                    ],
                ],
            ],
        ];

        $configJson = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return "new Chart(document.getElementById('{$this->h($chartId)}'), {$configJson});";
    }

    private function css(): string
    {
        return <<<CSS
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f9fafb;
    color: #111827;
    line-height: 1.5;
}
main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 2rem 1.5rem;
}
header h1 {
    margin: 0 0 0.75rem;
    font-size: 1.75rem;
}
dl.meta {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 0.25rem 1rem;
    background: #fff;
    padding: 1rem 1.25rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin: 0 0 2rem;
}
dl.meta dt { font-weight: 600; color: #4b5563; }
dl.meta dd { margin: 0; font-variant-numeric: tabular-nums; }
section {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
section h2 { margin: 0 0 0.25rem; font-size: 1.4rem; }
p.description { margin: 0 0 1.25rem; color: #4b5563; }
table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}
th, td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #f3f4f6;
    text-align: left;
}
thead th {
    background: #f3f4f6;
    border-bottom: 2px solid #d1d5db;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #374151;
}
tbody tr:nth-child(odd) { background: #fcfcfd; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; font-family: 'SF Mono', Menlo, Consolas, monospace; }
.chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 0.5rem;
}
.chart-card {
    margin: 0;
    padding: 0.75rem;
    background: #fcfcfd;
    border: 1px solid #f3f4f6;
    border-radius: 6px;
}
.chart-card figcaption {
    font-size: 0.85rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
    text-align: center;
}
.chart-wrapper {
    position: relative;
    height: 240px;
}
.hint { margin: 0; font-size: 0.85rem; color: #6b7280; }
.empty { padding: 2rem; text-align: center; color: #6b7280; background: #fff; border: 1px dashed #d1d5db; border-radius: 8px; }
CSS;
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

/**
 * Renders a self-contained HTML report (table + bar charts) for a RapidCache
 * feature run.
 *
 * The chart uses Chart.js loaded from a CDN - no build step, no local assets.
 * The output file is openable from `file://` and works offline once the CDN
 * script is cached by the browser.
 */
class HtmlReportGenerator
{
    private const CHART_JS_CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js';

    /** @var array<string, array{bg: string, border: string}> */
    private const PALETTE = [
        'Core'          => ['bg' => 'rgba(59, 130, 246, 0.75)', 'border' => 'rgb(37, 99, 235)'],
        'Tagging'       => ['bg' => 'rgba(16, 185, 129, 0.75)', 'border' => 'rgb(5, 150, 105)'],
        'Counters'      => ['bg' => 'rgba(245, 158, 11, 0.75)', 'border' => 'rgb(217, 119, 6)'],
        'Hash Core'     => ['bg' => 'rgba(139, 92, 246, 0.75)', 'border' => 'rgb(124, 58, 237)'],
        'Hash Fields'   => ['bg' => 'rgba(236, 72, 153, 0.75)', 'border' => 'rgb(219, 39, 119)'],
        'Hash Tagging'  => ['bg' => 'rgba(20, 184, 166, 0.75)', 'border' => 'rgb(13, 148, 136)'],
        'Hash Counters' => ['bg' => 'rgba(244, 63, 94, 0.75)',  'border' => 'rgb(225, 29, 72)'],
    ];
    private const DEFAULT_COLOR = ['bg' => 'rgba(107, 114, 128, 0.75)', 'border' => 'rgb(75, 85, 99)'];

    /**
     * @param array{items: int, host: string, port: int, system?: SystemInfo, generatedAt?: \DateTimeImmutable} $context
     * @param list<OperationResult> $results
     */
    public function generate(string $outputPath, array $context, array $results): void
    {
        $generatedAt = $context['generatedAt'] ?? new \DateTimeImmutable();
        $html = $this->render($context, $generatedAt, $results);

        $bytesWritten = file_put_contents($outputPath, $html);
        if ($bytesWritten === false) {
            throw new \RuntimeException(sprintf('Unable to write HTML report to %s', $outputPath));
        }
    }

    /**
     * @param array{items: int, host: string, port: int} $context
     * @param list<OperationResult> $results
     */
    private function render(array $context, \DateTimeImmutable $generatedAt, array $results): string
    {
        $css = $this->css();
        $body = $this->header($context, $generatedAt);

        if ($results === []) {
            $body .= '<p class="empty">No benchmark results captured.</p>';
        } else {
            /** @var array<string, list<OperationResult>> $byCategory */
            $byCategory = [];
            foreach ($results as $result) {
                $byCategory[$result->category][] = $result;
            }
            $body .= $this->tableSection($byCategory);
            $body .= $this->chartSection($byCategory);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IDCT Rapid Cache — Feature Benchmark</title>
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
     * @param array{items: int, host: string, port: int, system?: SystemInfo} $context
     */
    private function header(array $context, \DateTimeImmutable $generatedAt): string
    {
        $host = $this->h($context['host']);
        $port = $context['port'];
        $items = number_format($context['items']);
        $when = $this->h($generatedAt->format('Y-m-d H:i:s T'));

        $system = $context['system'] ?? null;
        $systemRows = '';
        if ($system instanceof SystemInfo) {
            $systemRows = sprintf(
                "\n        <dt>CPU</dt><dd>%s</dd>\n        <dt>Memory</dt><dd>%s</dd>\n        <dt>Environment</dt><dd>%s</dd>",
                $this->h($system->cpuLabel()),
                $this->h($system->memoryLabel()),
                $this->h($system->environment()),
            );
        }

        return <<<HTML
<header>
    <h1>IDCT Rapid Cache — Feature Benchmark</h1>
    <p class="lede">Throughput of RapidCache's own operations. No cross-library comparison — just how many of each operation the library sustains per second on this host.</p>
    <dl class="meta">
        <dt>Generated</dt><dd>{$when}</dd>
        <dt>Items per operation</dt><dd>{$items}</dd>
        <dt>Redis endpoint</dt><dd>{$host}:{$port}</dd>{$systemRows}
    </dl>
</header>
HTML;
    }

    /**
     * @param array<string, list<OperationResult>> $byCategory
     */
    private function tableSection(array $byCategory): string
    {
        $rows = '';
        foreach ($byCategory as $category => $results) {
            foreach ($results as $r) {
                $rows .= sprintf(
                    "<tr><td>%s</td><th scope=\"row\"><code>%s</code></th><td class=\"num\">%s</td><td class=\"num\">%s</td><td class=\"note\">%s</td></tr>\n",
                    $this->h($category),
                    $this->h($r->operation),
                    number_format($r->opsPerSec()),
                    number_format($r->avgMicros(), 2),
                    $this->h($r->note ?? ''),
                );
            }
        }

        return <<<HTML
<section>
    <h2>Results</h2>
    <p class="description">Each operation is timed over the same item count. <code>avg µs</code> is the mean wall time per call.</p>
    <table>
<thead>
<tr><th>Category</th><th>Operation</th><th>ops/sec</th><th>avg µs</th><th>Notes</th></tr>
</thead>
<tbody>
{$rows}</tbody>
    </table>
</section>
HTML;
    }

    /**
     * @param array<string, list<OperationResult>> $byCategory
     */
    private function chartSection(array $byCategory): string
    {
        $canvases = '';
        $scripts = '';
        $idx = 0;
        foreach ($byCategory as $category => $results) {
            $id = 'chart' . $idx++;
            $labelH = $this->h($category);
            $canvases .= <<<HTML
        <figure class="chart-card">
            <figcaption>{$labelH}</figcaption>
            <div class="chart-wrapper"><canvas id="{$id}"></canvas></div>
        </figure>

HTML;
            $scripts .= $this->chartScript($id, $category, $results) . "\n";
        }

        return <<<HTML
<section>
    <h2>Throughput by category</h2>
    <p class="description">One chart per category; each bar is one operation (ops/sec, higher is better). Scales are independent per category.</p>
    <div class="chart-grid">
{$canvases}    </div>
    <script>{$scripts}</script>
</section>
HTML;
    }

    /**
     * @param list<OperationResult> $results
     */
    private function chartScript(string $chartId, string $category, array $results): string
    {
        usort($results, fn(OperationResult $a, OperationResult $b) => $b->opsPerSec() <=> $a->opsPerSec());
        $colors = self::PALETTE[$category] ?? self::DEFAULT_COLOR;

        $config = [
            'type' => 'bar',
            'data' => [
                'labels' => array_map(fn(OperationResult $r) => $r->operation, $results),
                'datasets' => [[
                    'label' => $category . ' ops/sec',
                    'data' => array_map(fn(OperationResult $r) => round($r->opsPerSec(), 2), $results),
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
main { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }
header h1 { margin: 0 0 0.5rem; font-size: 1.75rem; }
p.lede { margin: 0 0 1.25rem; color: #4b5563; max-width: 70ch; }
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
table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
th, td { padding: 0.6rem 0.75rem; border-bottom: 1px solid #f3f4f6; text-align: left; }
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
td.note { color: #6b7280; font-size: 0.9rem; }
code { font-family: 'SF Mono', Menlo, Consolas, monospace; font-size: 0.9em; }
.chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
}
.chart-card { margin: 0; padding: 0.75rem; background: #fcfcfd; border: 1px solid #f3f4f6; border-radius: 6px; }
.chart-card figcaption { font-weight: 600; color: #374151; margin-bottom: 0.5rem; }
.chart-wrapper { position: relative; height: 240px; }
p.empty { color: #6b7280; }
CSS;
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

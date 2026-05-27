<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

/**
 * Renders a static, self-contained SVG bar chart for a benchmark run.
 *
 * Unlike {@see HtmlReportGenerator} (which draws with Chart.js at runtime),
 * this emits a plain `<svg>` with no scripts — so it renders inline on GitHub
 * (README, issues) and anywhere else that strips JavaScript. The output is
 * deterministic for a given set of results, which keeps committed/published
 * images diff-friendly.
 *
 * Layout: one horizontal-bar panel per metric (SET / GET / Total), one bar per
 * adapter. Each panel owns its own scale so the small-magnitude metric (e.g.
 * tagged SET, ~thousands) is not flattened by the large one (GET, ~hundreds of
 * thousands) — the same reasoning as the multi-chart HTML report.
 */
class SvgChartGenerator
{
    private const WIDTH = 900;

    // Horizontal layout (px).
    private const PAD_X = 24;
    private const LABEL_COL_W = 168;   // adapter-name gutter, right-aligned
    private const VALUE_COL_W = 96;    // room for the value printed after a bar

    // Vertical rhythm (px).
    private const BAR_H = 26;
    private const BAR_GAP = 12;
    private const PANEL_TITLE_H = 26;
    private const PANEL_GAP = 18;
    private const SECTION_TITLE_H = 34;
    private const SECTION_GAP = 12;
    private const HEADER_H = 92;
    private const FOOTER_PAD = 16;

    /** @var array<string, array{0: string, 1: string}> metric color => [fill, stroke] */
    private const PALETTE = [
        'blue'  => ['rgb(59, 130, 246)', 'rgb(37, 99, 235)'],
        'green' => ['rgb(16, 185, 129)', 'rgb(5, 150, 105)'],
        'amber' => ['rgb(245, 158, 11)', 'rgb(217, 119, 6)'],
    ];

    /** @var list<array{metric: string, label: string, color: string}> */
    private const METRICS = [
        ['metric' => 'set_throughput',   'label' => 'SET ops/sec',   'color' => 'blue'],
        ['metric' => 'get_throughput',   'label' => 'GET ops/sec',   'color' => 'green'],
        ['metric' => 'total_throughput', 'label' => 'Total ops/sec', 'color' => 'amber'],
    ];

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
        $svg = $this->render($context, $generatedAt, $basicResults, $taggedResults);

        $bytesWritten = file_put_contents($outputPath, $svg);
        if ($bytesWritten === false) {
            throw new \RuntimeException(sprintf('Unable to write SVG chart to %s', $outputPath));
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
        /** @var list<array{title: string, results: list<array<string, mixed>>}> $sections */
        $sections = [];
        if ($taggedResults !== []) {
            $sections[] = [
                'title' => 'Tagged Benchmark',
                'results' => array_map(fn(TaggedBenchmarkResult $r) => $r->toArray(), $taggedResults),
            ];
        }
        if ($basicResults !== []) {
            $sections[] = [
                'title' => 'Basic Benchmark',
                'results' => array_map(fn(BenchmarkResult $r) => $r->toArray(), $basicResults),
            ];
        }

        $body = '';
        $y = self::HEADER_H;
        foreach ($sections as $section) {
            $rendered = $this->renderSection($section['title'], $section['results'], $y);
            $body .= $rendered['svg'];
            $y = $rendered['nextY'] + self::SECTION_GAP;
        }

        if ($sections === []) {
            $body .= $this->text(self::PAD_X, $y + 20, 'No benchmark results captured.', 14, '#6b7280');
            $y += 40;
        }

        $height = (int) ($y + self::FOOTER_PAD);
        $header = $this->renderHeader($context, $generatedAt);
        $width = self::WIDTH;

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif">
  <rect x="0" y="0" width="{$width}" height="{$height}" fill="#ffffff"/>
{$header}
{$body}</svg>

SVG;
    }

    /**
     * @param array{items: int, host: string, port: int, tags: list<string>} $context
     */
    private function renderHeader(array $context, \DateTimeImmutable $generatedAt): string
    {
        $items = number_format($context['items']);
        $when = $generatedAt->format('Y-m-d H:i:s T');
        $tags = implode(', ', $context['tags']);
        $meta = sprintf(
            '%s items per adapter  ·  %s:%d  ·  tags: %s  ·  %s',
            $items,
            $context['host'],
            $context['port'],
            $tags,
            $when,
        );

        $svg  = $this->text(self::PAD_X, 36, 'IDCT Rapid Cache — Benchmark', 22, '#111827', 'bold');
        $svg .= $this->text(self::PAD_X, 60, $meta, 12, '#6b7280');
        $svg .= sprintf(
            '  <line x1="%d" y1="74" x2="%d" y2="74" stroke="#e5e7eb" stroke-width="1"/>' . "\n",
            self::PAD_X,
            self::WIDTH - self::PAD_X,
        );

        return $svg;
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array{svg: string, nextY: float}
     */
    private function renderSection(string $title, array $results, float $startY): array
    {
        $svg = $this->text(self::PAD_X, $startY + 22, $title, 17, '#111827', 'bold');
        $y = $startY + self::SECTION_TITLE_H;

        foreach (self::METRICS as $metric) {
            $rendered = $this->renderPanel($results, $metric['metric'], $metric['label'], $metric['color'], $y);
            $svg .= $rendered['svg'];
            $y = $rendered['nextY'] + self::PANEL_GAP;
        }

        return ['svg' => $svg, 'nextY' => $y - self::PANEL_GAP];
    }

    /**
     * Renders one metric as a horizontal bar group, one bar per adapter,
     * sorted fastest-first so the winning bar is on top.
     *
     * @param list<array<string, mixed>> $results
     * @return array{svg: string, nextY: float}
     */
    private function renderPanel(
        array $results,
        string $metricKey,
        string $label,
        string $color,
        float $startY,
    ): array {
        usort($results, fn(array $a, array $b) => (float) $b[$metricKey] <=> (float) $a[$metricKey]);

        [$fill, $stroke] = self::PALETTE[$color];
        $maxValue = 0.0;
        foreach ($results as $row) {
            $maxValue = max($maxValue, (float) $row[$metricKey]);
        }

        $barAreaX = self::PAD_X + self::LABEL_COL_W;
        $barAreaW = self::WIDTH - $barAreaX - self::VALUE_COL_W - self::PAD_X;

        $svg = $this->text(self::PAD_X, $startY + 16, $label, 13, '#374151', 'bold');
        $y = $startY + self::PANEL_TITLE_H;

        foreach ($results as $row) {
            $value = (float) $row[$metricKey];
            $barW = $maxValue > 0.0 ? (int) round($value / $maxValue * $barAreaW) : 0;
            $textY = $y + self::BAR_H / 2 + 4;

            // Adapter name in the right-aligned gutter.
            $svg .= sprintf(
                '  <text x="%d" y="%.1f" font-size="12" fill="#374151" text-anchor="end">%s</text>' . "\n",
                $barAreaX - 10,
                $textY,
                $this->h((string) $row['adapter']),
            );

            // Bar (min 1px so a non-zero value is always visible).
            $svg .= sprintf(
                '  <rect x="%d" y="%.1f" width="%d" height="%d" rx="3" fill="%s" stroke="%s" stroke-width="1"/>' . "\n",
                $barAreaX,
                $y,
                max($barW, $value > 0.0 ? 1 : 0),
                self::BAR_H,
                $fill,
                $stroke,
            );

            // Value printed just past the bar end.
            $svg .= sprintf(
                '  <text x="%d" y="%.1f" font-size="12" fill="#111827" text-anchor="start">%s</text>' . "\n",
                $barAreaX + max($barW, 1) + 8,
                $textY,
                $this->h(number_format($value)),
            );

            $y += self::BAR_H + self::BAR_GAP;
        }

        return ['svg' => $svg, 'nextY' => $y - self::BAR_GAP];
    }

    private function text(
        float $x,
        float $y,
        string $content,
        int $size,
        string $fill,
        string $weight = 'normal',
    ): string {
        return sprintf(
            '  <text x="%.1f" y="%.1f" font-size="%d" fill="%s" font-weight="%s">%s</text>' . "\n",
            $x,
            $y,
            $size,
            $fill,
            $weight,
            $this->h($content),
        );
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

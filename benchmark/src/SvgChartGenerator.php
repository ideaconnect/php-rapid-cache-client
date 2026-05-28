<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

/**
 * Renders a static, self-contained SVG bar chart for a RapidCache feature run.
 *
 * Unlike {@see HtmlReportGenerator} (which draws with Chart.js at runtime),
 * this emits a plain `<svg>` with no scripts - so it renders inline on GitHub
 * (README, issues) and anywhere else that strips JavaScript. The output is
 * deterministic for a given set of results, which keeps committed/published
 * images diff-friendly.
 *
 * Layout: one panel per category (Core / Tagging / Counters), one horizontal
 * bar per operation. Each panel owns its own scale so a high-throughput
 * category (counters) doesn't flatten a slower one (tagged writes).
 */
class SvgChartGenerator
{
    private const WIDTH = 900;

    // Horizontal layout (px).
    private const PAD_X = 24;
    private const LABEL_COL_W = 184;   // operation-name gutter, right-aligned
    private const VALUE_COL_W = 150;   // room for the value printed after a bar

    // Vertical rhythm (px).
    private const BAR_H = 24;
    private const BAR_GAP = 10;
    private const PANEL_TITLE_H = 28;
    private const PANEL_GAP = 22;
    private const HEADER_H = 110;
    private const FOOTER_PAD = 20;

    /** @var array<string, array{0: string, 1: string}> category => [fill, stroke] */
    private const PALETTE = [
        'Core'          => ['rgb(59, 130, 246)', 'rgb(37, 99, 235)'],
        'Tagging'       => ['rgb(16, 185, 129)', 'rgb(5, 150, 105)'],
        'Counters'      => ['rgb(245, 158, 11)', 'rgb(217, 119, 6)'],
        'Hash Core'     => ['rgb(139, 92, 246)', 'rgb(124, 58, 237)'],
        'Hash Fields'   => ['rgb(236, 72, 153)', 'rgb(219, 39, 119)'],
        'Hash Tagging'  => ['rgb(20, 184, 166)', 'rgb(13, 148, 136)'],
        'Hash Counters' => ['rgb(244, 63, 94)',  'rgb(225, 29, 72)'],
    ];
    private const DEFAULT_COLOR = ['rgb(107, 114, 128)', 'rgb(75, 85, 99)'];

    /**
     * @param array{items: int, host: string, port: int, system?: SystemInfo, generatedAt?: \DateTimeImmutable} $context
     * @param list<OperationResult> $results
     */
    public function generate(string $outputPath, array $context, array $results): void
    {
        $generatedAt = $context['generatedAt'] ?? new \DateTimeImmutable();
        $svg = $this->render($context, $generatedAt, $results);

        $bytesWritten = file_put_contents($outputPath, $svg);
        if ($bytesWritten === false) {
            throw new \RuntimeException(sprintf('Unable to write SVG chart to %s', $outputPath));
        }
    }

    /**
     * @param array{items: int, host: string, port: int, system?: SystemInfo} $context
     * @param list<OperationResult> $results
     */
    private function render(array $context, \DateTimeImmutable $generatedAt, array $results): string
    {
        /** @var array<string, list<OperationResult>> $byCategory */
        $byCategory = [];
        foreach ($results as $result) {
            $byCategory[$result->category][] = $result;
        }

        $body = '';
        $y = self::HEADER_H;
        foreach ($byCategory as $category => $rows) {
            $rendered = $this->renderPanel($category, $rows, $y);
            $body .= $rendered['svg'];
            $y = $rendered['nextY'] + self::PANEL_GAP;
        }

        if ($byCategory === []) {
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
     * @param array{items: int, host: string, port: int, system?: SystemInfo} $context
     */
    private function renderHeader(array $context, \DateTimeImmutable $generatedAt): string
    {
        $items = number_format($context['items']);
        $when = $generatedAt->format('Y-m-d H:i:s T');
        $meta = sprintf(
            '%s items  ·  %s:%d  ·  %s  ·  ops/sec, higher is better',
            $items,
            $context['host'],
            $context['port'],
            $when,
        );

        $system = $context['system'] ?? null;
        $systemLine = $system instanceof SystemInfo ? $system->summaryLine() : '';

        $svg  = $this->text(self::PAD_X, 34, 'IDCT Rapid Cache — Feature Benchmark', 22, '#111827', 'bold');
        $svg .= $this->text(self::PAD_X, 58, $meta, 12, '#6b7280');
        if ($systemLine !== '') {
            $svg .= $this->text(self::PAD_X, 78, $systemLine, 12, '#6b7280');
        }
        $svg .= sprintf(
            '  <line x1="%d" y1="92" x2="%d" y2="92" stroke="#e5e7eb" stroke-width="1"/>' . "\n",
            self::PAD_X,
            self::WIDTH - self::PAD_X,
        );

        return $svg;
    }

    /**
     * Renders one category as a horizontal bar group, one bar per operation,
     * sorted fastest-first. Scale is local to the category.
     *
     * @param list<OperationResult> $rows
     * @return array{svg: string, nextY: float}
     */
    private function renderPanel(string $category, array $rows, float $startY): array
    {
        usort($rows, fn(OperationResult $a, OperationResult $b) => $b->opsPerSec() <=> $a->opsPerSec());

        [$fill, $stroke] = self::PALETTE[$category] ?? self::DEFAULT_COLOR;
        $maxValue = 0.0;
        foreach ($rows as $row) {
            $maxValue = max($maxValue, $row->opsPerSec());
        }

        $barAreaX = self::PAD_X + self::LABEL_COL_W;
        $barAreaW = self::WIDTH - $barAreaX - self::VALUE_COL_W - self::PAD_X;

        $svg = $this->text(self::PAD_X, $startY + 18, $category, 16, '#111827', 'bold');
        $y = $startY + self::PANEL_TITLE_H;

        foreach ($rows as $row) {
            $value = $row->opsPerSec();
            $barW = $maxValue > 0.0 ? (int) round($value / $maxValue * $barAreaW) : 0;
            $textY = $y + self::BAR_H / 2 + 4;

            // Operation name in the right-aligned gutter.
            $svg .= sprintf(
                '  <text x="%d" y="%.1f" font-size="12" fill="#374151" text-anchor="end">%s</text>' . "\n",
                $barAreaX - 10,
                $textY,
                $this->h($row->operation),
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
                '  <text x="%d" y="%.1f" font-size="12" fill="#111827" text-anchor="start">%s ops/sec</text>' . "\n",
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

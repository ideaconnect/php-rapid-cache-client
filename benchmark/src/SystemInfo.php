<?php

declare(strict_types=1);

namespace IDCT\RapidCacheBenchmark;

/**
 * Captures the machine the benchmark ran on, so a published chart is
 * interpretable: throughput is meaningless without knowing the CPU, the
 * available memory, and whether it ran on a shared CI runner.
 *
 * Detection is Linux-first (reads /proc), which covers both local Linux/WSL2
 * and GitHub Actions runners, with graceful fallbacks elsewhere.
 */
final class SystemInfo
{
    public function __construct(
        public readonly string $cpu,
        public readonly int $cpuCores,
        public readonly ?int $memoryTotalBytes,
        public readonly ?int $memoryAvailableBytes,
        public readonly bool $githubActions,
    ) {}

    public static function detect(): self
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo') ?: '';
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';

        return new self(
            cpu: self::detectCpu($cpuinfo),
            cpuCores: self::detectCores($cpuinfo),
            memoryTotalBytes: self::matchKb($meminfo, 'MemTotal'),
            memoryAvailableBytes: self::matchKb($meminfo, 'MemAvailable'),
            githubActions: getenv('GITHUB_ACTIONS') === 'true',
        );
    }

    public function environment(): string
    {
        return $this->githubActions ? 'GitHub Actions' : 'Local machine';
    }

    public function cpuLabel(): string
    {
        return sprintf('%s (%d cores)', $this->cpu, $this->cpuCores);
    }

    /** e.g. "15.6 GB total, 12.3 GB available". */
    public function memoryLabel(): string
    {
        if ($this->memoryTotalBytes === null) {
            return 'unknown';
        }
        $label = self::humanBytes($this->memoryTotalBytes) . ' total';
        if ($this->memoryAvailableBytes !== null) {
            $label .= ', ' . self::humanBytes($this->memoryAvailableBytes) . ' available';
        }
        return $label;
    }

    /** Single-line summary for the SVG header. */
    public function summaryLine(): string
    {
        $memory = $this->memoryTotalBytes !== null
            ? self::humanBytes($this->memoryTotalBytes) . ' RAM'
            : 'unknown RAM';
        if ($this->memoryAvailableBytes !== null) {
            $memory .= sprintf(' (%s free)', self::humanBytes($this->memoryAvailableBytes));
        }

        return sprintf('%s  ·  %s  ·  %s', $this->cpuLabel(), $memory, $this->environment());
    }

    private static function detectCpu(string $cpuinfo): string
    {
        // x86: "model name"; some ARM kernels expose "Model" instead.
        if (preg_match('/^model name\s*:\s*(.+)$/mi', $cpuinfo, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/^Model\s*:\s*(.+)$/mi', $cpuinfo, $m) === 1) {
            return trim($m[1]);
        }
        $machine = php_uname('m');
        return $machine !== '' ? $machine : 'unknown CPU';
    }

    private static function detectCores(string $cpuinfo): int
    {
        $count = preg_match_all('/^processor\s*:/mi', $cpuinfo);
        if ($count > 0) {
            return $count;
        }
        // Fallback when /proc is unavailable (don't depend on shell_exec).
        $env = getenv('NUMBER_OF_PROCESSORS');
        return $env !== false && (int) $env > 0 ? (int) $env : 1;
    }

    private static function matchKb(string $meminfo, string $field): ?int
    {
        if (preg_match('/^' . preg_quote($field, '/') . ':\s*(\d+)\s*kB/mi', $meminfo, $m) === 1) {
            return (int) $m[1] * 1024;
        }
        return null;
    }

    private static function humanBytes(int $bytes): string
    {
        $gb = $bytes / (1024 ** 3);
        if ($gb >= 1) {
            return sprintf('%.1f GB', $gb);
        }
        return sprintf('%.0f MB', $bytes / (1024 ** 2));
    }
}

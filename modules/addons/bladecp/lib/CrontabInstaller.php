<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Adds or removes the one module cron line in the crontab of the system user
 * that runs the WHMCS PHP.
 *
 * The crontab binary runs through proc_open with fixed arguments, never a
 * shell. The current crontab is read first and every other line is kept byte
 * for byte, comments and variables such as MAILTO included. Only the line
 * that ends with the marker comment is ever replaced or removed.
 */
final class CrontabInstaller
{
    public const MARKER = '# bladecp-whmcs-cron';
    private const BINARIES = ['/usr/bin/crontab', '/bin/crontab', '/usr/local/bin/crontab'];

    /** @var (callable(list<string>,?string):array{0:int,1:string,2:string})|null */
    private $runner;

    private bool $processSupport;

    /**
     * @param (callable(list<string>,?string):array{0:int,1:string,2:string})|null $runner
     *        replacement process runner, used by tests
     * @param bool|null $processSupport whether proc_open may run; detected when null
     */
    public function __construct(private ?string $binary = null, ?callable $runner = null, ?bool $processSupport = null)
    {
        $this->runner = $runner;
        $this->processSupport = $processSupport ?? function_exists('proc_open');
    }

    public static function system(): self
    {
        foreach (self::BINARIES as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return new self($candidate);
            }
        }
        return new self(null);
    }

    /** @return array{available:bool,reason:string} reason is proc_open or crontab when unavailable */
    public function availability(): array
    {
        if (!$this->processSupport) {
            return ['available' => false, 'reason' => 'proc_open'];
        }
        if ($this->binary === null || $this->binary === '') {
            return ['available' => false, 'reason' => 'crontab'];
        }
        return ['available' => true, 'reason' => ''];
    }

    /** Our crontab line: every minute, output discarded, marked for later removal. */
    public static function line(string $command): string
    {
        return '* * * * * ' . trim($command) . ' >/dev/null 2>&1 ' . self::MARKER;
    }

    /**
     * The crontab with our line set to $line, or removed when $line is null.
     * Every other line, and its line ending, is kept exactly as it was.
     */
    public static function withLine(string $current, ?string $line): string
    {
        $kept = '';
        foreach ($current === '' ? [] : (preg_split('/(?<=\n)/', $current) ?: []) as $raw) {
            if ($raw !== '' && !self::isOurs($raw)) {
                $kept .= $raw;
            }
        }
        if ($line === null) {
            return $kept;
        }
        if ($kept !== '' && !str_ends_with($kept, "\n")) {
            // A crontab must end with a newline before another line follows.
            $kept .= "\n";
        }
        return $kept . $line . "\n";
    }

    public static function contains(string $current, string $line): bool
    {
        foreach (preg_split('/\r?\n/', $current) ?: [] as $raw) {
            if ($raw === $line) {
                return true;
            }
        }
        return false;
    }

    /** @return array{ok:bool,content?:string,error?:string} */
    public function read(): array
    {
        if (!$this->availability()['available']) {
            return ['ok' => false, 'error' => 'unavailable'];
        }
        [$code, $output, $error] = $this->exec(['-l'], null);
        if ($code === 0) {
            return ['ok' => true, 'content' => $output];
        }
        // An empty crontab is reported as an error by most crontab versions.
        if (stripos($error, 'no crontab for') !== false) {
            return ['ok' => true, 'content' => ''];
        }
        return ['ok' => false, 'error' => 'read_failed'];
    }

    public function installed(string $command): ?bool
    {
        $current = $this->read();
        return ($current['ok'] ?? false) === true ? self::contains((string) $current['content'], self::line($command)) : null;
    }

    /** @return array{ok:bool,changed:bool,error?:string} */
    public function install(string $command): array
    {
        return $this->apply(self::line($command));
    }

    /** @return array{ok:bool,changed:bool,error?:string} */
    public function uninstall(): array
    {
        return $this->apply(null);
    }

    /** @return array{ok:bool,changed:bool,error?:string} */
    private function apply(?string $line): array
    {
        $availability = $this->availability();
        if (!$availability['available']) {
            return ['ok' => false, 'changed' => false, 'error' => $availability['reason']];
        }
        $current = $this->read();
        if (($current['ok'] ?? false) !== true) {
            return ['ok' => false, 'changed' => false, 'error' => (string) ($current['error'] ?? 'read_failed')];
        }
        $before = (string) $current['content'];
        if ($line !== null && self::contains($before, $line)) {
            return ['ok' => true, 'changed' => false];
        }
        $after = self::withLine($before, $line);
        if ($after === $before) {
            return ['ok' => true, 'changed' => false];
        }
        [$code] = $this->exec(['-'], $after);
        if ($code !== 0) {
            return ['ok' => false, 'changed' => false, 'error' => 'write_failed'];
        }
        $verify = $this->read();
        if (($verify['ok'] ?? false) !== true || (string) $verify['content'] !== $after) {
            return ['ok' => false, 'changed' => true, 'error' => 'verify_failed'];
        }
        return ['ok' => true, 'changed' => true];
    }

    /**
     * @param list<string> $arguments
     * @return array{0:int,1:string,2:string}
     */
    private function exec(array $arguments, ?string $input): array
    {
        $command = array_merge([(string) $this->binary], $arguments);
        if ($this->runner !== null) {
            return ($this->runner)($command, $input);
        }
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } catch (\Throwable) {
            return [127, '', 'proc_open_failed'];
        }
        if (!is_resource($process)) {
            return [127, '', 'proc_open_failed'];
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }

    private static function isOurs(string $raw): bool
    {
        return str_ends_with(rtrim($raw, "\r\n"), self::MARKER);
    }
}

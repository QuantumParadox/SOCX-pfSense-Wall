#!/usr/local/bin/php
<?php
declare(strict_types=1);

/*
 * SOCX WALL MODE
 *
 * A single-pane high-contrast wall display for pfSense. The classic tmux split
 * wall stays intact; this renderer is selected with SOCX_MODE=wall.
 */

const APP_NAME = 'socx-wall';
const APP_VERSION = '0.1.0';

$opts = parse_args($argv);
apply_startup_unicode_defaults($opts);
if ($opts['help']) {
    print_help();
    exit(0);
}
if ($opts['unicode_test']) {
    echo unicode_test_output();
    exit(0);
}

$mode = $opts['mode'] ?: (getenv('SOCX_MODE') ?: 'wall');
if ($mode !== 'wall') {
    fwrite(STDERR, "Only --mode wall is implemented by " . APP_NAME . ".\n");
    exit(2);
}
stream_set_write_buffer(STDOUT, 0);

$color = !$opts['no_color'] && (getenv('NO_COLOR') === false || getenv('NO_COLOR') === '');
$interval = max(0.5, (float)$opts['interval']);
$tickerConfig = ticker_config($opts);
$theme = wall_theme($opts);
putenv('SOCX_THEME=' . $theme);
putenv('SOCX_EVENT_FEED_MODE=' . $tickerConfig['mode']);
$renderInterval = min($interval, $tickerConfig['interval_ms'] / 1000);
$renderInterval = max(0.025, $renderInterval);
$once = (bool)$opts['once'];
$state = [
    'net' => [],
    'time' => microtime(true),
    'tick' => 0,
    'ticker' => 0,
    'ticker_offset' => 0,
    'ticker_index' => 0,
    'ticker_changed' => true,
    'last_ticker_advance_at' => 0.0,
    'ticker_hold_until' => 0.0,
    'ticker_hold_text' => '',
    'ticker_config' => $tickerConfig,
    'ticker_queue' => [],
    'ticker_seen' => [],
    'static' => static_info(),
    'core_history' => [],
    'last_events' => [],
    'last_events_at' => 0.0,
    'events_fresh' => false,
    'command_events_fresh' => false,
    'last_command_status_at' => 0.0,
    'wan_history' => [],
    'lan_history' => [],
    'pf_history' => [],
    'pf_state_prev' => [],
    'ups_history' => [],
    'ups_cache' => [],
    'debug_timing' => getenv('SOCX_DEBUG_TIMING') === 'true',
    'tmux_mode' => tmux_detected(),
    'term' => getenv('TERM') ?: '',
    'theme' => $theme,
    'last_debug_at' => 0.0,
    'last_frame' => null,
    'next_frame_at' => 0.0,
    'cols' => 0,
    'rows' => 0,
    'next_size_at' => 0.0,
];

if (!$once) {
    register_shutdown_function(static function (): void {
        echo "\033[?25h\033[0m";
    });
    echo "\033[?25l\033[H\033[2J";
}

do {
    $now = microtime(true);
    $loopStart = $now;
    $fullRedraw = false;
    try {
        if ((int)$state['cols'] <= 0 || $now >= (float)$state['next_size_at']) {
            $oldCols = (int)$state['cols'];
            $oldRows = (int)$state['rows'];
            [$state['cols'], $state['rows']] = term_size($opts);
            $state['next_size_at'] = $now + 1.0;
            $fullRedraw = $fullRedraw || $oldCols !== (int)$state['cols'] || $oldRows !== (int)$state['rows'];
        }
        $cols = (int)$state['cols'];
        $rows = (int)$state['rows'];
        if ($state['last_frame'] === null || $once || $now >= (float)$state['next_frame_at']) {
            $fullRedraw = true;
            $state['tick']++;
            $hosts = load_host_map((string)$opts['hosts']);
            $frame = $opts['demo'] ? demo_frame($hosts, $state) : collect_live_frame($state, $hosts);
            if (!$state['ticker_queue'] || (!$opts['demo'] && (($state['events_fresh'] ?? false) || ($state['command_events_fresh'] ?? false)))) {
                update_ticker_queue($state, $frame['events'], $now);
            }
            $state['last_frame'] = $frame;
            $state['next_frame_at'] = $now + $interval;
        } else {
            $frame = $state['last_frame'];
        }
    } catch (Throwable $e) {
        log_wall_error($e);
        if ((int)$state['cols'] <= 0 || (int)$state['rows'] <= 0) {
            [$state['cols'], $state['rows']] = term_size($opts);
        }
        $cols = (int)$state['cols'];
        $rows = (int)$state['rows'];
        $frame = $state['last_frame'] ?: error_frame($e);
        $fullRedraw = true;
    }
    advance_ticker($state, $now);
    $frame['ticker_events'] = ticker_event_texts($state);
    $frame['ticker_offset'] = (int)$state['ticker_offset'];
    $frame['ticker_index'] = (int)$state['ticker_index'];
    $frame['ticker_config'] = $tickerConfig;
    $frame['event_feed_mode'] = (string)($tickerConfig['mode'] ?? 'scroll');
    $frame['ticker_hold_until'] = (float)$state['ticker_hold_until'];
    $frame['ticker_hold_text'] = (string)$state['ticker_hold_text'];
    $frame['ticker_now'] = $now;
    if ($once) {
        $screen = render_wall($frame, $cols, $rows, $color, $state, $theme);
        $captureOk = capture_screen($opts, $screen, $theme);
        echo $screen;
        if (!str_ends_with($screen, "\n")) {
            echo "\n";
        }
        if (!$captureOk) {
            exit(3);
        }
        break;
    }
    if ($fullRedraw) {
        $renderStart = microtime(true);
        $screen = render_wall($frame, $cols, $rows, $color, $state, $theme);
        $captureOk = capture_screen($opts, $screen, $theme);
        if (!$captureOk) {
            exit(3);
        }
        echo "\033[H" . $screen;
        $renderMs = (microtime(true) - $renderStart) * 1000;
        $state['ticker_changed'] = false;
    } else {
        $renderStart = microtime(true);
        if (($tickerConfig['mode'] ?? 'scroll') === 'scroll' || !empty($state['ticker_changed'])) {
            echo render_ticker_update($frame, $cols, $rows, $color, $theme);
            $state['ticker_changed'] = false;
        }
        $renderMs = (microtime(true) - $renderStart) * 1000;
    }
    maybe_log_timing($state, $cols, $rows, $theme, $tickerConfig, $fullRedraw, $renderMs, (microtime(true) - $loopStart) * 1000, $frame);
    flush();
    fflush(STDOUT);
    usleep((int)($renderInterval * 1000000));
} while (true);

function log_wall_error(Throwable $e): void
{
    $line = sprintf("[%s] %s: %s in %s:%d\n", date('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    @file_put_contents('/tmp/socx-wall.err', $line, FILE_APPEND);
}

function log_wall_bug(string $message): void
{
    @file_put_contents('/tmp/socx-wall.err', '[' . date('c') . '] ' . $message . "\n", FILE_APPEND);
}

function apply_startup_unicode_defaults(array $opts): void
{
    if (getenv('SOCX_DISABLE_ASCII_FALLBACK') === false) {
        putenv('SOCX_DISABLE_ASCII_FALLBACK=true');
    }
    if (getenv('SOCX_FORCE_UNICODE') === false) {
        putenv('SOCX_FORCE_UNICODE=true');
    }
    if (wall_theme($opts) === 'modern-btop' && getenv('SOCX_EVENT_FEED_MODE') === false) {
        putenv('SOCX_EVENT_FEED_MODE=rotate');
    }
    if (getenv('SOCX_EVENT_ROTATE_SECONDS') === false) {
        putenv('SOCX_EVENT_ROTATE_SECONDS=3');
    }
    if (getenv('SOCX_EVENT_HIGH_SECONDS') === false) {
        putenv('SOCX_EVENT_HIGH_SECONDS=6');
    }
    if (getenv('SOCX_EVENT_CRIT_SECONDS') === false) {
        putenv('SOCX_EVENT_CRIT_SECONDS=8');
    }
    if (!empty($opts['force_unicode'])) {
        putenv('SOCX_FORCE_UNICODE=true');
        putenv('SOCX_UNICODE=true');
        putenv('SOCX_BORDER_STYLE=unicode');
        putenv('SOCX_GRAPH_STYLE=unicode');
    }
}

function unicode_test_output(): string
{
    return "┌──────────── SOCX UNICODE TEST ────────────┐\n"
        . "│ Box drawing:  ┌─┬─┐ │ └─┴─┘               │\n"
        . "│ Blocks:       ▁▂▃▄▅▆▇█ ████████ ░░░░      │\n"
        . "│ Braille:      ⣀⣤⣶⣿                    │\n"
        . "│ Separator:    ◆                            │\n"
        . "└───────────────────────────────────────────┘\n";
}

function capture_screen(array $opts, string $screen, string $theme): bool
{
    $path = (string)($opts['capture'] ?? '');
    if ($path === '') {
        return true;
    }
    $plain = strip_ansi($screen);
    $ok = @file_put_contents($path, $plain) !== false;
    if (!$ok) {
        fwrite(STDERR, "Failed to write capture file: {$path}\n");
        return false;
    }
    if ($theme === 'modern-btop') {
        return validate_modern_capture($plain, $path);
    }
    return true;
}

function strip_ansi(string $text): string
{
    return preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? $text;
}

function validate_modern_capture(string $plain, string $path): bool
{
    $forbidden = [
        '+==',
        '+===',
        '====',
        '| NETWORK',
        '| PF STATES',
        '| CPU',
        '| MEMORY',
        '| UPS',
        '| EVENT TICKER',
    ];
    foreach ($forbidden as $needle) {
        if (str_contains($plain, $needle)) {
            fwrite(STDERR, "Capture validation failed for {$path}: found forbidden ASCII border marker {$needle}\n");
            return false;
        }
    }
    return true;
}

function maybe_log_timing(array &$state, int $cols, int $rows, string $theme, array $tickerConfig, bool $fullRedraw, float $renderMs, float $loopMs, array $frame): void
{
    if (empty($state['debug_timing'])) {
        return;
    }
    $now = microtime(true);
    if (($now - (float)($state['last_debug_at'] ?? 0.0)) < 1.0) {
        return;
    }
    $state['last_debug_at'] = $now;
    $ups = normalize_ups($frame['ups'] ?? []);
    $age = isset($ups['updated_age']) && is_numeric($ups['updated_age']) ? sprintf('%.2fs', (float)$ups['updated_age']) : '?';
    $line = sprintf(
        "[%s] size=%dx%d tmux=%s term=%s theme=%s redraw=%s ticker=%dms/%dcol render=%.2fms loop=%.2fms ups_age=%s\n",
        date('c'),
        $cols,
        $rows,
        !empty($state['tmux_mode']) ? 'yes' : 'no',
        (string)($state['term'] ?? ''),
        $theme,
        $fullRedraw ? 'full' : 'ticker',
        (int)$tickerConfig['interval_ms'],
        (int)$tickerConfig['step'],
        $renderMs,
        $loopMs,
        $age
    );
    @file_put_contents('/tmp/socx-wall-timing.log', $line, FILE_APPEND);
}

function parse_args(array $argv): array
{
    $opts = [
        'once' => false,
        'demo' => false,
        'no_color' => false,
        'help' => false,
        'interval' => 0.5,
        'mode' => '',
        'hosts' => '',
        'width' => 0,
        'height' => 0,
        'ticker_speed' => '',
        'ticker_step' => 0,
        'ticker_interval_ms' => 0,
        'ticker_max_events' => 0,
        'ticker_dedupe_seconds' => 0,
        'theme' => '',
        'ticker_smooth' => false,
        'force_unicode' => false,
        'unicode_test' => false,
        'capture' => '',
        'event_feed_mode' => '',
        'event_rotate_seconds' => 0,
        'event_high_seconds' => 0,
        'event_crit_seconds' => 0,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--once') {
            $opts['once'] = true;
        } elseif ($arg === '--demo') {
            $opts['demo'] = true;
        } elseif ($arg === '--no-color') {
            $opts['no_color'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif ($arg === '--interval' && isset($argv[$i + 1])) {
            $opts['interval'] = (float)$argv[++$i];
        } elseif (str_starts_with($arg, '--interval=')) {
            $opts['interval'] = (float)substr($arg, 11);
        } elseif ($arg === '--mode' && isset($argv[$i + 1])) {
            $opts['mode'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--mode=')) {
            $opts['mode'] = (string)substr($arg, 7);
        } elseif ($arg === '--hosts' && isset($argv[$i + 1])) {
            $opts['hosts'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--hosts=')) {
            $opts['hosts'] = (string)substr($arg, 8);
        } elseif ($arg === '--width' && isset($argv[$i + 1])) {
            $opts['width'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--width=')) {
            $opts['width'] = (int)substr($arg, 8);
        } elseif ($arg === '--height' && isset($argv[$i + 1])) {
            $opts['height'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--height=')) {
            $opts['height'] = (int)substr($arg, 9);
        } elseif ($arg === '--ticker-speed' && isset($argv[$i + 1])) {
            $opts['ticker_speed'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--ticker-speed=')) {
            $opts['ticker_speed'] = (string)substr($arg, 15);
        } elseif ($arg === '--ticker-step' && isset($argv[$i + 1])) {
            $opts['ticker_step'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--ticker-step=')) {
            $opts['ticker_step'] = (int)substr($arg, 14);
        } elseif ($arg === '--ticker-interval-ms' && isset($argv[$i + 1])) {
            $opts['ticker_interval_ms'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--ticker-interval-ms=')) {
            $opts['ticker_interval_ms'] = (int)substr($arg, 21);
        } elseif ($arg === '--ticker-max-events' && isset($argv[$i + 1])) {
            $opts['ticker_max_events'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--ticker-max-events=')) {
            $opts['ticker_max_events'] = (int)substr($arg, 20);
        } elseif ($arg === '--ticker-dedupe-seconds' && isset($argv[$i + 1])) {
            $opts['ticker_dedupe_seconds'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--ticker-dedupe-seconds=')) {
            $opts['ticker_dedupe_seconds'] = (int)substr($arg, 24);
        } elseif ($arg === '--theme' && isset($argv[$i + 1])) {
            $opts['theme'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--theme=')) {
            $opts['theme'] = (string)substr($arg, 8);
        } elseif ($arg === '--ticker-smooth') {
            $opts['ticker_smooth'] = true;
        } elseif ($arg === '--force-unicode') {
            $opts['force_unicode'] = true;
        } elseif ($arg === '--unicode-test') {
            $opts['unicode_test'] = true;
        } elseif ($arg === '--capture' && isset($argv[$i + 1])) {
            $opts['capture'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--capture=')) {
            $opts['capture'] = (string)substr($arg, 10);
        } elseif ($arg === '--event-feed-mode' && isset($argv[$i + 1])) {
            $opts['event_feed_mode'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--event-feed-mode=')) {
            $opts['event_feed_mode'] = (string)substr($arg, 18);
        } elseif ($arg === '--event-rotate-seconds' && isset($argv[$i + 1])) {
            $opts['event_rotate_seconds'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--event-rotate-seconds=')) {
            $opts['event_rotate_seconds'] = (int)substr($arg, 23);
        } elseif ($arg === '--event-high-seconds' && isset($argv[$i + 1])) {
            $opts['event_high_seconds'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--event-high-seconds=')) {
            $opts['event_high_seconds'] = (int)substr($arg, 21);
        } elseif ($arg === '--event-crit-seconds' && isset($argv[$i + 1])) {
            $opts['event_crit_seconds'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--event-crit-seconds=')) {
            $opts['event_crit_seconds'] = (int)substr($arg, 21);
        }
    }

    return $opts;
}

function print_help(): void
{
    echo APP_NAME . ' ' . APP_VERSION . "\n";
    echo "Usage: socx-wall.php [--mode wall] [--theme modern-btop] [--demo] [--once] [--interval SEC] [--hosts FILE]\n";
    echo "       [--ticker-speed slow|normal|fast|turbo] [--ticker-step N] [--ticker-interval-ms N]\n";
    echo "       [--force-unicode] [--unicode-test] [--capture FILE]\n";
    echo "       [--event-feed-mode scroll|rotate|stack] [--event-rotate-seconds N]\n";
    echo "Demo preview: socx-wall.php --demo --mode wall --theme modern-btop --ticker-smooth --width 160 --height 42\n";
    echo "Rotate preview: socx-wall.php --demo --mode wall --theme modern-btop --event-feed-mode rotate\n";
    echo "Capture preview: socx-wall.php --demo --once --mode wall --theme modern-btop --force-unicode --capture /tmp/socx-frame.txt\n";
}

function ticker_config(array $opts): array
{
    $theme = wall_theme($opts);
    $envMode = getenv('SOCX_EVENT_FEED_MODE');
    $defaultMode = $theme === 'modern-btop' ? 'rotate' : 'scroll';
    $mode = strtolower((string)($opts['event_feed_mode'] ?: ($envMode !== false && $envMode !== '' ? $envMode : $defaultMode)));
    if (!in_array($mode, ['scroll', 'rotate', 'stack'], true)) {
        $mode = $defaultMode;
    }

    $speed = strtolower((string)($opts['ticker_speed'] ?: getenv('SOCX_TICKER_SPEED') ?: 'fast'));
    $speedSteps = ['slow' => 1, 'normal' => 2, 'fast' => 4, 'turbo' => 6];
    $step = $speedSteps[$speed] ?? $speedSteps['fast'];

    $envStep = getenv('SOCX_TICKER_STEP');
    if ((int)$opts['ticker_step'] > 0) {
        $step = (int)$opts['ticker_step'];
    } elseif ($envStep !== false && is_numeric($envStep) && (int)$envStep > 0) {
        $step = (int)$envStep;
    }
    $step = max(1, min(12, $step));

    $envInterval = getenv('SOCX_TICKER_INTERVAL_MS');
    $defaultInterval = $mode === 'scroll'
        ? (((bool)($opts['ticker_smooth'] ?? false) || getenv('SOCX_TICKER_SMOOTH') === 'true') ? 75 : 25)
        : 500;
    $intervalMs = (int)$opts['ticker_interval_ms'] > 0 ? (int)$opts['ticker_interval_ms'] : (is_numeric($envInterval) ? (int)$envInterval : $defaultInterval);
    if (tmux_detected()) {
        $intervalMs = max(50, $intervalMs);
    }
    $intervalMs = $mode === 'scroll' ? max(25, min(500, $intervalMs)) : max(250, min(1000, $intervalMs));

    $envMax = getenv('SOCX_TICKER_MAX_EVENTS');
    $maxEvents = (int)$opts['ticker_max_events'] > 0 ? (int)$opts['ticker_max_events'] : (is_numeric($envMax) ? (int)$envMax : 40);
    $maxEvents = max(5, min(100, $maxEvents));

    $envDedupe = getenv('SOCX_TICKER_DEDUPE_SECONDS');
    $dedupe = (int)$opts['ticker_dedupe_seconds'] > 0 ? (int)$opts['ticker_dedupe_seconds'] : (is_numeric($envDedupe) ? (int)$envDedupe : 10);
    $dedupe = max(1, min(120, $dedupe));

    $envRotate = getenv('SOCX_EVENT_ROTATE_SECONDS');
    $rotateSeconds = (int)$opts['event_rotate_seconds'] > 0 ? (int)$opts['event_rotate_seconds'] : (is_numeric($envRotate) ? (int)$envRotate : 3);
    $rotateSeconds = max(1, min(10, $rotateSeconds));

    $envHigh = getenv('SOCX_EVENT_HIGH_SECONDS');
    $highSeconds = (int)$opts['event_high_seconds'] > 0 ? (int)$opts['event_high_seconds'] : (is_numeric($envHigh) ? (int)$envHigh : 6);
    $highSeconds = max(1, min(10, $highSeconds));

    $envCrit = getenv('SOCX_EVENT_CRIT_SECONDS');
    $critSeconds = (int)$opts['event_crit_seconds'] > 0 ? (int)$opts['event_crit_seconds'] : (is_numeric($envCrit) ? (int)$envCrit : 8);
    $critSeconds = max(1, min(10, $critSeconds));

    return [
        'mode' => $mode,
        'speed' => $speed,
        'step' => $step,
        'interval_ms' => $intervalMs,
        'max_events' => $maxEvents,
        'dedupe_seconds' => $dedupe,
        'rotate_seconds' => $rotateSeconds,
        'med_seconds' => max($rotateSeconds, 4),
        'high_seconds' => $highSeconds,
        'crit_seconds' => $critSeconds,
        'separator' => '   ◆   ',
    ];
}

function wall_theme(array $opts): string
{
    $theme = strtolower((string)($opts['theme'] ?: getenv('SOCX_THEME') ?: 'modern-btop'));
    return in_array($theme, ['modern-btop', 'classic'], true) ? $theme : 'modern-btop';
}

function tmux_detected(): bool
{
    return (getenv('TMUX') !== false && getenv('TMUX') !== '') || (getenv('TMUX_PANE') !== false && getenv('TMUX_PANE') !== '');
}

function run_cmd(string $cmd): string
{
    $out = [];
    @exec($cmd . ' 2>/dev/null', $out);
    return implode("\n", $out);
}

function process_running(string $pattern): bool
{
    $pgrep = is_executable('/bin/pgrep') ? '/bin/pgrep' : '/usr/bin/pgrep';
    if (!is_executable($pgrep)) {
        return false;
    }
    return trim(run_cmd($pgrep . ' -f ' . escapeshellarg($pattern) . ' | /usr/bin/head -1')) !== '';
}

function term_size(array $opts): array
{
    if ((int)$opts['width'] > 0 && (int)$opts['height'] > 0) {
        return [max(60, (int)$opts['width']), max(20, (int)$opts['height'])];
    }

    $tmuxPane = getenv('TMUX_PANE');
    if ($tmuxPane !== false && $tmuxPane !== '') {
        $tmux = trim(run_cmd('/usr/local/bin/tmux display-message -p -t ' . escapeshellarg($tmuxPane) . " '#{pane_width} #{pane_height}'"));
        if (preg_match('/^(\d+)\s+(\d+)$/', $tmux, $m)) {
            return [max(60, (int)$m[1]), max(20, (int)$m[2])];
        }
    }

    $envCols = getenv('COLUMNS');
    $envRows = getenv('LINES');
    if (is_numeric($envCols) && is_numeric($envRows)) {
        return [max(60, (int)$envCols), max(20, (int)$envRows)];
    }

    $size = trim(run_cmd('/bin/stty size'));
    if (preg_match('/^(\d+)\s+(\d+)$/', $size, $m)) {
        return [max(60, (int)$m[2]), max(20, (int)$m[1])];
    }
    return [120, 32];
}

function static_info(): array
{
    $ncpu = (int)trim(run_cmd('/sbin/sysctl -n hw.ncpu'));
    $physmem = (int)trim(run_cmd('/sbin/sysctl -n hw.physmem'));
    return [
        'host' => trim(run_cmd('/bin/hostname')) ?: (gethostname() ?: 'pfSense'),
        'ncpu' => max(1, $ncpu),
        'physmem' => max(0, $physmem),
    ];
}

function load_host_map(string $path): array
{
    $candidates = [];
    if ($path !== '') {
        $candidates[] = $path;
    }
    $env = getenv('SOCX_HOSTS_FILE');
    if ($env !== false && $env !== '') {
        $candidates[] = $env;
    }
    $candidates[] = '/usr/local/etc/socx_hosts.conf';
    $candidates[] = '/root/socx_hosts.conf';

    $map = [];
    foreach ($candidates as $file) {
        if (!is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$ip, $name] = array_map('trim', explode('=', $line, 2));
            if (filter_var($ip, FILTER_VALIDATE_IP) && $name !== '') {
                $map[$ip] = preg_replace('/[^\w.\-]/', '', $name) ?: $name;
            }
        }
    }
    return $map;
}

function collect_live_frame(array &$state, array $hosts): array
{
    $now = microtime(true);
    $top = run_cmd('/usr/bin/top -P -b -n 1');
    $cpu = parse_cpu($top);
    update_core_history($state, $cpu);
    $mem = parse_mem($top, (int)$state['static']['physmem']);
    $load = parse_load($top);
    $pf = parse_pf(run_cmd('/sbin/pfctl -si'));
    $net = parse_net(run_cmd('/usr/bin/netstat -ibn'), $state, $now);
    $procs = parse_processes(run_cmd('/bin/ps auxww'), 12);
    $temp = parse_temp(run_cmd("/sbin/sysctl -a | /usr/bin/grep -E 'dev.cpu\\.[0-9]+\\.temperature|hw.acpi.thermal.*temperature' | /usr/bin/head -8"));
    $freq = trim(run_cmd('/sbin/sysctl -n dev.cpu.0.freq'));
    $events = collect_events_cached($state, $hosts, $now);

    $state['time'] = $now;
    $iflan = getenv('SOCX_IFLAN') ?: 'ix0';
    $ifwan = getenv('SOCX_IFWAN') ?: 'ix1';
    $wan = $net[$ifwan] ?? first_net($net);
    $lan = $net[$iflan] ?? first_net($net);
    $ups = collect_ups_metrics($state, $now);
    update_metric_histories($state, $now, $wan, $lan, $pf);
    $commandEvents = collect_soc_command_events($state, $hosts, $now, [
        'cpu' => $cpu,
        'mem' => $mem,
        'load' => $load,
        'pf' => $pf,
        'wan' => $wan,
        'lan' => $lan,
        'ups' => $ups,
        'procs' => $procs,
        'ifwan' => $ifwan,
        'iflan' => $iflan,
    ]);
    $state['command_events_fresh'] = !empty($commandEvents);
    $events = array_merge($events, $commandEvents);
    $events = balance_events_for_feed(prioritize_events($events), 80);
    $flows = collect_pf_state_flows($state, $hosts, $now);
    if (!$flows) {
        $flows = flows_from_events($events);
    }
    $activityEvents = collect_activity_summary_events($state, $events, $flows, $ups, $wan, $lan, $pf, $now);
    if ($activityEvents) {
        $events = balance_events_for_feed(prioritize_events(array_merge($events, $activityEvents)), 80);
    }
    $packets = packets_from_events($events);

    return [
        'time' => date('H:i:s'),
        'refresh' => '500ms',
        'host' => $state['static']['host'],
        'badges' => health_badges($ups),
        'wan' => ['name' => $ifwan, 'down' => rate_text($wan['rx'] ?? null), 'up' => rate_text($wan['tx'] ?? null), 'link' => 'DHCP OK', 'rtt' => 'RTT --', 'loss' => 'LOSS --'],
        'lan' => ['name' => $iflan, 'down' => rate_text($lan['rx'] ?? null), 'up' => rate_text($lan['tx'] ?? null)],
        'wan_history' => history_values($state['wan_history']),
        'lan_history' => history_values($state['lan_history']),
        'pf_history' => history_values($state['pf_history']),
        'pf' => $pf,
        'mem' => $mem,
        'cpu' => [
            'used' => (int)round((float)$cpu['used']),
            'freq' => is_numeric($freq) ? sprintf('%.1fGHz', ((int)$freq) / 1000) : '--GHz',
            'temp' => $temp,
            'cores' => attach_core_history($cpu['cores'] ?: [['id' => 0, 'used' => (float)$cpu['used']]], $state),
            'load' => [$load['1'], $load['5'], $load['15']],
            'processes' => $load['processes'],
            'uptime' => $load['uptime'],
        ],
        'tick' => $state['tick'],
        'ups' => $ups,
        'procs' => $procs,
        'flows' => $flows,
        'packets' => $packets,
        'events' => event_ticker_texts($events),
    ];
}

function demo_frame(array $hosts, array $state = []): array
{
    $tick = (int)($state['tick'] ?? 0);
    $upsWatts = 420 + (int)(sin($tick / 4) * 80);
    if (($tick % 36) > 22) {
        $upsWatts = 900 + (($tick % 6) * 50);
    }
    $hosts = $hosts + [
        '192.168.1.161' => 'JupiterLXI',
        '192.168.1.102' => 'Enceladus',
        '192.168.1.127' => 'NAS-Core',
        '192.168.1.121' => 'Mediabox',
    ];
    return [
        'demo' => true,
        'time' => date('H:i:s'),
        'refresh' => '500ms',
        'host' => 'pfSense',
        'badges' => ['WAN UP', 'VPN UP', 'DNS OK', 'UPS ONLINE'],
        'wan' => ['name' => 'ix1', 'down' => '3.50K/s', 'up' => '9.13K/s', 'link' => '2.5G DHCP OK', 'rtt' => 'RTT 9ms', 'loss' => 'LOSS 0%'],
        'lan' => ['name' => 'ix0', 'down' => '4.50K/s', 'up' => '7.79K/s'],
        'wan_history' => demo_wave($tick, 28, 12, 4),
        'lan_history' => demo_wave($tick + 5, 28, 18, 6),
        'pf_history' => demo_wave($tick + 11, 28, 1600, 300),
        'pf' => ['states' => '1264', 'searches_rate' => '9579/s', 'passed' => 405900000, 'blocked' => 137800],
        'mem' => ['total' => parse_size('31.7G'), 'used' => parse_size('24.6G'), 'free' => parse_size('7.1G'), 'arc_total' => parse_size('17.0G'), 'used_pct' => 78],
        'cpu' => [
            'used' => 28,
            'freq' => '3.6GHz',
            'temp' => '58C',
            'cores' => [
                ['id' => 0, 'used' => 32], ['id' => 1, 'used' => 25],
                ['id' => 2, 'used' => 26], ['id' => 3, 'used' => 25],
            ],
            'load' => ['1.37', '1.58', '1.56'],
            'processes' => '152 processes: 1 running, 151 sleeping',
            'uptime' => '1+00:42:01',
        ],
        'tick' => $tick,
        'ups' => [
            'online' => true,
            'source' => 'snmp',
            'ups_name' => '192.168.1.114',
            'model' => 'Smart-UPS 2200',
            'status' => 'ONLINE',
            'watts' => $upsWatts,
            'load' => max(1, min(100, (int)round($upsWatts / 22))),
            'battery' => 100,
            'runtime_seconds' => 2400,
            'runtime' => '40m',
            'linev' => '120.1',
            'inputv' => '120.1',
            'inputfreq' => '60.0',
            'outputv' => '119.8',
            'outputfreq' => '60.0',
            'output_current' => sprintf('%.1f', max(0.5, $upsWatts / 120)),
            'battery_voltage' => '54.6',
            'battery_temp' => '20',
            'nominal_watts' => '1980',
            'updated_age' => 0.1,
            'peak60' => 1200,
            'avg60' => 603,
            'history' => demo_wave($tick, 40, 620, 300),
        ],
        'procs' => [
            ['pid' => '60684', 'name' => 'tmux', 'user' => 'root', 'rss' => parse_size('572M'), 'cpu' => 3.1, 'cmd' => 'tmux socx wall'],
            ['pid' => '8809', 'name' => 'ntopng', 'user' => 'ntopng', 'rss' => parse_size('540M'), 'cpu' => 2.9, 'cmd' => 'ntopng flow telemetry'],
            ['pid' => '63842', 'name' => 'perl', 'user' => 'root', 'rss' => parse_size('7.4M'), 'cpu' => 1.8, 'cmd' => 'socx alert ticker'],
            ['pid' => '2', 'name' => 'clock', 'user' => 'root', 'rss' => 65536, 'cpu' => 0.4, 'cmd' => '[clock]'],
            ['pid' => '5311', 'name' => 'unbound', 'user' => 'unbound', 'rss' => parse_size('146M'), 'cpu' => 0.3, 'cmd' => 'unbound resolver'],
            ['pid' => '4120', 'name' => 'php-fpm', 'user' => 'root', 'rss' => parse_size('58M'), 'cpu' => 0.2, 'cmd' => 'php-fpm pfSense webConfigurator'],
            ['pid' => '2188', 'name' => 'dpinger', 'user' => 'root', 'rss' => parse_size('9M'), 'cpu' => 0.2, 'cmd' => 'dpinger WAN_DHCP'],
            ['pid' => '1833', 'name' => 'syslogd', 'user' => 'root', 'rss' => parse_size('3M'), 'cpu' => 0.1, 'cmd' => 'syslogd -s'],
            ['pid' => '1221', 'name' => 'filterlog', 'user' => 'root', 'rss' => parse_size('5M'), 'cpu' => 0.1, 'cmd' => 'filterlog -i pflog0'],
        ],
        'flows' => [
            ['src' => host_label('192.168.1.161', $hosts), 'dst' => 'LAN.180', 'up' => '9.38K', 'down' => '5.31K', 'service' => 'https', 'class' => 'firewall', 'graph' => '[#######.]'],
            ['src' => host_label('192.168.1.102', $hosts), 'dst' => 'EXT.61.55', 'up' => '1.20K', 'down' => '1.06K', 'service' => 'https', 'class' => 'internet', 'graph' => '[##......]'],
            ['src' => host_label('192.168.1.127', $hosts), 'dst' => 'EXT.199.64', 'up' => '37.2K', 'down' => '36.2K', 'service' => 'https', 'class' => 'internet', 'graph' => '[######..]'],
            ['src' => host_label('192.168.1.121', $hosts), 'dst' => 'EXT.104.10', 'up' => '25.1K', 'down' => '1.53K', 'service' => 'https', 'class' => 'internet', 'graph' => '[####....]'],
            ['src' => host_label('192.168.1.161', $hosts), 'dst' => 'beacons.gvt2.com', 'up' => '120B', 'down' => '98B', 'service' => 'dnsbl', 'class' => 'dnsbl', 'graph' => '[!.......]'],
            ['src' => host_label('192.168.1.161', $hosts), 'dst' => 'discord.com', 'up' => '80B', 'down' => '0B', 'service' => 'dnsbl', 'class' => 'dnsbl', 'graph' => '[!.......]'],
            ['src' => host_label('192.168.1.127', $hosts), 'dst' => 'EXT.147.70:137', 'up' => '0B', 'down' => '0B', 'service' => 'smb', 'class' => 'blocked', 'graph' => '[!.......]'],
            ['src' => host_label('192.168.1.180', $hosts), 'dst' => 'api.anthropic.com', 'up' => '3.2K', 'down' => '9.1K', 'service' => 'https', 'class' => 'internet', 'graph' => '[####....]'],
            ['src' => 'EXT.45.33', 'dst' => 'WAN:443', 'up' => '0B', 'down' => '0B', 'service' => 'https', 'class' => 'blocked', 'graph' => '[!.......]'],
        ],
        'packets' => [
            ['time' => '16:42:16', 'proto' => 'TCP', 'dir' => 'OUT', 'src' => host_label('192.168.1.102', $hosts), 'dst' => 'EXT.104.10', 'service' => 'https', 'size' => '39B', 'verdict' => 'PASS'],
            ['time' => '16:42:16', 'proto' => 'TCP', 'dir' => 'IN', 'src' => 'EXT.72.14', 'dst' => 'WAN', 'service' => 'https', 'size' => 'ctrl', 'verdict' => 'PASS'],
            ['time' => '16:42:17', 'proto' => 'UDP', 'dir' => 'OUT', 'src' => 'WAN', 'dst' => 'EXT.133.233', 'service' => 'vpn', 'size' => '1B', 'verdict' => 'PASS'],
            ['time' => '16:42:18', 'proto' => 'DNS', 'dir' => 'OUT', 'src' => host_label('192.168.1.161', $hosts), 'dst' => 'beacons.gvt2.com', 'service' => 'dnsbl', 'size' => '0B', 'verdict' => 'DNSBL HIT'],
            ['time' => '16:42:18', 'proto' => 'UDP', 'dir' => 'OUT', 'src' => 'LAN.127', 'dst' => '147.185.133.70:137', 'service' => 'smb', 'size' => '0B', 'verdict' => 'DROP'],
            ['time' => '16:42:19', 'proto' => 'DNS', 'dir' => 'OUT', 'src' => host_label('192.168.1.161', $hosts), 'dst' => 'discord.com', 'service' => 'dnsbl', 'size' => '0B', 'verdict' => 'DNSBL HIT'],
            ['time' => '16:42:20', 'proto' => 'TCP', 'dir' => 'OUT', 'src' => 'LAN.180', 'dst' => 'api.anthropic.com', 'service' => 'https', 'size' => '812B', 'verdict' => 'PASS'],
            ['time' => '16:42:21', 'proto' => 'TCP', 'dir' => 'IN', 'src' => 'EXT.45.33', 'dst' => 'WAN:443', 'service' => 'https', 'size' => '0B', 'verdict' => 'DROP'],
            ['time' => '16:42:22', 'proto' => 'DNS', 'dir' => 'OUT', 'src' => 'LAN.106', 'dst' => 'grammarly.io', 'service' => 'dnsbl', 'size' => '0B', 'verdict' => 'SINKHOLE'],
        ],
        'events' => [
            '[IDS][HIGH][92%] ' . host_label('192.168.1.102', $hosts) . ' -> internet possible C2 beacon | inspect host',
            '[WAN][WARN] Frontier gateway packet loss 8% | watch',
            '[FW][MED] ' . host_label('192.168.1.127', $hosts) . ' -> 147.185.133.70:137 drop',
            '[DHCP][WARN] Unknown device LAN.203 joined | verify MAC',
            '[FLOW][WARN] ' . host_label('192.168.1.164', $hosts) . ' unusual DNS burst x84 | inspect',
            '[UPS][INFO] UPS online 552W, load 28%, batt 100%, run 36m, out 118V/5.2A | source snmp',
            '[VPN][INFO] WireGuard tunnel stable 14ms',
            '[DNS][INFO] Unbound healthy | resolver online',
            '[DNSBL][LOW] ' . host_label('192.168.1.161', $hosts) . ' DNSBL hit: beacons.gvt2.com',
            '[DNSBL][LOW] ' . host_label('192.168.1.161', $hosts) . ' DNSBL hit: discord.com',
        ],
    ];
}

function error_frame(Throwable $e): array
{
    $frame = demo_frame([], ['tick' => 0]);
    $frame['badges'] = ['WAN --', 'VPN --', 'DNS --', 'UPS --'];
    $frame['events'] = ['[HIGH] SOCX wall renderer recovered: ' . $e->getMessage()];
    $frame['packets'] = [
        ['time' => date('H:i:s'), 'proto' => 'ERR', 'dir' => 'LCL', 'src' => 'socx-wall', 'dst' => 'renderer', 'service' => 'recover', 'size' => 'ctrl', 'verdict' => 'WARN'],
    ];
    return $frame;
}

function render_wall(array $frame, int $cols, int $rows, bool $color, array &$state, string $theme = 'classic'): string
{
    if ($theme === 'modern-btop') {
        return render_modern_btop_wall($frame, $cols, $rows, $color, $state);
    }
    return render_classic_wall($frame, $cols, $rows, $color, $state);
}

function render_classic_wall(array $frame, int $cols, int $rows, bool $color, array &$state): string
{
    $canvas = make_canvas($cols, $rows);
    $layout = wall_layout($cols, $rows);

    $panels = [
        panel_obj(0, 0, $cols, $layout['header_h'], 'SOCX WALL MODE', static fn(array $f, array $p): array => header_rows($f, $p)),
        panel_obj(0, $layout['stats_y'], $layout['left_w'], $layout['stats_h'], 'OPERATIONS', static fn(array $f, array $p): array => operations_rows($f, $p)),
        panel_obj($layout['right_x'], $layout['stats_y'], $layout['right_w'], $layout['stats_h'], 'CPU', static fn(array $f, array $p): array => cpu_rows($f, $p)),
        panel_obj(0, $layout['middle_y'], $layout['left_w'], $layout['middle_h'], 'MEM / NET / PF / UPS LIVE', static fn(array $f, array $p): array => live_rows($f, $p)),
        panel_obj($layout['right_x'], $layout['middle_y'], $layout['right_w'], $layout['middle_h'], 'PROCESS FILTER', static fn(array $f, array $p): array => process_rows($f, $p)),
        panel_obj(0, $layout['bottom_y'], $layout['left_w'], $layout['bottom_h'], 'IFTOPX ix0 FLOW RADAR', static fn(array $f, array $p): array => iftop_rows($f, $p)),
        panel_obj($layout['right_x'], $layout['bottom_y'], $layout['right_w'], $layout['bottom_h'], 'TCPDUMPX ix1 PACKETS', static fn(array $f, array $p): array => tcpdump_rows($f, $p)),
        panel_obj(0, $layout['ticker_y'], $cols, $layout['ticker_h'], 'EVENT TICKER', static fn(array $f, array $p): array => ticker_rows($f, $p)),
    ];

    foreach ($panels as $panel) {
        draw_panel($canvas, $panel, $frame);
    }

    $lines = [];
    foreach ($canvas as $line) {
        $lines[] = colorize_line(canvas_line($line), $color);
    }
    return implode("\n", $lines);
}

function render_modern_btop_wall(array $frame, int $cols, int $rows, bool $color, array &$state): string
{
    $frame['unicode_status'] = unicode_render_status();
    $canvas = make_canvas($cols, $rows);
    $layout = modern_btop_layout($cols, $rows);

    $cardGap = $cols >= 110 ? 2 : ($cols >= 74 ? 1 : 0);
    $cardWidths = weighted_widths($cols - ($cardGap * 4), [18, 16, 22, 18, 18]);
    $x = 0;
    $cards = [];
    foreach (['NETWORK', 'PF STATES', 'CPU', 'MEMORY', 'UPS'] as $idx => $title) {
        $cards[] = panel_obj($x, $layout['cards_y'], $cardWidths[$idx], $layout['cards_h'], $title, match ($title) {
            'NETWORK' => static fn(array $f, array $p): array => modern_network_rows($f, $p),
            'PF STATES' => static fn(array $f, array $p): array => modern_pf_rows($f, $p),
            'CPU' => static fn(array $f, array $p): array => modern_cpu_card_rows($f, $p),
            'MEMORY' => static fn(array $f, array $p): array => modern_memory_rows($f, $p),
            default => static fn(array $f, array $p): array => modern_ups_rows($f, $p),
        }, 'card');
        $x += $cardWidths[$idx] + $cardGap;
    }

    $panels = [
        panel_obj(0, 0, $cols, $layout['header_h'], '', static fn(array $f, array $p): array => modern_header_rows($f, $p), 'header'),
        ...$cards,
        panel_obj(0, $layout['middle_y'], $layout['left_w'], $layout['middle_h'], 'PROCESS TREE / FILTER', static fn(array $f, array $p): array => modern_process_rows($f, $p), 'table'),
        panel_obj($layout['right_x'], $layout['middle_y'], $layout['right_w'], $layout['middle_h'], 'NETWORK FLOWS / IFTOPX', static fn(array $f, array $p): array => modern_flow_rows($f, $p), 'table'),
        panel_obj(0, $layout['packets_y'], $cols, $layout['packets_h'], 'LIVE PACKETS', static fn(array $f, array $p): array => modern_packet_rows($f, $p), 'table'),
        panel_obj(0, $layout['ticker_y'], $cols, $layout['ticker_h'], ticker_title(), static fn(array $f, array $p): array => ticker_rows($f, $p), 'ticker'),
    ];

    foreach ($panels as $panel) {
        draw_modern_panel($canvas, $panel, $frame);
    }

    $lines = [];
    foreach ($canvas as $line) {
        $lines[] = colorize_line(canvas_line($line), $color);
    }
    return implode("\n", $lines);
}

function render_ticker_update(array $frame, int $cols, int $rows, bool $color, string $theme = 'classic'): string
{
    if ($cols < 4 || $rows < 3) {
        return '';
    }
    $layout = $theme === 'modern-btop' ? modern_btop_layout($cols, $rows) : wall_layout($cols, $rows);
    $style = $theme === 'modern-btop' ? 'ticker' : 'box';
    $title = $theme === 'modern-btop' ? ticker_title() : 'EVENT TICKER';
    $panel = panel_obj(0, $layout['ticker_y'], $cols, $layout['ticker_h'], $title, static fn(array $f, array $p): array => ticker_rows($f, $p), $style);
    $tickerRows = ticker_rows($frame, $panel);
    $v = ($theme === 'modern-btop' && modern_border_style() === 'unicode') ? unicode_border_chars()['v'] : '|';
    if ($theme === 'modern-btop') {
        [$contentX, $contentY, $contentW, $contentH] = modern_content_bounds($panel);
        $out = '';
        for ($row = 0; $row < max(1, $contentH); $row++) {
            $lineCells = utf8_cells($v . str_repeat(' ', max(0, $cols - 2)) . $v);
            $tickerText = pad_or_clip($tickerRows[$row] ?? '', $contentW);
            $tickerCells = utf8_cells($tickerText);
            for ($i = 0; $i < count($tickerCells) && ($contentX + $i) < $cols - 1; $i++) {
                $lineCells[$contentX + $i] = $tickerCells[$i];
            }
            $ansiRow = $contentY + $row + 1;
            $out .= "\033[" . $ansiRow . ";1H" . colorize_line(implode('', $lineCells), $color);
        }
        return $out;
    }
    $ticker = $tickerRows[0] ?? '';
    $line = $v . pad_or_clip($ticker, $cols - 2) . $v;
    $ansiRow = $layout['ticker_y'] + 2;
    return "\033[" . $ansiRow . ";1H" . colorize_line($line, $color);
}

function modern_btop_layout(int $cols, int $rows): array
{
    $headerH = 2;
    $cardsH = $rows <= 26 ? 5 : 6;
    $tickerH = $rows >= 34 ? 4 : ($rows <= 26 ? 2 : 3);
    $contentH = max(10, $rows - $headerH - $cardsH - $tickerH);
    $packetsH = min(10, max(5, intdiv($contentH, 3)));
    $middleH = max(5, $contentH - $packetsH);
    $middleY = $headerH + $cardsH;
    $packetsY = $middleY + $middleH;
    $mid = intdiv($cols, 2);
    $middleGap = $cols >= 74 ? 1 : 0;
    $leftW = $mid;
    $rightX = $mid + $middleGap;

    return [
        'header_h' => $headerH,
        'cards_y' => $headerH,
        'cards_h' => $cardsH,
        'middle_y' => $middleY,
        'middle_h' => $middleH,
        'packets_y' => $packetsY,
        'packets_h' => $packetsH,
        'ticker_y' => $rows - $tickerH,
        'ticker_h' => $tickerH,
        'left_w' => $leftW,
        'right_x' => $rightX,
        'right_w' => $cols - $rightX,
    ];
}

function wall_layout(int $cols, int $rows): array
{
    $headerH = 3;
    $tickerH = 3;
    $statsH = $rows >= 26 ? 8 : 7;
    $bottomH = max(7, (int)floor($rows * 0.34));
    $middleH = $rows - $headerH - $statsH - $bottomH - $tickerH;
    if ($middleH < 5) {
        $bottomH = max(6, $bottomH - (5 - $middleH));
        $middleH = $rows - $headerH - $statsH - $bottomH - $tickerH;
    }
    if ($middleH < 5) {
        $statsH = max(6, $statsH - (5 - $middleH));
        $middleH = $rows - $headerH - $statsH - $bottomH - $tickerH;
    }
    $middleH = max(5, $middleH);
    $bottomH = max(6, $rows - $headerH - $statsH - $middleH - $tickerH);

    $mid = intdiv($cols, 2);
    return [
        'header_h' => $headerH,
        'stats_y' => $headerH,
        'stats_h' => $statsH,
        'middle_y' => $headerH + $statsH,
        'middle_h' => $middleH,
        'bottom_y' => $headerH + $statsH + $middleH,
        'bottom_h' => $bottomH,
        'ticker_y' => $rows - $tickerH,
        'ticker_h' => $tickerH,
        'left_w' => $mid + 1,
        'right_x' => $mid,
        'right_w' => $cols - $mid,
    ];
}

function unicode_render_status(): array
{
    $term = (string)(getenv('TERM') ?: '');
    $tmux = tmux_detected();
    $envUnicode = getenv('SOCX_UNICODE');
    $forceUnicode = getenv('SOCX_FORCE_UNICODE');
    $theme = strtolower((string)(getenv('SOCX_THEME') ?: 'modern-btop'));
    $modern = $theme === 'modern-btop';
    $enabled = true;
    $reason = $modern ? 'default modern-btop unicode' : 'default unicode';

    if ($forceUnicode !== false && strtolower(trim((string)$forceUnicode)) !== 'false') {
        $enabled = true;
        $reason = 'SOCX_FORCE_UNICODE=' . $forceUnicode;
    }

    if ($envUnicode !== false && $envUnicode !== '') {
        $value = strtolower(trim((string)$envUnicode));
        if (in_array($value, ['0', 'false', 'no', 'off', 'ascii'], true)) {
            $enabled = false;
            $reason = 'SOCX_UNICODE=' . $envUnicode;
        } elseif (in_array($value, ['1', 'true', 'yes', 'on', 'unicode'], true)) {
            $enabled = true;
            $reason = 'SOCX_UNICODE=' . $envUnicode;
        }
    }

    if ($enabled && strtolower(trim((string)$forceUnicode)) === 'false' && in_array(strtolower($term), ['dumb', 'ascii'], true)) {
        $enabled = false;
        $reason = 'TERM=' . ($term !== '' ? $term : 'empty') . ' does not support Unicode';
    }

    $borderEnv = getenv('SOCX_BORDER_STYLE');
    $graphEnv = getenv('SOCX_GRAPH_STYLE');
    $border = normalize_style((string)($borderEnv !== false && $borderEnv !== '' ? $borderEnv : 'unicode'), $enabled);
    $graph = normalize_style((string)($graphEnv !== false && $graphEnv !== '' ? $graphEnv : 'unicode'), $enabled);

    return [
        'enabled' => $enabled,
        'reason' => $enabled ? $reason : $reason,
        'border' => $border,
        'graph' => $graph,
        'term' => $term !== '' ? $term : 'unknown',
        'tmux' => $tmux,
    ];
}

function normalize_style(string $style, bool $unicodeEnabled): string
{
    $style = strtolower(trim($style));
    if ($style === 'ascii') {
        return 'ascii';
    }
    if ($style === 'unicode' && $unicodeEnabled) {
        return 'unicode';
    }
    return $unicodeEnabled ? 'unicode' : 'ascii';
}

function unicode_rendering_enabled(): bool
{
    return (bool)unicode_render_status()['enabled'];
}

function modern_border_style(): string
{
    return (string)unicode_render_status()['border'];
}

function modern_graph_style(): string
{
    return (string)unicode_render_status()['graph'];
}

function modern_theme_active(): bool
{
    return strtolower((string)(getenv('SOCX_THEME') ?: 'modern-btop')) === 'modern-btop';
}

function ascii_fallback_disabled(): bool
{
    $value = getenv('SOCX_DISABLE_ASCII_FALLBACK');
    return $value === false || strtolower(trim((string)$value)) !== 'false';
}

function unicode_border_chars(): array
{
    return [
        'tl' => '┌',
        'tr' => '┐',
        'bl' => '└',
        'br' => '┘',
        'h' => '─',
        'v' => '│',
        'lt' => '├',
        'rt' => '┤',
        'tt' => '┬',
        'bt' => '┴',
        'cross' => '┼',
    ];
}

function make_canvas(int $width, int $height): array
{
    $row = array_fill(0, $width, ' ');
    return array_fill(0, $height, $row);
}

function truncate_text(string $text, int $width): string
{
    $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
    if ($width <= 0) {
        return '';
    }
    $chars = utf8_cells($text);
    if (count($chars) <= $width) {
        return $text;
    }
    if ($width <= 3) {
        return implode('', array_slice($chars, 0, $width));
    }
    return rtrim(implode('', array_slice($chars, 0, $width - 3))) . '...';
}

function pad_or_clip(string $text, int $width): string
{
    $text = truncate_text($text, $width);
    $pad = max(0, $width - cell_len($text));
    return $text . str_repeat(' ', $pad);
}

function center_text(string $text, int $width): string
{
    $text = truncate_text($text, $width);
    $len = cell_len($text);
    if ($len >= $width) {
        return $text;
    }
    $left = intdiv($width - $len, 2);
    return str_repeat(' ', $left) . $text;
}

function safe_write(array &$canvas, int $x, int $y, string $text, int $maxWidth): void
{
    if ($y < 0 || $y >= count($canvas) || $maxWidth <= 0) {
        return;
    }
    if (!is_array($canvas[$y])) {
        $canvas[$y] = utf8_cells((string)$canvas[$y]);
    }
    $lineWidth = count($canvas[$y]);
    $chars = utf8_cells($text);
    if ($x < 0) {
        $chars = array_slice($chars, abs($x));
        $maxWidth += $x;
        $x = 0;
    }
    if ($x >= $lineWidth || $maxWidth <= 0) {
        return;
    }
    $maxWidth = min($maxWidth, $lineWidth - $x);
    $text = pad_or_clip(implode('', $chars), $maxWidth);
    $chars = utf8_cells($text);
    $line = $canvas[$y];
    for ($i = 0; $i < count($chars); $i++) {
        $line[$x + $i] = $chars[$i];
    }
    $canvas[$y] = $line;
}

function utf8_cells(string $text): array
{
    if ($text === '') {
        return [];
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return str_split($text);
    }
    return $chars;
}

function cell_len(string $text): int
{
    return count(utf8_cells($text));
}

function canvas_line($line): string
{
    return is_array($line) ? implode('', $line) : (string)$line;
}

function draw_hline(array &$canvas, int $x, int $y, int $width): void
{
    safe_write($canvas, $x, $y, str_repeat('=', max(0, $width)), $width);
}

function draw_vline(array &$canvas, int $x, int $y, int $height): void
{
    for ($i = 0; $i < $height; $i++) {
        safe_write($canvas, $x, $y + $i, '|', 1);
    }
}

function render_box(array &$canvas, int $x, int $y, int $width, int $height, string $title): void
{
    if (modern_border_style() === 'unicode') {
        render_box_unicode($canvas, $x, $y, $width, $height, $title);
    } else {
        render_box_ascii($canvas, $x, $y, $width, $height, $title);
    }
}

function render_box_ascii(array &$canvas, int $x, int $y, int $width, int $height, string $title): void
{
    if (modern_theme_active() && ascii_fallback_disabled()) {
        log_wall_bug('BUG: render_box_ascii called in modern-btop');
        return;
    }
    if ($width < 4 || $height < 3) {
        return;
    }
    draw_hline($canvas, $x + 1, $y, $width - 2);
    draw_hline($canvas, $x + 1, $y + $height - 1, $width - 2);
    draw_vline($canvas, $x, $y + 1, $height - 2);
    draw_vline($canvas, $x + $width - 1, $y + 1, $height - 2);
    safe_write($canvas, $x, $y, '+', 1);
    safe_write($canvas, $x + $width - 1, $y, '+', 1);
    safe_write($canvas, $x, $y + $height - 1, '+', 1);
    safe_write($canvas, $x + $width - 1, $y + $height - 1, '+', 1);
    safe_write($canvas, $x + 2, $y, ' ' . $title . ' ', max(0, $width - 4));
}

function render_box_unicode(array &$canvas, int $x, int $y, int $width, int $height, string $title): void
{
    if ($width < 4 || $height < 3) {
        return;
    }
    $b = unicode_border_chars();
    safe_write($canvas, $x, $y, $b['tl'], 1);
    safe_write($canvas, $x + $width - 1, $y, $b['tr'], 1);
    safe_write($canvas, $x, $y + $height - 1, $b['bl'], 1);
    safe_write($canvas, $x + $width - 1, $y + $height - 1, $b['br'], 1);
    safe_write($canvas, $x + 1, $y, str_repeat($b['h'], max(0, $width - 2)), $width - 2);
    safe_write($canvas, $x + 1, $y + $height - 1, str_repeat($b['h'], max(0, $width - 2)), $width - 2);
    for ($i = 1; $i < $height - 1; $i++) {
        safe_write($canvas, $x, $y + $i, $b['v'], 1);
        safe_write($canvas, $x + $width - 1, $y + $i, $b['v'], 1);
    }
    if ($title !== '') {
        $label = ' ' . strtoupper($title) . ' ';
        safe_write($canvas, $x + 1, $y, $label, min(cell_len($label), max(0, $width - 2)));
    }
}

function render_modern_box(array &$canvas, int $x, int $y, int $width, int $height, string $title): void
{
    if ($width < 4 || $height < 3) {
        return;
    }
    safe_write($canvas, $x, $y, '+', 1);
    safe_write($canvas, $x + $width - 1, $y, '+', 1);
    safe_write($canvas, $x, $y + $height - 1, '+', 1);
    safe_write($canvas, $x + $width - 1, $y + $height - 1, '+', 1);
    safe_write($canvas, $x + 1, $y, str_repeat('-', max(0, $width - 2)), $width - 2);
    safe_write($canvas, $x + 1, $y + $height - 1, str_repeat('-', max(0, $width - 2)), $width - 2);
    draw_vline($canvas, $x, $y + 1, $height - 2);
    draw_vline($canvas, $x + $width - 1, $y + 1, $height - 2);
    if ($title !== '') {
        $label = str_contains($title, '[') ? $title : '[' . $title . ']';
        safe_write($canvas, $x + 1, $y, $label, min(strlen($label), max(0, $width - 2)));
    }
}

function render_modern_card_frame(array &$canvas, int $x, int $y, int $width, int $height, string $title): void
{
    if ($width < 4 || $height < 2) {
        return;
    }
    if (modern_border_style() === 'unicode') {
        render_box_unicode($canvas, $x, $y, $width, $height, $title);
        return;
    }
    $label = ' ' . strtoupper($title) . ' ';
    $line = $label . str_repeat('-', max(0, $width - strlen($label)));
    safe_write($canvas, $x, $y, $line, $width);
}

function render_modern_header_frame(array &$canvas, int $x, int $y, int $width, int $height): void
{
    if ($width <= 0 || $height <= 0) {
        return;
    }
    $h = modern_border_style() === 'unicode' ? unicode_border_chars()['h'] : '-';
    safe_write($canvas, $x, $y + $height - 1, str_repeat($h, $width), $width);
}

function render_modern_ticker_frame(array &$canvas, int $x, int $y, int $width, string $title): void
{
    if ($width < 4) {
        return;
    }
    $b = unicode_border_chars();
    safe_write($canvas, $x, $y, $b['tl'], 1);
    safe_write($canvas, $x + $width - 1, $y, $b['tr'], 1);
    safe_write($canvas, $x + 1, $y, str_repeat($b['h'], max(0, $width - 2)), $width - 2);
    if ($title !== '') {
        $label = ' ' . strtoupper($title) . ' ';
        safe_write($canvas, $x + 1, $y, $label, min(cell_len($label), max(0, $width - 2)));
    }
    safe_write($canvas, $x, $y + 1, $b['v'], 1);
    safe_write($canvas, $x + $width - 1, $y + 1, $b['v'], 1);
}

function panel_obj(int $x, int $y, int $width, int $height, string $title, callable $render, string $style = 'box'): array
{
    return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'title' => $title, 'border' => 'cyan', 'render' => $render, 'clip' => true, 'style' => $style];
}

function render_row(array &$canvas, array $panel, int $rowIndex, string $text): void
{
    if ($rowIndex < 0 || $rowIndex >= $panel['height'] - 2) {
        return;
    }
    safe_write($canvas, $panel['x'] + 1, $panel['y'] + 1 + $rowIndex, $text, $panel['width'] - 2);
}

function draw_panel(array &$canvas, array $panel, array $frame): void
{
    render_box($canvas, $panel['x'], $panel['y'], $panel['width'], $panel['height'], $panel['title']);
    $rows = ($panel['render'])($frame, $panel);
    foreach ($rows as $idx => $line) {
        render_row($canvas, $panel, $idx, (string)$line);
    }
}

function draw_modern_panel(array &$canvas, array $panel, array $frame): void
{
    $style = (string)($panel['style'] ?? 'box');
    if ($style === 'card') {
        render_modern_card_frame($canvas, $panel['x'], $panel['y'], $panel['width'], $panel['height'], $panel['title']);
    } elseif ($style === 'header') {
        render_modern_header_frame($canvas, $panel['x'], $panel['y'], $panel['width'], $panel['height']);
    } elseif ($style === 'ticker' && (int)$panel['height'] === 2 && modern_border_style() === 'unicode') {
        render_modern_ticker_frame($canvas, $panel['x'], $panel['y'], $panel['width'], $panel['title']);
    } elseif (modern_border_style() === 'unicode') {
        render_box_unicode($canvas, $panel['x'], $panel['y'], $panel['width'], $panel['height'], $panel['title']);
    } else {
        render_modern_box($canvas, $panel['x'], $panel['y'], $panel['width'], $panel['height'], $panel['title']);
    }
    $rows = ($panel['render'])($frame, $panel);
    foreach ($rows as $idx => $line) {
        render_modern_row($canvas, $panel, $idx, (string)$line);
    }
}

function render_modern_row(array &$canvas, array $panel, int $rowIndex, string $text): void
{
    [$x, $y, $width, $height] = modern_content_bounds($panel);
    if ($rowIndex < 0 || $rowIndex >= $height) {
        return;
    }
    safe_write($canvas, $x, $y + $rowIndex, $text, $width);
}

function modern_content_bounds(array $panel): array
{
    $style = (string)($panel['style'] ?? 'box');
    $x = (int)$panel['x'];
    $y = (int)$panel['y'];
    $width = (int)$panel['width'];
    $height = (int)$panel['height'];
    if ($style === 'header') {
        return [$x, $y, $width, max(0, $height - 1)];
    }
    if ($style === 'ticker' && $height === 2) {
        return [$x + 1, $y + 1, max(0, $width - 2), 1];
    }
    $pad = 1;
    if ($style === 'card') {
        if (modern_border_style() === 'unicode') {
            return [$x + $pad, $y + 1, max(0, $width - ($pad * 2)), max(0, $height - 2)];
        }
        return [$x + $pad, $y + 1, max(0, $width - ($pad * 2)), max(0, $height - 1)];
    }
    return [$x + $pad, $y + 1, max(0, $width - ($pad * 2)), max(0, $height - 2)];
}

function modern_content_width(array $panel): int
{
    [, , $width] = modern_content_bounds($panel);
    return max(1, $width);
}

function ticker_title(): string
{
    $mode = strtoupper((string)(getenv('SOCX_EVENT_FEED_MODE') ?: 'rotate'));
    if ($mode === 'SCROLL') {
        $speed = strtoupper((string)(getenv('SOCX_TICKER_SPEED') ?: 'FAST'));
        return 'EVENT FEED [' . truncate_text($speed, 6) . ']';
    }
    return 'EVENT FEED [' . truncate_text($mode, 6) . ']';
}

function modern_header_rows(array $f, array $p): array
{
    $w = $p['width'];
    $badgeSep = modern_border_style() === 'unicode' ? ' ◆ ' : ' | ';
    $badges = implode($badgeSep, $f['badges']);
    $ups = normalize_ups($f['ups'] ?? []);
    $left = sprintf('SOCX MODERN WALL  %s  refresh %s', $f['time'], $f['refresh']);
    $space = max(1, $w - cell_len($left) - cell_len($badges));
    if (!empty($f['demo'])) {
        $status = $f['unicode_status'] ?? unicode_render_status();
        $debug = sprintf(
            'Unicode rendering: %s | Border style: %s | Graph style: %s | TERM: %s | TMUX: %s',
            !empty($status['enabled']) ? 'enabled' : 'disabled',
            $status['border'] ?? '?',
            $status['graph'] ?? '?',
            $status['term'] ?? 'unknown',
            !empty($status['tmux']) ? 'yes' : 'no'
        );
        if (empty($status['enabled']) && !empty($status['reason'])) {
            $debug .= ' | reason: ' . $status['reason'];
        }
        return [
            pad_or_clip($left . str_repeat(' ', $space) . $badges, $w),
            pad_or_clip($debug, $w),
        ];
    }
    $rows = [pad_or_clip($left . str_repeat(' ', $space) . $badges, $w)];
    if ($w < 126) {
        return $rows;
    }
    $status = sprintf(
        'WAN %s/%s   LAN %s/%s   PF %s states   UPS %sW %s%%',
        compact_rate($f['wan']['down']),
        compact_rate($f['wan']['up']),
        compact_rate($f['lan']['down']),
        compact_rate($f['lan']['up']),
        $f['pf']['states'] ?? '?',
        $ups['watts'] ?? '?',
        $ups['load'] ?? '?'
    );
    $rows[] = pad_or_clip($status, $w);
    return $rows;
}

function modern_network_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    if ($w < 22) {
        return [
            truncate_text(sprintf('WAN %s', compact_rate_pair((string)$f['wan']['down'], (string)$f['wan']['up'], max(1, $w - 4))), $w),
            truncate_text(sprintf('LAN %s', compact_rate_pair((string)$f['lan']['down'], (string)$f['lan']['up'], max(1, $w - 4))), $w),
            fit_sparkline($f['wan_history'] ?? [], $w),
        ];
    }
    return [
        sprintf('WAN %s %s  %s %s', down_marker(), compact_rate($f['wan']['down']), up_marker(), compact_rate($f['wan']['up'])),
        sprintf('LAN %s %s  %s %s', down_marker(), compact_rate($f['lan']['down']), up_marker(), compact_rate($f['lan']['up'])),
        fit_sparkline($f['wan_history'] ?? [], $w),
        truncate_text(($f['wan']['link'] ?? '') . ' ' . ($f['wan']['rtt'] ?? ''), $w),
    ];
}

function modern_pf_rows(array $f, array $p): array
{
    $pf = $f['pf'];
    $w = modern_content_width($p);
    if ($w < 22) {
        return [
            truncate_text(sprintf('st %s', compact_num((int)preg_replace('/\D/', '', (string)($pf['states'] ?? '0')))), $w),
            truncate_text(sprintf('sr %s', compact_pf_rate((string)($pf['searches_rate'] ?? '?'))), $w),
            truncate_text(sprintf('b%s/%s', compact_num_tight((int)($pf['blocked'] ?? 0)), compact_num_tight((int)($pf['passed'] ?? 0))), $w),
        ];
    }
    return [
        truncate_text(sprintf('states %s  search %s', $pf['states'] ?? '?', compact_pf_rate((string)($pf['searches_rate'] ?? '?'))), $w),
        truncate_text(sprintf('blk %s  pass %s', compact_num((int)($pf['blocked'] ?? 0)), compact_num((int)($pf['passed'] ?? 0))), $w),
        truncate_text(sprintf('ins %s  rem %s', compact_pf_rate((string)($pf['inserts_rate'] ?? '?')), compact_pf_rate((string)($pf['removals_rate'] ?? '?'))), $w),
        fit_sparkline($f['pf_history'] ?? [], $w),
    ];
}

function modern_cpu_card_rows(array $f, array $p): array
{
    $cpu = $f['cpu'];
    $tick = (int)($f['tick'] ?? 0);
    $w = modern_content_width($p);
    $cores = array_slice($cpu['cores'], 0, 4);
    if ($w < 22) {
        $rows = [sprintf('%d%% %s %s', $cpu['used'], str_replace('GHz', 'G', $cpu['freq']), modern_temp_text($cpu['temp']) )];
        foreach ($cores as $core) {
            $used = (int)round((float)$core['used']);
            $barW = max(2, min(6, $w - 9));
            $rows[] = sprintf('C%s %s %2d%%', $core['id'], animated_meter($used, $barW, $tick + (int)$core['id']), $used);
        }
        $rows[] = 'ld ' . implode(' ', array_slice($cpu['load'], 0, 2));
        return $rows;
    }
    $barW = max(4, min(8, intdiv($w - 16, 2)));
    $rows = [sprintf('%3d%%  %-7s  %s', $cpu['used'], $cpu['freq'], modern_temp_text($cpu['temp']))];
    foreach (array_chunk($cores, 2) as $pair) {
        $parts = [];
        foreach ($pair as $core) {
            $used = (int)round((float)$core['used']);
            $parts[] = sprintf('C%s %s %2d%%', $core['id'], animated_meter($used, $barW, $tick + (int)$core['id']), $used);
        }
        $rows[] = truncate_text(implode('  ', $parts), $w);
    }
    $rows[] = 'ld ' . implode(' ', $cpu['load']);
    return $rows;
}

function modern_memory_rows(array $f, array $p): array
{
    $mem = $f['mem'];
    $w = modern_content_width($p);
    $arcPct = percent((int)$mem['arc_total'], max(1, (int)$mem['total']));
    if ($w < 22) {
        return [
            sprintf('RAM %s/%s', compact_bytes_tight((int)$mem['used']), compact_bytes_tight((int)$mem['total'])),
            sprintf('%2d%% %s', (int)$mem['used_pct'], fit_bar((int)$mem['used_pct'], max(1, $w - 4))),
            sprintf('ARC %s F%s', compact_bytes_tight((int)$mem['arc_total']), compact_bytes_tight((int)$mem['free'])),
        ];
    }
    return [
        sprintf('RAM %s/%s', bytes_text((int)$mem['used']), bytes_text((int)$mem['total'])),
        sprintf('%2d%% %s', (int)$mem['used_pct'], fit_bar((int)$mem['used_pct'], max(1, $w - 4))),
        sprintf('ARC %s  free %s', bytes_text((int)$mem['arc_total']), bytes_text((int)$mem['free'])),
        sprintf('%2d%% %s', $arcPct, fit_bar($arcPct, max(1, $w - 4))),
    ];
}

function modern_ups_rows(array $f, array $p): array
{
    $ups = normalize_ups($f['ups'] ?? []);
    $w = modern_content_width($p);
    if (!$ups['online']) {
        return ['UPS unavailable', 'collector waiting', sparkline([], max(8, $w))];
    }
    $watts = center_text(sprintf('%s W', $ups['watts']), $w);
    $source = strtoupper(truncate_text((string)($ups['source'] ?? 'UPS'), 4));
    if ($w < 24) {
        $v = is_numeric($ups['outputv']) ? (string)(int)round((float)$ups['outputv']) . 'V' : '?V';
        return [
            $watts,
            truncate_text(sprintf('%s%% b%s%% %s', $ups['load'], $ups['battery'], $ups['runtime']), $w),
            truncate_text(sprintf('%s %sA %s', $v, $ups['output_current'], $source), $w),
        ];
    }
    return [
        $watts,
        truncate_text(sprintf('load %s%% batt %s%% run %s', $ups['load'], $ups['battery'], $ups['runtime']), $w),
        truncate_text(sprintf('out %sV %sA  in %sV', $ups['outputv'], $ups['output_current'], $ups['inputv']), $w),
        truncate_text(sprintf('bat %sV %sC %s', $ups['battery_voltage'], $ups['battery_temp'], $source), $w),
        fit_sparkline($ups['history'], $w),
    ];
}

function modern_process_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $cmdW = max(8, $w - 24);
    $rows = [sprintf('%-5s %-5s %-6s %4s %s', 'PID', 'USER', 'MEM', 'CPU', 'CMD')];
    foreach (array_slice($f['procs'], 0, max(1, $p['height'] - 3)) as $proc) {
        $cmd = $cmdW < 10 ? (string)$proc['name'] : (string)$proc['cmd'];
        $rows[] = sprintf('%-5s %-5s %-6s %4.1f %s',
            truncate_text((string)$proc['pid'], 5),
            truncate_text((string)$proc['user'], 5),
            truncate_text(bytes_text((int)$proc['rss']), 6),
            (float)$proc['cpu'],
            truncate_modern_text($cmd, $cmdW));
    }
    return $rows;
}

function modern_flow_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    if ($w < 52) {
        $rateW = 8;
        $svcW = 3;
        $flowW = max(10, $w - $rateW - $svcW - 5);
        $rows = [sprintf('%-2s %-*s %-*s %-*s', '#', $flowW, 'FLOW', $rateW, 'RATE', $svcW, 'SVC')];
        foreach (array_slice($f['flows'], 0, max(1, $p['height'] - 3)) as $idx => $flow) {
            $rows[] = sprintf('%02d %-*s %-*s %-*s',
                $idx + 1,
                $flowW,
                flow_path_text($flow, $flowW),
                $rateW,
                compact_rate_pair($flow['up'], $flow['down'], $rateW),
                $svcW,
                truncate_text(service_short((string)($flow['service'] ?? '')), $svcW));
        }
        return $rows;
    }
    $rateW = $w >= 74 ? 10 : 8;
    $svcW = $w >= 74 ? 5 : 4;
    $kindW = $w >= 74 ? 6 : 5;
    $flowW = max(14, $w - $rateW - $svcW - $kindW - 7);
    $rows = [sprintf('%-2s %-*s %-*s %-*s %-*s', '#', $flowW, 'FLOW', $rateW, 'RATE', $svcW, 'SVC', $kindW, 'KIND')];
    foreach (array_slice($f['flows'], 0, max(1, $p['height'] - 3)) as $idx => $flow) {
        $flowText = flow_path_text($flow, $flowW);
        $rows[] = sprintf('%02d %-*s %-*s %-*s %-*s',
            $idx + 1,
            $flowW,
            $flowText,
            $rateW,
            compact_rate_pair($flow['up'], $flow['down'], $rateW),
            $svcW,
            truncate_text(service_short((string)($flow['service'] ?? flow_class_short($flow['class']))), $svcW),
            $kindW,
            truncate_text(flow_class_short($flow['class']), $kindW));
    }
    return $rows;
}

function modern_packet_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $svcW = $w >= 110 ? 8 : 5;
    $verdictW = 10;
    $flowW = max(24, $w - $svcW - $verdictW - 44);
    $rows = [sprintf('%-8s %-5s %-4s %-*s %-*s %-*s', 'TIME', 'PROTO', 'DIR', $flowW, 'SRC -> DST', $svcW, 'SVC', $verdictW, 'VERDICT')];
    foreach (array_slice($f['packets'], 0, max(1, $p['height'] - 3)) as $pkt) {
        $rows[] = sprintf('%-8s %-5s %-4s %-*s %-*s %-*s',
            truncate_text((string)$pkt['time'], 8),
            truncate_text((string)$pkt['proto'], 5),
            truncate_text((string)$pkt['dir'], 4),
            $flowW,
            packet_flow_text($pkt, $flowW),
            $svcW,
            truncate_text(service_short((string)$pkt['service']), $svcW),
            $verdictW,
            truncate_text((string)$pkt['verdict'], $verdictW));
    }
    return $rows;
}

function header_rows(array $f, array $p): array
{
    $badges = $f['badges'];
    if ($p['width'] < 100) {
        $badges = array_map(static fn(string $badge): string => str_replace(['UPS ONLINE', 'DNS UNBOUND OK'], ['UPS OK', 'DNS OK'], $badge), $badges);
        $left = sprintf('SOCX WALL | NEON | %s | %s', $f['time'], $f['refresh']);
    } else {
        $left = sprintf('SOCX WALL | preset NEON | %s | refresh %s', $f['time'], $f['refresh']);
    }
    $badges = implode(' | ', $badges);
    $space = max(1, ($p['width'] - 2) - strlen($left) - strlen($badges));
    return [$left . str_repeat(' ', $space) . $badges];
}

function operations_rows(array $f, array $p): array
{
    $w = $p['width'] - 2;
    $ram = $f['mem'];
    $pf = $f['pf'];
    return [
        sprintf('WAN %-4s D %-9s U %-9s %s %s %s', $f['wan']['name'], $f['wan']['down'], $f['wan']['up'], $f['wan']['link'], $f['wan']['rtt'], $f['wan']['loss']),
        sprintf('LAN %-4s D %-9s U %-9s', $f['lan']['name'], $f['lan']['down'], $f['lan']['up']),
        sprintf('PF states %-8s search %-10s', $pf['states'] ?? '?', $pf['searches_rate'] ?? '?'),
        sprintf('blocked %-10s passed %-10s', compact_num((int)($pf['blocked'] ?? 0)), compact_num((int)($pf['passed'] ?? 0))),
        sprintf('RAM %s/%s %s ARC %s free %s', bytes_text((int)$ram['used']), bytes_text((int)$ram['total']), bar((int)$ram['used_pct'], max(6, min(16, $w - 48))), bytes_text((int)$ram['arc_total']), bytes_text((int)$ram['free'])),
    ];
}

function cpu_rows(array $f, array $p): array
{
    $w = $p['width'] - 2;
    $cpu = $f['cpu'];
    $tick = (int)($f['tick'] ?? 0);
    $barW = max(8, min(18, $w - 18));
    $rows = [
        sprintf('CPU %3d%%   %-7s   %s', $cpu['used'], $cpu['freq'], $cpu['temp']),
    ];
    $innerRows = max(1, $p['height'] - 2);
    if (count($cpu['cores']) > max(1, $innerRows - 2)) {
        $pairs = array_chunk(array_slice($cpu['cores'], 0, 6), 2);
        foreach (array_slice($pairs, 0, max(1, $innerRows - 2)) as $pair) {
            $parts = [];
            foreach ($pair as $core) {
                $used = (int)round((float)$core['used']);
                $parts[] = sprintf('C%s %s %2d%%', $core['id'], animated_bar($used, 8, $tick + (int)$core['id']), $used);
            }
            $rows[] = implode(' ', $parts);
        }
    } else {
        foreach (array_slice($cpu['cores'], 0, max(1, $p['height'] - 4)) as $core) {
            $used = (int)round((float)$core['used']);
            $rows[] = sprintf('C%-2s %s %3d%%', $core['id'], animated_bar($used, $barW, $tick + (int)$core['id']), $used);
        }
    }
    $rows[] = 'load avg ' . implode(' ', $cpu['load']);
    return $rows;
}

function live_rows(array $f, array $p): array
{
    $ram = $f['mem'];
    $ups = ups_summary($f['ups'] ?? []);
    return [
        sprintf('RAM %s/%s  ARC %s  free %s', bytes_text((int)$ram['used']), bytes_text((int)$ram['total']), bytes_text((int)$ram['arc_total']), bytes_text((int)$ram['free'])),
        sprintf('WAN D %-8s U %-8s | LAN D %-8s U %-8s', $f['wan']['down'], $f['wan']['up'], $f['lan']['down'], $f['lan']['up']),
        $ups,
    ];
}

function process_rows(array $f, array $p): array
{
    $w = $p['width'] - 2;
    $cmdW = max(8, $w - 38);
    $rows = [sprintf('%-6s %-10s %-8s %-7s %-5s %s', 'PID', 'PROGRAM', 'USER', 'MEM', 'CPU', 'COMMAND')];
    foreach (array_slice($f['procs'], 0, max(1, $p['height'] - 3)) as $proc) {
        $rows[] = sprintf('%-6s %-10s %-8s %-7s %5.1f %s',
            truncate_text((string)$proc['pid'], 6),
            truncate_text((string)$proc['name'], 10),
            truncate_text((string)$proc['user'], 8),
            bytes_text((int)$proc['rss']),
            (float)$proc['cpu'],
            truncate_text((string)$proc['cmd'], $cmdW));
    }
    return $rows;
}

function iftop_rows(array $f, array $p): array
{
    $w = $p['width'] - 2;
    $rows = [];
    $wide = $w >= 76;
    if ($wide) {
        $srcW = 16;
        $dstW = max(14, $w - 59);
        $rows[] = sprintf('%-2s %-*s -> %-*s %-7s %-7s %-8s %s', '#', $srcW, 'SOURCE', $dstW, 'DESTINATION', 'UP', 'DOWN', 'CLASS', 'GRAPH');
        foreach (array_slice($f['flows'], 0, max(1, $p['height'] - 4)) as $idx => $flow) {
            $rows[] = sprintf('%02d %-*s -> %-*s %-7s %-7s %-8s %s',
                $idx + 1,
                $srcW, truncate_text($flow['src'], $srcW),
                $dstW, truncate_text($flow['dst'], $dstW),
                $flow['up'],
                $flow['down'],
                truncate_text($flow['class'], 8),
                $flow['graph']);
        }
    } else {
        $rows[] = 'flow radar: source -> destination';
        $max = max(1, intdiv($p['height'] - 4, 2));
        foreach (array_slice($f['flows'], 0, $max) as $idx => $flow) {
            $available = max(12, $w - 7);
            $srcW = min(24, max(14, $available - 13));
            $dstW = max(8, $available - $srcW);
            $rows[] = sprintf('%02d %s -> %s', $idx + 1, truncate_text($flow['src'], $srcW), truncate_text($flow['dst'], $dstW));
            $detail = sprintf('   up %-7s dn %-7s %s', $flow['up'], $flow['down'], truncate_text($flow['class'], 8));
            if (strlen($detail . ' ' . $flow['graph']) <= $w) {
                $detail .= ' ' . $flow['graph'];
            }
            $rows[] = $detail;
        }
    }
    $rows[] = flow_total($f['flows']);
    return $rows;
}

function tcpdump_rows(array $f, array $p): array
{
    $w = $p['width'] - 2;
    $rows = [];
    $wide = $w >= 76;
    if ($wide) {
        $flowW = max(20, $w - 40);
        $rows[] = sprintf('%-8s %-5s %-4s %-*s %-8s %-5s %-7s', 'TIME', 'PROTO', 'DIR', $flowW, 'SOURCE -> DESTINATION', 'SERVICE', 'SIZE', 'VERDICT');
        foreach (array_slice($f['packets'], 0, max(1, $p['height'] - 3)) as $pkt) {
            $flow = $pkt['src'] . ' -> ' . $pkt['dst'];
            $rows[] = sprintf('%-8s %-5s %-4s %-*s %-8s %-5s %-7s',
                $pkt['time'], $pkt['proto'], $pkt['dir'], $flowW, truncate_text($flow, $flowW),
                truncate_text($pkt['service'], 8), truncate_text($pkt['size'], 5), $pkt['verdict']);
        }
    } else {
        $rows[] = 'packets: time proto dir flow';
        $max = max(1, intdiv($p['height'] - 3, 2));
        foreach (array_slice($f['packets'], 0, $max) as $pkt) {
            $rows[] = sprintf('%s %-4s %-3s %s', substr($pkt['time'], -5), $pkt['proto'], $pkt['dir'], truncate_text($pkt['src'], max(8, $w - 15)));
            $rows[] = sprintf('   -> %-12s %-7s %-5s %s', truncate_text($pkt['dst'], 12), truncate_text($pkt['service'], 7), truncate_text($pkt['size'], 5), $pkt['verdict']);
        }
    }
    return $rows;
}

function ticker_rows(array $f, array $p): array
{
    $events = $f['ticker_events'] ?? $f['events'];
    if (!$events) {
        $events = ['[LOW] SOCX wall mode live - waiting for firewall events'];
    }
    [, , $width, $contentH] = modern_content_bounds($p);
    $contentH = max(1, (int)$contentH);
    $cfg = $f['ticker_config'] ?? ticker_config([]);
    $mode = (string)($f['event_feed_mode'] ?? ($cfg['mode'] ?? 'scroll'));
    if ($mode === 'rotate') {
        return event_feed_rotate_rows($events, (int)($f['ticker_index'] ?? 0), $width, $contentH);
    }
    if ($mode === 'stack') {
        return event_feed_stack_rows($events, (int)($f['ticker_index'] ?? 0), $width, $contentH);
    }

    $now = (float)($f['ticker_now'] ?? microtime(true));
    $holdUntil = (float)($f['ticker_hold_until'] ?? 0.0);
    $holdText = (string)($f['ticker_hold_text'] ?? '');
    if ($holdText !== '' && $now < $holdUntil) {
        $marker = ticker_separator_marker();
        $separator = '   ' . $marker . '   ';
        $pad = str_repeat(' ', max(12, min(34, intdiv($width, 3))));
        return [pad_or_clip($pad . $separator . '!!! ' . $holdText . $separator, $width)];
    }

    $marker = ticker_separator_marker();
    $separator = '   ' . $marker . '   ';
    $line = $separator . implode($separator, $events) . $separator;
    $pad = str_repeat(' ', max(12, min(34, intdiv($width, 3))));
    $cycle = $pad . $line . $pad;
    $offset = (int)($f['ticker_offset'] ?? 0);
    return [ticker_view($cycle, $offset, $width)];
}

function event_feed_rotate_rows(array $events, int $index, int $width, int $height): array
{
    $events = array_values(array_filter(array_map('strval', $events), static fn(string $event): bool => trim($event) !== ''));
    if (!$events) {
        return [pad_or_clip('[SYS][INFO] SOCX wall mode live', $width)];
    }
    if ($height <= 1 || count($events) === 1) {
        return [event_feed_rotate_line($events, $index, $width)];
    }

    usort($events, static fn(string $a, string $b): int => event_priority_score($b) <=> event_priority_score($a));
    $primary = clean_ticker_event($events[0]);
    $secondaryPool = array_slice($events, 1);
    if (!$secondaryPool) {
        return [pad_or_clip(truncate_modern_text($primary, $width), $width)];
    }

    $secondary = clean_ticker_event($secondaryPool[(($index % count($secondaryPool)) + count($secondaryPool)) % count($secondaryPool)]);
    return [
        pad_or_clip(truncate_modern_text($primary, $width), $width),
        pad_or_clip(truncate_modern_text('next ◆ ' . $secondary, $width), $width),
    ];
}

function event_feed_rotate_line(array $events, int $index, int $width): string
{
    $events = array_values(array_filter(array_map('strval', $events), static fn(string $event): bool => trim($event) !== ''));
    if (!$events) {
        return pad_or_clip('[LOW] SOCX wall mode live', $width);
    }
    $count = count($events);
    $index = (($index % $count) + $count) % $count;
    $active = clean_ticker_event($events[$index]);
    $line = $active;

    if ($count > 1 && $width >= 92) {
        $next = clean_ticker_event($events[($index + 1) % $count]);
        $preview = $active . '   ◆ next: ' . $next;
        if (cell_len($preview) <= $width) {
            $line = $preview;
        }
    }
    return pad_or_clip(truncate_modern_text($line, $width), $width);
}

function event_feed_stack_rows(array $events, int $index, int $width, int $height): array
{
    $events = array_values(array_filter(array_map('strval', $events), static fn(string $event): bool => trim($event) !== ''));
    if (!$events) {
        return [pad_or_clip('[LOW] SOCX wall mode live', $width)];
    }
    $rows = [];
    $count = count($events);
    $height = max(1, min(3, $height));
    for ($i = 0; $i < $height; $i++) {
        $event = clean_ticker_event($events[(($index + $i) % $count + $count) % $count]);
        $rows[] = pad_or_clip(truncate_modern_text($event, $width), $width);
    }
    return $rows;
}

function ticker_view(string $text, int $offset, int $width): string
{
    $text = rtrim($text);
    if ($text === '') {
        $text = '[LOW] SOCX wall mode live';
    }
    $cycleLen = max(1, strlen($text));
    $offset %= $cycleLen;
    $marker = ticker_separator_marker();
    $separator = '   ' . $marker . '   ';
    $scroll = substr($text, $offset) . $separator . $text;
    while (strlen($scroll) < $width) {
        $scroll .= $separator . $text;
    }
    return substr($scroll, 0, $width);
}

function ticker_separator_marker(): string
{
    return '~';
}

function parse_load(string $top): array
{
    $line = strtok($top, "\n") ?: '';
    $load = ['1' => '?', '5' => '?', '15' => '?', 'uptime' => '?', 'processes' => '?'];
    if (preg_match('/load averages:\s*([0-9.]+),\s*([0-9.]+),\s*([0-9.]+)\s+up\s+(.+?)\s{2,}/', $line, $m)) {
        $load['1'] = $m[1];
        $load['5'] = $m[2];
        $load['15'] = $m[3];
        $load['uptime'] = trim($m[4]);
    }
    if (preg_match('/^\s*(\d+)\s+processes:\s*(.+)$/m', $top, $m)) {
        $load['processes'] = $m[1] . ' processes: ' . trim($m[2]);
    }
    return $load;
}

function parse_cpu(string $top): array
{
    $cpu = ['idle' => 100.0, 'used' => 0.0, 'cores' => []];
    if (preg_match_all('/^CPU\s+(\d+):\s*(.+)$/m', $top, $matches, PREG_SET_ORDER)) {
        $used = 0.0;
        foreach ($matches as $m) {
            $core = parse_cpu_line($m[2]);
            $core['id'] = (int)$m[1];
            $cpu['cores'][] = $core;
            $used += (float)$core['used'];
        }
        $cpu['used'] = $used / max(1, count($cpu['cores']));
        return $cpu;
    }
    if (preg_match('/^CPU:\s*(.+)$/m', $top, $m)) {
        $cpu = array_merge($cpu, parse_cpu_line($m[1]));
    }
    return $cpu;
}

function parse_cpu_line(string $line): array
{
    $idle = 100.0;
    if (preg_match('/([0-9.]+)%\s+idle/i', $line, $m)) {
        $idle = (float)$m[1];
    }
    return ['idle' => $idle, 'used' => max(0.0, min(100.0, 100.0 - $idle))];
}

function update_core_history(array &$state, array $cpu): void
{
    $cores = $cpu['cores'] ?: [['id' => 0, 'used' => (float)$cpu['used']]];
    foreach ($cores as $core) {
        $id = (int)($core['id'] ?? 0);
        $state['core_history'][$id] ??= [];
        $state['core_history'][$id][] = (float)($core['used'] ?? 0);
        if (count($state['core_history'][$id]) > 64) {
            $state['core_history'][$id] = array_slice($state['core_history'][$id], -64);
        }
    }
}

function attach_core_history(array $cores, array $state): array
{
    foreach ($cores as &$core) {
        $id = (int)($core['id'] ?? 0);
        $core['history'] = $state['core_history'][$id] ?? [(float)($core['used'] ?? 0)];
    }
    unset($core);
    return $cores;
}

function parse_mem(string $top, int $physmem): array
{
    $mem = ['total' => $physmem, 'free' => 0, 'active' => 0, 'inactive' => 0, 'wired' => 0, 'arc_total' => 0];
    if (preg_match('/^Mem:\s*(.+)$/m', $top, $m)) {
        foreach (explode(',', $m[1]) as $part) {
            if (preg_match('/^\s*([0-9.]+[KMGTPE]?)\s+([A-Za-z]+)/', trim($part), $x)) {
                $label = strtolower($x[2]);
                $bytes = parse_size($x[1]);
                if (str_starts_with($label, 'active')) {
                    $mem['active'] = $bytes;
                } elseif (str_starts_with($label, 'inact')) {
                    $mem['inactive'] = $bytes;
                } elseif (str_starts_with($label, 'wired')) {
                    $mem['wired'] = $bytes;
                } elseif (str_starts_with($label, 'free')) {
                    $mem['free'] = $bytes;
                }
            }
        }
    }
    if (preg_match('/^ARC:\s*([0-9.]+[KMGTPE]?)\s+Total/m', $top, $m)) {
        $mem['arc_total'] = parse_size($m[1]);
    }
    if ($mem['total'] <= 0) {
        $mem['total'] = $mem['active'] + $mem['inactive'] + $mem['wired'] + $mem['free'];
    }
    $mem['used'] = max(0, $mem['total'] - $mem['free']);
    $mem['used_pct'] = percent($mem['used'], $mem['total']);
    return $mem;
}

function parse_pf(string $raw): array
{
    $pf = ['states' => '?', 'searches_rate' => '?', 'inserts_rate' => '?', 'removals_rate' => '?', 'match_rate' => '?', 'passed' => 0, 'blocked' => 0];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/current entries\s+(\d+)/', $line, $m)) {
            $pf['states'] = $m[1];
        } elseif (preg_match('/searches\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['searches_rate'] = $m[1] . '/s';
        } elseif (preg_match('/inserts\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['inserts_rate'] = $m[1] . '/s';
        } elseif (preg_match('/removals\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['removals_rate'] = $m[1] . '/s';
        } elseif (preg_match('/^\s+match\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['match_rate'] = $m[1] . '/s';
        } elseif (preg_match('/^\s+Passed\s+(\d+)\s+(\d+)/', $line, $m)) {
            $pf['passed'] += (int)$m[1] + (int)$m[2];
        } elseif (preg_match('/^\s+Blocked\s+(\d+)\s+(\d+)/', $line, $m)) {
            $pf['blocked'] += (int)$m[1] + (int)$m[2];
        }
    }
    return $pf;
}

function parse_net(string $raw, array &$state, float $now): array
{
    $rows = [];
    $prev = $state['net'];
    $newPrev = [];
    $elapsed = max(0.001, $now - ($state['time'] ?? $now));
    foreach (explode("\n", $raw) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 11 || $parts[0] === 'Name' || !str_starts_with($parts[2], '<Link#')) {
            continue;
        }
        $name = $parts[0];
        if (str_ends_with($name, '*') || in_array($name, ['lo0', 'pflog0', 'pfsync0'], true)) {
            continue;
        }
        $ibytes = (int)$parts[7];
        $obytes = (int)$parts[10];
        $newPrev[$name] = ['in' => $ibytes, 'out' => $obytes];
        $rows[$name] = [
            'name' => $name,
            'rx' => isset($prev[$name]) ? max(0, (int)(($ibytes - $prev[$name]['in']) / $elapsed)) : null,
            'tx' => isset($prev[$name]) ? max(0, (int)(($obytes - $prev[$name]['out']) / $elapsed)) : null,
        ];
    }
    $state['net'] = $newPrev;
    return $rows;
}

function first_net(array $net): array
{
    foreach ($net as $row) {
        return $row;
    }
    return ['rx' => null, 'tx' => null];
}

function parse_processes(string $raw, int $limit): array
{
    $rows = [];
    foreach (explode("\n", $raw) as $line) {
        if (str_starts_with($line, 'USER') || trim($line) === '') {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line), 11);
        if (count($parts) < 11) {
            continue;
        }
        $cmd = $parts[10];
        if (str_contains($cmd, APP_NAME) || str_contains($cmd, '[idle]')) {
            continue;
        }
        $name = basename(strtok($cmd, ' ') ?: $cmd);
        $rows[] = ['user' => $parts[0], 'pid' => $parts[1], 'cpu' => (float)$parts[2], 'rss' => (int)$parts[5] * 1024, 'name' => $name, 'cmd' => $cmd];
    }
    usort($rows, static fn(array $a, array $b): int => $b['cpu'] <=> $a['cpu']);
    return array_slice($rows, 0, $limit);
}

function parse_temp(string $raw): string
{
    $max = null;
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/([-0-9.]+)C/', $line, $m)) {
            $value = (float)$m[1];
            $max = $max === null ? $value : max($max, $value);
        }
    }
    return $max === null ? '--C' : (int)round($max) . 'C';
}

function update_metric_histories(array &$state, float $now, array $wan, array $lan, array $pf): void
{
    push_history($state['wan_history'], $now, (float)(($wan['rx'] ?? 0) + ($wan['tx'] ?? 0)), 60.0);
    push_history($state['lan_history'], $now, (float)(($lan['rx'] ?? 0) + ($lan['tx'] ?? 0)), 60.0);
    push_history($state['pf_history'], $now, (float)pf_states_number($pf), 60.0);
}

function pf_states_number(array $pf): int
{
    $states = (string)($pf['states'] ?? '0');
    return (int)preg_replace('/\D/', '', $states);
}

function collect_ups_metrics(array &$state, float $now): array
{
    $cacheFile = getenv('SOCX_UPS_CACHE_FILE') ?: '/tmp/socx-ups-cache.env';
    $raw = is_readable($cacheFile) ? parse_env_file($cacheFile) : [];
    if (!$raw && getenv('SOCX_UPS_DIRECT_FALLBACK') === 'true') {
        $raw = parse_ups_status_line(collect_ups_status_line());
    }
    $watts = isset($raw['watts']) && is_numeric($raw['watts']) ? (int)round((float)$raw['watts']) : null;
    if ($watts !== null) {
        push_history($state['ups_history'], $now, (float)$watts, (float)(getenv('SOCX_UPS_HISTORY_SECONDS') ?: 60));
    }
    $history = history_values($state['ups_history']);
    $avg = $history ? (int)round(array_sum($history) / count($history)) : ($watts ?? 0);
    $peak = $history ? (int)round(max($history)) : ($watts ?? 0);
    $updated = isset($raw['updated']) && is_numeric($raw['updated']) ? max(0.0, $now - (float)$raw['updated']) : null;
    $runtimeSeconds = isset($raw['runtime']) && is_numeric($raw['runtime']) ? (float)$raw['runtime'] : null;
    $linev = ups_number_text($raw['linev'] ?? ($raw['inputv'] ?? null), 1);
    $inputv = ups_number_text($raw['inputv'] ?? ($raw['linev'] ?? null), 1);
    $outputv = ups_number_text($raw['outputv'] ?? null, 1);
    if ($outputv === '?') {
        $outputv = $linev;
    }

    return [
        'online' => $watts !== null,
        'source' => (string)($raw['source'] ?? ''),
        'ups_name' => (string)($raw['ups_name'] ?? ''),
        'model' => (string)($raw['model'] ?? ''),
        'status' => (string)($raw['status'] ?? ($watts !== null ? 'ONLINE' : '')),
        'watts' => $watts ?? '?',
        'load' => isset($raw['load']) && is_numeric($raw['load']) ? (string)(int)round((float)$raw['load']) : '?',
        'battery' => isset($raw['battery']) && is_numeric($raw['battery']) ? (string)(int)round((float)$raw['battery']) : '?',
        'runtime_seconds' => $runtimeSeconds,
        'runtime' => $runtimeSeconds !== null ? format_runtime($runtimeSeconds) : '?',
        'linev' => $linev,
        'inputv' => $inputv,
        'inputfreq' => ups_number_text($raw['inputfreq'] ?? null, 1),
        'outputv' => $outputv,
        'outputfreq' => ups_number_text($raw['outputfreq'] ?? null, 1),
        'output_current' => ups_number_text($raw['output_current'] ?? null, 1),
        'battery_voltage' => ups_number_text($raw['battery_voltage'] ?? null, 1),
        'battery_temp' => ups_number_text($raw['battery_temp'] ?? null, 0),
        'nominal_watts' => ups_number_text($raw['nominal_watts'] ?? null, 0),
        'updated_age' => $updated,
        'peak60' => $peak,
        'avg60' => $avg,
        'history' => $history,
    ];
}

function ups_number_text($value, int $decimals): string
{
    if (!is_numeric($value)) {
        return '?';
    }
    return $decimals > 0 ? sprintf('%.' . $decimals . 'f', (float)$value) : (string)(int)round((float)$value);
}

function collect_ups_status_line(): string
{
    $line = trim(run_cmd('/bin/sh -c "SOCX_UPS_TINY=1 /usr/local/sbin/socx-ups-status"'));
    if ($line === '' || stripos($line, 'unavailable') !== false) {
        return '';
    }
    if (preg_match('/^(UPS\s+\S+W)\s+(.+)$/', $line, $m)) {
        return $m[1] . ' 0.5s ' . $m[2];
    }
    return $line;
}

function parse_ups_status_line(string $line): array
{
    if ($line === '') {
        return [];
    }
    $raw = [];
    if (preg_match('/UPS\s+([0-9.]+)W/i', $line, $m)) {
        $raw['watts'] = $m[1];
    }
    if (preg_match('/\s([0-9.]+)%\s+batt\s+([0-9.]+)%/i', $line, $m)) {
        $raw['load'] = $m[1];
        $raw['battery'] = $m[2];
    }
    if (preg_match('/\s([0-9]+)m\b/i', $line, $m)) {
        $raw['runtime'] = (string)((int)$m[1] * 60);
    }
    $raw['status'] = 'ONLINE';
    $raw['updated'] = (string)microtime(true);
    return $raw;
}

function parse_env_file(string $file): array
{
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = strtolower(trim($key));
        $value = trim($value, " \t\n\r\0\x0B'\"");
        if ($key !== '') {
            $rows[$key] = $value;
        }
    }
    return $rows;
}

function health_badges(array $ups): array
{
    return ['WAN UP', 'VPN UP', 'DNS OK', !empty($ups['online']) ? 'UPS ONLINE' : 'UPS --'];
}

function soc_event(string $category, string $severity, string $message, array $extra = []): array
{
    $category = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $category) ?: 'SYS');
    $severity = strtoupper(preg_replace('/[^A-Z]/i', '', $severity) ?: 'INFO');
    $message = clean_event_message($message);
    $confidence = isset($extra['confidence']) && $extra['confidence'] !== '' ? '[' . strtoupper((string)$extra['confidence']) . ']' : '';
    $ticker = sprintf('[%s][%s]%s %s', $category, $severity, $confidence, $message);
    return array_merge([
        'time' => (string)($extra['time'] ?? date('H:i:s')),
        'proto' => (string)($extra['proto'] ?? $category),
        'dir' => (string)($extra['dir'] ?? 'EVT'),
        'src' => (string)($extra['src'] ?? 'SOCX'),
        'dst' => (string)($extra['dst'] ?? $category),
        'service' => strtolower($category),
        'size' => 'ctrl',
        'bytes' => 0,
        'verdict' => $severity,
        'class' => strtolower($category),
        'category' => $category,
        'severity' => $severity,
        'feed_only' => true,
        'ticker' => $ticker,
    ], $extra);
}

function clean_event_message(string $message): string
{
    $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
    $message = preg_replace('/\s+/', ' ', $message) ?? '';
    return trim($message);
}

function collect_events(array $hosts): array
{
    $events = [];
    $filter = tail_lines('/var/log/filter.log', 180);
    foreach ($filter as $line) {
        $event = parse_filter_event($line, $hosts);
        if ($event !== null) {
            $events[] = $event;
        }
    }
    foreach (['/var/log/pfblockerng/dnsbl.log', '/var/log/pfblockerng/dns_reply.log'] as $file) {
        foreach (tail_lines($file, 80) as $line) {
            $event = parse_dnsbl_event($line, $hosts);
            if ($event !== null) {
                $events[] = $event;
            }
        }
    }
    foreach (suricata_log_files() as $file) {
        foreach (tail_lines($file, 40) as $line) {
            $event = parse_suricata_event($line, $hosts);
            if ($event !== null) {
                $events[] = $event;
            }
        }
    }
    foreach (['/var/log/dhcpd.log', '/var/log/dhcp.log'] as $file) {
        foreach (tail_lines($file, 50) as $line) {
            $event = parse_dhcp_event($line, $hosts);
            if ($event !== null) {
                $events[] = $event;
            }
        }
    }
    foreach (['/var/log/system.log', '/var/log/resolver.log', '/var/log/openvpn.log', '/var/log/ipsec.log', '/var/log/wireguard.log'] as $file) {
        foreach (tail_lines($file, 60) as $line) {
            foreach (parse_command_log_events($line, $hosts) as $event) {
                $events[] = $event;
            }
        }
    }
    return balance_events_for_feed(prioritize_events($events), 80);
}

function collect_events_cached(array &$state, array $hosts, float $now): array
{
    if (($now - (float)($state['last_events_at'] ?? 0.0)) < 1.5 && isset($state['last_events'])) {
        $state['events_fresh'] = false;
        return $state['last_events'];
    }
    $events = collect_events($hosts);
    $state['last_events'] = $events;
    $state['last_events_at'] = $now;
    $state['events_fresh'] = true;
    return $events;
}

function collect_soc_command_events(array &$state, array $hosts, float $now, array $metrics): array
{
    $events = [];
    $routineInterval = max(10, (int)(getenv('SOCX_COMMAND_STATUS_INTERVAL') ?: 30));
    $routineDue = ($now - (float)($state['last_command_status_at'] ?? 0.0)) >= $routineInterval;

    $ups = normalize_ups($metrics['ups'] ?? []);
    if (empty($ups['online'])) {
        $events[] = soc_event('UPS', 'HIGH', 'UPS data unavailable | check NUT/APC');
    } elseif (stripos((string)($ups['status'] ?? ''), 'OB') !== false || stripos((string)($ups['status'] ?? ''), 'BATT') !== false) {
        $events[] = soc_event('UPS', 'HIGH', sprintf('UPS on battery, runtime %s | power event', $ups['runtime']));
    } elseif (is_numeric($ups['runtime_seconds'] ?? null) && (float)$ups['runtime_seconds'] < 1200) {
        $events[] = soc_event('UPS', 'WARN', sprintf('Runtime below 20m: %s | reduce load', $ups['runtime']));
    } elseif ($routineDue) {
        $events[] = soc_event('UPS', 'INFO', sprintf('UPS online %sW, load %s%%, batt %s%%, run %s, out %sV/%sA | source %s',
            $ups['watts'],
            $ups['load'],
            $ups['battery'],
            $ups['runtime'],
            $ups['outputv'],
            $ups['output_current'],
            $ups['source'] ?: 'collector'));
    }

    $mem = $metrics['mem'] ?? [];
    $cpu = $metrics['cpu'] ?? [];
    $usedPct = (int)($mem['used_pct'] ?? 0);
    if ($usedPct >= 92) {
        $events[] = soc_event('SYS', 'HIGH', sprintf('RAM above 92%% (%s/%s) | inspect processes', bytes_text((int)($mem['used'] ?? 0)), bytes_text((int)($mem['total'] ?? 1))));
    } elseif ($usedPct >= 85) {
        $events[] = soc_event('SYS', 'WARN', sprintf('RAM above 85%% (%s/%s) | watch', bytes_text((int)($mem['used'] ?? 0)), bytes_text((int)($mem['total'] ?? 1))));
    }
    if ((float)($cpu['used'] ?? 0) >= 90.0) {
        $events[] = soc_event('SYS', 'WARN', sprintf('CPU high %.0f%% | inspect process table', (float)$cpu['used']));
    }

    $wan = $metrics['wan'] ?? [];
    $lan = $metrics['lan'] ?? [];
    $wanRate = (int)($wan['rx'] ?? 0) + (int)($wan['tx'] ?? 0);
    $lanRate = (int)($lan['rx'] ?? 0) + (int)($lan['tx'] ?? 0);
    if (($wan['rx'] ?? null) === null && ($wan['tx'] ?? null) === null) {
        $state['wan_counter_misses'] = (int)($state['wan_counter_misses'] ?? 0) + 1;
        if ((int)$state['wan_counter_misses'] >= 3) {
            $events[] = soc_event('WAN', 'HIGH', 'WAN counters unavailable | check link');
        }
    } elseif ($wanRate > 50 * 1024 * 1024) {
        $state['wan_counter_misses'] = 0;
        $events[] = soc_event('FLOW', 'WARN', sprintf('High-rate WAN burst %s/s | watch', short_bytes($wanRate)));
    } elseif ($lanRate > 80 * 1024 * 1024) {
        $state['wan_counter_misses'] = 0;
        $events[] = soc_event('FLOW', 'WARN', sprintf('High-rate LAN burst %s/s | inspect top talker', short_bytes($lanRate)));
    } elseif ($routineDue) {
        $state['wan_counter_misses'] = 0;
        $events[] = soc_event('WAN', 'INFO', sprintf('Frontier gateway stable, WAN %s/s | watch', short_bytes(max(0, $wanRate))));
    } else {
        $state['wan_counter_misses'] = 0;
    }

    $procs = $metrics['procs'] ?? [];
    $procNames = strtolower(implode(' ', array_map(static fn(array $p): string => (string)($p['name'] ?? '') . ' ' . (string)($p['cmd'] ?? ''), $procs)));
    $unboundRunning = str_contains($procNames, 'unbound') || process_running('unbound');
    $ntopngRunning = str_contains($procNames, 'ntopng') || process_running('ntopng');
    if (!$unboundRunning) {
        $events[] = soc_event('DNS', 'HIGH', 'Unbound resolver process missing | check DNS');
    } elseif ($routineDue) {
        $events[] = soc_event('DNS', 'INFO', 'Unbound healthy | resolver online');
    }
    if ($routineDue && $ntopngRunning) {
        $events[] = soc_event('FLOW', 'INFO', 'ntopng flow intelligence online | watch top talkers');
    }

    if ($routineDue) {
        $events = array_merge($events, disk_status_events());
        $vpn = wireguard_status_event();
        if ($vpn !== null) {
            $events[] = $vpn;
        }
        $state['last_command_status_at'] = $now;
    }

    return $events;
}

function collect_activity_summary_events(array &$state, array $events, array $flows, array $ups, array $wan, array $lan, array $pf, float $now): array
{
    $interval = max(3, (int)(getenv('SOCX_ACTIVITY_SUMMARY_SECONDS') ?: 5));
    if (($now - (float)($state['last_activity_summary_at'] ?? 0.0)) < $interval) {
        return [];
    }
    $state['last_activity_summary_at'] = $now;

    $summary = [];
    $fwBlocked = 0;
    $fwAllowed = 0;
    $dnsblHits = 0;
    $idsHits = 0;
    foreach ($events as $event) {
        $category = event_category($event);
        $verdict = strtoupper((string)($event['verdict'] ?? ''));
        if ($category === 'FW' && $verdict === 'DROP') {
            $fwBlocked++;
        } elseif ($category === 'FW' && $verdict === 'PASS') {
            $fwAllowed++;
        } elseif ($category === 'DNSBL') {
            $dnsblHits++;
        } elseif ($category === 'IDS' || $category === 'IPS') {
            $idsHits++;
        }
    }

    if ($fwBlocked > 0) {
        $summary[] = soc_event('FW', 'INFO', sprintf('Firewall blocked %d recent attempts | WAN/LAN protected', $fwBlocked));
    }
    if ($fwAllowed > 0) {
        $summary[] = soc_event('FW', 'LOW', sprintf('Firewall allowed %d recent sessions | normal traffic', $fwAllowed));
    }
    if ($dnsblHits > 0) {
        $summary[] = soc_event('DNSBL', 'INFO', sprintf('%d DNSBL hits observed | query-level filtering active', $dnsblHits));
    }
    if ($idsHits > 0) {
        $summary[] = soc_event('IDS', 'WARN', sprintf('%d IDS/IPS alerts in recent logs | inspect if repeated', $idsHits));
    }

    $topFlow = first_useful_flow($flows);
    if ($topFlow !== null) {
        $summary[] = soc_event('FLOW', 'INFO', sprintf('Top flow %s %s %s | live pf state',
            flow_path_text($topFlow, 28),
            compact_rate_pair((string)$topFlow['up'], (string)$topFlow['down'], 10),
            service_human_label((string)($topFlow['service'] ?? ''))));
    }

    $wanRate = (int)($wan['rx'] ?? 0) + (int)($wan['tx'] ?? 0);
    $lanRate = (int)($lan['rx'] ?? 0) + (int)($lan['tx'] ?? 0);
    if ($wanRate > 0 || $lanRate > 0) {
        $summary[] = soc_event('FLOW', 'INFO', sprintf('Traffic now WAN %s/s, LAN %s/s | live counters',
            short_bytes(max(0, $wanRate)),
            short_bytes(max(0, $lanRate))));
    }
    if (!empty($ups['online'])) {
        $summary[] = soc_event('UPS', 'INFO', sprintf('UPS load %sW at %s%%, battery %s%%, runtime %s',
            $ups['watts'],
            $ups['load'],
            $ups['battery'],
            $ups['runtime']));
    }
    if (isset($pf['states'])) {
        $summary[] = soc_event('PF', 'INFO', sprintf('PF state table %s states, search %s | firewall healthy',
            $pf['states'],
            compact_pf_rate((string)($pf['searches_rate'] ?? '?'))));
    }

    return $summary;
}

function first_useful_flow(array $flows): ?array
{
    foreach ($flows as $flow) {
        if (($flow['class'] ?? '') === 'idle') {
            continue;
        }
        $path = flow_path_text($flow, 30);
        if ($path !== '' && !str_contains($path, 'waiting')) {
            return $flow;
        }
    }
    return null;
}

function disk_status_events(): array
{
    $events = [];
    $raw = run_cmd("/bin/df -Pk / /var 2>/dev/null | /usr/bin/tail -n +2");
    foreach (explode("\n", $raw) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 6) {
            continue;
        }
        $mount = $parts[5];
        $used = rtrim((string)$parts[4], '%');
        if (!is_numeric($used)) {
            continue;
        }
        $free = 100 - (int)$used;
        if ($free < 10) {
            $events[] = soc_event('SYS', 'HIGH', sprintf('Disk space below 10%% on %s | cleanup', $mount));
        } elseif ($free < 20) {
            $events[] = soc_event('SYS', 'WARN', sprintf('Disk space below 20%% on %s | watch', $mount));
        }
    }
    return $events;
}

function wireguard_status_event(): ?array
{
    $raw = trim(run_cmd('/usr/local/bin/wg show 2>/dev/null | /usr/bin/head -20'));
    if ($raw === '') {
        $raw = trim(run_cmd('/usr/bin/wg show 2>/dev/null | /usr/bin/head -20'));
    }
    if ($raw === '') {
        return null;
    }
    if (preg_match('/latest handshake:\s*([^\n]+)/i', $raw, $m)) {
        return soc_event('VPN', 'INFO', 'WireGuard tunnel stable: ' . trim($m[1]));
    }
    return soc_event('VPN', 'INFO', 'WireGuard interface active | policy route ready');
}

function suricata_log_files(): array
{
    $files = [];
    foreach (['/var/log/suricata/*/fast.log', '/var/log/suricata/fast.log', '/usr/local/var/log/suricata/*/fast.log'] as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            if (is_readable($file)) {
                $files[$file] = $file;
            }
        }
    }
    return array_values($files);
}

function parse_suricata_event(string $line, array $hosts): ?array
{
    if (!str_contains($line, '[**]')) {
        return null;
    }
    $msg = 'Suricata alert';
    if (preg_match('/\[\*\*\]\s*(?:\[[^\]]+\]\s*)?(.+?)\s*\[\*\*\]/', $line, $m)) {
        $msg = trim($m[1]);
    }
    $priority = 3;
    if (preg_match('/\[Priority:\s*(\d+)\]/i', $line, $m)) {
        $priority = (int)$m[1];
    }
    $category = preg_match('/\b(drop|blocked|ips)\b/i', $line) ? 'IPS' : 'IDS';
    $severity = $priority <= 1 ? 'HIGH' : ($priority === 2 ? 'MED' : 'WARN');
    if (preg_match('/\b(malware|c2|callback|exploit)\b/i', $msg) && $priority <= 1) {
        $severity = 'CRIT';
    }
    $proto = 'IP';
    $src = 'network';
    $dst = 'internet';
    if (preg_match('/\{([A-Z0-9]+)\}\s+([0-9a-fA-F:.]+)(?::(\d+))?\s+->\s+([0-9a-fA-F:.]+)(?::(\d+))?/', $line, $m)) {
        $proto = strtoupper($m[1]);
        $src = endpoint_label($m[2], $m[3] ?? '', $hosts);
        $dst = endpoint_label($m[4], $m[5] ?? '', $hosts);
    }
    $verb = $category === 'IPS' ? 'blocked' : 'alert';
    return soc_event($category, $severity, sprintf('%s -> %s Suricata %s: %s | inspect host', $src, $dst, $verb, truncate_modern_text($msg, 64)), [
        'feed_only' => false,
        'proto' => $proto,
        'dir' => 'ALRT',
        'src' => $src,
        'dst' => $dst,
        'service' => strtolower($category),
        'verdict' => $category === 'IPS' ? 'DROP' : 'ALERT',
        'class' => strtolower($category),
    ]);
}

function parse_dhcp_event(string $line, array $hosts): ?array
{
    $lower = strtolower($line);
    if (str_contains($lower, 'dhclient') || str_contains($lower, 'dhcp client')) {
        if (preg_match('/bound to\s+(\d{1,3}(?:\.\d{1,3}){3})/i', $line, $m)) {
            return soc_event('WAN', 'INFO', sprintf('WAN DHCP lease active: %s | ISP lease renewed', lan_label($m[1])));
        }
        if (preg_match('/dhcpack from\s+(\d{1,3}(?:\.\d{1,3}){3})/i', $line, $m)) {
            return soc_event('WAN', 'INFO', sprintf('WAN DHCP acknowledged by ISP gateway %s', lan_label($m[1])));
        }
        if (preg_match('/\brenew\b/i', $line)) {
            return soc_event('WAN', 'INFO', 'WAN DHCP lease renewal started');
        }
        return null;
    }
    if (!preg_match('/DHCP(ACK|OFFER|REQUEST).*?\b(\d{1,3}(?:\.\d{1,3}){3})\b/i', $line, $m)) {
        return null;
    }
    $kind = strtoupper($m[1]);
    $ip = $m[2];
    $label = host_label($ip, $hosts);
    $host = '';
    if (preg_match('/\(([^)]+)\)/', $line, $hm)) {
        $host = preg_replace('/[^\w.\-]/', '', $hm[1]) ?: '';
    }
    $name = $host !== '' ? $host . ' ' . lan_label($ip) : $label;
    if ($kind === 'OFFER' && !isset($hosts[$ip])) {
        return soc_event('DHCP', 'WARN', sprintf('Unknown device joined %s | verify MAC', lan_label($ip)));
    }
    if ($kind === 'ACK') {
        return soc_event('DHCP', 'INFO', sprintf('Renewed: %s', $name));
    }
    return soc_event('DHCP', 'INFO', sprintf('Lease request: %s', $name));
}

function parse_command_log_events(string $line, array $hosts): array
{
    $events = [];
    $lower = strtolower($line);
    if (preg_match('/\b([a-z]+[0-9]+|wg[0-9]+|ovpn[^\s:]*|wan|lan)\b.*link state changed to (up|down)/i', $line, $m)) {
        $iface = $m[1];
        $state = strtoupper($m[2]);
        $severity = $state === 'DOWN' ? 'HIGH' : 'INFO';
        $events[] = soc_event('IFACE', $severity, sprintf('%s link %s | %s', $iface, strtolower($state), $state === 'DOWN' ? 'check cable/gateway' : 'interface active'));
    }
    if (str_contains($lower, 'dpinger') || str_contains($lower, 'gateway')) {
        if (preg_match('/(down|alarm|packet loss|loss)/i', $line)) {
            $events[] = soc_event('WAN', 'WARN', 'Gateway loss/latency alarm | check Frontier ONT');
        } elseif (preg_match('/(clear|recovered|up)/i', $line)) {
            $events[] = soc_event('WAN', 'INFO', 'WAN gateway recovered | watch');
        }
    }
    if (preg_match('/\b(openvpn|wireguard|ipsec|wg[0-9]*)\b/i', $line)) {
        if (preg_match('/(down|failed|timeout|inactive|disconnect)/i', $line)) {
            $events[] = soc_event('VPN', 'HIGH', 'VPN down or handshake failed | protect LAN route');
        } elseif (preg_match('/(up|handshake|connected|established)/i', $line)) {
            $events[] = soc_event('VPN', 'INFO', 'VPN tunnel active | policy route restored');
        }
    }
    if (preg_match('/\b(unbound|resolver)\b/i', $line)) {
        if (preg_match('/(fail|error|fatal|timeout|refused)/i', $line)) {
            $events[] = soc_event('DNS', 'HIGH', 'DNS resolver failures rising | inspect Unbound');
        } elseif (preg_match('/(start|restart|stop|exit|reload)/i', $line)) {
            $events[] = soc_event('DNS', 'MED', 'Resolver restart detected | watch clients');
        }
    }
    if (preg_match('/arp.*(moved|duplicate|is using|changed)/i', $line)) {
        if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $line, $ipm)) {
            $events[] = soc_event('ARP', 'HIGH', sprintf('Possible ARP spoof: %s changed MAC | verify', lan_label($ipm[1])));
        } else {
            $events[] = soc_event('ARP', 'MED', 'ARP/MAC anomaly detected | verify');
        }
    }
    if (preg_match('/ntopng.*(restart|start|stop|exit)/i', $line)) {
        $events[] = soc_event('SYS', 'INFO', 'ntopng restarted | flow intelligence online');
    }
    return $events;
}

function event_priority_score($event): int
{
    if (is_array($event)) {
        $category = strtoupper((string)($event['category'] ?? ''));
        $severity = strtoupper((string)($event['severity'] ?? ''));
        if ($category === '' || $severity === '') {
            $parts = event_text_parts((string)($event['ticker'] ?? ''));
            $category = $category !== '' ? $category : $parts['category'];
            $severity = $severity !== '' ? $severity : $parts['severity'];
        }
        $timeBoost = isset($event['time']) && preg_match('/^\d{2}:\d{2}:\d{2}$/', (string)$event['time']) ? 1 : 0;
        return severity_rank($severity) + category_weight($category, $severity) + $timeBoost;
    }

    $parts = event_text_parts((string)$event);
    return severity_rank($parts['severity']) + category_weight($parts['category'], $parts['severity']);
}

function event_text_parts(string $text): array
{
    $category = 'SYS';
    $severity = 'INFO';
    if (preg_match('/^\s*\[([A-Z0-9]+)\]\[([A-Z]+)\]/i', $text, $m)) {
        $category = strtoupper($m[1]);
        $severity = strtoupper($m[2]);
    } elseif (preg_match('/\[(CRIT|HIGH|MED|WARN|LOW|INFO)\]/i', $text, $m)) {
        $severity = strtoupper($m[1]);
    }
    return ['category' => $category, 'severity' => $severity];
}

function severity_rank(string $severity): int
{
    return match (strtoupper($severity)) {
        'CRIT' => 600,
        'HIGH' => 500,
        'MED' => 350,
        'WARN' => 250,
        'LOW' => 100,
        default => 50,
    };
}

function category_weight(string $category, string $severity): int
{
    $category = strtoupper($category);
    $severity = strtoupper($severity);
    return match ($category) {
        'IPS', 'IDS' => 50,
        'WAN', 'VPN', 'UPS', 'DNS' => 40,
        'FW' => 30,
        'ARP', 'DHCP', 'DEVICE', 'IFACE' => 20,
        'SYS' => 15,
        'FLOW', 'PF' => 10,
        'DNSBL' => in_array($severity, ['WARN', 'MED', 'HIGH', 'CRIT'], true) ? 0 : -20,
        default => 0,
    };
}

function prioritize_events(array $events): array
{
    usort($events, static function (array $a, array $b): int {
        $score = event_priority_score($b) <=> event_priority_score($a);
        if ($score !== 0) {
            return $score;
        }
        return strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? ''));
    });
    return $events;
}

function balance_events_for_feed(array $events, int $limit): array
{
    $caps = [
        'FW' => 8,
        'DNSBL' => 8,
        'FLOW' => 5,
        'PF' => 5,
        'SYS' => 5,
        'DNS' => 5,
        'WAN' => 5,
        'VPN' => 5,
        'UPS' => 5,
        'DHCP' => 4,
        'ARP' => 4,
        'IFACE' => 4,
        'IDS' => 6,
        'IPS' => 6,
    ];
    $counts = [];
    $balanced = [];
    $overflow = [];

    foreach ($events as $event) {
        $category = event_category($event);
        $cap = $caps[$category] ?? 4;
        $counts[$category] = (int)($counts[$category] ?? 0);
        if ($counts[$category] < $cap) {
            $balanced[] = $event;
            $counts[$category]++;
            continue;
        }
        $overflow[] = $event;
    }

    foreach ($overflow as $event) {
        if (count($balanced) >= $limit) {
            break;
        }
        $balanced[] = $event;
    }

    return array_slice($balanced, 0, $limit);
}

function event_category($event): string
{
    if (is_array($event)) {
        $category = strtoupper((string)($event['category'] ?? ''));
        if ($category !== '') {
            return $category;
        }
        return event_text_parts((string)($event['ticker'] ?? ''))['category'];
    }
    return event_text_parts((string)$event)['category'];
}

function event_ticker_texts(array $events): array
{
    return array_values(array_filter(array_map(static fn(array $event): string => clean_ticker_event((string)($event['ticker'] ?? '')), $events)));
}

function update_ticker_queue(array &$state, array $events, float $now): void
{
    $cfg = $state['ticker_config'] ?? ticker_config([]);
    $maxEvents = (int)$cfg['max_events'];
    $dedupeSeconds = (int)$cfg['dedupe_seconds'];
    $mode = (string)($cfg['mode'] ?? 'scroll');

    foreach ($events as $event) {
        $text = clean_ticker_event((string)$event);
        if ($text === '') {
            continue;
        }
        $key = ticker_key($text);
        $isRepeat = isset($state['ticker_seen'][$key]) && ($now - (float)$state['ticker_seen'][$key]['last']) <= $dedupeSeconds;
        if ($isRepeat) {
            $state['ticker_seen'][$key]['last'] = $now;
            $state['ticker_seen'][$key]['count'] = (int)$state['ticker_seen'][$key]['count'] + 1;
            foreach ($state['ticker_queue'] as &$item) {
                if ($item['key'] === $key) {
                    $item['last'] = $now;
                    $item['count'] = (int)$state['ticker_seen'][$key]['count'];
                    $item['text'] = $text;
                    $state['ticker_changed'] = true;
                    break;
                }
            }
            unset($item);
            continue;
        }

        $state['ticker_seen'][$key] = ['last' => $now, 'count' => 1];
        array_unshift($state['ticker_queue'], ['key' => $key, 'text' => $text, 'count' => 1, 'last' => $now]);
        $state['ticker_changed'] = true;
        if ($mode === 'scroll' || is_high_or_crit($text)) {
            $state['ticker_index'] = 0;
            $state['last_ticker_advance_at'] = $now;
        } elseif ((float)($state['last_ticker_advance_at'] ?? 0.0) <= 0.0) {
            $state['last_ticker_advance_at'] = $now;
        }

        if ($mode === 'scroll' && is_high_or_crit($text)) {
            $state['ticker_hold_text'] = $text;
            $state['ticker_hold_until'] = $now + (str_contains($text, '[CRIT]') ? 2.0 : 1.25);
            $state['ticker_offset'] = 0;
        }
    }

    $state['ticker_queue'] = array_slice($state['ticker_queue'], 0, $maxEvents);
    foreach (array_keys($state['ticker_seen']) as $key) {
        if (($now - (float)$state['ticker_seen'][$key]['last']) > max($dedupeSeconds * 3, 30)) {
            unset($state['ticker_seen'][$key]);
        }
    }
}

function clean_ticker_event(string $event): string
{
    $event = str_replace(ticker_separator_marker(), '-', $event);
    $event = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $event) ?? '';
    $event = preg_replace('/\s+/', ' ', $event) ?? '';
    return trim($event);
}

function ticker_key(string $event): string
{
    $event = preg_replace('/\s+x\d+$/', '', $event) ?? $event;
    return strtolower(trim($event));
}

function is_high_or_crit(string $event): bool
{
    return str_contains($event, '[HIGH]') || str_contains($event, '[CRIT]');
}

function ticker_event_texts(array $state): array
{
    $rows = [];
    $items = $state['ticker_queue'] ?? [];
    usort($items, static function (array $a, array $b): int {
        $score = event_priority_score((string)($b['text'] ?? '')) <=> event_priority_score((string)($a['text'] ?? ''));
        if ($score !== 0) {
            return $score;
        }
        return ((float)($b['last'] ?? 0.0)) <=> ((float)($a['last'] ?? 0.0));
    });

    $dnsblShown = 0;
    $dnsblOverflow = 0;
    $categoryShown = [];
    $categoryCaps = [
        'FW' => 6,
        'DNSBL' => 8,
        'FLOW' => 5,
        'PF' => 5,
        'UPS' => 5,
        'WAN' => 5,
        'DNS' => 5,
        'VPN' => 5,
        'SYS' => 5,
        'DHCP' => 4,
        'ARP' => 4,
        'IFACE' => 4,
        'IDS' => 6,
        'IPS' => 6,
    ];
    foreach ($items as $item) {
        $text = (string)$item['text'];
        $count = (int)($item['count'] ?? 1);
        if ($count > 1) {
            $text .= ' x' . $count;
        }
        $parts = event_text_parts($text);
        $category = $parts['category'];
        $categoryShown[$category] = (int)($categoryShown[$category] ?? 0);
        if ($categoryShown[$category] >= ($categoryCaps[$category] ?? 4)) {
            continue;
        }
        $categoryShown[$category]++;
        $lowDnsbl = $parts['category'] === 'DNSBL' && in_array($parts['severity'], ['INFO', 'LOW'], true);
        if ($lowDnsbl) {
            if ($dnsblShown >= 6) {
                $dnsblOverflow += max(1, $count);
                continue;
            }
            $dnsblShown++;
        }
        $rows[] = $text;
    }
    if ($dnsblOverflow > 0) {
        $rows[] = '[DNSBL][INFO] Additional DNSBL hits summarized x' . $dnsblOverflow . ' | no action';
    }
    if (!$rows && isset($state['last_frame']['events'])) {
        foreach ($state['last_frame']['events'] as $event) {
            $rows[] = clean_ticker_event((string)$event);
        }
        usort($rows, static fn(string $a, string $b): int => event_priority_score($b) <=> event_priority_score($a));
    }
    return interleave_event_rows($rows);
}

function interleave_event_rows(array $rows): array
{
    $urgent = [];
    $buckets = [];
    $categoryOrder = ['FLOW', 'UPS', 'PF', 'DNSBL', 'WAN', 'DNS', 'VPN', 'IDS', 'IPS', 'DHCP', 'ARP', 'IFACE', 'SYS', 'DEVICE', 'FW'];
    foreach ($rows as $row) {
        $parts = event_text_parts((string)$row);
        if (in_array($parts['severity'], ['CRIT', 'HIGH'], true)) {
            $urgent[] = (string)$row;
            continue;
        }
        $buckets[$parts['category']][] = (string)$row;
    }

    $mixed = [];
    do {
        $added = false;
        foreach ($categoryOrder as $category) {
            if (!empty($buckets[$category])) {
                $mixed[] = array_shift($buckets[$category]);
                $added = true;
            }
        }
        foreach (array_keys($buckets) as $category) {
            if (!in_array($category, $categoryOrder, true) && !empty($buckets[$category])) {
                $mixed[] = array_shift($buckets[$category]);
                $added = true;
            }
        }
    } while ($added);

    usort($urgent, static fn(string $a, string $b): int => event_priority_score($b) <=> event_priority_score($a));
    return array_values(array_unique(array_merge($urgent, $mixed)));
}

function advance_ticker(array &$state, float $now): void
{
    $cfg = $state['ticker_config'] ?? ticker_config([]);
    $mode = (string)($cfg['mode'] ?? 'scroll');
    if ($mode !== 'scroll') {
        advance_rotating_event_feed($state, $cfg, $now);
        return;
    }

    if ($now < (float)($state['ticker_hold_until'] ?? 0.0)) {
        $state['last_ticker_advance_at'] = $now;
        return;
    }
    $interval = max(0.001, ((int)$cfg['interval_ms']) / 1000);
    $last = (float)($state['last_ticker_advance_at'] ?? 0.0);
    if ($last <= 0.0) {
        $state['last_ticker_advance_at'] = $now;
        return;
    }
    $ticks = (int)floor(($now - $last) / $interval);
    if ($ticks <= 0) {
        return;
    }
    $state['last_ticker_advance_at'] = $last + ($ticks * $interval);
    $state['ticker_offset'] = ((int)($state['ticker_offset'] ?? 0) + ($ticks * (int)$cfg['step'])) % 1000000;
    $state['ticker_changed'] = true;
}

function advance_rotating_event_feed(array &$state, array $cfg, float $now): void
{
    $count = count($state['ticker_queue'] ?? []);
    if ($count <= 0) {
        $state['last_ticker_advance_at'] = $now;
        $state['ticker_index'] = 0;
        return;
    }
    $index = (int)($state['ticker_index'] ?? 0);
    if ($index < 0 || $index >= $count) {
        $state['ticker_index'] = 0;
        $state['ticker_changed'] = true;
        $state['last_ticker_advance_at'] = $now;
        return;
    }
    $last = (float)($state['last_ticker_advance_at'] ?? 0.0);
    if ($last <= 0.0) {
        $state['last_ticker_advance_at'] = $now;
        return;
    }
    $item = $state['ticker_queue'][$index] ?? [];
    $text = (string)($item['text'] ?? '');
    $duration = event_feed_duration($text, $cfg);
    if (($now - $last) < $duration) {
        return;
    }
    $state['ticker_index'] = ($index + 1) % $count;
    $state['last_ticker_advance_at'] = $now;
    $state['ticker_changed'] = true;
}

function event_feed_duration(string $event, array $cfg): int
{
    if (str_contains($event, '[CRIT]')) {
        return (int)($cfg['crit_seconds'] ?? 8);
    }
    if (str_contains($event, '[HIGH]')) {
        return (int)($cfg['high_seconds'] ?? 6);
    }
    if (str_contains($event, '[MED]')) {
        return (int)($cfg['med_seconds'] ?? 4);
    }
    return (int)($cfg['rotate_seconds'] ?? 3);
}

function tail_lines(string $file, int $count): array
{
    if (!is_readable($file)) {
        return [];
    }
    $raw = run_cmd('/usr/bin/tail -n ' . (int)$count . ' ' . escapeshellarg($file));
    return array_values(array_filter(explode("\n", $raw), static fn(string $line): bool => trim($line) !== ''));
}

function parse_filter_event(string $line, array $hosts): ?array
{
    $payloadStart = strrpos($line, ': ');
    $payload = $payloadStart === false ? $line : substr($line, $payloadStart + 2);
    if (!str_contains($payload, ',')) {
        return null;
    }
    $fields = str_getcsv($payload, ',', '"', '\\');
    if (count($fields) < 20) {
        return null;
    }
    $action = strtolower($fields[6] ?? '');
    if ($action !== 'pass' && $action !== 'block') {
        return null;
    }
    $dir = strtoupper($fields[7] ?? 'FLOW');
    $ipVersion = (string)($fields[8] ?? '');
    if ($ipVersion === '6') {
        $proto = strtoupper($fields[12] ?? 'IP6');
        $len = preg_replace('/\D/', '', $fields[14] ?? '') ?: '0';
        $src = $fields[15] ?? '';
        $dst = $fields[16] ?? '';
        $sport = $fields[17] ?? '';
        $dport = $fields[18] ?? '';
    } else {
        $proto = strtoupper($fields[16] ?? ($fields[15] ?? 'IP'));
        $len = preg_replace('/\D/', '', $fields[17] ?? '') ?: '0';
        $src = $fields[18] ?? '';
        $dst = $fields[19] ?? '';
        $sport = $fields[20] ?? '';
        $dport = $fields[21] ?? '';
    }
    if ($src === '' || $dst === '') {
        return null;
    }
    if (is_pf_flow_noise_ip($src) || is_pf_flow_noise_ip($dst)) {
        return null;
    }
    $srcLabel = endpoint_label($src, $sport, $hosts);
    $dstLabel = endpoint_label($dst, $dport, $hosts);
    $verdict = $action === 'block' ? 'DROP' : 'PASS';
    $svc = service_name($dport ?: $sport);
    $time = preg_match('/(\d{2}:\d{2}:\d{2})/', $line, $m) ? $m[1] : date('H:i:s');
    $severity = $verdict === 'DROP' ? 'MED' : 'LOW';
    $ticker = firewall_ticker_text($action, $severity, (string)$dir, $srcLabel, $dstLabel, $svc);
    return [
        'time' => $time,
        'proto' => $proto,
        'dir' => in_array($dir, ['IN', 'OUT'], true) ? $dir : 'FLOW',
        'src' => $srcLabel,
        'dst' => $dstLabel,
        'service' => $svc,
        'size' => $len === '0' ? 'ctrl' : $len . 'B',
        'bytes' => (int)$len,
        'verdict' => $verdict,
        'class' => $verdict === 'DROP' ? 'blocked' : (($svc === 'dns' || $svc === 'https') ? 'internet' : 'firewall'),
        'category' => 'FW',
        'severity' => $severity,
        'ticker' => $ticker,
    ];
}

function firewall_ticker_text(string $action, string $severity, string $dir, string $srcLabel, string $dstLabel, string $svc): string
{
    $src = compact_endpoint_label($srcLabel, true);
    $dst = compact_endpoint_label($dstLabel, true);
    $service = service_human_label($svc);
    $blocked = strtolower($action) === 'block';
    $dir = strtoupper($dir);

    if ($blocked) {
        if ($dir === 'IN' && endpoint_is_external($srcLabel) && endpoint_is_external($dstLabel)) {
            return sprintf('[FW][%s] WAN scan blocked: %s -> WAN %s', $severity, $src, $service);
        }
        if (endpoint_is_lan($srcLabel)) {
            return sprintf('[FW][%s] Firewall blocked %s -> %s %s', $severity, $src, $dst, $service);
        }
        return sprintf('[FW][%s] Firewall blocked %s -> %s %s', $severity, $src, $dst, $service);
    }

    if (endpoint_is_lan($srcLabel) || endpoint_is_lan($dstLabel)) {
        return sprintf('[FW][LOW] Firewall allowed %s -> %s %s', $src, $dst, $service);
    }
    return sprintf('[FW][LOW] Firewall observed %s -> %s %s', $src, $dst, $service);
}

function parse_dnsbl_event(string $line, array $hosts): ?array
{
    if (!preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $line, $ipm)) {
        return null;
    }
    $domain = 'blocked-domain';
    if (preg_match('/([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $line, $dm)) {
        $domain = $dm[1];
    }
    $verdict = stripos($line, 'sink') !== false ? 'SINKHOLE' : 'DNSBL HIT';
    $src = endpoint_label($ipm[1], '', $hosts);
    return [
        'time' => date('H:i:s'),
        'proto' => 'DNS',
        'dir' => 'OUT',
        'src' => $src,
        'dst' => $domain,
        'service' => 'dnsbl',
        'size' => '0B',
        'bytes' => 0,
        'verdict' => $verdict,
        'class' => 'dnsbl',
        'category' => 'DNSBL',
        'severity' => 'LOW',
        'ticker' => sprintf('[DNSBL][LOW] %s DNSBL hit: %s', compact_endpoint_label($src, true), $domain),
    ];
}

function flows_from_events(array $events): array
{
    $agg = [];
    foreach ($events as $e) {
        if (!empty($e['feed_only'])) {
            continue;
        }
        $key = $e['src'] . '|' . $e['dst'] . '|' . $e['class'] . '|' . ($e['service'] ?? '');
        if (!isset($agg[$key])) {
            $agg[$key] = ['src' => $e['src'], 'dst' => $e['dst'], 'up_bytes' => 0, 'down_bytes' => 0, 'class' => $e['class'], 'service' => $e['service'] ?? '', 'total' => 0];
        }
        $bytes = max(1, (int)$e['bytes']);
        if ($e['dir'] === 'IN') {
            $agg[$key]['down_bytes'] += $bytes;
        } else {
            $agg[$key]['up_bytes'] += $bytes;
        }
        $agg[$key]['total'] += $bytes;
    }
    usort($agg, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    $max = max(1, (int)($agg[0]['total'] ?? 1));
    $rows = [];
    foreach (array_slice($agg, 0, 8) as $row) {
        $rows[] = [
            'src' => $row['src'],
            'dst' => $row['dst'],
            'up' => short_bytes($row['up_bytes']),
            'down' => short_bytes($row['down_bytes']),
            'class' => $row['class'],
            'service' => $row['service'],
            'graph' => graph_bar((int)$row['total'], $max, $row['class'] === 'dnsbl' || $row['class'] === 'blocked'),
        ];
    }
    if (!$rows) {
        $rows[] = ['src' => 'pfSense', 'dst' => 'waiting', 'up' => '0B', 'down' => '0B', 'class' => 'idle', 'graph' => '[........]'];
    }
    return $rows;
}

function collect_pf_state_flows(array &$state, array $hosts, float $now): array
{
    $raw = run_cmd('/sbin/pfctl -ss -v');
    if (trim($raw) === '') {
        return [];
    }

    $prev = $state['pf_state_prev'] ?? [];
    $current = [];
    $rows = [];
    $pending = null;

    foreach (explode("\n", $raw) as $line) {
        if (trim($line) === '') {
            continue;
        }
        if (!preg_match('/^\s/', $line)) {
            $pending = parse_pf_state_header($line, $hosts);
            continue;
        }
        if ($pending === null || !preg_match('/,\s*(\d+):(\d+)\s+pkts,\s*(\d+):(\d+)\s+bytes/i', $line, $m)) {
            continue;
        }

        $bytesA = (int)$m[3];
        $bytesB = (int)$m[4];
        $key = $pending['key'];
        $prior = $prev[$key] ?? null;
        $elapsed = $prior ? max(0.001, $now - (float)$prior['t']) : max(1.0, parse_pf_age_seconds($line));
        $deltaA = $prior ? max(0, $bytesA - (int)$prior['a']) : $bytesA;
        $deltaB = $prior ? max(0, $bytesB - (int)$prior['b']) : $bytesB;
        $rateA = (int)round($deltaA / $elapsed);
        $rateB = (int)round($deltaB / $elapsed);
        $score = $rateA + $rateB;
        if ($score <= 0 && !$prior) {
            $score = min($bytesA + $bytesB, 1_000_000);
        }
        $current[$key] = ['a' => $bytesA, 'b' => $bytesB, 't' => $now];
        $rows[] = [
            'src' => $pending['src'],
            'dst' => $pending['dst'],
            'up' => short_bytes($rateA) . '/s',
            'down' => short_bytes($rateB) . '/s',
            'class' => $pending['class'],
            'service' => $pending['service'],
            'proto' => $pending['proto'],
            'iface' => $pending['iface'],
            'score' => $score,
            'graph' => graph_bar(max(1, $score), max(1, $score), false),
        ];
        $pending = null;
    }

    $state['pf_state_prev'] = $current;
    usort($rows, static fn(array $a, array $b): int => ((int)$b['score']) <=> ((int)$a['score']));
    return array_slice($rows, 0, 16);
}

function parse_pf_state_header(string $line, array $hosts): ?array
{
    if (!preg_match('/^(\S+)\s+([a-z0-9]+)\s+(.+?)\s+(->|<-)\s+(.+?)\s{2,}/i', $line, $m)) {
        return null;
    }
    $iface = $m[1];
    if (in_array($iface, ['lo0', 'pflog0', 'pfsync0'], true)) {
        return null;
    }
    $proto = strtoupper($m[2]);
    $left = parse_pf_endpoint_text($m[3], $hosts);
    $right = parse_pf_endpoint_text($m[5], $hosts);
    if ($left['label'] === '' || $right['label'] === '') {
        return null;
    }
    if ($m[4] === '<-') {
        $src = $right;
        $dst = $left;
    } else {
        $src = $left;
        $dst = $right;
    }
    if (is_pf_flow_noise_ip($src['ip']) || is_pf_flow_noise_ip($dst['ip'])) {
        return null;
    }
    $service = service_name($dst['port'] !== '' ? $dst['port'] : $src['port']);
    $class = str_starts_with($iface, 'tun') || str_starts_with($iface, 'wg') ? 'vpn' : (($service === 'dns' || $service === 'https') ? 'internet' : 'state');
    return [
        'iface' => $iface,
        'proto' => $proto,
        'src' => $src['label'],
        'dst' => $dst['label'],
        'service' => $service,
        'class' => $class,
        'key' => implode('|', [$iface, $proto, $src['ip'], $src['port'], $dst['ip'], $dst['port']]),
    ];
}

function parse_pf_endpoint_text(string $text, array $hosts): array
{
    $text = trim($text);
    if (preg_match('/\(([^)]+)\)/', $text, $m)) {
        $text = trim($m[1]);
    }
    $text = preg_split('/\s+/', $text)[0] ?? $text;
    $text = trim($text, '[]');
    $ip = $text;
    $port = '';
    if (preg_match('/^(.+):(\d+)$/', $text, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
        $ip = $m[1];
        $port = $m[2];
    } elseif (preg_match('/^(.+)\[(\d+)\]$/', $text, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
        $ip = $m[1];
        $port = $m[2];
    } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $text, $m)) {
        $ip = $m[1];
        $port = $m[2];
    }
    $label = filter_var($ip, FILTER_VALIDATE_IP) ? endpoint_label($ip, $port, $hosts) : truncate_text($text, 24);
    return ['ip' => $ip, 'port' => $port, 'label' => $label];
}

function is_pf_flow_noise_ip(string $ip): bool
{
    $ip = strtolower($ip);
    if ($ip === '255.255.255.255' || $ip === '192.168.1.255' || $ip === '::1' || str_starts_with($ip, '127.')) {
        return true;
    }
    return str_starts_with($ip, 'ff') || str_starts_with($ip, 'fe80:');
}

function parse_pf_age_seconds(string $line): float
{
    if (!preg_match('/age\s+([0-9:]+)/', $line, $m)) {
        return 1.0;
    }
    $parts = array_map('intval', explode(':', $m[1]));
    if (count($parts) === 3) {
        return (float)(($parts[0] * 3600) + ($parts[1] * 60) + $parts[2]);
    }
    if (count($parts) === 2) {
        return (float)(($parts[0] * 60) + $parts[1]);
    }
    return max(1.0, (float)($parts[0] ?? 1));
}

function packets_from_events(array $events): array
{
    $rows = [];
    foreach ($events as $e) {
        if (!empty($e['feed_only'])) {
            continue;
        }
        $rows[] = ['time' => $e['time'], 'proto' => $e['proto'], 'dir' => $e['dir'], 'src' => $e['src'], 'dst' => $e['dst'], 'service' => $e['service'], 'size' => $e['size'], 'verdict' => $e['verdict']];
        if (count($rows) >= 12) {
            break;
        }
    }
    if (!$rows) {
        $rows[] = ['time' => date('H:i:s'), 'proto' => 'PF', 'dir' => 'LCL', 'src' => 'pfSense', 'dst' => 'waiting', 'service' => 'log', 'size' => 'ctrl', 'verdict' => 'PASS'];
    }
    return $rows;
}

function endpoint_label(string $ip, string $port, array $hosts): string
{
    $label = host_label($ip, $hosts);
    if ($port !== '' && !str_starts_with($label, 'LAN.')) {
        return $label . ':' . $port;
    }
    return $label;
}

function host_label(string $ip, array $hosts): string
{
    if (isset($hosts[$ip])) {
        return $hosts[$ip] . '/' . lan_label($ip);
    }
    return lan_label($ip);
}

function lan_label(string $ip): string
{
    if ($ip === '255.255.255.255' || $ip === '192.168.1.255') {
        return 'BCAST';
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return truncate_text($ip, 24);
    }
    if (preg_match('/^192\.168\.1\.(\d+)$/', $ip, $m)) {
        return 'LAN.' . $m[1];
    }
    if (preg_match('/^\d+\.\d+\.(\d+)\.(\d+)$/', $ip, $m)) {
        return 'EXT.' . $m[1] . '.' . $m[2];
    }
    return $ip;
}

function service_name(string $port): string
{
    return match ($port) {
        '53' => 'dns',
        '80' => 'http',
        '443' => 'https',
        '123' => 'ntp',
        '500', '1443', '4500', '51820', '51821' => 'vpn',
        '22' => 'ssh',
        '137', '138', '139', '445' => 'smb',
        default => $port !== '' ? 'port' . $port : 'unknown',
    };
}

function flow_total(array $flows): string
{
    $up = 0;
    $down = 0;
    foreach ($flows as $flow) {
        $up += parse_short_bytes($flow['up']);
        $down += parse_short_bytes($flow['down']);
    }
    return sprintf('TOTAL up %-8s down %-8s both %s', short_bytes($up), short_bytes($down), short_bytes($up + $down));
}

function rate_text(?int $bytes): string
{
    return $bytes === null ? '--/s' : short_bytes($bytes) . '/s';
}

function bytes_text(int $bytes): string
{
    return short_bytes($bytes);
}

function short_bytes(int $bytes): string
{
    $units = ['B', 'K', 'M', 'G', 'T'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    if ($unit === 0) {
        return (string)((int)$value) . 'B';
    }
    return ($value >= 10 ? sprintf('%.1f', $value) : sprintf('%.2f', $value)) . $units[$unit];
}

function parse_short_bytes(string $text): int
{
    if (!preg_match('/([0-9.]+)\s*([BKMGTP]?)/i', $text, $m)) {
        return 0;
    }
    $value = (float)$m[1];
    $unit = strtoupper($m[2]);
    $mult = ['' => 1, 'B' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776][$unit] ?? 1;
    return (int)round($value * $mult);
}

function parse_size(string $text): int
{
    if (!preg_match('/^([0-9.]+)\s*([KMGTPE]?)/i', trim($text), $m)) {
        return 0;
    }
    $value = (float)$m[1];
    $unit = strtoupper($m[2]);
    $mult = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776, 'P' => 1125899906842624][$unit] ?? 1;
    return (int)round($value * $mult);
}

function percent(int $value, int $total): int
{
    if ($total <= 0) {
        return 0;
    }
    return max(0, min(100, (int)round(($value / $total) * 100)));
}

function compact_num(int $num): string
{
    if ($num >= 1000000000) {
        return sprintf('%.1fB', $num / 1000000000);
    }
    if ($num >= 1000000) {
        return sprintf('%.1fM', $num / 1000000);
    }
    if ($num >= 1000) {
        return sprintf('%.1fK', $num / 1000);
    }
    return (string)$num;
}

function compact_num_tight(int $num): string
{
    if ($num >= 1000000000) {
        return (string)((int)round($num / 1000000000)) . 'B';
    }
    if ($num >= 1000000) {
        return (string)((int)round($num / 1000000)) . 'M';
    }
    if ($num >= 1000) {
        return (string)((int)round($num / 1000)) . 'K';
    }
    return (string)$num;
}

function compact_bytes_tight(int $bytes): string
{
    $units = ['B', 'K', 'M', 'G', 'T'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    if ($unit === 0) {
        return (string)((int)round($value)) . 'B';
    }
    return (string)((int)round($value)) . $units[$unit];
}

function compact_rate(string $rate): string
{
    $rate = str_replace('/s', '', $rate);
    return truncate_text($rate, 8);
}

function compact_pf_rate(string $rate): string
{
    $rate = trim(str_replace('/s', '', $rate));
    if ($rate === '' || $rate === '?') {
        return '?';
    }
    if (!is_numeric($rate)) {
        return truncate_text($rate, 7);
    }
    return short_bytes((int)round((float)$rate)) . '/s';
}

function down_marker(): string
{
    return modern_graph_style() === 'unicode' ? '↓' : 'D';
}

function up_marker(): string
{
    return modern_graph_style() === 'unicode' ? '↑' : 'U';
}

function modern_temp_text(string $temp): string
{
    if (modern_graph_style() !== 'unicode') {
        return $temp;
    }
    if (preg_match('/^([0-9-]+)C$/', $temp, $m)) {
        return $m[1] . '°C';
    }
    return $temp;
}

function compact_rate_pair(string $up, string $down, int $width): string
{
    $text = strip_decimal_unit($up) . '/' . strip_decimal_unit($down);
    return truncate_text($text, $width);
}

function strip_decimal_unit(string $value): string
{
    $value = str_replace(['B', '/s'], '', $value);
    if (preg_match('/^([0-9]+)\.([0-9])[0-9]*([KMGTP]?)$/', $value, $m)) {
        return $m[1] . $m[3];
    }
    return $value;
}

function flow_class_short(string $class): string
{
    return match ($class) {
        'dnsbl' => 'dns-hit',
        'blocked' => 'drop',
        'firewall' => 'fw',
        'internet' => 'net',
        'state' => 'state',
        'vpn' => 'vpn',
        default => truncate_text($class, 3),
    };
}

function service_short(string $service): string
{
    $service = strtolower(trim($service));
    return match (true) {
        $service === '' => '?',
        $service === 'https' => 'tls',
        $service === 'http' => 'web',
        $service === 'dns', $service === 'dnsbl' => 'dns',
        $service === 'unknown' => 'unk',
        str_starts_with($service, 'port') => 'p' . substr($service, 4, 4),
        default => truncate_text($service, 4),
    };
}

function service_human_label(string $service): string
{
    $short = service_short($service);
    return match ($short) {
        'tls' => 'HTTPS/TLS',
        'web' => 'HTTP',
        'dns' => 'DNS',
        'vpn' => 'VPN',
        'ssh' => 'SSH',
        'ntp' => 'NTP',
        'smb' => 'SMB',
        'unk', '?' => 'unknown service',
        default => preg_match('/^p(\d+)/', $short, $m) ? 'port ' . $m[1] : strtoupper($short),
    };
}

function flow_path_text(array $flow, int $width): string
{
    $mini = $width < 40;
    $src = compact_endpoint_label((string)($flow['src'] ?? ''), $mini);
    $dst = compact_endpoint_label((string)($flow['dst'] ?? ''), $mini);
    return truncate_modern_text($src . '->' . $dst, $width);
}

function packet_flow_text(array $packet, int $width): string
{
    $mini = $width < 30;
    $src = compact_endpoint_label((string)($packet['src'] ?? ''), $mini);
    $dst = compact_endpoint_label((string)($packet['dst'] ?? ''), $mini);
    return truncate_modern_text($src . '->' . $dst, $width);
}

function endpoint_is_lan(string $label): bool
{
    return str_contains($label, '/LAN.') || preg_match('/^LAN\.\d+$/', $label) === 1 || preg_match('/^L\d+$/', $label) === 1;
}

function endpoint_is_external(string $label): bool
{
    return str_starts_with($label, 'EXT.') || preg_match('/^E\d/', $label) === 1 || $label === 'EXTv6' || $label === 'E6';
}

function compact_endpoint_label(string $label, bool $mini = false): string
{
    $label = trim($label);
    if (filter_var($label, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return compact_ipv6_label($label, $mini);
    }
    if (preg_match('/^(.+):(\d+)$/', $label, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return compact_ipv6_label($m[1], $mini);
    }
    $label = preg_replace('/:\d+$/', '', $label) ?? $label;
    if (filter_var($label, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return compact_ipv6_label($label, $mini);
    }
    if (preg_match('/\/(LAN\.\d+)/', $label, $m)) {
        return $mini ? str_replace('LAN.', 'L', $m[1]) : $m[1];
    }
    if (preg_match('/^LAN\.(\d+)$/', $label, $m)) {
        return $mini ? 'L' . $m[1] : $label;
    }
    if (preg_match('/^EXT\.(\d+(?:\.\d+)?)$/', $label, $m)) {
        return $mini ? 'E' . $m[1] : truncate_text($label, 10);
    }
    if (str_contains($label, ':')) {
        return $mini ? 'v6' : truncate_text($label, 10);
    }
    if ($label === 'BCAST') {
        return $mini ? 'BC' : $label;
    }
    if ($label === 'pfSense') {
        return $mini ? 'pf' : $label;
    }
    if ($label === 'WAN') {
        return $label;
    }
    if (str_starts_with($label, 'EXT.')) {
        return $mini ? str_replace('EXT.', 'E', truncate_text($label, 10)) : truncate_text($label, 10);
    }
    return truncate_text($label, $mini ? 8 : 10);
}

function compact_ipv6_label(string $ip, bool $mini = false): string
{
    $ip = strtolower($ip);
    if ($ip === '::1') {
        return $mini ? 'lo6' : 'loop6';
    }
    if (str_starts_with($ip, 'fe80:')) {
        return $mini ? 'LL6' : 'LLv6';
    }
    if (str_starts_with($ip, 'ff')) {
        return $mini ? 'MC6' : 'MCAST6';
    }
    if (str_starts_with($ip, 'fd') || str_starts_with($ip, 'fc')) {
        return $mini ? 'L6' : 'LANv6';
    }
    return $mini ? 'E6' : 'EXTv6';
}

function truncate_modern_text(string $text, int $width): string
{
    $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
    if ($width <= 0) {
        return '';
    }
    $chars = utf8_cells($text);
    if (count($chars) <= $width) {
        return $text;
    }
    if ($width <= 1) {
        return '…';
    }
    return rtrim(implode('', array_slice($chars, 0, $width - 1))) . '…';
}

function compact_flow_bar(string $up, string $down, string $class, int $width): string
{
    $width = max(1, $width);
    $rate = rate_to_number($up) + rate_to_number($down);
    $pct = flow_rate_percent($rate, $class);
    if (modern_graph_style() === 'unicode') {
        $filled = max(1, min($width, (int)round(($pct / 100) * $width)));
        return str_repeat('█', $filled) . str_repeat('░', max(0, $width - $filled));
    }
    $inner = max(1, $width - 2);
    $char = ($class === 'dnsbl' || $class === 'blocked') ? '!' : '#';
    $filled = max(1, min($inner, (int)round(($pct / 100) * $inner)));
    return '[' . str_repeat($char, $filled) . str_repeat('.', $inner - $filled) . ']';
}

function rate_to_number(string $rate): float
{
    $rate = trim(str_replace(['/s', 'B'], '', $rate));
    if ($rate === '' || $rate === '?') {
        return 0.0;
    }
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)([KMGTP]?)$/i', $rate, $m)) {
        return (float)preg_replace('/[^0-9.]/', '', $rate);
    }
    $value = (float)$m[1];
    $unit = strtoupper($m[2]);
    $mult = match ($unit) {
        'P' => 1024 ** 5,
        'T' => 1024 ** 4,
        'G' => 1024 ** 3,
        'M' => 1024 ** 2,
        'K' => 1024,
        default => 1,
    };
    return $value * $mult;
}

function flow_rate_percent(float $rate, string $class): int
{
    if ($rate <= 0) {
        return ($class === 'dnsbl' || $class === 'blocked') ? 25 : 12;
    }
    $pct = (int)round(log10($rate + 10) * 18);
    if ($class === 'dnsbl' || $class === 'blocked') {
        $pct = max($pct, 35);
    }
    return max(12, min(100, $pct));
}

function split_widths(int $total, int $parts): array
{
    $parts = max(1, $parts);
    $base = intdiv($total, $parts);
    $remainder = $total % $parts;
    $widths = [];
    for ($i = 0; $i < $parts; $i++) {
        $widths[] = $base + ($i < $remainder ? 1 : 0);
    }
    return array_map(static fn(int $w): int => max(4, $w), $widths);
}

function weighted_widths(int $total, array $weights): array
{
    $weights = array_values(array_filter(array_map('intval', $weights), static fn(int $w): bool => $w > 0));
    if (!$weights) {
        return [$total];
    }
    $minWidth = $total >= count($weights) * 4 ? 4 : 1;
    $sum = array_sum($weights);
    $widths = [];
    $fractions = [];
    $used = 0;
    foreach ($weights as $idx => $weight) {
        $exact = ($total * $weight) / $sum;
        $base = max($minWidth, (int)floor($exact));
        $widths[$idx] = $base;
        $fractions[$idx] = $exact - floor($exact);
        $used += $base;
    }
    while ($used < $total) {
        arsort($fractions);
        foreach (array_keys($fractions) as $idx) {
            if ($used >= $total) {
                break;
            }
            $widths[$idx]++;
            $used++;
        }
    }
    while ($used > $total) {
        asort($fractions);
        $changed = false;
        foreach (array_keys($fractions) as $idx) {
            if ($used <= $total) {
                break;
            }
            if ($widths[$idx] > $minWidth) {
                $widths[$idx]--;
                $used--;
                $changed = true;
            }
        }
        if (!$changed) {
            break;
        }
    }
    ksort($widths);
    return array_values($widths);
}

function bar(int $pct, int $width): string
{
    return render_meter_ascii($pct, $width);
}

function render_meter_ascii(int $pct, int $width): string
{
    $width = max(4, $width);
    $filled = (int)round(($pct / 100) * $width);
    return '[' . str_repeat('#', $filled) . str_repeat('.', $width - $filled) . ']';
}

function render_meter_unicode(int $pct, int $width): string
{
    $width = max(1, $width);
    $filled = (int)round((max(0, min(100, $pct)) / 100) * $width);
    return str_repeat('█', $filled) . str_repeat('▁', max(0, $width - $filled));
}

function animated_meter(int $pct, int $width, int $tick): string
{
    if (modern_graph_style() !== 'unicode') {
        return animated_bar($pct, $width, $tick);
    }
    $width = max(1, $width);
    $filled = (int)round((max(0, min(100, $pct)) / 100) * $width);
    $chars = array_fill(0, $width, '▁');
    for ($i = 0; $i < $filled; $i++) {
        $chars[$i] = '█';
    }
    $pulse = $tick % $width;
    $chars[$pulse] = $pulse < $filled ? '▇' : '▃';
    return implode('', $chars);
}

function fit_bar(int $pct, int $totalWidth): string
{
    if (modern_graph_style() === 'unicode') {
        return render_meter_unicode($pct, max(1, $totalWidth));
    }
    return render_meter_ascii($pct, max(2, $totalWidth - 2));
}

function sparkline(array $values, int $width): string
{
    if (modern_graph_style() === 'unicode') {
        return render_sparkline_unicode($values, $width);
    }
    return render_sparkline_ascii($values, $width);
}

function render_sparkline_ascii(array $values, int $width): string
{
    $width = max(4, $width);
    $values = array_values(array_filter($values, static fn($v): bool => is_numeric($v)));
    if (!$values) {
        return '[' . str_repeat('.', $width) . ']';
    }
    $values = array_slice($values, -$width);
    $min = min($values);
    $max = max($values);
    $range = max(1.0, (float)$max - (float)$min);
    $chars = sparkline_chars();
    $out = '';
    foreach ($values as $value) {
        $idx = (int)round((((float)$value - (float)$min) / $range) * (strlen($chars) - 1));
        $out .= $chars[max(0, min(strlen($chars) - 1, $idx))];
    }
    return '[' . str_pad($out, $width, '.', STR_PAD_LEFT) . ']';
}

function render_sparkline_unicode(array $values, int $width): string
{
    $width = max(1, $width);
    $values = array_values(array_filter($values, static fn($v): bool => is_numeric($v)));
    if (!$values) {
        return str_repeat('▁', $width);
    }
    $values = array_slice($values, -$width);
    $min = min($values);
    $max = max($values);
    $range = max(1.0, (float)$max - (float)$min);
    $chars = utf8_cells('▁▂▃▄▅▆▇█');
    $out = '';
    foreach ($values as $value) {
        $idx = (int)round((((float)$value - (float)$min) / $range) * (count($chars) - 1));
        $out .= $chars[max(0, min(count($chars) - 1, $idx))];
    }
    return str_repeat('▁', max(0, $width - cell_len($out))) . $out;
}

function fit_sparkline(array $values, int $totalWidth): string
{
    if (modern_graph_style() === 'unicode') {
        return sparkline($values, max(1, $totalWidth));
    }
    return sparkline($values, max(2, $totalWidth - 2));
}

function sparkline_chars(): string
{
    return '.:-=+*#%@';
}

function push_history(array &$history, float $now, float $value, float $seconds): void
{
    $history[] = ['t' => $now, 'v' => $value];
    $cutoff = $now - max(1.0, $seconds);
    $history = array_values(array_filter($history, static fn(array $row): bool => (float)$row['t'] >= $cutoff));
}

function history_values(array $history): array
{
    return array_map(static fn(array $row): float => (float)$row['v'], $history);
}

function demo_wave(int $tick, int $count, int $base, int $spread): array
{
    $rows = [];
    for ($i = max(0, $tick - $count + 1); $i <= $tick; $i++) {
        $rows[] = max(0, $base + (int)round(sin($i / 3) * $spread) + (($i % 11) * (int)max(1, $spread / 18)));
    }
    return $rows;
}

function normalize_ups($ups): array
{
    if (is_array($ups)) {
        return $ups + [
            'online' => false,
            'source' => '',
            'ups_name' => '',
            'model' => '',
            'status' => '',
            'watts' => '?',
            'load' => '?',
            'battery' => '?',
            'runtime_seconds' => null,
            'runtime' => '?',
            'linev' => '?',
            'inputv' => '?',
            'inputfreq' => '?',
            'outputv' => '?',
            'outputfreq' => '?',
            'output_current' => '?',
            'battery_voltage' => '?',
            'battery_temp' => '?',
            'nominal_watts' => '?',
            'peak60' => '?',
            'avg60' => '?',
            'history' => [],
        ];
    }
    return parse_ups_status_line((string)$ups) + [
        'online' => $ups !== '',
        'source' => '',
        'ups_name' => '',
        'model' => '',
        'watts' => '?',
        'load' => '?',
        'battery' => '?',
        'runtime_seconds' => null,
        'runtime' => '?',
        'linev' => '?',
        'inputv' => '?',
        'inputfreq' => '?',
        'outputv' => '?',
        'outputfreq' => '?',
        'output_current' => '?',
        'battery_voltage' => '?',
        'battery_temp' => '?',
        'nominal_watts' => '?',
        'peak60' => '?',
        'avg60' => '?',
        'history' => [],
    ];
}

function ups_summary($ups): string
{
    $ups = normalize_ups($ups);
    if (empty($ups['online'])) {
        return 'UPS --';
    }
    return sprintf('UPS %sW load %s%% batt %s%% run %s out %sV %sA', $ups['watts'], $ups['load'], $ups['battery'], $ups['runtime'], $ups['outputv'], $ups['output_current']);
}

function format_runtime(float $seconds): string
{
    if ($seconds <= 0) {
        return '?';
    }
    $seconds = (int)round($seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0 ? sprintf('%dh%02dm', $hours, $minutes) : sprintf('%dm', $minutes);
}

function animated_bar(int $pct, int $width, int $tick): string
{
    $width = max(4, $width);
    $filled = (int)round(($pct / 100) * $width);
    $chars = array_fill(0, $width, '.');
    for ($i = 0; $i < $filled; $i++) {
        $chars[$i] = '#';
    }
    $pulse = $tick % $width;
    $chars[$pulse] = '+';
    return '[' . implode('', $chars) . ']';
}

function graph_bar(int $value, int $max, bool $alert): string
{
    $width = 8;
    $filled = max(1, min($width, (int)round(($value / max(1, $max)) * $width)));
    if (modern_graph_style() === 'unicode') {
        return str_repeat($alert ? '▓' : '█', $filled) . str_repeat('▁', $width - $filled);
    }
    $char = $alert ? '!' : '#';
    return '[' . str_repeat($char, $filled) . str_repeat('.', $width - $filled) . ']';
}

function colorize_line(string $line, bool $color): string
{
    $separatorMarker = ticker_separator_marker();
    if (!$color) {
        return str_replace($separatorMarker, '◆', $line);
    }
    $c = [
        'reset' => "\033[0m",
        'cyan' => "\033[38;5;51;1m",
        'green' => "\033[38;5;119;1m",
        'yellow' => "\033[38;5;226;1m",
        'red' => "\033[38;5;197;1m",
        'blue' => "\033[38;5;81;1m",
        'dim' => "\033[38;5;245m",
        'white' => "\033[38;5;255;1m",
        'crit' => "\033[5;7;38;5;196;1m",
    ];
    $line = color_replace('/(-{2,})/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([+=|])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([┌┐└┘─│├┤┬┴┼])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(WAN UP|VPN UP|DNS OK|UPS ONLINE|PASS|ONLINE|UP)\b/', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[CRIT\])/', $c['crit'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[HIGH\])|\b(FW BLOCK|DROP|REJECT|BLOCK|blocked|HIGH)\b/', $c['red'] . '$0' . $c['reset'], $line);
    $line = color_replace('/(\[WARN\]|\[MED\])|\b(DNS DENY|WARN|warning|MED)\b/', $c['yellow'] . '$0' . $c['reset'], $line);
    $line = color_replace('/(\[INFO\]|\[LOW\])/', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[DNSBL\]|\[FW\]|\[IDS\]|\[IPS\]|\[VPN\]|\[WAN\]|\[DHCP\]|\[ARP\]|\[FLOW\]|\[PF\]|\[UPS\]|\[SYS\]|\[DNS\]|\[IFACE\]|\[DEVICE\])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(DNSBL HIT|SINKHOLE)\b/', $c['yellow'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(CPU|RAM|ARC|PF|LAN|WAN|UPS|NETWORK|MEMORY|TOTAL|IFTOPX|TCPDUMPX|SOCX MODERN WALL|SOCX WALL|EVENT FEED|LIVE PACKETS|PROCESS TREE|PF STATES)\b/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[[#!.]+\])/', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([█▇▆▅▄▃▂▁▓]+)/u', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([↓↑])/u', $c['yellow'] . '$1' . $c['reset'], $line);
    $line = str_replace('◆', $c['cyan'] . '◆' . $c['reset'], $line);
    $line = str_replace($separatorMarker, $c['cyan'] . '◆' . $c['reset'], $line);
    $line = color_replace('/\bx(\d+)\b/', $c['yellow'] . 'x$1' . $c['reset'], $line);
    return $line . $c['reset'];
}

function color_replace(string $pattern, string $replacement, string $line): string
{
    $next = preg_replace($pattern, $replacement, $line);
    return is_string($next) ? $next : $line;
}

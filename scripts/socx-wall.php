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
$density = wall_density($opts);
putenv('SOCX_THEME=' . $theme);
putenv('SOCX_EVENT_FEED_MODE=' . $tickerConfig['mode']);
putenv('SOCX_DENSITY=' . $density);
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
    'speedtest_history' => [],
    'speedtest_last_updated' => '',
    'ups_history' => [],
    'ups_cache' => [],
    'vpn_status_cache' => null,
    'trend_prev' => [],
    'new_hosts_seen' => [],
    'last_identity_event_at' => 0.0,
    'debug_timing' => getenv('SOCX_DEBUG_TIMING') === 'true',
    'tmux_mode' => tmux_detected(),
    'term' => getenv('TERM') ?: '',
    'theme' => $theme,
    'density' => $density,
    'last_debug_at' => 0.0,
    'last_frame' => null,
    'next_frame_at' => 0.0,
    'cols' => 0,
    'rows' => 0,
    'next_size_at' => 0.0,
    'force_clear' => true,
];

if (!$once) {
    register_shutdown_function(static function (): void {
        echo "\033[?7h\033[?25h\033[0m\033[?1049l";
    });
    echo "\033[?1049h\033[?25l\033[?7l\033[H\033[2J\033[?7h";
}

do {
    $now = microtime(true);
    $loopStart = $now;
    $fullRedraw = false;
    try {
        $nightActive = terminal_night_active();
        if (!array_key_exists('night_active', $state) || $state['night_active'] !== $nightActive) {
            $tickerConfig = ticker_config($opts);
            $state['ticker_config'] = $tickerConfig;
            $state['night_active'] = $nightActive;
            $state['ticker_changed'] = true;
            $fullRedraw = true;
        }
        if ((int)$state['cols'] <= 0 || $now >= (float)$state['next_size_at']) {
            $oldCols = (int)$state['cols'];
            $oldRows = (int)$state['rows'];
            [$state['cols'], $state['rows']] = term_size($opts);
            $state['next_size_at'] = $now + 1.0;
            $sizeChanged = $oldCols !== (int)$state['cols'] || $oldRows !== (int)$state['rows'];
            $fullRedraw = $fullRedraw || $sizeChanged;
            $state['force_clear'] = !empty($state['force_clear']) || $sizeChanged;
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
        echo terminal_synchronized_write(terminal_atomic_write(terminal_full_redraw($screen, !empty($state['force_clear']))));
        $renderMs = (microtime(true) - $renderStart) * 1000;
        $state['force_clear'] = false;
        $state['ticker_changed'] = false;
    } else {
        $renderStart = microtime(true);
        if (($tickerConfig['mode'] ?? 'scroll') === 'scroll' || !empty($state['ticker_changed'])) {
            echo terminal_synchronized_write(terminal_atomic_write(render_ticker_update($frame, $cols, $rows, $color, $theme)));
            $state['ticker_changed'] = false;
        }
        $renderMs = (microtime(true) - $renderStart) * 1000;
    }
    maybe_log_timing($state, $cols, $rows, $theme, $tickerConfig, $fullRedraw, $renderMs, (microtime(true) - $loopStart) * 1000, $frame);
    flush();
    fflush(STDOUT);
    usleep((int)($renderInterval * 1000000));
} while (true);

function terminal_atomic_write(string $payload): string
{
    if ($payload === '') {
        return '';
    }
    return "\033[?7l" . $payload . "\033[?7h";
}

function terminal_synchronized_write(string $payload): string
{
    if ($payload === '') {
        return '';
    }

    /*
     * Modern tmux/xterm terminals can hold screen writes until the matching
     * end marker. This prevents an occasional half-painted frame from being
     * visible on the physical SOCX monitor. Older terminals simply ignore
     * these private mode markers and retain the existing atomic-write path.
     */
    if (getenv('SOCX_DISABLE_SYNC_OUTPUT') === 'true') {
        return $payload;
    }
    return "\033[?2026h" . $payload . "\033[?2026l";
}

function terminal_full_redraw(string $screen, bool $clear): string
{
    $prefix = $clear ? "\033[H\033[J" : "\033[H";
    return $prefix . $screen;
}

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
        putenv('SOCX_EVENT_ROTATE_SECONDS=1');
    }
    if (getenv('SOCX_EVENT_HIGH_SECONDS') === false) {
        putenv('SOCX_EVENT_HIGH_SECONDS=2');
    }
    if (getenv('SOCX_EVENT_CRIT_SECONDS') === false) {
        putenv('SOCX_EVENT_CRIT_SECONDS=3');
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
    $vpn = is_array($frame['vpn_status'] ?? null) ? $frame['vpn_status'] : [];
    $vpnAge = isset($vpn['age_seconds']) && is_numeric($vpn['age_seconds']) ? sprintf('%.2fs', (float)$vpn['age_seconds']) : '?';
    $line = sprintf(
        "[%s] size=%dx%d tmux=%s term=%s theme=%s redraw=%s ticker=%dms/%dcol render=%.2fms loop=%.2fms ups_age=%s vpn=%s vpn_age=%s vpn_fresh=%s\n",
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
        $age,
        (string)($vpn['status'] ?? '?'),
        $vpnAge,
        !empty($vpn['data_fresh']) ? 'yes' : 'no'
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
        'density' => '',
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
        } elseif ($arg === '--density' && isset($argv[$i + 1])) {
            $opts['density'] = (string)$argv[++$i];
        } elseif (str_starts_with($arg, '--density=')) {
            $opts['density'] = (string)substr($arg, 10);
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
    echo "       [--density compact|normal|large]\n";
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

    $nightActive = terminal_night_active();
    $speed = strtolower((string)($opts['ticker_speed'] ?: getenv('SOCX_TICKER_SPEED') ?: 'fast'));
    if ($nightActive && empty($opts['ticker_speed'])) {
        $speed = strtolower((string)(getenv('SOCX_NIGHT_TICKER_SPEED') ?: 'slow'));
    }
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
    if ($nightActive && (int)$opts['ticker_interval_ms'] <= 0) {
        $intervalMs = max($intervalMs, 150);
    }
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

    if ($nightActive) {
        $rotateSeconds = max($rotateSeconds, 5);
        $highSeconds = max($highSeconds, 8);
        $critSeconds = max($critSeconds, 10);
    }

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

function terminal_night_active(): bool
{
    $mode = strtolower(trim((string)(getenv('SOCX_NIGHT_MODE') ?: 'auto')));
    if (in_array($mode, ['on', 'true', '1', 'yes'], true)) {
        return true;
    }
    if (in_array($mode, ['off', 'false', '0', 'no'], true)) {
        return false;
    }
    $hour = (int)date('G');
    return $hour >= 20 || $hour < 8;
}

function wall_theme(array $opts): string
{
    $theme = strtolower((string)($opts['theme'] ?: getenv('SOCX_THEME') ?: 'modern-btop'));
    return in_array($theme, ['modern-btop', 'classic'], true) ? $theme : 'modern-btop';
}

function wall_density(array $opts = []): string
{
    $density = strtolower((string)($opts['density'] ?? ''));
    if ($density === '') {
        $density = strtolower((string)(getenv('SOCX_DENSITY') ?: 'normal'));
    }
    return in_array($density, ['compact', 'normal', 'large'], true) ? $density : 'normal';
}

function modern_density(): string
{
    return wall_density();
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
    static $cached = null;
    static $cachedAt = 0.0;
    $now = microtime(true);
    if (is_array($cached) && ($now - $cachedAt) < 30.0) {
        return $cached;
    }
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
    foreach (dhcp_lease_host_map() as $ip => $name) {
        if (!isset($map[$ip])) {
            $map[$ip] = $name;
        }
    }
    foreach (dynamic_host_overlays() as $ip => $name) {
        if (env_bool('SOCX_DYNAMIC_HOST_OVERRIDES', true) || !isset($map[$ip]) || preg_match('/^Device-\d+$/', (string)$map[$ip])) {
            $map[$ip] = $name;
        }
    }
    $cached = $map;
    $cachedAt = $now;
    return $map;
}

function dynamic_host_overlays(): array
{
    $map = [];
    $pi = collect_pi_discovery_insight(microtime(true));
    if (!empty($pi['available']) && !empty($pi['ip']) && filter_var((string)$pi['ip'], FILTER_VALIDATE_IP)) {
        $map[(string)$pi['ip']] = 'Pi5-AI-Node';
    }
    $upsHost = trim((string)(getenv('SOCX_UPS_SNMP_HOST') ?: ''));
    if ($upsHost !== '' && filter_var($upsHost, FILTER_VALIDATE_IP)) {
        $map[$upsHost] = 'APC-SmartUPS';
    }
    foreach (ai_lab_endpoint_checks() as $check) {
        $host = (string)($check['host'] ?? '');
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            continue;
        }
        $label = preg_replace('/[^\w.\-]/', '', (string)($check['label'] ?? 'AI-Lab')) ?: 'AI-Lab';
        $map[$host] = $label;
    }
    return $map;
}

function dhcp_lease_host_map(): array
{
    $files = [
        '/var/dhcpd/var/db/dhcpd.leases',
        '/var/dhcpd/var/db/dhcpd6.leases',
        '/var/db/dhcpd.leases',
    ];
    $map = [];
    foreach ($files as $file) {
        if (!is_readable($file)) {
            continue;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        if (preg_match_all('/lease\s+(\d{1,3}(?:\.\d{1,3}){3})\s+\{(.*?)\n\}/s', $raw, $leases, PREG_SET_ORDER)) {
            foreach ($leases as $lease) {
                $ip = $lease[1];
                $body = $lease[2];
                if (!preg_match('/client-hostname\s+"([^"]+)"/', $body, $hm)
                    && !preg_match('/set\s+hostname\s+=\s+"([^"]+)"/', $body, $hm)) {
                    continue;
                }
                $name = preg_replace('/[^\w.\-]/', '', (string)$hm[1]) ?: '';
                if ($name !== '') {
                    $map[$ip] = $name;
                }
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
    $packetEvents = $events;

    $state['time'] = $now;
    $iflan = getenv('SOCX_IFLAN') ?: 'ix0';
    $ifwan = getenv('SOCX_IFWAN') ?: 'ix1';
    $wan = $net[$ifwan] ?? first_net($net);
    $lan = $net[$iflan] ?? first_net($net);
    $ups = collect_ups_metrics($state, $now);
    $vpnStatus = collect_vpn_status($state, $now);
    $speedtest = collect_speedtest_metrics($state, $now);
    $wanQuality = collect_wan_quality($state, $now);
    $mirandaInsight = collect_miranda_insight($now);
    $flows = collect_pf_state_flows($state, $hosts, $now);
    if (!$flows) {
        $flows = flows_from_events($events);
    }
    update_speedtest_history($state, $speedtest, $now);
    update_metric_histories($state, $now, $wan, $lan, $pf);
    $commandEvents = collect_soc_command_events($state, $hosts, $now, [
        'cpu' => $cpu,
        'mem' => $mem,
        'load' => $load,
        'pf' => $pf,
        'wan' => $wan,
        'lan' => $lan,
        'ups' => $ups,
        'vpn_status' => $vpnStatus,
        'speedtest' => $speedtest,
        'wan_quality' => $wanQuality,
        'procs' => $procs,
        'flows' => $flows,
        'ifwan' => $ifwan,
        'iflan' => $iflan,
    ]);
    $state['command_events_fresh'] = !empty($commandEvents);
    $events = array_merge($events, $commandEvents);
    $vpnEvent = vpn_status_event($vpnStatus);
    if ($vpnEvent !== null) {
        $events[] = $vpnEvent;
    }
    $speedtestEvent = speedtest_status_event($speedtest);
    if ($speedtestEvent !== null) {
        $events[] = $speedtestEvent;
    }
    $vpnPathEvent = speedtest_named_paths_event($speedtest);
    if ($vpnPathEvent !== null) {
        $events[] = $vpnPathEvent;
    }
    if (!empty($mirandaInsight['available'])) {
        $events[] = miranda_insight_event($mirandaInsight);
    }
    $autopilotEvent = autopilot_status_event($now);
    if ($autopilotEvent !== null) {
        $events[] = $autopilotEvent;
    }
    $incidentSummary = collect_incident_mode_summary($state, $now);
    $incidentEvent = incident_mode_wall_event($incidentSummary);
    if ($incidentEvent !== null) {
        $events[] = $incidentEvent;
    }
    $events = balance_events_for_feed(prioritize_events($events), 80);
    $topFlow = top_network_flow($flows);
    $activityEvents = collect_activity_summary_events($state, $events, $flows, $ups, $wan, $lan, $pf, $now);
    if ($activityEvents) {
        $events = balance_events_for_feed(prioritize_events(array_merge($events, $activityEvents)), 80);
    }
    $watchEvents = watchlist_events($flows, $events);
    if ($watchEvents) {
        $events = balance_events_for_feed(prioritize_events(array_merge($events, $watchEvents)), 80);
    }
    $packets = packet_radar_merge(packet_radar_from_cache(), packets_from_events($packetEvents ?: $events));
    $pulse = threat_pulse_from_events($events, $packets, $hosts, $state);
    $wanHealth = wan_health_score($wan, $vpnStatus, $speedtest, $pulse, $wanQuality);
    $wanTraffic = (float)(($wan['rx'] ?? 0) + ($wan['tx'] ?? 0));
    $trends = update_metric_trends($state, [
        'cpu' => (float)$cpu['used'],
        'memory' => (float)$mem['used_pct'],
        'packet_drops' => (float)($pulse['fw_drops_min'] ?? 0),
        'state_count' => (float)pf_states_number($pf),
        'search_rate' => pf_search_rate_number($pf),
        'dnsbl_hits' => (float)($pulse['dnsbl_min'] ?? 0),
        'wan_traffic' => $wanTraffic,
    ]);
    $scanSummary = wan_scan_summary_event($packetEvents ?: $events);
    if ($scanSummary !== null) {
        $events[] = $scanSummary;
    }
    $events[] = wan_health_event($wanHealth);
    $qualityEvent = wan_quality_event($wanQuality);
    if ($qualityEvent !== null) {
        $events[] = $qualityEvent;
    }
    $speedHistoryEvent = speedtest_history_event(speedtest_history_summary($state['speedtest_history'] ?? [], $now));
    if ($speedHistoryEvent !== null) {
        $events[] = $speedHistoryEvent;
    }
    foreach (collect_what_changed_events($state, $flows, $wanQuality, $speedtest, collect_pi_llm_insight($now), $now) as $event) {
        $events[] = $event;
    }
    $events[] = threat_pulse_event($pulse, $trends);
    $socxHealth = socx_health_score($wanHealth, $vpnStatus, $ups, $mem, $cpu, $speedtest, $pulse);
    $events[] = socx_health_score_event($socxHealth);
    foreach (ai_soc_enrichment_events($events, $packets, $pulse) as $event) {
        $events[] = $event;
    }
    $events[] = identity_footer_event($ups);
    $events = balance_events_for_feed(prioritize_events($events), 80);

    return [
        'time' => date('H:i:s'),
        'refresh' => '500ms',
        'host' => $state['static']['host'],
        'badges' => health_badges($ups, [
            'wan_latency_ms' => gateway_latency_ms(),
            'vpn_status' => $vpnStatus,
            'dns_latency_ms' => env_latency_ms('SOCX_DNS_LATENCY_MS'),
        ]),
        'wan' => ['name' => $ifwan, 'down' => rate_text($wan['rx'] ?? null), 'up' => rate_text($wan['tx'] ?? null), 'rx_bps' => (int)($wan['rx'] ?? 0), 'tx_bps' => (int)($wan['tx'] ?? 0), 'link' => 'DHCP OK', 'rtt' => 'RTT --', 'loss' => 'LOSS --'],
        'lan' => ['name' => $iflan, 'down' => rate_text($lan['rx'] ?? null), 'up' => rate_text($lan['tx'] ?? null), 'rx_bps' => (int)($lan['rx'] ?? 0), 'tx_bps' => (int)($lan['tx'] ?? 0)],
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
        'vpn_status' => $vpnStatus,
        'speedtest' => $speedtest,
        'miranda_insight' => $mirandaInsight,
        'procs' => $procs,
        'flows' => $flows,
        'top_flow' => $topFlow,
        'ai_lab' => is_array($state['ai_lab_status'] ?? null) ? $state['ai_lab_status'] : [],
        'socx_health' => $socxHealth,
        'incident_summary' => $incidentSummary,
        'wan_health' => $wanHealth,
        'speedtest_history' => speedtest_history_summary($state['speedtest_history'] ?? [], $now),
        'packets' => $packets,
        'trends' => $trends,
        'threat_pulse' => $pulse,
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
        'badges' => ['WAN UP 2.3ms', 'VPN PARTIAL 1/3', 'DNS OK 7ms', 'UPS 100% 40m', 'DATA LIVE'],
        'wan' => ['name' => 'ix1', 'down' => '3.50K/s', 'up' => '9.13K/s', 'link' => '2.5G DHCP OK', 'rtt' => 'RTT 9ms', 'loss' => 'LOSS 0%'],
        'lan' => ['name' => 'ix0', 'down' => '4.50K/s', 'up' => '7.79K/s'],
        'wan_history' => demo_wave($tick, 28, 12, 4),
        'lan_history' => demo_wave($tick + 5, 28, 18, 6),
        'pf_history' => demo_wave($tick + 11, 28, 1600, 300),
        'pf' => ['states' => '1264', 'searches_rate' => '9579/s', 'passed' => 405900000, 'blocked' => 137800],
        'mem' => ['total' => parse_size('31.7G'), 'used' => parse_size('24.6G'), 'free' => parse_size('7.1G'), 'arc_total' => parse_size('17.0G'), 'used_pct' => 78, 'swap_total' => parse_size('4G'), 'swap_used' => 0, 'swap_free' => parse_size('4G'), 'swap_used_pct' => 0],
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
            'nominal_watts' => '1950',
            'env_label1' => 'Port 1 Temp 1',
            'env_label2' => 'Port 2 Temp 2',
            'env_temp1' => '25',
            'env_temp2' => '25',
            'env_humidity1' => '',
            'env_humidity2' => '40',
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
            ['time' => '16:42:18', 'proto' => 'FW', 'dir' => 'IN', 'src' => 'WAN', 'dst' => 'pfSense', 'service' => 'scan', 'size' => 'ctrl', 'verdict' => 'DROP', 'category' => 'FW', 'severity' => 'HIGH', 'context' => ['scanner', 'abuse:high'], 'story' => '42 WAN scans stopped in 60s | ports: 23, 25, 8080'],
            ['time' => '16:42:18', 'proto' => 'DNS', 'dir' => 'OUT', 'src' => host_label('192.168.1.161', $hosts), 'dst' => 'beacons.gvt2.com', 'service' => 'dnsbl', 'size' => '0B', 'verdict' => 'DNSBL HIT', 'category' => 'DNSBL', 'context' => ['dnsbl', 'known-bad']],
            ['time' => '16:42:18', 'proto' => 'UDP', 'dir' => 'OUT', 'src' => 'LAN.127', 'dst' => '147.185.133.70:137', 'service' => 'smb', 'sport' => '', 'dport' => '137', 'size' => '0B', 'verdict' => 'DROP', 'category' => 'FW', 'context' => ['scanner']],
            ['time' => '16:42:19', 'proto' => 'DNS', 'dir' => 'OUT', 'src' => host_label('192.168.1.161', $hosts), 'dst' => 'discord.com', 'service' => 'dnsbl', 'size' => '0B', 'verdict' => 'DNSBL HIT', 'category' => 'DNSBL', 'context' => ['dnsbl', 'known-bad']],
            ['time' => '16:42:20', 'proto' => 'TCP', 'dir' => 'OUT', 'src' => 'LAN.180', 'dst' => 'api.anthropic.com', 'service' => 'https', 'size' => '812B', 'verdict' => 'PASS'],
            ['time' => '16:42:21', 'proto' => 'TCP', 'dir' => 'IN', 'src' => 'EXT.233.95', 'dst' => 'WAN:1269', 'service' => 'unknown', 'sport' => '', 'dport' => '1269', 'size' => '0B', 'verdict' => 'DROP', 'category' => 'FW', 'context' => ['scanner', 'abuse:high']],
            ['time' => '16:42:22', 'proto' => 'DNS', 'dir' => 'OUT', 'src' => 'LAN.106', 'dst' => 'grammarly.io', 'service' => 'dnsbl', 'size' => '0B', 'verdict' => 'SINKHOLE', 'category' => 'DNSBL', 'context' => ['dnsbl', 'known-bad']],
        ],
        'trends' => [
            'cpu' => 'up',
            'memory' => 'up',
            'packet_drops' => 'up',
            'state_count' => 'stable',
            'search_rate' => 'up',
            'dnsbl_hits' => 'up',
            'wan_traffic' => 'up',
        ],
        'threat_pulse' => [
            'fw_drops_min' => 42,
            'dnsbl_min' => 11,
            'ids_alerts' => 1,
            'new_hosts' => 1,
        ],
        'vpn_status' => [
            'status' => 'PARTIAL',
            'online' => 1,
            'total' => 3,
            'data_fresh' => true,
            'age_seconds' => 0.4,
            'details' => [
                ['label' => 'NYCVPN', 'status' => 'UP', 'reason' => 'handshake fresh'],
                ['label' => 'RCNVPN', 'status' => 'DOWN', 'reason' => 'missing monitor'],
                ['label' => 'RCNVPN2', 'status' => 'DOWN', 'reason' => 'missing monitor'],
            ],
        ],
        'speedtest' => [
            'status' => 'ok',
            'download_mbps' => '2940',
            'upload_mbps' => '2866',
            'ping_ms' => '9',
            'jitter_ms' => '2',
            'server_name' => 'Frontier',
            'server_location' => 'Secaucus, NJ',
            'mode' => 'frontier',
            'age_seconds' => 720,
            'interval_seconds' => 21600,
            'fresh' => true,
        ],
        'socx_health' => ['score' => 93, 'severity' => 'INFO', 'reason' => 'demo signals nominal'],
        'events' => [
            '[FW][HIGH] 42 WAN scans stopped in 60s | ports: 23, 25, 8080',
            '[IDS][HIGH][92%] ' . host_label('192.168.1.102', $hosts) . ' -> internet possible C2 beacon | inspect host',
            '[WAN][WARN] Frontier gateway packet loss 8% | watch',
            '[FW][MED] ' . host_label('192.168.1.127', $hosts) . ' -> 147.185.133.70:137 drop',
            '[DHCP][WARN] Unknown device LAN.203 joined | verify MAC',
            '[FLOW][WARN] ' . host_label('192.168.1.164', $hosts) . ' unusual DNS burst x84 | inspect',
            '[UPS][INFO] UPS online 552W, load 28%, batt 100%, run 36m, out 118V/5.2A | source snmp',
            '[VPN][WARN] 1/3 VPN gateways online: NYCVPN up, RCNVPN down, RCNVPN2 down',
            '[WAN][INFO] Speedtest Frontier Secaucus, NJ 2940/2866 Mbps ping 9ms | age 12m',
            '[DNS][INFO] Unbound healthy | resolver online',
            '[INTEL][INFO][88%] CVE n/a no exploit observed | CPE cpe:2.3:a:netgate:pfsense:* | CVSS n/a EPSS n/a KEV no',
            '[TTP][WARN][88%] ATT&CK T1046 service discovery | CAPEC-300 port scan | D3FEND D3-NTA/D3-NTF',
            '[DETECT][INFO][88%] Sigma socx_pfsense_scan_burst | YARA socx_log_ioc_context | Suricata sid:9001046 scan-burst',
            '[EVID][INFO][88%] Win EID 5152/5157/4688 | Linux/macOS auth.log+pf logs | memory pf states+sockets',
            '[RESP][INFO][88%] contain block src/quarantine host | preserve logs, pcap, pfctl -ss, config.xml',
            '[SRC][LOW] refs NVD CPE/CVSS, CISA KEV, FIRST EPSS, MITRE ATT&CK/CAPEC/D3FEND, SigmaHQ, YARA, Suricata',
            '[DNSBL][LOW] ' . host_label('192.168.1.161', $hosts) . ' DNS blocked beacons.gvt2.com',
            '[DNSBL][LOW] ' . host_label('192.168.1.161', $hosts) . ' DNS blocked discord.com',
            '[SOCX][INFO] SOCX WALL // JupiterLXI // pfSense // Frontier Fiber // UPS protected // AI-SOC ready',
        ],
    ];
}

function error_frame(Throwable $e): array
{
    $frame = demo_frame([], ['tick' => 0]);
    $frame['badges'] = ['WAN --', 'VPN UNKNOWN', 'DNS --', 'UPS --', 'DATA STALE'];
    $frame['speedtest'] = ['status' => 'unknown', 'fresh' => false];
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

    $cardGap = $cols >= 110 ? (modern_density() === 'large' ? 3 : 2) : ($cols >= 74 ? 1 : 0);
    $cardTitles = ['NETWORK', 'SPEEDTEST', 'CPU', 'MEMORY', 'UPS'];
    $cardWeights = [18, 16, 22, 18, 18];
    if (modern_show_threat_pulse($cols)) {
        $cardTitles[] = 'THREAT PULSE';
        $cardWeights = [18, 15, 19, 16, 17, 13];
    }
    $cardWidths = weighted_widths($cols - ($cardGap * max(0, count($cardTitles) - 1)), $cardWeights);
    $x = 0;
    $cards = [];
    foreach ($cardTitles as $idx => $title) {
        $cards[] = panel_obj($x, $layout['cards_y'], $cardWidths[$idx], $layout['cards_h'], $title, match ($title) {
            'NETWORK' => static fn(array $f, array $p): array => modern_network_rows($f, $p),
            'SPEEDTEST' => static fn(array $f, array $p): array => modern_speedtest_rows($f, $p),
            'CPU' => static fn(array $f, array $p): array => modern_cpu_card_rows($f, $p),
            'MEMORY' => static fn(array $f, array $p): array => modern_memory_rows($f, $p),
            'THREAT PULSE' => static fn(array $f, array $p): array => modern_threat_pulse_rows($f, $p),
            default => static fn(array $f, array $p): array => modern_ups_rows($f, $p),
        }, 'card');
        $x += $cardWidths[$idx] + $cardGap;
    }

    $panels = [
        panel_obj(0, 0, $cols, $layout['header_h'], '', static fn(array $f, array $p): array => modern_header_rows($f, $p), 'header'),
        ...$cards,
        panel_obj(0, $layout['middle_y'], $layout['left_w'], $layout['middle_h'], 'PFTOP LIVE STATES', static fn(array $f, array $p): array => modern_pftop_rows($f, $p), 'table'),
        panel_obj($layout['right_x'], $layout['middle_y'], $layout['right_w'], $layout['middle_h'], 'NETWORK FLOWS / IFTOPX', static fn(array $f, array $p): array => modern_flow_rows($f, $p), 'table'),
        panel_obj(0, $layout['packets_y'], $cols, $layout['packets_h'], 'PACKET RADAR', static fn(array $f, array $p): array => modern_packet_rows($f, $p), 'table'),
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
    $density = modern_density();
    $headerH = $density === 'large' ? 3 : 2;
    if ($density === 'large') {
        $cardsH = $rows <= 26 ? 6 : 7;
        $tickerH = $rows >= 34 ? 4 : 3;
        $packetsTarget = $rows <= 26 ? 5 : ($rows >= 34 ? 8 : 6);
    } elseif ($density === 'compact') {
        $cardsH = $rows <= 26 ? 5 : 6;
        $tickerH = $rows >= 34 ? 3 : ($rows <= 26 ? 2 : 3);
        $packetsTarget = $rows <= 26 ? 7 : ($rows >= 34 ? 12 : 9);
    } else {
        $cardsH = $rows <= 22 ? 5 : 6;
        $tickerH = $rows >= 34 ? 4 : ($rows <= 26 ? 2 : 3);
        $packetsTarget = $rows <= 26 ? 6 : ($rows >= 34 ? 11 : 8);
    }
    $contentH = max(10, $rows - $headerH - $cardsH - $tickerH);
    $packetsH = min(12, max($packetsTarget, intdiv($contentH, 3)));
    $middleH = max(5, $contentH - $packetsH);
    if ($rows <= 26 && $middleH < 9) {
        $middleH = 9;
        $packetsH = max(5, $contentH - $middleH);
    }
    $middleY = $headerH + $cardsH;
    $packetsY = $middleY + $middleH;
    $mid = intdiv($cols, 2);
    $middleGap = $cols >= 74 ? ($density === 'large' ? 2 : 1) : 0;
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

function modern_show_threat_pulse(int $cols): bool
{
    $density = modern_density();
    if ($density === 'large') {
        return $cols >= 210;
    }
    return $cols >= 180;
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

function density_row_limit(int $available): int
{
    $available = max(1, $available);
    if (modern_density() === 'large') {
        return max(1, (int)ceil($available * 0.72));
    }
    return $available;
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
    $density = modern_density();
    if (!empty($f['demo']) && getenv('SOCX_SHOW_DEMO_DEBUG') === 'true') {
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
            modern_header_status_line($f, $w),
            pad_or_clip($debug, $w),
        ];
    }

    $statusLine = modern_header_status_line($f, $w);
    if ($density === 'large') {
        $detailLine = modern_header_detail_line($f, $w);
        return $detailLine !== '' ? [$statusLine, $detailLine] : [$statusLine];
    }

    $rows = [$statusLine];
    if ($w < 126) {
        return $rows;
    }
    $detailLine = modern_header_detail_line($f, $w);
    if ($detailLine !== '') {
        $rows[] = $detailLine;
    }
    return $rows;
}

function modern_header_status_line(array $f, int $w): string
{
    $time = substr((string)($f['time'] ?? date('H:i:s')), 0, 5);
    $wanState = header_status_from_badges((array)($f['badges'] ?? []), 'WAN', 'UP');
    $dnsState = header_status_from_badges((array)($f['badges'] ?? []), 'DNS', 'OK');
    $vpn = header_vpn_texts($f['vpn_status'] ?? []);
    $ups = header_ups_texts($f['ups'] ?? []);
    $speed = header_speedtest_texts($f['speedtest'] ?? []);
    $live = header_data_text($f);
    $uptime = header_uptime_texts($f, $live);
    $night = terminal_night_active() ? 'NIGHT' : '';

    $title = 'SOCX';
    $candidates = [
        [$title, '[' . $time . ']', $uptime['full'], $night, 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['full'], $speed['full']],
        ['[' . $time . ']', $uptime['full'], $night, 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['full'], $speed['full']],
        [$time, $uptime['full'], $night, 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['full'], $speed['full']],
        [$time, $uptime['full'], $night, 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['compact'], $speed['compact']],
        [$time, $uptime['full'], $night, 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['compact'], $speed['tiny']],
        [$time, $uptime['full'], $night, 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], 'DNS:' . $dnsState, $ups['compact'], $speed['tiny']],
        [$time, $uptime['full'], $night, 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], $ups['compact'], $speed['tiny']],
        [$time, $uptime['compact'], 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['full'], $speed['full']],
        [$time, $uptime['compact'], 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['compact'], $speed['full']],
        [$time, $uptime['compact'], 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['compact'], $speed['compact']],
        [$time, $uptime['tiny'], 'WAN:' . $wanState, $vpn['full'], 'DNS:' . $dnsState, $ups['tiny'], $speed['compact']],
        [$time, $uptime['compact'], 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], 'DNS:' . $dnsState, $ups['full'], $speed['full']],
        [$time, $uptime['compact'], 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], 'DNS:' . $dnsState, $ups['full'], $speed['compact']],
        [$time, $uptime['tiny'], 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], 'DNS:' . $dnsState, $ups['compact'], $speed['compact']],
        [$time, $uptime['tiny'], 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], 'DNS:' . $dnsState, $ups['compact'], $speed['tiny']],
        [$time, $uptime['tiny'], 'WAN:' . $wanState, 'VPN:' . $vpn['medium'], $ups['compact'], $speed['tiny']],
        [$time, $uptime['tiny'], 'WAN:' . $wanState, $vpn['compact'], 'DNS:' . $dnsState, $ups['compact'], $speed['tiny']],
        [$time, $uptime['tiny'], 'WAN:' . $wanState, $vpn['tiny'], $ups['tiny'], $speed['tiny']],
    ];

    foreach ($candidates as $fields) {
        $fields = array_values(array_filter($fields, static fn($field): bool => $field !== ''));
        $line = header_join_fit($fields, $w);
        if ($line !== null) {
            return pad_or_clip($line, $w);
        }
    }

    $core = array_values(array_filter([$time, $uptime['tiny'], $night, 'WAN:' . $wanState, $vpn['tiny'], $speed['tiny']], static fn($field): bool => $field !== ''));
    $line = header_join_shortest($core, $w);
    return pad_or_clip($line, $w);
}

function modern_header_detail_line(array $f, int $w): string
{
    $ups = normalize_ups($f['ups'] ?? []);
    $statusParts = [
        sprintf('WAN %s/%s', compact_rate($f['wan']['down']), compact_rate($f['wan']['up'])),
        sprintf('LAN %s/%s', compact_rate($f['lan']['down']), compact_rate($f['lan']['up'])),
        sprintf('PF %s states', $f['pf']['states'] ?? '?'),
    ];
    $speedText = speedtest_status_text($f['speedtest'] ?? [], max(18, $w - 94));
    if ($speedText !== '') {
        $statusParts[] = $speedText;
    }
    $vpnDetails = vpn_header_details($f['vpn_status'] ?? [], max(20, $w - 72));
    if ($vpnDetails !== '') {
        $statusParts[] = $vpnDetails;
    }
    $statusParts[] = sprintf('UPS %sW %s%%', $ups['watts'] ?? '?', $ups['load'] ?? '?');
    $status = implode('   ', $statusParts);
    return pad_or_clip($status, $w);
}

function header_status_from_badges(array $badges, string $prefix, string $fallback): string
{
    foreach ($badges as $badge) {
        $badge = strtoupper((string)$badge);
        if (preg_match('/^' . preg_quote($prefix, '/') . '\s+([A-Z\/]+)/', $badge, $m)) {
            return $m[1];
        }
    }
    return $fallback;
}

function header_vpn_texts($status): array
{
    if (!is_array($status)) {
        return ['full' => 'VPN:UNKNOWN', 'medium' => 'VPN:UNK', 'compact' => 'VPN:UNK', 'tiny' => 'VPN:?'];
    }
    $state = strtoupper((string)($status['status'] ?? 'UNKNOWN'));
    $total = isset($status['total']) && is_numeric($status['total']) ? (int)$status['total'] : 0;
    $online = isset($status['online']) && is_numeric($status['online']) ? (int)$status['online'] : 0;
    if ($state === 'N/A' || $total <= 0) {
        return ['full' => 'VPN:N/A', 'medium' => 'VPN:N/A', 'compact' => 'VPN:N/A', 'tiny' => 'VPN:N/A'];
    }
    if ($state === 'UNKNOWN') {
        return ['full' => 'VPN:UNKNOWN', 'medium' => 'VPN:UNK', 'compact' => 'VPN:UNK', 'tiny' => 'VPN:?'];
    }
    $state = in_array($state, ['UP', 'PARTIAL', 'DOWN'], true) ? $state : 'UNKNOWN';
    return [
        'full' => sprintf('VPN:%s %d/%d', $state, $online, $total),
        'medium' => sprintf('%s %d/%d', $state, $online, $total),
        'compact' => sprintf('VPN:%d/%d', $online, $total),
        'tiny' => sprintf('V:%d/%d', $online, $total),
    ];
}

function header_ups_texts($upsRaw): array
{
    $ups = normalize_ups($upsRaw);
    if (!$ups['online']) {
        return ['full' => 'UPS:UNK', 'compact' => 'UPS:?', 'tiny' => 'U:?'];
    }
    $battery = (string)($ups['battery'] ?? '?');
    $runtime = (string)($ups['runtime'] ?? '');
    $full = 'UPS:' . $battery . '%';
    if ($runtime !== '' && $runtime !== '?' && cell_len($full . ' ' . $runtime) <= 14) {
        $full .= ' ' . $runtime;
    }
    return ['full' => $full, 'compact' => 'UPS:' . $battery . '%', 'tiny' => 'U:' . $battery . '%'];
}

function header_speedtest_texts($speedtest): array
{
    if (!is_array($speedtest)) {
        return ['full' => 'SPD:WAIT', 'compact' => 'SPD:WAIT', 'tiny' => 'SPD:WAIT'];
    }
    $status = strtolower((string)($speedtest['status'] ?? 'waiting'));
    if ($status === 'ok') {
        $down = (string)($speedtest['download_mbps'] ?? '');
        $up = (string)($speedtest['upload_mbps'] ?? '');
        if ($down === '' || $up === '') {
            return ['full' => 'SPD:WAIT', 'compact' => 'SPD:WAIT', 'tiny' => 'SPD:WAIT'];
        }
        $ping = (string)($speedtest['ping_ms'] ?? '');
        $next = speedtest_countdown_text($speedtest);
        $source = speedtest_source_label($speedtest);
        $full = sprintf('SPD %s:%s↓/%s↑', $source, $down, $up);
        $compact = sprintf('SPD %s:%s/%s', speedtest_source_tiny($speedtest), $down, $up);
        if ($ping !== '') {
            $full .= ' ' . $ping . 'ms';
            $compact .= ' ' . $ping . 'ms';
        }
        if ($next !== '') {
            $fullNext = $full . ' ' . $next;
            if (cell_len($fullNext) <= 28) {
                $full = $fullNext;
            }
        }
        return ['full' => $full, 'compact' => $compact, 'tiny' => sprintf('SPD %s:%s/%s', speedtest_source_tiny($speedtest), $down, $up)];
    }
    $label = match ($status) {
        'disabled', 'off' => 'SPD:OFF',
        'error', 'failed', 'fail' => 'SPD:ERR',
        'missing' => 'SPD:ERR',
        'running' => 'SPD:RUN',
        default => 'SPD:WAIT',
    };
    return ['full' => $label, 'compact' => $label, 'tiny' => $label];
}

function speedtest_source_label(array $speedtest): string
{
    $profile = strtolower((string)($speedtest['profile'] ?? ''));
    $mode = strtolower((string)($speedtest['mode'] ?? ''));
    $tool = strtolower((string)($speedtest['tool'] ?? ''));
    if ($profile === 'vpn' || str_contains($mode, 'vpn')) {
        return 'VPN';
    }
    if ($profile === 'direct' || in_array($mode, ['direct', 'frontier'], true)) {
        return 'DIRECT';
    }
    if ($mode === 'client' || str_contains($tool, 'speedtest-net')) {
        return 'CLIENT';
    }
    if ($mode === 'router' || in_array($tool, ['ookla', 'speedtest-go', 'speedtest-cli'], true)) {
        return 'ROUTER';
    }
    return 'AUTO';
}

function speedtest_source_tiny(array $speedtest): string
{
    return match (speedtest_source_label($speedtest)) {
        'DIRECT' => 'D',
        'VPN' => 'V',
        'CLIENT' => 'C',
        'ROUTER' => 'R',
        default => 'A',
    };
}

function speedtest_baseline(): array
{
    $down = getenv('SOCX_SPEEDTEST_BASELINE_DOWN_MBPS');
    $up = getenv('SOCX_SPEEDTEST_BASELINE_UP_MBPS');
    return [
        'down' => is_numeric($down) ? (float)$down : 2000.0,
        'up' => is_numeric($up) ? (float)$up : 2000.0,
    ];
}

function speedtest_baseline_text(array $speedtest): string
{
    if (strtolower((string)($speedtest['status'] ?? '')) !== 'ok') {
        return 'BASE 2G/2G';
    }
    $baseline = speedtest_baseline();
    $down = (float)($speedtest['download_mbps'] ?? 0);
    $up = (float)($speedtest['upload_mbps'] ?? 0);
    if ($down <= 0 || $up <= 0) {
        return 'BASE 2G/2G';
    }
    $downPct = $baseline['down'] > 0 ? (($down / $baseline['down']) - 1.0) * 100.0 : 0.0;
    $upPct = $baseline['up'] > 0 ? (($up / $baseline['up']) - 1.0) * 100.0 : 0.0;
    return sprintf('BASE 2G/2G %s%d%%/%s%d%%',
        $downPct >= 0 ? '+' : '',
        (int)round($downPct),
        $upPct >= 0 ? '+' : '',
        (int)round($upPct));
}

function speedtest_profile_context_text(array $speedtest): string
{
    $source = speedtest_source_label($speedtest);
    $compare = is_array($speedtest['profile_compare'] ?? null) ? $speedtest['profile_compare'] : [];
    if ($source === 'VPN' && !empty($compare['available'])) {
        return sprintf('vs DIRECT %.0f%%/%.0f%% %+dms',
            (float)($compare['down_pct'] ?? 0),
            (float)($compare['up_pct'] ?? 0),
            (int)round((float)($compare['ping_delta'] ?? 0)));
    }
    if ($source === 'DIRECT') {
        $vpn = is_array($speedtest['vpn'] ?? null) ? $speedtest['vpn'] : [];
        if (strtolower((string)($vpn['status'] ?? '')) === 'ok') {
            return sprintf('VPN %s/%sM %sms',
                (string)($vpn['download_mbps'] ?? '?'),
                (string)($vpn['upload_mbps'] ?? '?'),
                (string)($vpn['ping_ms'] ?? '?'));
        }
    }
    return speedtest_baseline_text($speedtest);
}

function header_data_text(array $f): string
{
    $vpn = is_array($f['vpn_status'] ?? null) ? $f['vpn_status'] : [];
    if (($vpn['status'] ?? '') === 'UNKNOWN' && empty($vpn['data_fresh'])) {
        return 'STALE';
    }
    return 'LIVE';
}

function header_uptime_texts(array $f, string $live): array
{
    if ($live === 'STALE') {
        return ['full' => 'STALE', 'compact' => 'STALE', 'tiny' => 'STALE'];
    }
    $uptime = uptime_text_variants((string)($f['cpu']['uptime'] ?? ''));
    if ($uptime === null) {
        return ['full' => 'UP:?', 'compact' => 'UP:?', 'tiny' => 'UP:?'];
    }
    return [
        'full' => 'UP ' . $uptime['full'],
        'compact' => 'UP ' . $uptime['compact'],
        'tiny' => 'UP ' . $uptime['tiny'],
    ];
}

function uptime_text_variants(string $raw): ?array
{
    $parts = uptime_parts($raw);
    if ($parts === null) {
        return null;
    }
    $days = $parts['days'];
    $hours = $parts['hours'];
    $minutes = $parts['minutes'];
    if ($days > 0) {
        return [
            'full' => sprintf('%dd %dh %dm', $days, $hours, $minutes),
            'compact' => sprintf('%dd %dh', $days, $hours),
            'tiny' => $days . 'd',
        ];
    }
    if ($hours > 0) {
        return [
            'full' => sprintf('%dh %dm', $hours, $minutes),
            'compact' => sprintf('%dh %dm', $hours, $minutes),
            'tiny' => $hours . 'h',
        ];
    }
    return ['full' => max(0, $minutes) . 'm', 'compact' => max(0, $minutes) . 'm', 'tiny' => max(0, $minutes) . 'm'];
}

function uptime_parts(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '?') {
        return null;
    }
    if (preg_match('/(\d+)\s+days?,\s*(\d{1,2}):(\d{2})/i', $raw, $m)) {
        return ['days' => (int)$m[1], 'hours' => (int)$m[2], 'minutes' => (int)$m[3]];
    }
    if (preg_match('/(?:(\d+)\+)?(\d{1,2}):(\d{2})(?::(\d{2}))?/', $raw, $m)) {
        return [
            'days' => isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0,
            'hours' => (int)$m[2],
            'minutes' => (int)$m[3],
        ];
    }
    if (preg_match('/(\d+)\s+days?/i', $raw, $m)) {
        return ['days' => (int)$m[1], 'hours' => 0, 'minutes' => 0];
    }
    if (preg_match('/(\d+)\s+hrs?.*?(\d+)\s+mins?/i', $raw, $m)) {
        return ['days' => 0, 'hours' => (int)$m[1], 'minutes' => (int)$m[2]];
    }
    if (preg_match('/(\d+)\s+mins?/i', $raw, $m)) {
        return ['days' => 0, 'hours' => 0, 'minutes' => (int)$m[1]];
    }
    return null;
}

function header_join_fit(array $fields, int $width): ?string
{
    $fields = array_values(array_filter(array_map('strval', $fields), static fn(string $field): bool => $field !== ''));
    $line = implode(' | ', $fields);
    return cell_len($line) <= $width ? $line : null;
}

function header_join_shortest(array $fields, int $width): string
{
    $fields = array_values(array_filter(array_map('strval', $fields), static fn(string $field): bool => $field !== ''));
    while ($fields && cell_len(implode(' | ', $fields)) > $width) {
        $dropIndex = null;
        foreach ($fields as $idx => $field) {
            if (!str_starts_with($field, 'SPD:') && !str_starts_with($field, 'WAN:') && !str_starts_with($field, 'V:') && !preg_match('/^\d{2}:\d{2}$/', $field)) {
                $dropIndex = $idx;
                break;
            }
        }
        if ($dropIndex === null) {
            break;
        }
        array_splice($fields, $dropIndex, 1);
    }
    return implode(' | ', $fields);
}

function modern_network_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    [, , , $contentH] = modern_content_bounds($p);
    $trend = trend_arrow($f, 'wan_traffic');
    $topFlow = is_array($f['top_flow'] ?? null) ? $f['top_flow'] : first_useful_flow($f['flows'] ?? []);
    $rotate = ((int)($f['tick'] ?? 0) % 24);
    $showHealth = !empty($f['socx_health']) && $rotate >= 6 && $rotate < 11;
    $showInsight = !empty($f['miranda_insight']['available']) && $rotate >= 11 && $rotate < 17;
    $showAi = !empty($f['ai_lab']) && $rotate >= 17;
    if ($w < 22) {
        $rows = [
            network_rate_line('WAN', (string)$f['wan']['down'], (string)$f['wan']['up'], $w),
            network_rate_line('LAN', (string)$f['lan']['down'], (string)$f['lan']['up'], $w),
            network_dual_bar((int)($f['wan']['rx_bps'] ?? 0), (int)($f['wan']['tx_bps'] ?? 0), $w),
        ];
        if ($contentH >= 4) {
            $rows[] = $showHealth
                ? socx_health_mini_line((array)$f['socx_health'], $w)
                : ($showInsight
                    ? miranda_insight_mini_line((array)$f['miranda_insight'], $w)
                    : ($showAi ? ai_lab_mini_line((array)$f['ai_lab'], $w) : top_flow_mini_line($topFlow, $w)));
        }
        return $rows;
    }
    $rows = [
        truncate_text(sprintf('WAN %s %s  %s %s %s', down_marker(), compact_rate($f['wan']['down']), up_marker(), compact_rate($f['wan']['up']), $trend), $w),
        sprintf('LAN %s %s  %s %s', down_marker(), compact_rate($f['lan']['down']), up_marker(), compact_rate($f['lan']['up'])),
        network_bar_line('WAN', (int)($f['wan']['rx_bps'] ?? 0), (int)($f['wan']['tx_bps'] ?? 0), $w),
    ];
    if ($contentH >= 4) {
        $rows[] = $showHealth
            ? socx_health_line((array)$f['socx_health'], $w)
            : ($showInsight
                ? miranda_insight_line((array)$f['miranda_insight'], $w)
                : ($showAi ? ai_lab_line((array)$f['ai_lab'], $w) : top_flow_line($topFlow, $w)));
    }
    return $rows;
}

function socx_health_mini_line(array $health, int $width): string
{
    $score = isset($health['score']) && is_numeric($health['score']) ? (int)$health['score'] : 0;
    return truncate_text(sprintf('HEALTH %d', $score), $width);
}

function socx_health_line(array $health, int $width): string
{
    $score = isset($health['score']) && is_numeric($health['score']) ? (int)$health['score'] : 0;
    $reason = trim((string)($health['reason'] ?? ''));
    return truncate_text(sprintf('HEALTH %d %s', $score, $reason !== '' ? $reason : 'nominal'), $width);
}

function ai_lab_mini_line(array $status, int $width): string
{
    $online = (int)($status['online'] ?? 0);
    $total = (int)($status['total'] ?? 0);
    $offline = is_array($status['offline'] ?? null) ? $status['offline'] : [];
    $label = $offline ? ai_lab_short_name((string)$offline[0]) . '?' : 'OK';
    if ($total <= 0) {
        return truncate_text('AI ready', $width);
    }
    return truncate_text(sprintf('AI %d/%d %s', $online, $total, $label), $width);
}

function ai_lab_line(array $status, int $width): string
{
    $online = (int)($status['online'] ?? 0);
    $total = (int)($status['total'] ?? 0);
    $onlineNames = is_array($status['online_names'] ?? null) ? $status['online_names'] : [];
    $offline = is_array($status['offline'] ?? null) ? $status['offline'] : [];
    if ($total <= 0) {
        return truncate_text('AI LAB ready', $width);
    }
    $detail = $offline ? ('down ' . implode(',', array_slice($offline, 0, 2))) : implode(',', array_slice($onlineNames, 0, 2));
    return truncate_text(sprintf('AI LAB %d/%d %s', $online, $total, $detail), $width);
}

function ai_lab_short_name(string $name): string
{
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name) ?? '');
    if ($clean === '') {
        return 'AI';
    }
    return substr($clean, 0, 5);
}

function miranda_insight_mini_line(array $insight, int $width): string
{
    $severity = strtoupper((string)($insight['severity'] ?? 'INFO'));
    $age = (string)($insight['age'] ?? '');
    return truncate_text(sprintf('AI %s %s', $severity, $age), $width);
}

function miranda_insight_line(array $insight, int $width): string
{
    $severity = strtoupper((string)($insight['severity'] ?? 'INFO'));
    $reason = trim((string)($insight['reason'] ?? 'SOCX analyzed'));
    $age = trim((string)($insight['age'] ?? ''));
    return truncate_text(sprintf('AI SOC %s%s | %s', $severity, $age !== '' ? ' ' . $age : '', $reason), $width);
}

function network_dual_bar(int $down, int $up, int $width): string
{
    if ($width < 8) {
        return fit_sparkline([$down, $up], $width);
    }
    $barWidth = max(1, intdiv(max(0, $width - 6), 2));
    $max = max(1, $down, $up);
    $downBar = mini_fill_bar($down, $max, $barWidth);
    $upBar = mini_fill_bar($up, $max, $barWidth);
    return truncate_text(sprintf('D %s U %s', $downBar, $upBar), $width);
}

function network_bar_line(string $label, int $down, int $up, int $width): string
{
    $prefix = strtoupper(substr($label, 0, 1));
    if ($width < 18) {
        return network_dual_bar($down, $up, $width);
    }
    $barWidth = max(2, intdiv(max(0, $width - 10), 2));
    $max = max(1, $down, $up);
    return truncate_text(sprintf('%s D %s U %s', $prefix, mini_fill_bar($down, $max, $barWidth), mini_fill_bar($up, $max, $barWidth)), $width);
}

function mini_fill_bar(int $value, int $max, int $width): string
{
    $width = max(1, $width);
    $pct = $max > 0 ? max(0.0, min(1.0, $value / $max)) : 0.0;
    $filled = $value > 0 ? max(1, (int)round($pct * $width)) : 0;
    return str_repeat('█', min($width, $filled)) . str_repeat('░', max(0, $width - $filled));
}

function network_reference_rate(): int
{
    $gbps = (float)(getenv('SOCX_WAN_GBPS') ?: getenv('SOCX_LINK_GBPS') ?: '5');
    return max(1_000_000, (int)round(($gbps * 1000 * 1000 * 1000) / 8));
}

function top_flow_mini_line(?array $flow, int $width): string
{
    if ($flow === null) {
        return truncate_text('TOP waiting', $width);
    }
    $src = top_flow_source_label((string)($flow['src'] ?? ''));
    $svc = service_short((string)($flow['service'] ?? ''));
    $rate = short_bytes((int)($flow['score'] ?? 0)) . '/s';
    $shortSrc = preg_match('/^LAN\.(\d+)$/', $src, $m) ? 'L' . $m[1] : truncate_text($src, max(3, $width - cell_len('TOP  ' . $svc)));
    $verb = top_flow_direction_word($flow);
    $friendly = top_flow_friendly_source((string)($flow['src'] ?? ''));
    $candidates = [
        sprintf('TOP %s %s', $friendly, $svc),
        sprintf('TOP %s %s %s', $src, $verb, $svc),
        sprintf('TOP %s %s', $src, $svc),
        sprintf('TOP %s %s %s', $shortSrc, $verb, $svc),
        sprintf('TOP %s %s', $shortSrc, $svc),
        sprintf('%s %s %s', $src, $svc, $rate),
        sprintf('%s %s', $src, $svc),
        sprintf('%s %s', $svc, $rate),
    ];
    foreach ($candidates as $candidate) {
        if (cell_len($candidate) <= $width) {
            return $candidate;
        }
    }
    return truncate_text(end($candidates), $width);
}

function top_flow_friendly_source(string $label): string
{
    $label = trim(preg_replace('/:\d+$/', '', $label) ?? $label);
    if (str_contains($label, '/LAN.')) {
        [$name] = explode('/LAN.', $label, 2);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';
        if ($name !== '') {
            return truncate_text($name, 10);
        }
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,}$/', $label) === 1 && !str_starts_with($label, 'EXT.')) {
        return truncate_text($label, 10);
    }
    return top_flow_source_label($label);
}

function top_flow_source_label(string $label): string
{
    $label = preg_replace('/:\d+$/', '', trim($label)) ?? trim($label);
    if (preg_match('/^L(\d+)$/', $label, $m)) {
        return 'LAN.' . $m[1];
    }
    if (preg_match('/^LAN\.\d+$/', $label) === 1) {
        return $label;
    }
    if (str_contains($label, '/LAN.')) {
        [$name] = explode('/', $label, 2);
        return truncate_text($name, 8);
    }
    return compact_endpoint_label($label, true);
}

function top_flow_line(?array $flow, int $width): string
{
    if ($flow === null) {
        return truncate_text('TOP waiting for flow data', $width);
    }
    $path = flow_path_text($flow, max(8, $width - 12));
    $svc = service_short((string)($flow['service'] ?? ''));
    $rate = short_bytes((int)($flow['score'] ?? 0)) . '/s';
    $verb = top_flow_direction_word($flow);
    $friendly = top_flow_friendly_source((string)($flow['src'] ?? ''));
    $candidates = [
        sprintf('TOP %s %s %s', $friendly, $svc, $rate),
        sprintf('TOP %s %s', $friendly, $svc),
        sprintf('TOP %s %s %s %s', $verb, $path, $svc, $rate),
        sprintf('TOP %s %s %s', $path, $svc, $rate),
        sprintf('TOP %s %s', $path, $svc),
    ];
    foreach ($candidates as $candidate) {
        if (cell_len($candidate) <= $width) {
            return $candidate;
        }
    }
    return truncate_text(end($candidates), $width);
}

function top_flow_direction_word(array $flow): string
{
    $up = parse_short_bytes((string)($flow['up'] ?? '0B'));
    $down = parse_short_bytes((string)($flow['down'] ?? '0B'));
    if ($down > ($up * 1.25)) {
        return 'downloading';
    }
    if ($up > ($down * 1.25)) {
        return 'uploading';
    }
    return 'talking';
}

function network_rate_line(string $label, string $down, string $up, int $width, string $trend = ''): string
{
    $label = strtoupper($label);
    $showDecimal = $width >= 16;
    $down = compact_rate_tiny($down, $showDecimal);
    $up = compact_rate_tiny($up, $showDecimal);
    $candidates = [
        sprintf('%s %s%s %s%s %s', $label, down_marker(), $down, up_marker(), $up, $trend),
        sprintf('%s %s%s %s%s', $label, down_marker(), $down, up_marker(), $up),
        sprintf('%s%s%s %s%s', $label, down_marker(), $down, up_marker(), $up),
        sprintf('%s D%s U%s %s', $label, $down, $up, $trend),
        sprintf('%s D%s U%s', $label, $down, $up),
        sprintf('%s %s/%s %s', $label, $down, $up, $trend),
        sprintf('%s %s/%s', $label, $down, $up),
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if (cell_len($candidate) <= $width) {
            return $candidate;
        }
    }
    return truncate_text(trim(end($candidates)), $width);
}

function modern_pf_rows(array $f, array $p): array
{
    $pf = $f['pf'];
    $w = modern_content_width($p);
    $stateTrend = trend_arrow($f, 'state_count');
    $searchTrend = trend_arrow($f, 'search_rate');
    $dropTrend = trend_arrow($f, 'packet_drops');
    if ($w < 22) {
        return [
            truncate_text(sprintf('STATES %s %s', compact_num((int)preg_replace('/\D/', '', (string)($pf['states'] ?? '0'))), $stateTrend), $w),
            truncate_text(sprintf('SEARCH %s %s', compact_pf_rate((string)($pf['searches_rate'] ?? '?')), $searchTrend), $w),
            truncate_text(sprintf('TRAFFIC D%s/P%s %s', compact_num_tight((int)($pf['blocked'] ?? 0)), compact_num_tight((int)($pf['passed'] ?? 0)), $dropTrend), $w),
        ];
    }
    return [
        truncate_text(sprintf('STATES %s %s', $pf['states'] ?? '?', $stateTrend), $w),
        truncate_text(sprintf('SEARCH %s %s', compact_pf_rate((string)($pf['searches_rate'] ?? '?')), $searchTrend), $w),
        truncate_text(sprintf('TRAFFIC D%s %s P%s', compact_num_tight((int)($pf['blocked'] ?? 0)), $dropTrend, compact_num_tight((int)($pf['passed'] ?? 0))), $w),
        fit_sparkline($f['pf_history'] ?? [], $w),
    ];
}

function modern_speedtest_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $speedtest = is_array($f['speedtest'] ?? null) ? $f['speedtest'] : [];
    $status = strtolower((string)($speedtest['status'] ?? 'waiting'));
    if ($status === 'ok') {
        $down = (string)($speedtest['download_mbps'] ?? '');
        $up = (string)($speedtest['upload_mbps'] ?? '');
        $ping = (string)($speedtest['ping_ms'] ?? '');
        $jitter = (string)($speedtest['jitter_ms'] ?? '');
        $next = speedtest_countdown_text($speedtest);
        $server = trim((string)($speedtest['server_name'] ?? '') . ' ' . (string)($speedtest['server_location'] ?? ''));
        $server = $server !== '' ? $server : 'auto server';
        $fresh = !empty($speedtest['fresh']);
        $health = $fresh ? 'stable' : 'stale';
        $source = speedtest_source_label($speedtest);
        $context = speedtest_profile_context_text($speedtest);
        $nextValue = speedtest_countdown_value_text($speedtest);
        $healthNext = trim($health . ' ' . $nextValue);
        if (cell_len($healthNext) > $w && $fresh) {
            $healthNext = trim('ok ' . $nextValue);
        }
        if ($healthNext === '') {
            $healthNext = $fresh ? 'stable' : 'stale';
        }

        if ($w < 22) {
            $srcTiny = speedtest_source_tiny($speedtest);
            return [
                truncate_text(sprintf('%s↓ %sM', $srcTiny, $down !== '' ? $down : '?'), $w),
                truncate_text(sprintf('%s↑ %sM', $srcTiny, $up !== '' ? $up : '?'), $w),
                truncate_text(sprintf('LAT %sms', $ping !== '' ? $ping : '?'), $w),
                truncate_text(speedtest_card_footer_text($speedtest, $w, $healthNext, $source, $context), $w),
            ];
        }

        return [
            truncate_text(sprintf('%s DOWN %sM ↓', $source, $down !== '' ? $down : '?'), $w),
            truncate_text(sprintf('%s UP   %sM ↑', $source, $up !== '' ? $up : '?'), $w),
            truncate_text(sprintf('PING %sms%s %s', $ping !== '' ? $ping : '?', $jitter !== '' ? ' J' . $jitter . 'ms' : '', $health), $w),
            truncate_text(speedtest_card_footer_text($speedtest, $w, $healthNext, $source, $context), $w),
        ];
    }

    $message = trim((string)($speedtest['message'] ?? ''));
    return match ($status) {
        'disabled', 'off' => [
            truncate_text('SPD OFF', $w),
            truncate_text('collector disabled', $w),
            '',
            '',
        ],
        'error', 'failed', 'fail' => [
            truncate_text('SPD ERR', $w),
            truncate_text($message !== '' ? $message : 'last run failed', $w),
            truncate_text('check WAN/VPN path', $w),
            '',
        ],
        'missing' => [
            truncate_text('SPD ERR', $w),
            truncate_text($w < 14 ? 'missing' : 'speedtest missing', $w),
            truncate_text($w < 14 ? 'install' : 'install collector', $w),
            '',
        ],
        'running' => [
            truncate_text('SPD RUN', $w),
            truncate_text($w < 14 ? 'testing' : 'test running', $w),
            truncate_text($w < 14 ? 'updating' : 'cache updating', $w),
            '',
        ],
        default => [
            truncate_text('SPD WAIT', $w),
            truncate_text($w < 14 ? 'no cache' : 'no cached result', $w),
            truncate_text($w < 14 ? 'warming' : 'collector warming', $w),
            '',
        ],
    };
}

function speedtest_card_footer_text(array $speedtest, int $width, string $healthNext, string $source, string $context): string
{
    $sourceShort = speedtest_source_tiny($speedtest);
    $pathFull = 'PATH ' . $source;
    $pathTiny = 'PATH ' . $sourceShort;
    $context = trim($context);
    $healthNext = trim($healthNext);
    $candidates = [];
    if ($healthNext !== '') {
        $candidates[] = $healthNext;
        $candidates[] = $healthNext . ' ' . $pathTiny;
    }
    if (preg_match('/^vs\s+direct/i', $context) === 1) {
        $candidates[] = 'VPN compare';
        $candidates[] = 'DIRECT cmp';
    } elseif ($context !== '') {
        $candidates[] = $context;
    }
    $candidates[] = $pathFull;
    $candidates[] = $pathTiny;

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && cell_len($candidate) <= $width) {
            return $candidate;
        }
    }
    return truncate_text($healthNext !== '' ? $healthNext : $pathTiny, $width);
}

function modern_cpu_card_rows(array $f, array $p): array
{
    $cpu = $f['cpu'];
    $tick = (int)($f['tick'] ?? 0);
    $w = modern_content_width($p);
    $cores = array_slice($cpu['cores'], 0, 4);
    $trend = trend_arrow($f, 'cpu');
    if ($w < 22) {
        $rows = [sprintf('%d%% %s %s %s', $cpu['used'], str_replace('GHz', 'G', $cpu['freq']), modern_temp_text($cpu['temp']), $trend)];
        foreach (array_chunk($cores, 2) as $pair) {
            $parts = [];
            foreach ($pair as $core) {
                $used = (int)round((float)$core['used']);
                $parts[] = sprintf('C%s %3d%%', $core['id'], $used);
            }
            $rows[] = truncate_text(implode(' ', $parts), $w);
        }
        $rows[] = cpu_load_percent_text($cpu, $w);
        return $rows;
    }
    $barW = max(4, min(8, intdiv($w - 16, 2)));
    $rows = [sprintf('%3d%% %s  %-7s  %s', $cpu['used'], $trend, $cpu['freq'], modern_temp_text($cpu['temp']))];
    foreach (array_chunk($cores, 2) as $pair) {
        $parts = [];
        foreach ($pair as $core) {
            $used = (int)round((float)$core['used']);
            $parts[] = sprintf('C%s %s %2d%%', $core['id'], animated_meter($used, $barW, $tick + (int)$core['id']), $used);
        }
        $rows[] = truncate_text(implode('  ', $parts), $w);
    }
    $rows[] = cpu_load_percent_text($cpu, $w, true);
    return $rows;
}

function cpu_load_percent_text(array $cpu, int $width, bool $include15 = false): string
{
    $cores = max(1, count((array)($cpu['cores'] ?? [])));
    $loads = array_values((array)($cpu['load'] ?? []));
    $pct = [];
    foreach (array_slice($loads, 0, $include15 ? 3 : 2) as $load) {
        if (!is_numeric($load)) {
            continue;
        }
        $pct[] = max(0, min(999, (int)round(((float)$load / $cores) * 100)));
    }
    if (!$pct) {
        return truncate_text('LOAD ?', $width);
    }

    $candidates = [];
    if ($include15 && count($pct) >= 3) {
        $candidates[] = sprintf('LOAD 1/5/15m %d/%d/%d%%', $pct[0], $pct[1], $pct[2]);
    }
    if (count($pct) >= 2) {
        $candidates[] = sprintf('LOAD 1/5m %d/%d%%', $pct[0], $pct[1]);
        $candidates[] = sprintf('LOAD %d/%d%%', $pct[0], $pct[1]);
    }
    $candidates[] = sprintf('LOAD %d%%', $pct[0]);
    $candidates[] = sprintf('L %d%%', $pct[0]);

    foreach ($candidates as $candidate) {
        if (cell_len($candidate) <= $width) {
            return $candidate;
        }
    }
    return truncate_text(end($candidates), $width);
}

function modern_memory_rows(array $f, array $p): array
{
    $mem = $f['mem'];
    $w = modern_content_width($p);
    $trend = trend_arrow($f, 'memory');
    if ($w < 22) {
        return [
            sprintf('RAM %s/%s', compact_bytes_tight((int)$mem['used']), compact_bytes_tight((int)$mem['total'])),
            sprintf('%2d%% %s %s', (int)$mem['used_pct'], fit_bar((int)$mem['used_pct'], max(1, $w - 6)), $trend),
            sprintf('ARC %s F%s', compact_bytes_tight((int)$mem['arc_total']), compact_bytes_tight((int)$mem['free'])),
            memory_swap_text($mem, $w),
        ];
    }
    return [
        sprintf('RAM %s/%s', bytes_text((int)$mem['used']), bytes_text((int)$mem['total'])),
        sprintf('%2d%% %s %s', (int)$mem['used_pct'], fit_bar((int)$mem['used_pct'], max(1, $w - 6)), $trend),
        sprintf('ARC %s  free %s', bytes_text((int)$mem['arc_total']), bytes_text((int)$mem['free'])),
        memory_swap_text($mem, $w),
    ];
}

function memory_swap_text(array $mem, int $width): string
{
    $total = (int)($mem['swap_total'] ?? 0);
    $used = (int)($mem['swap_used'] ?? 0);
    if ($total <= 0) {
        return truncate_text('SWAP off', $width);
    }
    if ($width < 22) {
        return truncate_text(sprintf('SWAP %s/%s', compact_bytes_tight($used), compact_bytes_tight($total)), $width);
    }
    $pct = percent($used, $total);
    return truncate_text(sprintf('SWAP %s/%s %d%%', bytes_text($used), bytes_text($total), $pct), $width);
}

function modern_threat_pulse_rows(array $f, array $p): array
{
    $pulse = $f['threat_pulse'] ?? [];
    $incident = is_array($f['incident_summary'] ?? null) ? $f['incident_summary'] : [];
    $w = modern_content_width($p);
    $incidentLine = '';
    if ($incident) {
        $incidentLine = truncate_text(sprintf('INC %s p%s', compact_num_tight((int)($incident['fw_blocks'] ?? 0)), (string)($incident['top_port'] ?? '?')), $w);
    }
    return [
        truncate_text(sprintf('FW drops/min %s %s', compact_num_tight((int)($pulse['fw_drops_min'] ?? 0)), trend_arrow($f, 'packet_drops')), $w),
        truncate_text(sprintf('DNSBL/min %s %s', compact_num_tight((int)($pulse['dnsbl_min'] ?? 0)), trend_arrow($f, 'dnsbl_hits')), $w),
        $incidentLine !== '' ? $incidentLine : truncate_text(sprintf('IDS alerts %s', compact_num_tight((int)($pulse['ids_alerts'] ?? 0))), $w),
        truncate_text(sprintf('New hosts %s', compact_num_tight((int)($pulse['new_hosts'] ?? 0))), $w),
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
    $rotateText = ups_rotating_sensor_text($ups, (int)($f['tick'] ?? 0), $w);
    if ($w < 24) {
        $v = is_numeric($ups['outputv']) ? (string)(int)round((float)$ups['outputv']) . 'V' : '?V';
        $voltLine = sprintf('%s %sA', $v, $ups['output_current']);
        if (cell_len($voltLine . ' ' . $source) <= $w) {
            $voltLine .= ' ' . $source;
        }
        return [
            $watts,
            truncate_text(sprintf('%s%% b%s%% %s', $ups['load'], $ups['battery'], $ups['runtime']), $w),
            truncate_text($voltLine, $w),
            truncate_text($rotateText, $w),
        ];
    }
    return [
        $watts,
        truncate_text(sprintf('load %s%% batt %s%% run %s', $ups['load'], $ups['battery'], $ups['runtime']), $w),
        truncate_text(sprintf('out %sV %sA  in %sV', $ups['outputv'], $ups['output_current'], $ups['inputv']), $w),
        truncate_text($rotateText, $w),
        truncate_text(sprintf('bat %sV %sC %s', $ups['battery_voltage'], $ups['battery_temp'], $source), $w),
        fit_sparkline($ups['history'], $w),
    ];
}

function ups_load_watts_text(array $ups): string
{
    $watts = is_numeric($ups['watts'] ?? null) ? (string)(int)round((float)$ups['watts']) : '?';
    $nominal = is_numeric($ups['nominal_watts'] ?? null) ? (int)round((float)$ups['nominal_watts']) : 0;
    if ($nominal <= 0) {
        return $watts . 'W';
    }
    return sprintf('%s/%dW', $watts, $nominal);
}

function ups_load_watts_label(string $loadWatts, int $width): string
{
    $full = 'LOAD ' . $loadWatts;
    if (cell_len($full) <= $width) {
        return $full;
    }
    $short = 'L ' . $loadWatts;
    if (cell_len($short) <= $width) {
        return $short;
    }
    return $loadWatts;
}

function ups_rotating_sensor_text(array $ups, int $tick, int $width): string
{
    $temp = ups_env_temp_text($ups, $width);
    $humidity = ups_env_humidity_text($ups, $width);
    $load = ups_load_watts_label(ups_load_watts_text($ups), $width);
    $sequence = array_values(array_filter([$temp, $humidity, $load], static fn(string $text): bool => $text !== ''));
    if (!$sequence) {
        return '';
    }
    $phaseTicks = max(2, (int)(getenv('SOCX_UPS_ROTATE_TICKS') ?: 8));
    $phase = intdiv(max(0, $tick), $phaseTicks) % count($sequence);
    return $sequence[$phase];
}

function ups_env_temp_text(array $ups, int $width): string
{
    $values = [];
    foreach (['env_temp1', 'env_temp2'] as $key) {
        if (is_numeric($ups[$key] ?? null)) {
            $values[] = (float)$ups[$key];
        }
    }
    if (!$values && is_numeric($ups['battery_temp'] ?? null)) {
        $values[] = (float)$ups['battery_temp'];
    }
    if (!$values) {
        return '';
    }
    $celsius = (int)round(array_sum($values) / count($values));
    $fahrenheit = (int)round(($celsius * 9 / 5) + 32);
    $short = sprintf('T %dF/%dC', $fahrenheit, $celsius);
    if (cell_len($short) <= $width) {
        return $short;
    }
    return sprintf('%dF/%dC', $fahrenheit, $celsius);
}

function ups_env_humidity_text(array $ups, int $width): string
{
    $values = [];
    foreach (['env_humidity1', 'env_humidity2'] as $key) {
        if (is_numeric($ups[$key] ?? null)) {
            $value = (int)round((float)$ups[$key]);
            if ($value >= 0 && $value <= 100) {
                $values[] = (string)$value;
            }
        }
    }
    if (!$values) {
        return '';
    }
    $joined = implode('/', array_slice($values, 0, 2));
    $short = 'RH ' . $joined . '%';
    if (cell_len($short) <= $width) {
        return $short;
    }
    $compact = 'R ' . $joined . '%';
    if (cell_len($compact) <= $width) {
        return $compact;
    }
    return $joined . '%RH';
}

function modern_process_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $large = modern_density() === 'large';
    $cmdW = max(8, $w - ($large ? 18 : 24));
    $rows = [$large ? sprintf('%-5s %-6s %4s %s', 'PID', 'MEM', 'CPU', 'CMD') : sprintf('%-5s %-5s %-6s %4s %s', 'PID', 'USER', 'MEM', 'CPU', 'CMD')];
    foreach (array_slice($f['procs'], 0, density_row_limit(max(1, $p['height'] - 3))) as $proc) {
        $cmd = $cmdW < 10 ? (string)$proc['name'] : (string)$proc['cmd'];
        if ($large) {
            $rows[] = sprintf('%-5s %-6s %4.1f %s',
                truncate_text((string)$proc['pid'], 5),
                truncate_text(bytes_text((int)$proc['rss']), 6),
                (float)$proc['cpu'],
                truncate_modern_text($cmd, $cmdW));
        } else {
            $rows[] = sprintf('%-5s %-5s %-6s %4.1f %s',
                truncate_text((string)$proc['pid'], 5),
                truncate_text((string)$proc['user'], 5),
                truncate_text(bytes_text((int)$proc['rss']), 6),
                (float)$proc['cpu'],
                truncate_modern_text($cmd, $cmdW));
        }
    }
    return $rows;
}

function modern_pftop_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $flows = aggregate_display_flows(array_values($f['flows'] ?? []));
    if (!$flows) {
        return [truncate_text('PF states live - waiting for traffic', $w)];
    }

    $maxRows = density_row_limit(max(1, $p['height'] - 3));
    $rows = [];
    if ($w < 48) {
        $flowW = max(10, $w - 18);
        $rows[] = sprintf('%-3s %-4s %-7s %s', 'DIR', 'APP', 'TRAFFIC', 'PATH');
        foreach (array_slice($flows, 0, $maxRows) as $flow) {
            $rows[] = sprintf('%-3s %-4s %-7s %s',
                pftop_direction($flow),
                truncate_text(service_short((string)($flow['service'] ?? '')), 4),
                pftop_rate($flow, 7),
                pftop_flow_text($flow, $flowW));
        }
        return $rows;
    }

    if ($w < 70) {
        $flowW = max(12, $w - 31);
        $rows[] = sprintf('%-3s %-3s %-4s %-4s %-8s %s', 'DIR', 'PRO', 'APP', 'STAT', 'TRAFFIC', 'PATH');
        foreach (array_slice($flows, 0, $maxRows) as $flow) {
            $rows[] = sprintf('%-3s %-3s %-4s %-4s %-8s %s',
                pftop_direction($flow),
                pftop_proto($flow),
                truncate_text(service_short((string)($flow['service'] ?? '')), 4),
                pftop_state_short((string)($flow['state'] ?? '')),
                pftop_rate($flow, 8),
                pftop_flow_text($flow, $flowW));
        }
        return $rows;
    }

    $flowW = max(20, $w - 50);
    $rows[] = sprintf('%-3s %-3s %-4s %-4s %-8s %-7s %-7s %s', 'DIR', 'PRO', 'APP', 'STAT', 'TRAFFIC', 'AGE', 'LEFT', 'PATH');
    foreach (array_slice($flows, 0, $maxRows) as $flow) {
        $rows[] = sprintf('%-3s %-3s %-4s %-4s %-8s %-7s %-7s %s',
            pftop_direction($flow),
            pftop_proto($flow),
            truncate_text(service_short((string)($flow['service'] ?? '')), 4),
            pftop_state_short((string)($flow['state'] ?? '')),
            pftop_rate($flow, 8),
            truncate_text((string)($flow['age'] ?? '--'), 7),
            truncate_text((string)($flow['expires'] ?? '--'), 7),
            pftop_flow_text($flow, $flowW));
    }
    return $rows;
}

function pftop_direction(array $flow): string
{
    if (($flow['class'] ?? '') === 'vpn' || preg_match('/^(tun|wg)/', (string)($flow['iface'] ?? ''))) {
        return 'VPN';
    }
    if (!empty($flow['dir'])) {
        return strtoupper(substr((string)$flow['dir'], 0, 3));
    }
    $src = (string)($flow['src'] ?? '');
    $dst = (string)($flow['dst'] ?? '');
    if (endpoint_is_lan($src) && endpoint_is_lan($dst)) {
        return 'LAN';
    }
    if (endpoint_is_lan($src)) {
        return 'OUT';
    }
    if ((endpoint_is_external($src) || !endpoint_is_lan($src)) && (endpoint_is_lan($dst) || endpoint_is_wan($dst))) {
        return 'IN';
    }
    return 'PF';
}

function pftop_proto(array $flow): string
{
    $proto = strtoupper((string)($flow['proto'] ?? ''));
    if ($proto === '') {
        $service = strtolower((string)($flow['service'] ?? ''));
        $proto = in_array($service, ['dns', 'ntp', 'vpn'], true) ? 'UDP' : 'TCP';
    }
    return truncate_text($proto, 3);
}

function pftop_rate(array $flow, int $width = 8): string
{
    $up = rate_to_number((string)($flow['up'] ?? '0'));
    $down = rate_to_number((string)($flow['down'] ?? '0'));
    $bytes = (int)round($up + $down);
    $rate = short_bytes($bytes) . '/s';
    if (cell_len($rate) <= $width) {
        return $rate;
    }
    $rate = compact_bytes_tight($bytes) . '/s';
    if (cell_len($rate) <= $width) {
        return $rate;
    }
    return implode('', array_slice(utf8_cells($rate), 0, max(0, $width)));
}

function pftop_count_suffix(array $flow, int $width): string
{
    $count = (int)($flow['display_count'] ?? 1);
    if ($count <= 1 || $width < 3) {
        return '';
    }
    $text = 'x' . min(99, $count);
    return cell_len($text) <= $width ? $text : '';
}

function pftop_state_short(string $state): string
{
    $state = strtoupper($state);
    return match (true) {
        str_contains($state, 'ESTABLISHED') => 'EST',
        str_contains($state, 'SINGLE') => 'SING',
        str_contains($state, 'MULTIPLE') => 'MULT',
        str_contains($state, 'CLOSED') => 'CLOS',
        str_contains($state, 'FIN') => 'FIN',
        str_contains($state, 'SYN') => 'SYN',
        $state !== '' => truncate_text(str_replace(':', '/', $state), 4),
        default => '--',
    };
}

function pftop_flow_text(array $flow, int $width): string
{
    $suffix = pftop_count_suffix($flow, $width);
    $pathWidth = $suffix !== '' ? max(4, $width - cell_len($suffix) - 1) : $width;
    $path = endpoint_pair_text((string)($flow['src'] ?? ''), (string)($flow['dst'] ?? ''), $pathWidth);
    return $suffix !== '' ? truncate_text($path . ' ' . $suffix, $width) : $path;
}

function modern_flow_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $large = modern_density() === 'large';
    $flows = aggregate_display_flows(array_values($f['flows'] ?? []));
    if ($w < 52 || $large) {
        $rateW = $large && $w >= 58 ? 11 : 8;
        $svcW = $large && $w >= 58 ? 5 : 4;
        $flowW = max(10, $w - $rateW - $svcW - 5);
        $rows = [sprintf('%-2s %-*s %-*s %-*s', '#', $flowW, 'PATH', $rateW, 'TRAFFIC', $svcW, 'APP')];
        foreach (array_slice($flows, 0, density_row_limit(max(1, $p['height'] - 3))) as $idx => $flow) {
            $rows[] = sprintf('%02d %-*s %-*s %-*s',
                $idx + 1,
                $flowW,
                flow_path_with_count($flow, $flowW),
                $rateW,
                compact_rate_pair($flow['up'], $flow['down'], $rateW),
                $svcW,
                truncate_text(service_short((string)($flow['service'] ?? '')), $svcW));
        }
        return $rows;
    }
    $rateW = $w >= 74 ? 10 : 8;
    $svcW = $w >= 74 ? 5 : 4;
    $kindW = 6;
    $flowW = max(14, $w - $rateW - $svcW - $kindW - 7);
    $rows = [sprintf('%-2s %-*s %-*s %-*s %-*s', '#', $flowW, 'PATH', $rateW, 'TRAFFIC', $svcW, 'APP', $kindW, 'TYPE')];
    foreach (array_slice($flows, 0, density_row_limit(max(1, $p['height'] - 3))) as $idx => $flow) {
        $flowText = flow_path_with_count($flow, $flowW);
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

function aggregate_display_flows(array $flows): array
{
    $groups = [];
    $order = [];
    foreach ($flows as $flow) {
        if (!is_array($flow)) {
            continue;
        }
        $key = implode('|', [
            pftop_direction($flow),
            strtolower((string)($flow['service'] ?? '')),
            (string)($flow['src'] ?? ''),
            (string)($flow['dst'] ?? ''),
        ]);
        if (!isset($groups[$key])) {
            $flow['display_count'] = 0;
            $flow['display_score'] = 0;
            $flow['display_up_bytes'] = 0;
            $flow['display_down_bytes'] = 0;
            $groups[$key] = $flow;
            $order[] = $key;
        }
        $groups[$key]['display_count'] = (int)($groups[$key]['display_count'] ?? 0) + 1;
        $groups[$key]['display_score'] = (int)($groups[$key]['display_score'] ?? 0) + (int)($flow['score'] ?? 0);
        $groups[$key]['display_up_bytes'] = (int)($groups[$key]['display_up_bytes'] ?? 0) + parse_short_bytes((string)($flow['up'] ?? '0'));
        $groups[$key]['display_down_bytes'] = (int)($groups[$key]['display_down_bytes'] ?? 0) + parse_short_bytes((string)($flow['down'] ?? '0'));
    }
    $out = [];
    foreach ($order as $key) {
        $row = $groups[$key];
        if ((int)($row['display_count'] ?? 1) > 1) {
            $row['score'] = (int)($row['display_score'] ?? $row['score'] ?? 0);
            $row['up'] = short_bytes((int)($row['display_up_bytes'] ?? 0)) . '/s';
            $row['down'] = short_bytes((int)($row['display_down_bytes'] ?? 0)) . '/s';
        }
        $out[] = $row;
    }
    usort($out, static fn(array $a, array $b): int => (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0));
    return $out;
}

function flow_path_with_count(array $flow, int $width): string
{
    $count = (int)($flow['display_count'] ?? 1);
    if ($count <= 1 || $width < 8) {
        return flow_path_text($flow, $width);
    }
    $suffix = 'x' . min(99, $count);
    $path = flow_path_text($flow, max(4, $width - cell_len($suffix) - 1));
    return truncate_text($path . ' ' . $suffix, $width);
}

function modern_packet_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $maxRows = density_row_limit(max(1, $p['height'] - 2));
    $rows = [];

    if ($w < 96) {
        $tagW = min(14, max(9, intdiv($w, 5)));
        $storyW = max(12, $w - $tagW - 11);
        foreach (array_slice($f['packets'], 0, $maxRows) as $pkt) {
            $rows[] = sprintf('%-8s %-*s %-*s',
                truncate_text((string)$pkt['time'], 8),
                $tagW,
                packet_event_tag($pkt, $tagW),
                $storyW,
                packet_story_text($pkt, $storyW));
        }
        return $rows;
    }

    $tagW = 14;
    $storyW = max(36, $w - $tagW - 13);
    $rows[] = sprintf('%-8s %-*s %-*s', 'TIME', $tagW, 'EVENT', $storyW, 'WHAT HAPPENED');
    foreach (array_slice($f['packets'], 0, max(1, $maxRows - 1)) as $pkt) {
        $rows[] = sprintf('%-8s %-*s %-*s',
            truncate_text((string)$pkt['time'], 8),
            $tagW,
            packet_event_tag($pkt, $tagW),
            $storyW,
            packet_story_text($pkt, $storyW));
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
    $mem = ['total' => $physmem, 'free' => 0, 'active' => 0, 'inactive' => 0, 'wired' => 0, 'arc_total' => 0, 'swap_total' => 0, 'swap_used' => 0, 'swap_free' => 0, 'swap_used_pct' => 0];
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
    if (preg_match('/^Swap:\s*(.+)$/m', $top, $m)) {
        foreach (explode(',', $m[1]) as $part) {
            if (preg_match('/^\s*([0-9.]+[KMGTPE]?)\s+([A-Za-z]+)/', trim($part), $x)) {
                $label = strtolower($x[2]);
                $bytes = parse_size($x[1]);
                if (str_starts_with($label, 'total')) {
                    $mem['swap_total'] = $bytes;
                } elseif (str_starts_with($label, 'used')) {
                    $mem['swap_used'] = $bytes;
                } elseif (str_starts_with($label, 'free')) {
                    $mem['swap_free'] = $bytes;
                }
            }
        }
        if ($mem['swap_used'] <= 0 && $mem['swap_total'] > 0 && $mem['swap_free'] > 0) {
            $mem['swap_used'] = max(0, $mem['swap_total'] - $mem['swap_free']);
        }
    }
    if ($mem['total'] <= 0) {
        $mem['total'] = $mem['active'] + $mem['inactive'] + $mem['wired'] + $mem['free'];
    }
    $mem['used'] = max(0, $mem['total'] - $mem['free']);
    $mem['used_pct'] = percent($mem['used'], $mem['total']);
    $mem['swap_used_pct'] = percent((int)$mem['swap_used'], (int)$mem['swap_total']);
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
    $nominalEnv = getenv('SOCX_UPS_NOMINAL_WATTS');
    $nominalWattsRaw = ($nominalEnv !== false && trim((string)$nominalEnv) !== '')
        ? $nominalEnv
        : ((isset($raw['nominal_watts']) && is_numeric($raw['nominal_watts'])) ? $raw['nominal_watts'] : '1950');

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
        'nominal_watts' => ups_number_text($nominalWattsRaw, 0),
        'env_label1' => (string)($raw['env_label1'] ?? ''),
        'env_label2' => (string)($raw['env_label2'] ?? ''),
        'env_temp1' => ups_number_text($raw['env_temp1'] ?? null, 0),
        'env_temp2' => ups_number_text($raw['env_temp2'] ?? null, 0),
        'env_humidity1' => ups_number_text($raw['env_humidity1'] ?? null, 0),
        'env_humidity2' => ups_number_text($raw['env_humidity2'] ?? null, 0),
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

function collect_speedtest_metrics(array &$state, float $now): array
{
    if (!env_bool('SOCX_SPEEDTEST_ENABLED', true)) {
        return [
            'status' => 'disabled',
            'message' => 'Speedtest collector disabled',
            'mode' => 'off',
            'tool' => '',
            'download_mbps' => '',
            'upload_mbps' => '',
            'ping_ms' => '',
            'jitter_ms' => '',
            'server_id' => '',
            'server_name' => '',
            'server_location' => '',
            'isp' => '',
            'external_ip' => '',
            'result_url' => '',
            'interval_seconds' => speedtest_interval_seconds(),
            'age_seconds' => null,
            'fresh' => false,
            'cache_file' => '',
        ];
    }
    $cacheFile = getenv('SOCX_SPEEDTEST_CACHE_FILE') ?: '/tmp/socx-speedtest-cache.env';
    $clientFile = getenv('SOCX_SPEEDTEST_CLIENT_CACHE_FILE') ?: '/tmp/socx-speedtest-client.env';
    $routerFile = getenv('SOCX_SPEEDTEST_ROUTER_CACHE_FILE') ?: '/tmp/socx-speedtest-router.env';
    $directFile = getenv('SOCX_SPEEDTEST_DIRECT_CACHE_FILE') ?: '/tmp/socx-speedtest-direct.env';
    $vpnFile = getenv('SOCX_SPEEDTEST_VPN_CACHE_FILE') ?: '/tmp/socx-speedtest-vpn.env';
    $intervalFromEnv = (getenv('SOCX_SPEEDTEST_INTERVAL') !== false && trim((string)getenv('SOCX_SPEEDTEST_INTERVAL')) !== '')
        || (getenv('SOCX_SPEEDTEST_INTERVAL_SECONDS') !== false && trim((string)getenv('SOCX_SPEEDTEST_INTERVAL_SECONDS')) !== '');
    $activeRaw = is_readable($cacheFile) ? parse_env_file($cacheFile) : [];
    $clientRaw = is_readable($clientFile) ? parse_env_file($clientFile) : [];
    $routerRaw = is_readable($routerFile) ? parse_env_file($routerFile) : [];
    $directRaw = is_readable($directFile) ? parse_env_file($directFile) : [];
    $vpnRaw = is_readable($vpnFile) ? parse_env_file($vpnFile) : [];
    $interval = speedtest_interval_seconds();
    if (!$intervalFromEnv) {
        foreach ([$activeRaw, $clientRaw, $routerRaw, $directRaw, $vpnRaw] as $candidate) {
            if (isset($candidate['interval_seconds']) && is_numeric($candidate['interval_seconds'])) {
                $interval = (float)$candidate['interval_seconds'];
                break;
            }
        }
    }
    $client = normalize_speedtest_cache($clientRaw, $clientFile, $interval, $now);
    $router = normalize_speedtest_cache($routerRaw, $routerFile, $interval, $now);
    $direct = normalize_speedtest_cache($directRaw, $directFile, $interval, $now);
    $vpn = normalize_speedtest_cache($vpnRaw, $vpnFile, $interval, $now);
    $active = normalize_speedtest_cache($activeRaw, $cacheFile, $interval, $now);
    if (($client['status'] ?? '') === 'ok' && (($direct['status'] ?? '') !== 'ok' || (float)($client['age_seconds'] ?? 999999) <= (float)($direct['age_seconds'] ?? 999999))) {
        $direct = $client;
        $direct['profile'] = 'direct';
        $direct['mode'] = (string)($direct['mode'] ?? 'client');
    }
    $selected = $active;
    $rotate = max(2.0, (float)(getenv('SOCX_SPEEDTEST_ROTATE_SECONDS') ?: 4.0));
    $displayProfile = strtolower(trim((string)(getenv('SOCX_SPEEDTEST_DISPLAY_PROFILE') ?: 'auto')));
    $vpnRenderable = (($vpn['status'] ?? '') === 'ok' || ($vpn['status'] ?? '') === 'running' || ($vpn['status'] ?? '') === 'error');
    $directRenderable = (($direct['status'] ?? '') === 'ok' || ($direct['status'] ?? '') === 'running' || ($direct['status'] ?? '') === 'error');
    $showVpn = $vpnRenderable
        && (int)floor($now / $rotate) % 2 === 1;
    if ($displayProfile === 'vpn' && $vpnRenderable) {
        $selected = $vpn;
    } elseif ($displayProfile === 'direct' && $directRenderable) {
        $selected = $direct;
    } elseif ($showVpn) {
        $selected = $vpn;
    } elseif (($direct['status'] ?? '') === 'ok' && !empty($direct['fresh'])) {
        $selected = $direct;
    } elseif (($client['status'] ?? '') === 'ok' && !empty($client['fresh'])) {
        $selected = $client;
    } elseif (($active['status'] ?? '') !== 'ok' && ($router['status'] ?? '') === 'ok') {
        $selected = $router;
    }
    $selected['client'] = $client;
    $selected['router'] = $router;
    $selected['direct'] = $direct;
    $selected['vpn'] = $vpn;
    $selected['profile_compare'] = speedtest_profile_compare($direct, $vpn);
    $selected['baseline'] = speedtest_baseline();
    $selected['source_label'] = speedtest_source_label($selected);
    $selected['baseline_text'] = speedtest_baseline_text($selected);
    $selected['router_under_client'] = speedtest_router_under_client($client, $router);
    return $selected;
}

function normalize_speedtest_cache(array $raw, string $cacheFile, float $interval, float $now): array
{
    $updated = isset($raw['updated']) && is_numeric($raw['updated']) ? (float)$raw['updated'] : 0.0;
    $age = $updated > 0 ? max(0.0, $now - $updated) : null;
    $status = strtolower((string)($raw['status'] ?? ($raw ? 'unknown' : 'waiting')));
    $fresh = $age !== null && $status === 'ok' && $age <= max($interval * 1.5, $interval + 300.0);

    return [
        'status' => $status,
        'message' => (string)($raw['message'] ?? ''),
        'mode' => (string)($raw['mode'] ?? 'auto'),
        'profile' => (string)($raw['profile'] ?? speedtest_profile_from_cache($raw)),
        'tool' => (string)($raw['tool'] ?? ''),
        'download_mbps' => numeric_text($raw['download_mbps'] ?? ''),
        'upload_mbps' => numeric_text($raw['upload_mbps'] ?? ''),
        'ping_ms' => numeric_text($raw['ping_ms'] ?? ''),
        'jitter_ms' => numeric_text($raw['jitter_ms'] ?? ''),
        'server_id' => (string)($raw['server_id'] ?? ''),
        'server_name' => (string)($raw['server_name'] ?? ''),
        'server_location' => (string)($raw['server_location'] ?? ''),
        'isp' => (string)($raw['isp'] ?? ''),
        'external_ip' => (string)($raw['external_ip'] ?? ''),
        'result_url' => (string)($raw['result_url'] ?? ''),
        'interval_seconds' => $interval,
        'updated' => $updated > 0 ? (string)$updated : '',
        'age_seconds' => $age,
        'fresh' => $fresh,
        'cache_file' => $cacheFile,
    ];
}

function speedtest_profile_from_cache(array $raw): string
{
    $mode = strtolower((string)($raw['mode'] ?? ''));
    if (in_array($mode, ['client', 'direct', 'frontier'], true)) {
        return 'direct';
    }
    if (str_contains($mode, 'vpn')) {
        return 'vpn';
    }
    return 'auto';
}

function speedtest_profile_compare(array $direct, array $vpn): array
{
    if (strtolower((string)($direct['status'] ?? '')) !== 'ok' || strtolower((string)($vpn['status'] ?? '')) !== 'ok') {
        return ['available' => false];
    }
    $directDown = (float)($direct['download_mbps'] ?? 0);
    $directUp = (float)($direct['upload_mbps'] ?? 0);
    $vpnDown = (float)($vpn['download_mbps'] ?? 0);
    $vpnUp = (float)($vpn['upload_mbps'] ?? 0);
    $directPing = (float)($direct['ping_ms'] ?? 0);
    $vpnPing = (float)($vpn['ping_ms'] ?? 0);
    if ($directDown <= 0 || $directUp <= 0 || $vpnDown <= 0 || $vpnUp <= 0) {
        return ['available' => false];
    }
    $downPct = ($vpnDown / $directDown) * 100.0;
    $upPct = ($vpnUp / $directUp) * 100.0;
    $pingDelta = $vpnPing > 0 && $directPing > 0 ? $vpnPing - $directPing : 0.0;
    $severity = ($downPct < 35.0 || $upPct < 35.0 || $pingDelta > 80.0) ? 'WARN' : (($downPct < 55.0 || $upPct < 55.0 || $pingDelta > 40.0) ? 'LOW' : 'INFO');
    return [
        'available' => true,
        'down_pct' => $downPct,
        'up_pct' => $upPct,
        'ping_delta' => $pingDelta,
        'severity' => $severity,
    ];
}

function speedtest_router_under_client(array $client, array $router): bool
{
    if (strtolower((string)($client['status'] ?? '')) !== 'ok' || strtolower((string)($router['status'] ?? '')) !== 'ok') {
        return false;
    }
    $clientDown = (float)($client['download_mbps'] ?? 0);
    $clientUp = (float)($client['upload_mbps'] ?? 0);
    $routerDown = (float)($router['download_mbps'] ?? 0);
    $routerUp = (float)($router['upload_mbps'] ?? 0);
    if ($clientDown <= 0 || $clientUp <= 0 || $routerDown <= 0 || $routerUp <= 0) {
        return false;
    }
    return $routerDown < $clientDown * 0.70 || $routerUp < $clientUp * 0.70;
}

function update_speedtest_history(array &$state, array $speedtest, float $now): void
{
    if (strtolower((string)($speedtest['status'] ?? '')) !== 'ok') {
        return;
    }
    $updated = (string)($speedtest['updated'] ?? '');
    if ($updated === '') {
        $age = isset($speedtest['age_seconds']) && is_numeric($speedtest['age_seconds']) ? (float)$speedtest['age_seconds'] : 0.0;
        $updated = sprintf('%.3f', max(0.0, $now - $age));
    }
    if ($updated === (string)($state['speedtest_last_updated'] ?? '')) {
        return;
    }
    $down = (float)($speedtest['download_mbps'] ?? 0);
    $up = (float)($speedtest['upload_mbps'] ?? 0);
    $ping = (float)($speedtest['ping_ms'] ?? 0);
    if ($down <= 0 || $up <= 0) {
        return;
    }
    $history = is_array($state['speedtest_history'] ?? null) ? $state['speedtest_history'] : [];
    $history[] = ['t' => (float)$updated, 'down' => $down, 'up' => $up, 'ping' => $ping];
    $cutoff = $now - 86400.0;
    $history = array_values(array_filter($history, static fn(array $row): bool => (float)($row['t'] ?? 0) >= $cutoff));
    $state['speedtest_history'] = array_slice($history, -16);
    $state['speedtest_last_updated'] = $updated;
}

function speedtest_history_summary(array $history, float $now): array
{
    $history = array_values(array_filter($history, static fn(array $row): bool => (float)($row['t'] ?? 0) >= $now - 86400.0));
    if (!$history) {
        return ['count' => 0, 'avg_down' => 0, 'avg_up' => 0, 'avg_ping' => 0, 'trend' => 'stable'];
    }
    $count = count($history);
    $avgDown = array_sum(array_map(static fn(array $r): float => (float)($r['down'] ?? 0), $history)) / $count;
    $avgUp = array_sum(array_map(static fn(array $r): float => (float)($r['up'] ?? 0), $history)) / $count;
    $avgPing = array_sum(array_map(static fn(array $r): float => (float)($r['ping'] ?? 0), $history)) / $count;
    $first = $history[0];
    $last = $history[$count - 1];
    $trend = 'stable';
    if ($count >= 2) {
        $delta = ((float)($last['down'] ?? 0) + (float)($last['up'] ?? 0)) - ((float)($first['down'] ?? 0) + (float)($first['up'] ?? 0));
        if (abs($delta) > max(100.0, (($avgDown + $avgUp) * 0.05))) {
            $trend = $delta > 0 ? 'rising' : 'falling';
        }
    }
    return ['count' => $count, 'avg_down' => $avgDown, 'avg_up' => $avgUp, 'avg_ping' => $avgPing, 'trend' => $trend];
}

function wan_health_score(array $wan, array $vpn, array $speedtest, array $pulse, array $quality = []): array
{
    $score = 100;
    $reasons = [];
    $wanRate = (int)($wan['rx'] ?? 0) + (int)($wan['tx'] ?? 0);
    if ($wanRate <= 0) {
        $score -= 10;
        $reasons[] = 'idle';
    }
    $vpnStatus = strtoupper((string)($vpn['status'] ?? 'UNKNOWN'));
    if ($vpnStatus === 'DOWN') {
        $score -= 8;
        $reasons[] = 'vpn down';
    } elseif ($vpnStatus === 'PARTIAL' || $vpnStatus === 'UNKNOWN') {
        $score -= 4;
        $reasons[] = strtolower($vpnStatus ?: 'vpn unknown');
    }
    if (strtolower((string)($speedtest['status'] ?? '')) !== 'ok') {
        $score -= 8;
        $reasons[] = 'speedtest wait';
    } elseif (empty($speedtest['fresh'])) {
        $score -= 6;
        $reasons[] = 'speedtest stale';
    }
    $qualityScore = isset($quality['score']) && is_numeric($quality['score']) ? (int)$quality['score'] : 100;
    if ($qualityScore < 60) {
        $score -= 18;
        $reasons[] = 'gateway degraded';
    } elseif ($qualityScore < 85) {
        $score -= 8;
        $reasons[] = 'gateway watch';
    }
    if ((int)($pulse['fw_drops_min'] ?? 0) > 60) {
        $score -= 8;
        $reasons[] = 'scan burst';
    }
    if ((int)($pulse['dnsbl_min'] ?? 0) > 20) {
        $score -= 6;
        $reasons[] = 'dns blocks';
    }
    $score = max(0, min(100, $score));
    $label = $score >= 90 ? 'stable' : ($score >= 75 ? 'watch' : 'degraded');
    return ['score' => $score, 'label' => $label, 'reason' => implode(', ', array_slice($reasons, 0, 2))];
}

function numeric_text($value): string
{
    return is_numeric($value) ? (string)(int)round((float)$value) : '';
}

function speedtest_interval_seconds(): float
{
    $value = strtolower(trim((string)(getenv('SOCX_SPEEDTEST_INTERVAL') ?: getenv('SOCX_SPEEDTEST_INTERVAL_SECONDS') ?: '6h')));
    return match ($value) {
        '30m', '30min', '30mins' => 1800.0,
        '1h', '1hr', 'hour' => 3600.0,
        '6h', '6hr', '6hrs' => 21600.0,
        default => speedtest_interval_value($value),
    };
}

function speedtest_interval_value(string $value): float
{
    if (preg_match('/^([0-9.]+)\s*m$/', $value, $m)) {
        return max(1800.0, (float)$m[1] * 60.0);
    }
    if (preg_match('/^([0-9.]+)\s*h$/', $value, $m)) {
        return max(1800.0, (float)$m[1] * 3600.0);
    }
    return is_numeric($value) ? max(1800.0, (float)$value) : 3600.0;
}

function speedtest_status_text($speedtest, int $width): string
{
    if (!is_array($speedtest) || $width < 14) {
        return '';
    }
    $status = strtolower((string)($speedtest['status'] ?? ''));
    if ($status === 'ok') {
        $down = (string)($speedtest['download_mbps'] ?? '');
        $up = (string)($speedtest['upload_mbps'] ?? '');
        if ($down === '' || $up === '') {
            return '';
        }
        $ping = (string)($speedtest['ping_ms'] ?? '');
        $next = speedtest_countdown_text($speedtest);
        $text = sprintf('SPD %s %s/%sM', speedtest_source_label($speedtest), $down, $up);
        if ($ping !== '' && cell_len($text . ' ' . $ping . 'ms') <= $width) {
            $text .= ' ' . $ping . 'ms';
        }
        if ($next !== '' && cell_len($text . ' ' . $next) <= $width) {
            $text .= ' ' . $next;
        }
        return truncate_modern_text($text, $width);
    }
    if ($status === 'running') {
        return truncate_modern_text('SPD running', $width);
    }
    if ($status === 'disabled' || $status === 'off') {
        return truncate_modern_text('SPD off', $width);
    }
    if ($status === 'waiting' || $status === '') {
        return truncate_modern_text('SPD wait', $width);
    }
    if ($status === 'missing') {
        return truncate_modern_text('SPD missing', $width);
    }
    if ($status === 'error') {
        return truncate_modern_text('SPD error', $width);
    }
    return '';
}

function speedtest_status_event(array $speedtest): ?array
{
    $status = strtolower((string)($speedtest['status'] ?? ''));
    if ($status === '' || $status === 'waiting') {
        return null;
    }
    if ($status === 'ok') {
        if (!empty($speedtest['router_under_client'])) {
            $client = is_array($speedtest['client'] ?? null) ? $speedtest['client'] : $speedtest;
            $router = is_array($speedtest['router'] ?? null) ? $speedtest['router'] : [];
            return soc_event('SPD', 'WARN', sprintf('Client %s/%s Mbps vs router %s/%s Mbps | router CLI likely tool/server limited',
                (string)($client['download_mbps'] ?? '?'),
                (string)($client['upload_mbps'] ?? '?'),
                (string)($router['download_mbps'] ?? '?'),
                (string)($router['upload_mbps'] ?? '?')));
        }
        $compare = is_array($speedtest['profile_compare'] ?? null) ? $speedtest['profile_compare'] : [];
        if (!empty($compare['available']) && speedtest_source_label($speedtest) === 'VPN') {
            return soc_event('SPD', (string)($compare['severity'] ?? 'INFO'), sprintf('VPN Speedtest %.0f%% down / %.0f%% up of direct | latency %+dms',
                (float)($compare['down_pct'] ?? 0),
                (float)($compare['up_pct'] ?? 0),
                (int)round((float)($compare['ping_delta'] ?? 0))));
        }
        $server = trim((string)($speedtest['server_name'] ?? '') . ' ' . (string)($speedtest['server_location'] ?? ''));
        $server = $server !== '' ? $server : 'auto server';
        $down = (string)($speedtest['download_mbps'] ?? '?');
        $up = (string)($speedtest['upload_mbps'] ?? '?');
        $ping = (string)($speedtest['ping_ms'] ?? '?');
        $next = speedtest_countdown_text($speedtest);
        $fresh = !empty($speedtest['fresh']);
        return soc_event('WAN', $fresh ? 'INFO' : 'WARN',
            sprintf('Speedtest %s %s %s/%s Mbps ping %sms | next %s',
                speedtest_source_label($speedtest),
                truncate_modern_text($server, 36),
                $down !== '' ? $down : '?',
                $up !== '' ? $up : '?',
                $ping !== '' ? $ping : '?',
                speedtest_countdown_value_text($speedtest) !== '' ? speedtest_countdown_value_text($speedtest) : 'unknown'));
    }
    if ($status === 'running') {
        return soc_event('WAN', 'INFO', 'Speedtest running | bandwidth test in progress');
    }
    if ($status === 'missing') {
        return soc_event('WAN', 'WARN', 'Speedtest missing | install speedtest-cli or Ookla speedtest');
    }
    if ($status === 'error') {
        return soc_event('WAN', 'WARN', 'Speedtest failed | check WAN/VPN path and CLI');
    }
    return null;
}

function speedtest_named_paths_event(array $speedtest): ?array
{
    if (!env_bool('SOCX_SPEEDTEST_PATH_EVENTS_ENABLED', true)) {
        return null;
    }
    $paths = speedtest_named_paths();
    if (!$paths) {
        return null;
    }
    $direct = is_array($speedtest['direct'] ?? null) ? $speedtest['direct'] : [];
    $directDown = (float)($direct['download_mbps'] ?? 0);
    $directUp = (float)($direct['upload_mbps'] ?? 0);
    $directPing = (float)($direct['ping_ms'] ?? 0);
    $parts = [];
    $worst = 'INFO';
    foreach ($paths as $path) {
        if (($path['profile'] ?? '') !== 'vpn') {
            continue;
        }
        $cache = (string)($path['cache'] ?? '');
        if ($cache === '' || !is_readable($cache)) {
            $parts[] = (string)$path['label'] . ' wait';
            $worst = severity_max($worst, 'LOW');
            continue;
        }
        $row = normalize_speedtest_cache(parse_env_file($cache), $cache, speedtest_interval_seconds(), microtime(true));
        if (($row['status'] ?? '') !== 'ok') {
            $parts[] = (string)$path['label'] . ' ' . strtoupper((string)($row['status'] ?? 'wait'));
            $worst = severity_max($worst, 'LOW');
            continue;
        }
        $down = (float)($row['download_mbps'] ?? 0);
        $up = (float)($row['upload_mbps'] ?? 0);
        $ping = (float)($row['ping_ms'] ?? 0);
        $pct = ($directDown > 0 && $directUp > 0) ? sprintf(' %.0f/%.0f%%', ($down / $directDown) * 100.0, ($up / $directUp) * 100.0) : '';
        $delta = ($directPing > 0 && $ping > 0) ? sprintf(' %+dms', (int)round($ping - $directPing)) : '';
        if (($directDown > 0 && $down < $directDown * 0.35) || ($directUp > 0 && $up < $directUp * 0.35) || ($directPing > 0 && $ping - $directPing > 80)) {
            $worst = severity_max($worst, 'WARN');
        }
        $parts[] = sprintf('%s %s/%sM%s%s',
            (string)$path['label'],
            (string)($row['download_mbps'] ?? '?'),
            (string)($row['upload_mbps'] ?? '?'),
            $pct,
            $delta);
    }
    if (!$parts) {
        return null;
    }
    return soc_event('SPD', $worst, 'VPN PATHS ' . implode(' | ', array_slice($parts, 0, 3)));
}

function speedtest_named_paths(): array
{
    $file = getenv('SOCX_SPEEDTEST_PATHS_FILE') ?: '/usr/local/etc/socx_speedtest_paths.conf';
    if (!is_readable($file)) {
        $alt = __DIR__ . '/../config/socx_speedtest_paths.conf.example';
        $file = is_readable($alt) ? $alt : '';
    }
    if ($file === '') {
        return [];
    }
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = array_pad(explode('|', $line), 6, '');
        $rows[] = [
            'slug' => trim($parts[0]),
            'label' => trim($parts[1]) !== '' ? trim($parts[1]) : trim($parts[0]),
            'profile' => trim($parts[2]) !== '' ? trim($parts[2]) : 'vpn',
            'cache' => trim($parts[3]) !== '' ? trim($parts[3]) : '/tmp/socx-speedtest-vpn-' . trim($parts[0]) . '.env',
            'gateway' => trim($parts[4]),
            'notes' => trim($parts[5]),
        ];
    }
    return $rows;
}

function severity_max(string $a, string $b): string
{
    $rank = ['INFO' => 1, 'LOW' => 2, 'WARN' => 3, 'HIGH' => 4, 'CRIT' => 5];
    return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
}

function speedtest_history_event(array $summary): ?array
{
    $count = (int)($summary['count'] ?? 0);
    if ($count < 1) {
        return null;
    }
    $trend = (string)($summary['trend'] ?? 'stable');
    $severity = $trend === 'falling' ? 'WARN' : 'INFO';
    return soc_event('WAN', $severity, sprintf('Speedtest 24h avg %d/%d Mbps ping %dms | %s',
        (int)round((float)($summary['avg_down'] ?? 0)),
        (int)round((float)($summary['avg_up'] ?? 0)),
        (int)round((float)($summary['avg_ping'] ?? 0)),
        $trend));
}

function wan_health_event(array $health): array
{
    $score = (int)($health['score'] ?? 0);
    $label = (string)($health['label'] ?? 'unknown');
    $reason = trim((string)($health['reason'] ?? ''));
    $severity = $score >= 90 ? 'INFO' : ($score >= 75 ? 'LOW' : 'WARN');
    return soc_event('WAN', $severity, sprintf('WAN health %d%% %s%s',
        $score,
        $label,
        $reason !== '' ? ' | ' . $reason : ''));
}

function collect_wan_quality(array &$state, float $now): array
{
    $ttl = max(2.0, min(15.0, (float)(getenv('SOCX_WAN_QUALITY_TTL_SECONDS') ?: 5.0)));
    $cached = $state['wan_quality_cache'] ?? null;
    if (is_array($cached) && isset($cached['checked_at']) && ($now - (float)$cached['checked_at']) < $ttl) {
        $cached['age_seconds'] = max(0.0, $now - (float)$cached['checked_at']);
        $cached['from_cache'] = true;
        return $cached;
    }

    $rows = collect_wan_gateway_rows();
    $best = select_primary_wan_gateway($rows);
    if ($best === null) {
        $quality = [
            'status' => 'unknown',
            'score' => 72,
            'label' => 'unknown',
            'name' => '',
            'latency_ms' => gateway_latency_ms(),
            'loss_pct' => null,
            'reason' => 'gateway status unavailable',
            'checked_at' => $now,
            'age_seconds' => 0.0,
            'from_cache' => false,
        ];
        $state['wan_quality_cache'] = $quality;
        return $quality;
    }

    $latency = gateway_latency_from_row($best);
    $loss = gateway_loss_from_row($best);
    $status = strtolower((string)($best['status'] ?? 'unknown'));
    $score = 100;
    $reasons = [];
    if (preg_match('/down|offline|alarm|loss|unknown|pending/i', $status)) {
        $score -= 45;
        $reasons[] = $status;
    }
    if ($loss !== null) {
        if ($loss >= 10.0) {
            $score -= 35;
            $reasons[] = sprintf('loss %.0f%%', $loss);
        } elseif ($loss >= 1.0) {
            $score -= 15;
            $reasons[] = sprintf('loss %.1f%%', $loss);
        }
    }
    if ($latency !== null) {
        if ($latency >= 100.0) {
            $score -= 25;
            $reasons[] = sprintf('lat %.0fms', $latency);
        } elseif ($latency >= 40.0) {
            $score -= 10;
            $reasons[] = sprintf('lat %.0fms', $latency);
        }
    }
    $score = max(0, min(100, $score));
    $quality = [
        'status' => $status !== '' ? $status : 'unknown',
        'score' => $score,
        'label' => $score >= 90 ? 'stable' : ($score >= 70 ? 'watch' : 'degraded'),
        'name' => (string)($best['name'] ?? 'WAN'),
        'latency_ms' => $latency,
        'loss_pct' => $loss,
        'reason' => $reasons ? implode(', ', array_slice($reasons, 0, 2)) : 'gateway clean',
        'checked_at' => $now,
        'age_seconds' => 0.0,
        'from_cache' => false,
        'raw' => (string)($best['raw'] ?? ''),
    ];
    $state['wan_quality_cache'] = $quality;
    return $quality;
}

function wan_quality_event(array $quality): ?array
{
    if (!$quality) {
        return null;
    }
    $score = isset($quality['score']) && is_numeric($quality['score']) ? (int)$quality['score'] : 0;
    $severity = $score >= 90 ? 'INFO' : ($score >= 70 ? 'LOW' : 'WARN');
    $lat = isset($quality['latency_ms']) && is_numeric($quality['latency_ms'])
        ? ((float)$quality['latency_ms'] < 10.0 ? sprintf('%.1fms', (float)$quality['latency_ms']) : sprintf('%.0fms', (float)$quality['latency_ms']))
        : '?ms';
    $loss = isset($quality['loss_pct']) && is_numeric($quality['loss_pct'])
        ? sprintf('%s%%', rtrim(rtrim(sprintf('%.1f', (float)$quality['loss_pct']), '0'), '.'))
        : '?%';
    $name = truncate_text((string)($quality['name'] ?? 'WAN'), 18);
    return soc_event('WAN', $severity, sprintf('WAN quality %s %d%% | %s loss %s via %s',
        (string)($quality['label'] ?? 'unknown'),
        $score,
        $lat,
        $loss,
        $name));
}

function collect_what_changed_events(array &$state, array $flows, array $wanQuality, array $speedtest, array $piInsight, float $now): array
{
    $interval = max(5.0, (float)(getenv('SOCX_WHAT_CHANGED_INTERVAL_SECONDS') ?: 20.0));
    if (($now - (float)($state['last_what_changed_at'] ?? 0.0)) < $interval) {
        return [];
    }
    $state['last_what_changed_at'] = $now;
    $events = [];

    $top = first_useful_flow($flows);
    $topKey = $top !== null ? flow_path_text($top, 44) . ' ' . service_human_label((string)($top['service'] ?? '')) : '';
    if ($topKey !== '' && isset($state['what_top_flow']) && $topKey !== (string)$state['what_top_flow']) {
        $events[] = soc_event('CHANGE', 'INFO', sprintf('Top flow changed: %s', truncate_text($topKey, 76)));
    }
    if ($topKey !== '') {
        $state['what_top_flow'] = $topKey;
    }

    $qualityKey = (string)($wanQuality['label'] ?? '') . ':' . (string)($wanQuality['score'] ?? '');
    if ($qualityKey !== ':' && isset($state['what_wan_quality']) && $qualityKey !== (string)$state['what_wan_quality']) {
        $events[] = soc_event('CHANGE', 'LOW', sprintf('WAN quality changed: %s %s%% | %s',
            (string)($wanQuality['label'] ?? 'unknown'),
            (string)($wanQuality['score'] ?? '?'),
            (string)($wanQuality['reason'] ?? 'watch')));
    }
    if ($qualityKey !== ':') {
        $state['what_wan_quality'] = $qualityKey;
    }

    $speedKey = strtolower((string)($speedtest['status'] ?? '')) . ':' . speedtest_source_label($speedtest);
    if ($speedKey !== ':' && isset($state['what_speedtest']) && $speedKey !== (string)$state['what_speedtest']) {
        $events[] = soc_event('CHANGE', 'LOW', sprintf('Speedtest source/status changed: %s %s',
            speedtest_source_label($speedtest),
            strtoupper((string)($speedtest['status'] ?? 'unknown'))));
    }
    if ($speedKey !== ':') {
        $state['what_speedtest'] = $speedKey;
    }

    $piDisc = is_array($piInsight['discovery'] ?? null) ? $piInsight['discovery'] : [];
    $piKey = strtolower((string)($piInsight['status'] ?? 'missing')) . ':' . (string)($piInsight['roles'] ?? '') . ':' . (string)($piDisc['ip'] ?? '');
    if ($piKey !== '::' && isset($state['what_pi']) && $piKey !== (string)$state['what_pi']) {
        $events[] = soc_event('CHANGE', 'LOW', sprintf('Pi AI changed: %s roles %s%s',
            strtoupper((string)($piInsight['status'] ?? 'unknown')),
            (string)($piInsight['roles'] ?? '?'),
            !empty($piDisc['ip']) ? ' @' . (string)$piDisc['ip'] : ''));
    }
    if ($piKey !== '::') {
        $state['what_pi'] = $piKey;
    }

    $unknown = count_unknown_lan_endpoints($flows);
    if (isset($state['what_unknown_count']) && $unknown !== (int)$state['what_unknown_count']) {
        $severity = $unknown > (int)$state['what_unknown_count'] ? 'WARN' : 'INFO';
        $events[] = soc_event('CHANGE', $severity, sprintf('Unknown device count changed: %d | run socx doctor topology', $unknown));
    }
    $state['what_unknown_count'] = $unknown;

    return array_slice($events, 0, 4);
}

function count_unknown_lan_endpoints(array $flows): int
{
    $unknown = [];
    foreach ($flows as $flow) {
        foreach (['src', 'dst'] as $field) {
            $label = (string)($flow[$field] ?? '');
            if (!endpoint_is_lan($label)) {
                continue;
            }
            $key = lan_endpoint_key($label);
            if ($key !== '' && !str_contains($label, '/LAN.')) {
                $unknown[$key] = true;
            }
        }
    }
    return count($unknown);
}

function collect_wan_gateway_rows(): array
{
    $rows = [];
    foreach ([
        '/usr/local/sbin/configctl interface gatewaystatus',
        'configctl interface gatewaystatus',
        '/usr/local/sbin/pfSsh.php playback gatewaystatus',
    ] as $cmd) {
        $out = trim(run_cmd($cmd . ' 2>/dev/null'));
        if ($out === '') {
            continue;
        }
        foreach (parse_wan_gateway_status_output($out) as $name => $row) {
            $rows[$name] = $row;
        }
        if ($rows) {
            break;
        }
    }
    foreach (glob('/var/run/dpinger*.status') ?: [] as $file) {
        $text = is_readable($file) ? trim((string)@file_get_contents($file)) : '';
        if ($text === '') {
            continue;
        }
        $name = vpn_gateway_name_from_dpinger_file($file);
        if ($name === '') {
            $name = basename($file);
        }
        $key = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $name) ?? $name);
        $row = gateway_row_from_dpinger_status($name, $text);
        $row['latency_ms'] = gateway_latency_from_text($text);
        $rows[$key] = $row;
    }
    return $rows;
}

function parse_wan_gateway_status_output(string $raw): array
{
    $rows = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim(strip_ansi($line));
        if ($line === '' || preg_match('/^(Name|=|-)/i', $line)) {
            continue;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) < 2) {
            continue;
        }
        $name = (string)$parts[0];
        if ($name === '') {
            continue;
        }
        $loss = '';
        $status = '';
        foreach ($parts as $idx => $part) {
            if (preg_match('/^\d+(?:\.\d+)?%$/', $part)) {
                $loss = $part;
                $status = (string)($parts[$idx + 1] ?? '');
                break;
            }
        }
        if ($status === '') {
            $status = (string)end($parts);
        }
        $key = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $name) ?? $name);
        $rows[$key] = [
            'name' => $name,
            'status' => strtolower($status),
            'loss' => $loss,
            'latency_ms' => gateway_latency_from_text($line),
            'raw' => truncate_text($line, 160),
        ];
    }
    return $rows;
}

function select_primary_wan_gateway(array $rows): ?array
{
    if (!$rows) {
        return null;
    }
    foreach ($rows as $key => $row) {
        $hay = strtoupper((string)$key . ' ' . (string)($row['name'] ?? ''));
        if (str_contains($hay, 'WAN')) {
            return $row;
        }
    }
    return reset($rows) ?: null;
}

function gateway_loss_from_row(array $row): ?float
{
    $loss = (string)($row['loss'] ?? '');
    if (preg_match('/([0-9.]+)/', $loss, $m)) {
        return (float)$m[1];
    }
    $raw = (string)($row['raw'] ?? '');
    if (preg_match('/([0-9.]+)\s*%/', $raw, $m)) {
        return (float)$m[1];
    }
    return null;
}

function gateway_latency_from_row(array $row): ?float
{
    if (isset($row['latency_ms']) && is_numeric($row['latency_ms'])) {
        return (float)$row['latency_ms'];
    }
    return gateway_latency_from_text((string)($row['raw'] ?? ''));
}

function gateway_latency_from_text(string $text): ?float
{
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*ms/i', $text, $m)) {
        return (float)$m[1];
    }
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    foreach ($parts as $part) {
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $part)) {
            $value = (float)$part;
            if ($value > 0 && $value < 10000) {
                return $value;
            }
        }
    }
    return null;
}

function speedtest_age_text(array $speedtest): string
{
    if (!isset($speedtest['age_seconds']) || !is_numeric($speedtest['age_seconds'])) {
        return '';
    }
    return format_age_seconds((float)$speedtest['age_seconds']);
}

function speedtest_countdown_text(array $speedtest): string
{
    $value = speedtest_countdown_value_text($speedtest);
    return $value !== '' ? 'next ' . $value : '';
}

function speedtest_countdown_value_text(array $speedtest): string
{
    if (!isset($speedtest['age_seconds'], $speedtest['interval_seconds'])
        || !is_numeric($speedtest['age_seconds'])
        || !is_numeric($speedtest['interval_seconds'])) {
        return '';
    }
    $remaining = (float)$speedtest['interval_seconds'] - (float)$speedtest['age_seconds'];
    if ($remaining <= 1.0) {
        return 'due';
    }
    return format_countdown_seconds($remaining);
}

function format_countdown_seconds(float $seconds): string
{
    $seconds = max(0, (int)ceil($seconds));
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return (string)(int)ceil($seconds / 60) . 'm';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = (int)ceil(($seconds % 3600) / 60);
    if ($minutes >= 60) {
        $hours++;
        $minutes = 0;
    }
    return sprintf('%dh%02d', $hours, $minutes);
}

function health_badges(array $ups, array $context = []): array
{
    $wan = 'WAN UP' . latency_suffix($context['wan_latency_ms'] ?? null);
    $vpn = vpn_badge_text($context['vpn_status'] ?? null);
    $dns = 'DNS OK' . latency_suffix($context['dns_latency_ms'] ?? null);
    $data = vpn_data_badge_text($context['vpn_status'] ?? null);
    if (empty($ups['online'])) {
        return [$wan, $vpn, $dns, 'UPS --', $data];
    }
    $battery = is_numeric($ups['battery'] ?? null) ? (string)(int)round((float)$ups['battery']) . '%' : 'OK';
    $runtime = (string)($ups['runtime'] ?? '');
    $upsText = trim('UPS ' . $battery . ' ' . ($runtime !== '?' ? $runtime : ''));
    return [$wan, $vpn, $dns, $upsText, $data];
}

function latency_suffix($value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    $ms = max(0.0, (float)$value);
    return ' ' . ($ms < 10 ? sprintf('%.1fms', $ms) : sprintf('%.0fms', $ms));
}

function env_latency_ms(string $name): ?float
{
    $value = getenv($name);
    return is_numeric($value) ? (float)$value : null;
}

function gateway_latency_ms(): ?float
{
    $env = env_latency_ms('SOCX_WAN_LATENCY_MS');
    if ($env !== null) {
        return $env;
    }
    foreach (['/var/run/dpinger*.status', '/tmp/dpinger*.status'] as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            $raw = is_readable($file) ? trim((string)@file_get_contents($file)) : '';
            if ($raw !== '' && preg_match('/([0-9.]+)\s*ms/i', $raw, $m)) {
                return (float)$m[1];
            }
        }
    }
    return null;
}

function collect_vpn_status(array &$state, float $now): array
{
    $ttl = vpn_status_ttl_seconds();
    $cached = $state['vpn_status_cache'] ?? null;
    if (is_array($cached) && isset($cached['checked_at']) && ($now - (float)$cached['checked_at']) < $ttl) {
        $cached['age_seconds'] = max(0.0, $now - (float)$cached['checked_at']);
        $cached['from_cache'] = true;
        $cached['data_fresh'] = ($cached['status'] ?? 'UNKNOWN') !== 'UNKNOWN';
        return $cached;
    }

    $status = compute_vpn_status($now);
    $state['vpn_status_cache'] = $status;
    log_vpn_status_debug($status);
    return $status;
}

function vpn_status_ttl_seconds(): float
{
    $ttl = getenv('SOCX_VPN_STATUS_TTL_SECONDS');
    $seconds = is_numeric($ttl) ? (float)$ttl : 2.0;
    return max(0.5, min(5.0, $seconds));
}

function compute_vpn_status(float $now): array
{
    [$targets, $targetError] = discover_vpn_targets();
    $raw = [
        'target_error' => $targetError,
        'targets' => array_map(static fn(array $target): string => vpn_target_summary($target), $targets),
        'commands' => [],
    ];

    if ($targetError !== '') {
        return vpn_unknown_status($now, $raw, $targetError);
    }
    if (!$targets) {
        return [
            'status' => 'N/A',
            'online' => 0,
            'total' => 0,
            'details' => [],
            'checked_at' => $now,
            'age_seconds' => 0.0,
            'data_fresh' => true,
            'from_cache' => false,
            'source' => 'config',
            'reason' => 'no VPN gateways/interfaces configured',
            'raw' => $raw,
        ];
    }

    [$gatewayRows, $gatewayRaw] = collect_gateway_status_rows();
    [$ifStates, $ifRaw] = collect_ifconfig_states();
    [$wgStates, $wgRaw] = collect_wireguard_handshakes($targets);
    $raw['commands'] = array_merge($gatewayRaw, [$ifRaw, $wgRaw]);

    if (!$ifRaw['ok'] && !$gatewayRows) {
        return vpn_unknown_status($now, $raw, 'cannot refresh interface or gateway status', count($targets));
    }

    $details = [];
    $online = 0;
    foreach ($targets as $target) {
        $detail = evaluate_vpn_target($target, $gatewayRows, $ifStates, $wgStates);
        if ($detail['status'] === 'UP') {
            $online++;
        }
        $details[] = $detail;
    }

    $total = count($details);
    $status = match (true) {
        $total === 0 => 'N/A',
        $online === $total => 'UP',
        $online === 0 => 'DOWN',
        default => 'PARTIAL',
    };

    return [
        'status' => $status,
        'online' => $online,
        'total' => $total,
        'details' => $details,
        'checked_at' => $now,
        'age_seconds' => 0.0,
        'data_fresh' => true,
        'from_cache' => false,
        'source' => 'runtime',
        'reason' => vpn_status_reason($status, $online, $total),
        'raw' => $raw,
    ];
}

function vpn_unknown_status(float $now, array $raw, string $reason, int $total = 0): array
{
    return [
        'status' => 'UNKNOWN',
        'online' => 0,
        'total' => $total,
        'details' => [],
        'checked_at' => $now,
        'age_seconds' => 0.0,
        'data_fresh' => false,
        'from_cache' => false,
        'source' => 'unknown',
        'reason' => $reason,
        'raw' => $raw,
    ];
}

function discover_vpn_targets(): array
{
    $file = getenv('SOCX_PFSENSE_CONFIG') ?: '/conf/config.xml';
    if (!is_readable($file)) {
        return [[], 'pfSense config unreadable'];
    }
    $raw = (string)@file_get_contents($file);
    if ($raw === '') {
        return [[], 'pfSense config empty'];
    }

    if (function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string($raw);
        if ($xml !== false) {
            return [vpn_targets_from_xml($xml), ''];
        }
    }

    $targets = vpn_targets_from_config_text($raw);
    return [$targets, ''];
}

function vpn_targets_from_xml(SimpleXMLElement $xml): array
{
    $gateways = [];
    if (isset($xml->gateways->gateway_item)) {
        foreach ($xml->gateways->gateway_item as $gateway) {
            $name = trim((string)$gateway->name);
            if ($name === '') {
                continue;
            }
            $gateways[$name] = [
                'name' => $name,
                'logical' => trim((string)$gateway->interface),
                'gateway' => trim((string)$gateway->gateway),
                'monitor' => trim((string)$gateway->monitor),
                'descr' => trim((string)$gateway->descr),
                'disabled' => isset($gateway->disabled),
            ];
        }
    }

    $targets = [];
    if (isset($xml->interfaces)) {
        foreach ($xml->interfaces->children() as $logical => $iface) {
            $target = vpn_target_from_interface((string)$logical, [
                'ifname' => trim((string)$iface->if),
                'descr' => trim((string)$iface->descr),
                'gateway_name' => trim((string)$iface->gateway),
                'enabled' => isset($iface->enable),
            ], $gateways);
            if ($target !== null) {
                $targets[] = $target;
            }
        }
    }

    foreach ($gateways as $gateway) {
        if (!vpn_gateway_target_candidate($gateway)) {
            continue;
        }
        $target = [
            'label' => vpn_clean_label($gateway['descr'] !== '' ? $gateway['descr'] : $gateway['name']),
            'logical' => $gateway['logical'],
            'ifname' => '',
            'gateway_name' => $gateway['name'],
            'gateway' => $gateway['gateway'],
            'monitor' => $gateway['monitor'],
            'type' => 'gateway',
            'gateway_disabled' => (bool)$gateway['disabled'],
        ];
        $targets[] = $target;
    }

    return vpn_dedupe_targets($targets);
}

function vpn_targets_from_config_text(string $raw): array
{
    $gateways = [];
    if (preg_match('/<gateways>(.*?)<\/gateways>/s', $raw, $gm)) {
        preg_match_all('/<gateway_item>(.*?)<\/gateway_item>/s', $gm[1], $blocks);
        foreach ($blocks[1] as $block) {
            $name = xml_tag_value($block, 'name');
            if ($name === '') {
                continue;
            }
            $gateways[$name] = [
                'name' => $name,
                'logical' => xml_tag_value($block, 'interface'),
                'gateway' => xml_tag_value($block, 'gateway'),
                'monitor' => xml_tag_value($block, 'monitor'),
                'descr' => xml_tag_value($block, 'descr'),
                'disabled' => str_contains($block, '<disabled'),
            ];
        }
    }

    $targets = [];
    if (preg_match('/<interfaces>(.*?)<\/interfaces>/s', $raw, $im)) {
        preg_match_all('/<([a-z0-9_]+)>(.*?)<\/\1>/is', $im[1], $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            $logical = strtolower((string)$block[1]);
            $body = (string)$block[2];
            $target = vpn_target_from_interface($logical, [
                'ifname' => xml_tag_value($body, 'if'),
                'descr' => xml_tag_value($body, 'descr'),
                'gateway_name' => xml_tag_value($body, 'gateway'),
                'enabled' => str_contains($body, '<enable'),
            ], $gateways);
            if ($target !== null) {
                $targets[] = $target;
            }
        }
    }

    foreach ($gateways as $gateway) {
        if (vpn_gateway_target_candidate($gateway)) {
            $targets[] = [
                'label' => vpn_clean_label($gateway['descr'] !== '' ? $gateway['descr'] : $gateway['name']),
                'logical' => $gateway['logical'],
                'ifname' => '',
                'gateway_name' => $gateway['name'],
                'gateway' => $gateway['gateway'],
                'monitor' => $gateway['monitor'],
                'type' => 'gateway',
                'gateway_disabled' => (bool)$gateway['disabled'],
            ];
        }
    }

    return vpn_dedupe_targets($targets);
}

function vpn_target_from_interface(string $logical, array $iface, array $gateways): ?array
{
    $logical = strtolower($logical);
    if (in_array($logical, ['wan', 'lan'], true) || empty($iface['enabled'])) {
        return null;
    }
    $ifname = trim((string)($iface['ifname'] ?? ''));
    $descr = vpn_clean_label((string)($iface['descr'] ?? ''));
    $gatewayName = trim((string)($iface['gateway_name'] ?? ''));
    $gateway = $gateways[$gatewayName] ?? null;
    $needle = implode(' ', [$logical, $ifname, $descr, $gatewayName, (string)($gateway['descr'] ?? '')]);
    if (!vpn_name_matches($needle) && !vpn_interface_name($ifname) && !vpn_gateway_name($gatewayName)) {
        return null;
    }

    return [
        'label' => $descr !== '' ? $descr : ($gatewayName !== '' ? $gatewayName : $ifname),
        'logical' => $logical,
        'ifname' => $ifname,
        'gateway_name' => $gatewayName,
        'gateway' => (string)($gateway['gateway'] ?? ''),
        'monitor' => (string)($gateway['monitor'] ?? ''),
        'type' => vpn_interface_name($ifname) ? 'interface' : 'gateway',
        'gateway_disabled' => (bool)($gateway['disabled'] ?? false),
    ];
}

function vpn_gateway_target_candidate(array $gateway): bool
{
    $name = (string)($gateway['name'] ?? '');
    $logical = strtolower((string)($gateway['logical'] ?? ''));
    if ($name === '' || in_array($logical, ['wan', 'lan'], true) || vpn_norm($name) === 'WANDHCP') {
        return false;
    }
    $needle = implode(' ', [$name, $logical, (string)($gateway['descr'] ?? ''), (string)($gateway['gateway'] ?? '')]);
    return vpn_name_matches($needle) || vpn_gateway_name($name);
}

function vpn_dedupe_targets(array $targets): array
{
    $deduped = [];
    foreach ($targets as $target) {
        $label = vpn_clean_label((string)($target['label'] ?? 'VPN'));
        if ($label === '' || preg_match('/^WAN(_DHCP)?$/i', $label)) {
            continue;
        }
        $target['label'] = $label;
        $gateway = vpn_norm((string)($target['gateway_name'] ?? ''));
        $logical = vpn_norm((string)($target['logical'] ?? ''));
        $ifname = vpn_norm((string)($target['ifname'] ?? ''));
        $key = $gateway !== '' ? 'GW:' . $gateway : ($logical !== '' ? 'LOGIC:' . $logical : ($ifname !== '' ? 'IF:' . $ifname : 'LABEL:' . vpn_norm($label)));
        if (isset($deduped[$key]) && (string)($deduped[$key]['ifname'] ?? '') !== '' && (string)($target['ifname'] ?? '') === '') {
            continue;
        }
        $deduped[$key] = $target;
    }
    return array_values($deduped);
}

function collect_gateway_status_rows(): array
{
    $rows = [];
    $raw = [];

    $configctl = vpn_command('/usr/local/sbin/configctl interface gatewaystatus', 1);
    if (!$configctl['ok'] && trim($configctl['output']) === '') {
        $configctl = vpn_command('configctl interface gatewaystatus', 1);
    }
    $raw[] = vpn_command_debug('configctl', $configctl);
    if ($configctl['ok']) {
        foreach (parse_gateway_status_output($configctl['output']) as $name => $row) {
            $rows[$name] = $row;
        }
    }

    foreach (glob('/var/run/dpinger*.status') ?: [] as $file) {
        $text = is_readable($file) ? trim((string)@file_get_contents($file)) : '';
        if ($text === '') {
            continue;
        }
        $name = vpn_gateway_name_from_dpinger_file($file);
        if ($name !== '') {
            $rows[vpn_norm($name)] = gateway_row_from_dpinger_status($name, $text);
        }
    }

    if (!$rows || env_bool('SOCX_VPN_USE_PFSSH', false)) {
        $pfssh = vpn_command('/usr/local/sbin/pfSsh.php playback gatewaystatus', 2);
        $raw[] = vpn_command_debug('pfSsh gatewaystatus', $pfssh);
        foreach (parse_gateway_status_output($pfssh['output']) as $name => $row) {
            $rows[$name] = $row;
        }
    }

    return [$rows, $raw];
}

function parse_gateway_status_output(string $raw): array
{
    $rows = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim(strip_ansi($line));
        if ($line === '' || preg_match('/^(Name|=|-)/i', $line)) {
            continue;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) < 2) {
            continue;
        }
        $name = (string)$parts[0];
        if (vpn_norm($name) === '' || vpn_norm($name) === 'WANDHCP') {
            continue;
        }
        $loss = '';
        $status = '';
        foreach ($parts as $idx => $part) {
            if (preg_match('/^\d+(?:\.\d+)?%$/', $part)) {
                $loss = $part;
                $status = (string)($parts[$idx + 1] ?? '');
                break;
            }
        }
        if ($loss === '') {
            continue;
        }
        if ($status === '') {
            $status = (string)end($parts);
        }
        $rows[vpn_norm($name)] = [
            'name' => $name,
            'status' => strtolower($status),
            'loss' => $loss,
            'raw' => truncate_text($line, 160),
        ];
    }
    return $rows;
}

function gateway_row_from_dpinger_status(string $name, string $raw): array
{
    $lower = strtolower($raw);
    $loss = '';
    if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $raw, $m)) {
        $loss = $m[1] . '%';
    }
    $status = preg_match('/\b(down|offline|alarm|loss)\b/i', $lower) ? 'down' : 'online';
    return [
        'name' => $name,
        'status' => $status,
        'loss' => $loss,
        'raw' => truncate_text($raw, 160),
    ];
}

function vpn_gateway_name_from_dpinger_file(string $file): string
{
    $base = basename($file);
    if (preg_match('/dpinger_([^~.]+)/', $base, $m)) {
        return $m[1];
    }
    return '';
}

function collect_ifconfig_states(): array
{
    $cmd = vpn_command('/sbin/ifconfig -a', 1);
    $states = [];
    $current = '';
    foreach (explode("\n", $cmd['output']) as $line) {
        if (preg_match('/^([A-Za-z0-9_.:-]+):\s+flags=([^<]*<([^>]*)>)?/i', $line, $m)) {
            $current = rtrim($m[1], ':');
            $states[$current] = [
                'exists' => true,
                'flags' => strtoupper((string)($m[3] ?? '')),
                'status' => '',
                'raw' => trim($line),
            ];
            continue;
        }
        if ($current === '') {
            continue;
        }
        $trimmed = trim($line);
        $states[$current]['raw'] .= ' ' . $trimmed;
        if (preg_match('/status:\s*(\S+)/i', $line, $m)) {
            $states[$current]['status'] = strtolower($m[1]);
        }
    }
    return [$states, vpn_command_debug('ifconfig', $cmd)];
}

function collect_wireguard_handshakes(array $targets): array
{
    $needsWireGuard = false;
    foreach ($targets as $target) {
        if (vpn_wireguard_target($target)) {
            $needsWireGuard = true;
            break;
        }
    }
    if (!$needsWireGuard) {
        return [[], ['source' => 'wg', 'ok' => true, 'sample' => 'not needed']];
    }

    $cmd = vpn_command('/usr/bin/wg show all latest-handshakes', 1);
    if (!$cmd['ok'] && trim($cmd['output']) === '') {
        $cmd = vpn_command('/usr/local/bin/wg show all latest-handshakes', 1);
    }
    $states = parse_wireguard_handshakes($cmd['output']);
    return [$states, vpn_command_debug('wg latest-handshakes', $cmd)];
}

function parse_wireguard_handshakes(string $raw): array
{
    $rows = [];
    $now = time();
    foreach (explode("\n", $raw) as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 3 || !is_numeric($parts[2])) {
            continue;
        }
        $iface = $parts[0];
        $ts = (int)$parts[2];
        $age = $ts > 0 ? max(0, $now - $ts) : null;
        if (!isset($rows[$iface]) || ($age !== null && $age < (float)($rows[$iface]['age'] ?? INF))) {
            $rows[$iface] = ['age' => $age, 'timestamp' => $ts];
        }
    }
    return $rows;
}

function evaluate_vpn_target(array $target, array $gatewayRows, array $ifStates, array $wgStates): array
{
    $label = vpn_clean_label((string)($target['label'] ?? 'VPN'));
    $gatewayName = (string)($target['gateway_name'] ?? '');
    $ifname = (string)($target['ifname'] ?? '');
    $gatewayRow = $gatewayName !== '' ? ($gatewayRows[vpn_norm($gatewayName)] ?? null) : null;
    $iface = $ifname !== '' ? ($ifStates[$ifname] ?? null) : null;
    $reasons = [];

    if ($gatewayName !== '') {
        if ($gatewayRow === null) {
            return vpn_detail($target, 'DOWN', 'missing monitor');
        }
        if (!gateway_row_is_online($gatewayRow)) {
            return vpn_detail($target, 'DOWN', 'gateway ' . gateway_row_reason($gatewayRow));
        }
        $reasons[] = 'gateway online';
    }

    if ($ifname !== '') {
        if ($iface === null) {
            return vpn_detail($target, 'DOWN', 'interface missing');
        }
        if (!interface_state_online($iface)) {
            return vpn_detail($target, 'DOWN', 'interface ' . interface_state_reason($iface));
        }
        $reasons[] = 'interface active';
    }

    if (vpn_wireguard_target($target)) {
        $wg = $ifname !== '' ? ($wgStates[$ifname] ?? null) : null;
        if ($wg === null) {
            if ($gatewayRow !== null && gateway_row_is_online($gatewayRow)) {
                return vpn_detail($target, 'UP', implode(', ', array_merge($reasons, ['handshake not reported'])));
            }
            return vpn_detail($target, 'DOWN', 'no WireGuard handshake');
        }
        $age = $wg['age'];
        if ($age === null || (float)$age > vpn_wireguard_handshake_max_age()) {
            if ($gatewayRow !== null && gateway_row_is_online($gatewayRow)) {
                $reasons[] = $age === null ? 'handshake not reported' : 'handshake stale ' . format_age_seconds((float)$age);
                return vpn_detail($target, 'UP', implode(', ', $reasons));
            }
            return vpn_detail($target, 'DOWN', $age === null ? 'no WireGuard handshake' : 'handshake stale ' . format_age_seconds((float)$age));
        }
        $reasons[] = 'handshake ' . format_age_seconds((float)$age);
    }

    if (!$reasons) {
        return vpn_detail($target, 'UNKNOWN', 'no usable runtime signal');
    }
    return vpn_detail($target, 'UP', implode(', ', $reasons));
}

function gateway_row_is_online(array $row): bool
{
    $status = strtolower((string)($row['status'] ?? ''));
    $loss = (string)($row['loss'] ?? '');
    if (preg_match('/100(?:\.0+)?%/', $loss)) {
        return false;
    }
    if (preg_match('/\b(down|offline|alarm|alert|loss|fail|unknown)\b/i', $status . ' ' . ($row['raw'] ?? ''))) {
        return false;
    }
    return preg_match('/\b(online|up|none|ok)\b/i', $status . ' ' . ($row['raw'] ?? '')) === 1;
}

function gateway_row_reason(array $row): string
{
    $loss = (string)($row['loss'] ?? '');
    if (preg_match('/100(?:\.0+)?%/', $loss)) {
        return '100% loss';
    }
    $status = trim((string)($row['status'] ?? ''));
    return $status !== '' ? $status : 'down';
}

function interface_state_online(array $iface): bool
{
    $flags = strtoupper((string)($iface['flags'] ?? ''));
    $status = strtolower((string)($iface['status'] ?? ''));
    if ($status === 'no' || str_contains($status, 'no carrier') || str_contains($status, 'down')) {
        return false;
    }
    if ($status === 'active') {
        return true;
    }
    return str_contains($flags, 'UP') && str_contains($flags, 'RUNNING');
}

function interface_state_reason(array $iface): string
{
    $status = trim((string)($iface['status'] ?? ''));
    if ($status !== '') {
        return $status;
    }
    $flags = trim((string)($iface['flags'] ?? ''));
    return $flags !== '' ? strtolower($flags) : 'down';
}

function vpn_detail(array $target, string $status, string $reason): array
{
    return [
        'label' => vpn_clean_label((string)($target['label'] ?? 'VPN')),
        'status' => $status,
        'reason' => $reason,
        'gateway' => (string)($target['gateway_name'] ?? ''),
        'ifname' => (string)($target['ifname'] ?? ''),
    ];
}

function vpn_status_reason(string $status, int $online, int $total): string
{
    return match ($status) {
        'UP' => sprintf('all %d VPN targets online', $total),
        'PARTIAL' => sprintf('%d/%d VPN targets online', $online, $total),
        'DOWN' => sprintf('0/%d VPN targets online', $total),
        'N/A' => 'no VPN targets configured',
        default => 'VPN status unknown',
    };
}

function vpn_badge_text($status): string
{
    if (!is_array($status)) {
        return 'VPN UNKNOWN';
    }
    $state = strtoupper((string)($status['status'] ?? 'UNKNOWN'));
    $online = (int)($status['online'] ?? 0);
    $total = (int)($status['total'] ?? 0);
    return match ($state) {
        'UP' => sprintf('VPN UP %d/%d', $online, $total),
        'PARTIAL' => sprintf('VPN PARTIAL %d/%d', $online, $total),
        'DOWN' => sprintf('VPN DOWN %d/%d', $online, $total),
        'N/A' => 'VPN N/A',
        default => 'VPN UNKNOWN',
    };
}

function vpn_data_badge_text($status): string
{
    if (!is_array($status)) {
        return 'DATA STALE';
    }
    return !empty($status['data_fresh']) ? 'DATA LIVE' : 'DATA STALE';
}

function vpn_status_event(array $status): ?array
{
    $state = strtoupper((string)($status['status'] ?? 'UNKNOWN'));
    if ($state === 'N/A') {
        return soc_event('VPN', 'LOW', 'VPN N/A | no VPN gateways/interfaces configured');
    }
    if ($state === 'UNKNOWN') {
        return soc_event('VPN', 'WARN', 'VPN status unknown | data stale: ' . (string)($status['reason'] ?? 'refresh failed'));
    }
    $online = (int)($status['online'] ?? 0);
    $total = (int)($status['total'] ?? 0);
    $details = vpn_details_text((array)($status['details'] ?? []), 92);
    $severity = match ($state) {
        'UP' => 'INFO',
        'PARTIAL' => 'WARN',
        'DOWN' => 'HIGH',
        default => 'WARN',
    };
    return soc_event('VPN', $severity, sprintf('%d/%d VPN gateways online: %s', $online, $total, $details !== '' ? $details : strtolower($state)));
}

function vpn_details_text(array $details, int $width): string
{
    $parts = [];
    foreach ($details as $detail) {
        $label = vpn_clean_label((string)($detail['label'] ?? 'VPN'));
        $state = strtolower((string)($detail['status'] ?? 'unknown'));
        $parts[] = $label . ' ' . $state;
    }
    return truncate_modern_text(implode(', ', $parts), $width);
}

function vpn_header_details($status, int $width): string
{
    if (!is_array($status)) {
        return '';
    }
    $state = strtoupper((string)($status['status'] ?? ''));
    if ($state === 'N/A') {
        return 'VPN: n/a';
    }
    if ($state === 'UNKNOWN') {
        return 'VPN: unknown';
    }
    $details = (array)($status['details'] ?? []);
    if (!$details) {
        return '';
    }
    $parts = [];
    foreach ($details as $detail) {
        $parts[] = vpn_short_label((string)($detail['label'] ?? 'VPN')) . ' ' . strtolower((string)($detail['status'] ?? 'unknown'));
    }
    return truncate_modern_text('VPN: ' . implode(' | ', $parts), $width);
}

function vpn_short_label(string $label): string
{
    $label = vpn_clean_label($label);
    $upper = strtoupper($label);
    return match (true) {
        $upper === 'NYCVPN' => 'NYC',
        $upper === 'RCNVPN' => 'RCN',
        $upper === 'RCNVPN2' => 'RCN2',
        str_ends_with($upper, 'VPN') && strlen($label) > 3 => substr($label, 0, -3),
        default => truncate_text($label, 8),
    };
}

function log_vpn_status_debug(array $status): void
{
    $path = getenv('SOCX_VPN_DEBUG_LOG') ?: '/tmp/socx-wall-vpn.log';
    $raw = $status['raw'] ?? [];
    $commands = [];
    foreach ((array)($raw['commands'] ?? []) as $cmd) {
        if (is_array($cmd)) {
            $commands[] = sprintf('%s ok=%s sample=%s',
                (string)($cmd['source'] ?? 'cmd'),
                !empty($cmd['ok']) ? '1' : '0',
                vpn_sanitize_debug((string)($cmd['sample'] ?? '')));
        }
    }
    $details = vpn_details_text((array)($status['details'] ?? []), 180);
    $line = sprintf("[%s] status=%s online=%d total=%d fresh=%s age=%.2fs reason=%s targets=%s raw=%s details=%s\n",
        date('c'),
        (string)($status['status'] ?? 'UNKNOWN'),
        (int)($status['online'] ?? 0),
        (int)($status['total'] ?? 0),
        !empty($status['data_fresh']) ? 'yes' : 'no',
        (float)($status['age_seconds'] ?? 0.0),
        vpn_sanitize_debug((string)($status['reason'] ?? '')),
        vpn_sanitize_debug(implode('; ', (array)($raw['targets'] ?? []))),
        vpn_sanitize_debug(implode(' | ', $commands)),
        vpn_sanitize_debug($details)
    );
    if (is_file($path) && @filesize($path) !== false && (int)@filesize($path) > 262144) {
        @file_put_contents($path, '');
    }
    @file_put_contents($path, $line, FILE_APPEND);
}

function vpn_command(string $cmd, int $timeoutSeconds): array
{
    $timeout = is_executable('/bin/timeout') ? '/bin/timeout ' . max(1, $timeoutSeconds) . ' ' : '';
    $out = [];
    $code = 1;
    @exec($timeout . '/bin/sh -c ' . escapeshellarg($cmd . ' 2>&1'), $out, $code);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => implode("\n", $out),
    ];
}

function vpn_command_debug(string $source, array $result): array
{
    return [
        'source' => $source,
        'ok' => !empty($result['ok']),
        'code' => (int)($result['code'] ?? 1),
        'sample' => truncate_text(str_replace("\n", ' | ', trim((string)($result['output'] ?? ''))), 240),
    ];
}

function vpn_wireguard_target(array $target): bool
{
    return vpn_interface_name((string)($target['ifname'] ?? '')) && preg_match('/(^tun_wg|^wg|wireguard|WG)/i', (string)($target['ifname'] ?? '') . ' ' . (string)($target['label'] ?? '')) === 1;
}

function vpn_wireguard_handshake_max_age(): float
{
    $age = getenv('SOCX_VPN_WG_HANDSHAKE_MAX_SECONDS');
    return is_numeric($age) ? max(30.0, (float)$age) : 180.0;
}

function vpn_name_matches(string $text): bool
{
    return preg_match('/(VPN|WG|WireGuard|NYC|RCN|TorGuard)/i', $text) === 1;
}

function vpn_gateway_name(string $name): bool
{
    $norm = vpn_norm($name);
    if ($norm === '' || $norm === 'WANDHCP') {
        return false;
    }
    return preg_match('/(VPN|WG|WIREGUARD|NYC|RCN|TORGUARD)/', $norm) === 1;
}

function vpn_interface_name(string $ifname): bool
{
    return preg_match('/^(tun_wg|wg|ovpn|tun|ipsec|gif|gre|enc)/i', trim($ifname)) === 1;
}

function vpn_clean_label(string $label): string
{
    $label = html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5);
    $label = preg_replace('/[^\w.\-]/', '', $label) ?? '';
    return $label !== '' ? $label : 'VPN';
}

function vpn_norm(string $text): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $text) ?? '');
}

function vpn_target_summary(array $target): string
{
    return sprintf('%s if=%s gw=%s',
        vpn_clean_label((string)($target['label'] ?? 'VPN')),
        (string)($target['ifname'] ?? ''),
        (string)($target['gateway_name'] ?? ''));
}

function vpn_sanitize_debug(string $text): string
{
    $text = preg_replace('/[A-Za-z0-9+\/=]{32,}/', '<key>', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return truncate_text(trim($text), 400);
}

function format_age_seconds(float $seconds): string
{
    if ($seconds < 60) {
        return (string)(int)round($seconds) . 's';
    }
    if ($seconds < 3600) {
        return (string)(int)floor($seconds / 60) . 'm' . str_pad((string)((int)$seconds % 60), 2, '0', STR_PAD_LEFT);
    }
    return (string)(int)floor($seconds / 3600) . 'h' . str_pad((string)((int)floor($seconds / 60) % 60), 2, '0', STR_PAD_LEFT);
}

function xml_tag_value(string $block, string $tag): string
{
    if (!preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s', $block, $m)) {
        return '';
    }
    return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
}

function pf_search_rate_number(array $pf): float
{
    $rate = trim(str_replace('/s', '', (string)($pf['searches_rate'] ?? '0')));
    return is_numeric($rate) ? (float)$rate : 0.0;
}

function update_metric_trends(array &$state, array $values): array
{
    $prev = is_array($state['trend_prev'] ?? null) ? $state['trend_prev'] : [];
    $trends = [];
    foreach ($values as $key => $value) {
        $current = is_numeric($value) ? (float)$value : 0.0;
        if (!array_key_exists($key, $prev)) {
            $trends[$key] = 'stable';
            continue;
        }
        $old = (float)$prev[$key];
        $delta = $current - $old;
        $threshold = trend_threshold($key, $old);
        if ($delta > $threshold) {
            $trends[$key] = 'up';
        } elseif ($delta < -$threshold) {
            $trends[$key] = 'down';
        } else {
            $trends[$key] = 'stable';
        }
    }
    $state['trend_prev'] = array_map('floatval', $values);
    return $trends;
}

function trend_threshold(string $key, float $previous): float
{
    return match ($key) {
        'cpu' => 2.0,
        'memory' => 1.5,
        'packet_drops', 'dnsbl_hits' => 0.5,
        'state_count' => max(10.0, abs($previous) * 0.03),
        'search_rate' => max(100.0, abs($previous) * 0.06),
        'wan_traffic' => max(1024.0, abs($previous) * 0.10),
        default => max(1.0, abs($previous) * 0.05),
    };
}

function trend_arrow(array $frame, string $key): string
{
    $trend = (string)($frame['trends'][$key] ?? 'stable');
    return match ($trend) {
        'up' => '↑',
        'down' => '↓',
        default => '→',
    };
}

function threat_pulse_from_events(array $events, array $packets, array $hosts, array &$state): array
{
    $fwDrops = 0;
    $dnsbl = 0;
    $ids = 0;
    $idsHigh = 0;
    $idsWatch = 0;
    $newHosts = 0;
    $seen = is_array($state['new_hosts_seen'] ?? null) ? $state['new_hosts_seen'] : [];

    foreach ($events as $event) {
        $category = event_category($event);
        $verdict = strtoupper((string)($event['verdict'] ?? ''));
        $ticker = (string)($event['ticker'] ?? '');
        if ($category === 'FW' && ($verdict === 'DROP' || preg_match('/\b(drop|blocked|reject)\b/i', $ticker))) {
            $fwDrops++;
        } elseif ($category === 'DNSBL') {
            $dnsbl++;
        } elseif ($category === 'IDS' || $category === 'IPS') {
            $ids++;
            $sev = strtoupper((string)($event['severity'] ?? 'WARN'));
            if (in_array($sev, ['HIGH', 'CRIT'], true)) {
                $idsHigh++;
            } else {
                $idsWatch++;
            }
        }

        if (in_array($category, ['DHCP', 'ARP', 'DEVICE'], true)) {
            foreach ([(string)($event['src'] ?? ''), (string)($event['dst'] ?? '')] as $endpoint) {
                $label = compact_endpoint_label($endpoint, false);
                if ($label !== '' && endpoint_is_lan($label) && !isset($seen[$label])) {
                    $seen[$label] = microtime(true);
                    $newHosts++;
                }
            }
        }
    }

    foreach ($packets as $packet) {
        if (strtoupper((string)($packet['category'] ?? '')) === 'DNSBL') {
            $dnsbl++;
        }
    }

    while (count($seen) > 512) {
        array_shift($seen);
    }
    $state['new_hosts_seen'] = $seen;

    return [
        'fw_drops_min' => $fwDrops,
        'dnsbl_min' => $dnsbl,
        'ids_alerts' => $ids,
        'ids_high' => $idsHigh,
        'ids_watch' => $idsWatch,
        'new_hosts' => $newHosts,
    ];
}

function collect_incident_mode_summary(array &$state, float $now): array
{
    $ttl = max(10.0, (float)(getenv('SOCX_INCIDENT_MODE_CACHE_SECONDS') ?: 30.0));
    $cached = is_array($state['incident_mode_summary'] ?? null) ? $state['incident_mode_summary'] : [];
    $last = (float)($state['incident_mode_checked'] ?? 0.0);
    if ($cached && ($now - $last) < $ttl) {
        return $cached;
    }
    $summary = [
        'status' => 'unknown',
        'fw_blocks' => 0,
        'top_port' => '?',
        'top_source' => '',
        'dnsbl' => 0,
        'ids_high' => 0,
        'text' => 'Incident Mode waiting',
        'checked' => $now,
    ];
    $cmd = 'command -v socx-incident-mode >/dev/null 2>&1 && socx-incident-mode 120 2>/dev/null | head -80';
    $out = shell_exec($cmd);
    if (is_string($out) && trim($out) !== '') {
        $summary['status'] = 'ok';
        if (preg_match('/(?:FW|firewall|blocks?)[^0-9]{0,20}([0-9][0-9,]*)/i', $out, $m)) {
            $summary['fw_blocks'] = (int)str_replace(',', '', $m[1]);
        }
        if (preg_match('/(?:top ports?|ports?)[:\s|]+([0-9]+)/i', $out, $m)) {
            $summary['top_port'] = $m[1];
        } elseif (preg_match('/\bport\s+([0-9]{1,5})\b/i', $out, $m)) {
            $summary['top_port'] = $m[1];
        }
        if (preg_match('/(?:top sources?|sources?)[:\s|]+([A-Za-z0-9_.:-]+)/i', $out, $m)) {
            $summary['top_source'] = $m[1];
        }
        if (preg_match('/DNSBL[^0-9]{0,20}([0-9][0-9,]*)/i', $out, $m)) {
            $summary['dnsbl'] = (int)str_replace(',', '', $m[1]);
        }
        if (preg_match('/IDS[^0-9]{0,20}([0-9][0-9,]*)/i', $out, $m)) {
            $summary['ids_high'] = (int)str_replace(',', '', $m[1]);
        }
        $firstLine = trim((string)strtok($out, "\n"));
        $summary['text'] = $firstLine !== '' ? $firstLine : 'Incident Mode sampled';
    }
    $state['incident_mode_summary'] = $summary;
    $state['incident_mode_checked'] = $now;
    return $summary;
}

function incident_mode_wall_event(array $summary): ?array
{
    if (($summary['status'] ?? '') !== 'ok') {
        return null;
    }
    $blocks = (int)($summary['fw_blocks'] ?? 0);
    $dns = (int)($summary['dnsbl'] ?? 0);
    $ids = (int)($summary['ids_high'] ?? 0);
    if ($blocks <= 0 && $dns <= 0 && $ids <= 0) {
        return soc_event('INC', 'INFO', 'Incident Mode quiet | evidence sampler ready');
    }
    $severity = ($ids > 0 || $blocks > 80) ? 'HIGH' : (($blocks > 20 || $dns > 20) ? 'WARN' : 'LOW');
    $port = (string)($summary['top_port'] ?? '?');
    $source = trim((string)($summary['top_source'] ?? ''));
    $detail = $source !== '' ? $source : 'recent window';
    return soc_event('INC', $severity, sprintf('Incident watch FW %d DNSBL %d IDS %d | top port %s | %s', $blocks, $dns, $ids, $port, $detail));
}

function threat_pulse_event(array $pulse, array $trends = []): array
{
    return soc_event('PULSE', 'INFO', sprintf(
        'Threat pulse FW %d/min %s | DNSBL %d/min %s | IDS %d | new hosts %d',
        (int)($pulse['fw_drops_min'] ?? 0),
        trend_symbol_from($trends['packet_drops'] ?? 'stable'),
        (int)($pulse['dnsbl_min'] ?? 0),
        trend_symbol_from($trends['dnsbl_hits'] ?? 'stable'),
        (int)($pulse['ids_alerts'] ?? 0),
        (int)($pulse['new_hosts'] ?? 0)
    ));
}

function trend_symbol_from(string $trend): string
{
    return match ($trend) {
        'up' => '↑',
        'down' => '↓',
        default => '→',
    };
}

function wan_scan_summary_event(array $events): ?array
{
    $count = 0;
    $ports = [];
    foreach ($events as $event) {
        if (!is_array($event) || !is_wan_scan_packet($event)) {
            continue;
        }
        $count++;
        $port = (string)($event['dport'] ?? '');
        if ($port !== '') {
            $ports[$port] = (int)($ports[$port] ?? 0) + 1;
        }
    }
    if ($count < 8) {
        return null;
    }
    arsort($ports);
    $topPorts = array_slice(array_keys($ports), 0, 3);
    return soc_event('FW', 'HIGH', sprintf(
        '%d WAN scans stopped in 60s | ports: %s',
        $count,
        $topPorts ? implode(', ', $topPorts) : 'mixed'
    ));
}

function identity_footer_event(array $ups): array
{
    $identity = getenv('SOCX_IDENTITY_FOOTER');
    if ($identity === false || trim($identity) === '') {
        $identity = 'SOCX WALL // JupiterLXI // pfSense // Frontier Fiber // '
            . (!empty($ups['online']) ? 'UPS protected' : 'UPS monitored')
            . ' // AI-SOC ready';
    }
    return soc_event('SOCX', 'INFO', trim($identity));
}

function ai_soc_enrichment_events(array $events, array $packets, array $pulse): array
{
    if (!env_bool('SOCX_AI_SOC_ENRICHMENT', true)) {
        return [];
    }
    $profile = ai_soc_profile($events, $packets, $pulse);
    $confidence = $profile['confidence'];
    $severity = $profile['active_threat'] ? 'WARN' : 'INFO';

    return [
        soc_event('INTEL', 'INFO', sprintf(
            'CVE %s | CPE %s | CWE %s | CVSS %s EPSS %s KEV %s',
            $profile['cve'],
            $profile['cpe'],
            $profile['cwe'],
            $profile['cvss'],
            $profile['epss'],
            $profile['kev']
        ), ['confidence' => $confidence]),
        soc_event('TTP', $severity, sprintf(
            'ATT&CK %s | CAPEC %s | D3FEND %s',
            $profile['attack'],
            $profile['capec'],
            $profile['d3fend']
        ), ['confidence' => $confidence]),
        soc_event('DETECT', 'INFO', sprintf(
            'Sigma %s | YARA %s | Suricata %s',
            $profile['sigma'],
            $profile['yara'],
            $profile['suricata']
        ), ['confidence' => $confidence]),
        soc_event('EVID', 'INFO', sprintf(
            'Win EID %s | Linux/macOS %s | memory %s',
            $profile['windows_event_ids'],
            $profile['posix_artifacts'],
            $profile['memory_artifacts']
        ), ['confidence' => $confidence]),
        soc_event('CLOUD', 'INFO', sprintf(
            'Cloud logs %s | contain %s | preserve %s',
            $profile['cloud_logs'],
            $profile['containment'],
            $profile['evidence']
        ), ['confidence' => $confidence]),
        soc_event('SRC', 'LOW', 'source refs ' . $profile['sources']),
    ];
}

function ai_soc_profile(array $events, array $packets, array $pulse): array
{
    $corpus = ai_soc_corpus($events, $packets);
    $hasScan = (int)($pulse['fw_drops_min'] ?? 0) > 0 || preg_match('/\b(WAN scan|scanner|port scan|service discovery)\b/i', $corpus);
    $hasDnsbl = (int)($pulse['dnsbl_min'] ?? 0) > 0 || preg_match('/\b(DNSBL|sinkhole|known-bad)\b/i', $corpus);
    $hasIds = (int)($pulse['ids_alerts'] ?? 0) > 0 || preg_match('/\b(Suricata|IDS|IPS|alert)\b/i', $corpus);
    $hasCve = (bool)preg_match('/\bCVE-\d{4}-\d{4,7}\b/i', $corpus);

    $attack = [];
    if ($hasScan) {
        $attack[] = 'T1046 service discovery';
    }
    if ($hasDnsbl) {
        $attack[] = 'T1071.004 DNS';
    }
    if ($hasIds && !$attack) {
        $attack[] = 'T1190/T1105 candidate';
    }

    $capec = [];
    if ($hasScan) {
        $capec[] = 'CAPEC-300 port scan';
    }
    if ($hasDnsbl) {
        $capec[] = 'CAPEC n/a DNS intel';
    }

    $score = 58;
    $score += $hasScan ? 18 : 0;
    $score += $hasDnsbl ? 12 : 0;
    $score += $hasIds ? 14 : 0;
    $score += $hasCve ? 5 : 0;
    $score = max(50, min(96, $score));

    return [
        'active_threat' => $hasScan || $hasDnsbl || $hasIds,
        'confidence' => ai_soc_env('SOCX_AI_SOC_CONFIDENCE', $score . '%'),
        'cve' => ai_soc_env('SOCX_AI_SOC_CVE', ai_soc_refs('/\bCVE-\d{4}-\d{4,7}\b/i', $corpus, 'n/a no exploit observed')),
        'cpe' => ai_soc_env('SOCX_AI_SOC_CPE', 'cpe:2.3:a:netgate:pfsense:* + cpe:2.3:o:freebsd:freebsd:*'),
        'cwe' => ai_soc_env('SOCX_AI_SOC_CWE', ai_soc_refs('/\bCWE-\d+\b/i', $corpus, $hasCve ? 'lookup NVD' : 'n/a')),
        'capec' => ai_soc_env('SOCX_AI_SOC_CAPEC', $capec ? implode(' + ', $capec) : 'n/a'),
        'cvss' => ai_soc_env('SOCX_AI_SOC_CVSS', $hasCve ? 'lookup NVD' : 'n/a'),
        'epss' => ai_soc_env('SOCX_AI_SOC_EPSS', $hasCve ? 'lookup FIRST' : 'n/a'),
        'kev' => ai_soc_env('SOCX_AI_SOC_KEV', $hasCve ? 'check CISA' : 'no'),
        'attack' => ai_soc_env('SOCX_AI_SOC_ATTACK', $attack ? implode(' + ', $attack) : 'n/a'),
        'd3fend' => ai_soc_env('SOCX_AI_SOC_D3FEND', $hasScan ? 'D3-NTA/D3-NTF' : 'D3-NTA'),
        'sigma' => ai_soc_env('SOCX_AI_SOC_SIGMA', $hasScan ? 'socx_pfsense_scan_burst' : 'socx_network_watch'),
        'yara' => ai_soc_env('SOCX_AI_SOC_YARA', $hasIds || $hasDnsbl ? 'socx_log_ioc_context' : 'n/a packet-only'),
        'suricata' => ai_soc_env('SOCX_AI_SOC_SURICATA', $hasScan ? 'sid:9001046 scan-burst' : 'local rule n/a'),
        'windows_event_ids' => ai_soc_env('SOCX_AI_SOC_WINDOWS_EIDS', '5152/5157/4688'),
        'posix_artifacts' => ai_soc_env('SOCX_AI_SOC_POSIX_ARTIFACTS', 'filter.log,dnsbl.log,suricata/*,auth.log'),
        'memory_artifacts' => ai_soc_env('SOCX_AI_SOC_MEMORY_ARTIFACTS', 'pf states,sockets,proc map'),
        'cloud_logs' => ai_soc_env('SOCX_AI_SOC_CLOUD_LOGS', 'VPC Flow,WAF,DNS,CloudTrail/AzureActivity'),
        'containment' => ai_soc_env('SOCX_AI_SOC_CONTAINMENT', 'block src,quarantine host,tighten rule'),
        'evidence' => ai_soc_env('SOCX_AI_SOC_EVIDENCE', 'logs,pcap,pfctl -ss,config.xml'),
        'sources' => ai_soc_env('SOCX_AI_SOC_SOURCES', 'NVD/CPE/CVSS, CISA KEV, FIRST EPSS, MITRE ATT&CK/CAPEC/D3FEND, SigmaHQ, YARA, Suricata'),
    ];
}

function ai_soc_corpus(array $events, array $packets): string
{
    $parts = [];
    foreach (array_slice(array_merge($events, $packets), 0, 120) as $row) {
        if (is_array($row)) {
            foreach (['ticker', 'story', 'category', 'severity', 'verdict', 'src', 'dst', 'service'] as $key) {
                if (isset($row[$key]) && is_scalar($row[$key])) {
                    $parts[] = (string)$row[$key];
                }
            }
            if (isset($row['context'])) {
                $parts[] = is_array($row['context']) ? implode(' ', $row['context']) : (string)$row['context'];
            }
        } elseif (is_scalar($row)) {
            $parts[] = (string)$row;
        }
    }
    return implode(' ', $parts);
}

function ai_soc_refs(string $pattern, string $text, string $fallback): string
{
    if (!preg_match_all($pattern, $text, $matches)) {
        return $fallback;
    }
    $refs = array_values(array_unique(array_map('strtoupper', $matches[0])));
    return implode(',', array_slice($refs, 0, 3));
}

function ai_soc_env(string $name, string $fallback): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $fallback;
    }
    return clean_event_message((string)$value);
}

function env_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
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
    foreach (collect_ai_lab_events($state, $metrics, $routineDue) as $event) {
        $events[] = $event;
    }

    if ($routineDue) {
        $events = array_merge($events, disk_status_events());
        $vpn = wireguard_status_event();
        if ($vpn !== null) {
            $events[] = $vpn;
        }
        $lldp = lldp_status_event();
        if ($lldp !== null) {
            $events[] = $lldp;
        }
        $watchdog = service_watchdog_event();
        if ($watchdog !== null) {
            $events[] = $watchdog;
        }
        $doctor = wall_doctor_status_event($now);
        if ($doctor !== null) {
            $events[] = $doctor;
        }
        $backup = backup_safety_event();
        if ($backup !== null) {
            $events[] = $backup;
        }
        foreach (operator_mission_events($metrics, $events, $now) as $event) {
            $events[] = $event;
        }
        $state['last_command_status_at'] = $now;
    }

    return $events;
}

function operator_mission_events(array $metrics, array $events, float $now): array
{
    if (!env_bool('SOCX_MISSION_EVENTS_ENABLED', true)) {
        return [];
    }
    $out = [];
    $vpn = is_array($metrics['vpn_status'] ?? null) ? $metrics['vpn_status'] : [];
    $speed = is_array($metrics['speedtest'] ?? null) ? $metrics['speedtest'] : [];
    $ups = normalize_ups($metrics['ups'] ?? []);
    $cpu = is_array($metrics['cpu'] ?? null) ? $metrics['cpu'] : [];
    $mem = is_array($metrics['mem'] ?? null) ? $metrics['mem'] : [];
    $flows = is_array($metrics['flows'] ?? null) ? $metrics['flows'] : [];
    $top = first_useful_flow($flows);
    $missionParts = [];
    $vpnState = strtoupper((string)($vpn['status'] ?? 'UNKNOWN'));
    $missionParts[] = 'VPN ' . ($vpnState !== '' ? $vpnState : 'UNKNOWN');
    if (strtolower((string)($speed['status'] ?? '')) === 'ok') {
        $missionParts[] = 'SPD ' . (string)($speed['download_mbps'] ?? '?') . '/' . (string)($speed['upload_mbps'] ?? '?');
    }
    if ($top !== null) {
        $missionParts[] = 'top ' . flow_path_text($top, 24) . ' ' . service_short((string)($top['service'] ?? ''));
    }
    $out[] = soc_event('MISSION', 'INFO', 'What matters: ' . implode(' | ', array_slice($missionParts, 0, 3)));

    $vpnDetail = vpn_detail_event($vpn);
    if ($vpnDetail !== null) {
        $out[] = $vpnDetail;
    }
    $pi = pi_role_event($now);
    if ($pi !== null) {
        $out[] = $pi;
    }
    $thermal = thermal_confidence_event($cpu, $mem, $ups);
    if ($thermal !== null) {
        $out[] = $thermal;
    }
    $noise = operator_noise_event($events);
    if ($noise !== null) {
        $out[] = $noise;
    }
    return $out;
}

function vpn_detail_event(array $vpn): ?array
{
    $items = is_array($vpn['details'] ?? null) ? $vpn['details'] : (is_array($vpn['items'] ?? null) ? $vpn['items'] : []);
    $details = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = strtoupper(truncate_text((string)($item['name'] ?? $item['gateway'] ?? $item['interface'] ?? $item['id'] ?? 'VPN'), 8));
        $state = strtolower((string)($item['status'] ?? $item['state'] ?? 'unknown'));
        $lat = '';
        if (isset($item['rtt']) && is_numeric($item['rtt'])) {
            $lat = ' ' . (int)round((float)$item['rtt']) . 'ms';
        } elseif (isset($item['latency']) && is_numeric($item['latency'])) {
            $lat = ' ' . (int)round((float)$item['latency']) . 'ms';
        }
        $details[] = $name . ' ' . $state . $lat;
    }
    if (!$details) {
        $online = (int)($vpn['online'] ?? 0);
        $total = (int)($vpn['total'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return soc_event('VPN', $online === $total ? 'INFO' : 'WARN', sprintf('VPN detail %d/%d online | gateway truth active', $online, $total));
    }
    return soc_event('VPN', 'INFO', 'VPN detail ' . implode(' | ', array_slice($details, 0, 3)));
}

function pi_role_event(float $now): ?array
{
    $file = getenv('SOCX_PI_LLM_ANALYSIS_CACHE') ?: '/tmp/socx-pi-llm-analysis.env';
    if (!is_readable($file)) {
        return null;
    }
    $data = parse_env_file($file);
    $roles = trim((string)($data['roles'] ?? $data['roles_online'] ?? ''));
    $sev = strtoupper(trim((string)($data['severity'] ?? $data['status'] ?? 'WATCH')));
    $summary = trim((string)($data['summary'] ?? $data['reason'] ?? 'Pi AI analysis cache present'));
    $updated = isset($data['updated']) && is_numeric($data['updated']) ? (float)$data['updated'] : (float)@filemtime($file);
    $age = $updated > 0 ? max(0, (int)round($now - $updated)) : 0;
    $severity = str_contains($sev, 'WARN') || str_contains($sev, 'WATCH') ? 'LOW' : 'INFO';
    return soc_event('PIAI', $severity, sprintf('Pi AI roles %s age %s | %s',
        $roles !== '' ? $roles : 'waiting',
        $age > 0 ? format_age_seconds((float)$age) : 'fresh',
        truncate_text($summary, 72)));
}

function thermal_confidence_event(array $cpu, array $mem, array $ups): ?array
{
    $temp = parse_temperature_c((string)($cpu['temp'] ?? ''));
    $warn = (float)(getenv('SOCX_CPU_WARN_C') ?: 75);
    $headroom = $temp !== null ? $warn - $temp : null;
    $used = (int)($mem['used_pct'] ?? 0);
    $watts = (string)($ups['watts'] ?? '?');
    if ($temp === null && $used <= 0 && $watts === '?') {
        return null;
    }
    $severity = ($headroom !== null && $headroom < 5) || $used >= 88 ? 'WARN' : 'INFO';
    $head = $headroom !== null ? sprintf('headroom %.0fC', $headroom) : 'headroom ?';
    return soc_event('THERM', $severity, sprintf('CPU %s %s | RAM %d%% | UPS %sW',
        $temp !== null ? (int)round($temp) . 'C' : '?C',
        $head,
        $used,
        $watts));
}

function parse_temperature_c(string $text): ?float
{
    if (preg_match('/(-?[0-9.]+)\s*(?:C|°C)?/i', $text, $m)) {
        return (float)$m[1];
    }
    return null;
}

function operator_noise_event(array $events): ?array
{
    $fw = 0;
    $dns = 0;
    $idsRoutine = 0;
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $cat = event_category($event);
        $ticker = strtolower((string)($event['ticker'] ?? ''));
        if ($cat === 'FW' && str_contains($ticker, 'scan')) {
            $fw++;
        } elseif ($cat === 'DNSBL') {
            $dns++;
        } elseif (($cat === 'IDS' || $cat === 'IPS') && preg_match('/routine|watch|decoder|stream|checksum/', $ticker)) {
            $idsRoutine++;
        }
    }
    $parts = [];
    if ($fw > 0) {
        $parts[] = "WAN scans x$fw";
    }
    if ($dns > 0) {
        $parts[] = "DNSBL hits x$dns";
    }
    if ($idsRoutine > 0) {
        $parts[] = "IDS routine x$idsRoutine";
    }
    if (!$parts) {
        return null;
    }
    return soc_event('NOISE', 'LOW', 'Probably noise: ' . implode(' | ', $parts) . ' | preserve evidence before tuning');
}

function wall_doctor_status_event(float $now): ?array
{
    if (!env_bool('SOCX_WALL_DOCTOR_ENABLED', true)) {
        return null;
    }

    $warns = [];
    $oks = 0;
    $checks = 0;

    $checks++;
    $errBytes = is_readable('/tmp/socx-wall.err') ? (int)filesize('/tmp/socx-wall.err') : 0;
    if ($errBytes > 0) {
        $warns[] = 'wall err ' . short_bytes($errBytes);
    } else {
        $oks++;
    }

    $checks++;
    $unknownBytes = is_readable('/var/db/socx_unknown_services.log') ? (int)filesize('/var/db/socx_unknown_services.log') : 0;
    if ($unknownBytes > 0) {
        $warns[] = 'unknown svc ' . short_bytes($unknownBytes);
    } else {
        $oks++;
    }

    $checks++;
    if (!process_running('vnstatd')) {
        $warns[] = 'vnstatd down';
    } else {
        $oks++;
    }

    foreach ([
        'speedtest' => getenv('SOCX_SPEEDTEST_CACHE') ?: '/tmp/socx-speedtest-cache.env',
        'ups' => getenv('SOCX_UPS_CACHE_FILE') ?: '/tmp/socx-ups-cache.env',
        'miranda' => getenv('SOCX_MIRANDA_ANALYSIS_CACHE') ?: '/tmp/socx-miranda-analysis.env',
        'pi-llm' => getenv('SOCX_PI_LLM_ANALYSIS_CACHE') ?: '/tmp/socx-pi-llm-analysis.env',
    ] as $label => $file) {
        $checks++;
        if (!is_readable($file)) {
            $warns[] = $label . ' missing';
            continue;
        }
        $age = max(0, (int)round($now - (float)filemtime($file)));
        $maxAge = $label === 'speedtest' ? 25200 : ($label === 'miranda' ? 900 : 120);
        if ($age > $maxAge) {
            $warns[] = $label . ' stale ' . format_age_seconds((float)$age);
        } else {
            $oks++;
        }
    }

    if ($warns) {
        return soc_event('DOCTOR', 'WARN', sprintf('Wall Doctor %d/%d OK | %s', $oks, $checks, implode(', ', array_slice($warns, 0, 3))));
    }
    return soc_event('DOCTOR', 'INFO', sprintf('Wall Doctor OK %d/%d | run socx doctor for detail', $oks, $checks));
}

function lldp_status_event(): ?array
{
    if (!is_executable('/usr/local/sbin/lldpctl') && trim(run_cmd('command -v lldpctl 2>/dev/null')) === '') {
        return null;
    }
    $config = trim(run_cmd('/usr/local/sbin/lldpcli show configuration 2>/dev/null | /usr/bin/head -80'));
    $ifacePattern = '';
    if ($config !== '' && preg_match('/Interface pattern:\s*(.+)$/mi', $config, $m)) {
        $ifacePattern = trim($m[1]);
    }
    $out = trim(run_cmd('/usr/local/sbin/lldpctl 2>/dev/null | /usr/bin/head -160'));
    if ($out === '') {
        return soc_event('IFACE', 'LOW', sprintf('LLDP enabled%s | waiting for switch advertisements',
            $ifacePattern !== '' ? ' on ' . truncate_text($ifacePattern, 22) : ''));
    }
    $neighbors = lldp_neighbor_summaries($out);
    if (!$neighbors) {
        return soc_event('IFACE', 'LOW', sprintf('LLDP enabled%s | no neighbors visible yet',
            $ifacePattern !== '' ? ' on ' . truncate_text($ifacePattern, 22) : ''));
    }
    return soc_event('IFACE', 'INFO', sprintf('LLDP topology %d neighbor%s | %s',
        count($neighbors),
        count($neighbors) === 1 ? '' : 's',
        implode(' | ', array_slice($neighbors, 0, 2))));
}

function lldp_neighbor_summaries(string $out): array
{
    $rows = [];
    $current = ['iface' => '', 'sys' => '', 'port' => '', 'descr' => ''];
    foreach (preg_split('/\R/', $out) ?: [] as $line) {
        if (preg_match('/^\s*Interface:\s*([^,]+),/', $line, $m)) {
            if ($current['iface'] !== '') {
                $rows[] = lldp_neighbor_text($current);
            }
            $current = ['iface' => trim($m[1]), 'sys' => '', 'port' => '', 'descr' => ''];
            continue;
        }
        if (preg_match('/^\s*SysName:\s*(.+)$/i', $line, $m)) {
            $current['sys'] = trim($m[1]);
            continue;
        }
        if (preg_match('/^\s*PortID:\s*(.+)$/i', $line, $m)) {
            $current['port'] = trim($m[1]);
            continue;
        }
        if (preg_match('/^\s*PortDescr:\s*(.+)$/i', $line, $m)) {
            $current['descr'] = trim($m[1]);
        }
    }
    if ($current['iface'] !== '') {
        $rows[] = lldp_neighbor_text($current);
    }
    $rows = array_values(array_filter(array_unique($rows)));
    return array_slice($rows, 0, 6);
}

function lldp_neighbor_text(array $row): string
{
    $iface = truncate_text((string)($row['iface'] ?? 'if'), 8);
    $sys = trim((string)($row['sys'] ?? ''));
    $port = trim((string)($row['port'] ?? ''));
    $descr = trim((string)($row['descr'] ?? ''));
    $right = $sys !== '' ? $sys : ($descr !== '' ? $descr : ($port !== '' ? $port : 'neighbor'));
    if ($port !== '' && $sys !== '' && !str_contains($right, $port)) {
        $right .= '/' . $port;
    }
    $right = preg_replace('/\s+/', ' ', $right) ?? $right;
    return truncate_text($iface . '->' . $right, 34);
}

function service_watchdog_event(): ?array
{
    $names = [];
    $cmd = 'php -r ' . escapeshellarg('require_once("config.inc"); $items=config_get_path("installedpackages/servicewatchdog/item", []); foreach($items as $i){ if(!empty($i["name"])) echo $i["name"]."\n"; }') . ' 2>/dev/null';
    $out = trim(run_cmd($cmd));
    if ($out === '') {
        return null;
    }
    foreach (preg_split('/\R/', $out) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $names[] = $line;
        }
    }
    $names = array_values(array_unique($names));
    if (!$names) {
        return null;
    }
    $down = service_watchdog_down_services($names);
    if ($down) {
        return soc_event('SYS', 'WARN', sprintf('Service Watchdog alert: %s not running',
            implode(', ', array_slice($down, 0, 4))));
    }
    return soc_event('SYS', 'INFO', sprintf('Service Watchdog supervising %d services: %s', count($names), implode(', ', array_slice($names, 0, 5))));
}

function service_watchdog_down_services(array $names): array
{
    $down = [];
    foreach (array_slice($names, 0, 16) as $name) {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$name) ?? '';
        if ($name === '') {
            continue;
        }
        $status = trim(run_cmd('/usr/sbin/service ' . escapeshellarg($name) . ' status 2>&1'));
        $low = strtolower($status);
        if ($status === '') {
            continue;
        }
        if (str_contains($low, 'not running') || str_contains($low, 'stopped') || str_contains($low, 'dead')) {
            $down[] = $name;
        }
    }
    return array_values(array_unique($down));
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
        $summary[] = soc_event('DNSBL', 'INFO', sprintf('%d DNS blocks observed | query-level filtering active', $dnsblHits));
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
    foreach (top_talker_direction_events($flows) as $event) {
        $summary[] = $event;
    }
    foreach (top_device_activity_events($flows) as $event) {
        $summary[] = $event;
    }
    $deviceEvent = known_unknown_device_event($flows);
    if ($deviceEvent !== null) {
        $summary[] = $deviceEvent;
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
    $digest = socx_daily_digest_event($fwBlocked, $dnsblHits, $idsHits, $wanRate, $lanRate, $ups);
    if ($digest !== null) {
        $summary[] = $digest;
    }

    return $summary;
}

function top_talker_direction_events(array $flows): array
{
    $download = null;
    $upload = null;
    foreach ($flows as $flow) {
        $src = (string)($flow['src'] ?? '');
        $dst = (string)($flow['dst'] ?? '');
        $lanSide = endpoint_is_lan($src) ? $src : (endpoint_is_lan($dst) ? $dst : '');
        if ($lanSide === '') {
            continue;
        }
        $down = parse_short_bytes((string)($flow['down'] ?? '0B'));
        $up = parse_short_bytes((string)($flow['up'] ?? '0B'));
        if ($download === null || $down > (int)$download['rate']) {
            $download = ['flow' => $flow, 'rate' => $down, 'host' => $lanSide];
        }
        if ($upload === null || $up > (int)$upload['rate']) {
            $upload = ['flow' => $flow, 'rate' => $up, 'host' => $lanSide];
        }
    }
    $events = [];
    if ($download !== null && (int)$download['rate'] > 0) {
        $flow = (array)$download['flow'];
        $events[] = soc_event('FLOW', 'INFO', sprintf('Top download %s %s/s via %s',
            truncate_text((string)$download['host'], 24),
            short_bytes((int)$download['rate']),
            service_human_label((string)($flow['service'] ?? ''))));
    }
    if ($upload !== null && (int)$upload['rate'] > 0) {
        $flow = (array)$upload['flow'];
        $events[] = soc_event('FLOW', 'INFO', sprintf('Top upload %s %s/s via %s',
            truncate_text((string)$upload['host'], 24),
            short_bytes((int)$upload['rate']),
            service_human_label((string)($flow['service'] ?? ''))));
    }
    return $events;
}

function top_device_activity_events(array $flows): array
{
    $devices = [];
    foreach ($flows as $flow) {
        $src = (string)($flow['src'] ?? '');
        $dst = (string)($flow['dst'] ?? '');
        $device = endpoint_is_lan($src) ? $src : (endpoint_is_lan($dst) ? $dst : '');
        if ($device === '') {
            continue;
        }
        $key = preg_replace('/\/LAN\.(\d+)$/', '', $device) ?? $device;
        $rate = parse_short_bytes((string)($flow['up'] ?? '0B')) + parse_short_bytes((string)($flow['down'] ?? '0B'));
        $svc = service_short((string)($flow['service'] ?? ''));
        if (!isset($devices[$key])) {
            $devices[$key] = ['rate' => 0, 'services' => []];
        }
        $devices[$key]['rate'] += $rate;
        if ($svc !== '' && $svc !== 'unk') {
            $devices[$key]['services'][$svc] = true;
        }
    }
    if (!$devices) {
        return [];
    }
    uasort($devices, static fn(array $a, array $b): int => $b['rate'] <=> $a['rate']);
    $events = [];
    foreach (array_slice($devices, 0, 2, true) as $device => $info) {
        $services = array_slice(array_keys($info['services']), 0, 4);
        $events[] = soc_event('DEVICE', 'INFO', sprintf('Top device %s %s/s | apps %s',
            truncate_text((string)$device, 22),
            short_bytes((int)$info['rate']),
            $services ? implode('+', $services) : 'flow'));
    }
    return $events;
}

function watchlist_events(array $flows, array $events): array
{
    $rules = load_watchlist_rules();
    if (!$rules) {
        return [];
    }
    $matches = [];
    foreach ($flows as $flow) {
        $hay = strtolower(implode(' ', [
            (string)($flow['src'] ?? ''),
            (string)($flow['dst'] ?? ''),
            (string)($flow['service'] ?? ''),
            (string)($flow['sport'] ?? ''),
            (string)($flow['dport'] ?? ''),
            flow_path_text($flow, 80),
        ]));
        foreach ($rules as $rule) {
            if (!watchlist_rule_matches($rule, $hay, $flow, null)) {
                continue;
            }
            $label = $rule['label'] !== '' ? $rule['label'] : $rule['value'];
            $matches[] = soc_event('WATCH', (string)$rule['severity'], sprintf('%s matched flow %s %s',
                truncate_text($label, 22),
                flow_path_text($flow, 32),
                service_human_label((string)($flow['service'] ?? ''))));
        }
    }
    foreach (array_slice($events, 0, 80) as $event) {
        $text = strtolower((string)($event['ticker'] ?? ''));
        if ($text === '') {
            continue;
        }
        foreach ($rules as $rule) {
            if (!watchlist_rule_matches($rule, $text, [], $event)) {
                continue;
            }
            $label = $rule['label'] !== '' ? $rule['label'] : $rule['value'];
            $matches[] = soc_event('WATCH', (string)$rule['severity'], sprintf('%s matched event %s',
                truncate_text($label, 22),
                truncate_text((string)($event['ticker'] ?? ''), 70)));
        }
    }
    return watchlist_dedupe_events($matches);
}

function load_watchlist_rules(): array
{
    static $cache = null;
    static $cachedAt = 0.0;
    $now = microtime(true);
    if (is_array($cache) && ($now - $cachedAt) < 20.0) {
        return $cache;
    }
    $files = [];
    $env = getenv('SOCX_WATCHLIST_FILE');
    if ($env !== false && trim($env) !== '') {
        $files[] = trim($env);
    }
    $files[] = '/usr/local/etc/socx_watchlist.conf';
    $files[] = '/root/socx_watchlist.conf';
    $rules = [];
    foreach ($files as $file) {
        if (!is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rule = parse_watchlist_rule($line);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }
    }
    $cache = array_slice($rules, 0, 80);
    $cachedAt = $now;
    return $cache;
}

function parse_watchlist_rule(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        return null;
    }
    [$kind, $rest] = array_map('trim', explode('=', $line, 2));
    $kind = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $kind) ?? '');
    $parts = array_map('trim', explode('|', $rest));
    $value = strtolower((string)array_shift($parts));
    $label = '';
    $severity = 'WARN';
    foreach ($parts as $part) {
        if (str_starts_with(strtolower($part), 'label:')) {
            $label = trim(substr($part, 6));
        } elseif (str_starts_with(strtolower($part), 'severity:')) {
            $severity = strtoupper(trim(substr($part, 9)));
        }
    }
    if ($kind === '' || $value === '') {
        return null;
    }
    $severity = in_array($severity, ['INFO', 'LOW', 'WARN', 'MED', 'HIGH', 'CRIT'], true) ? $severity : 'WARN';
    return ['kind' => $kind, 'value' => $value, 'label' => $label, 'severity' => $severity];
}

function watchlist_rule_matches(array $rule, string $hay, array $flow, ?array $event): bool
{
    $kind = (string)$rule['kind'];
    $value = strtolower((string)$rule['value']);
    if ($value === '') {
        return false;
    }
    return match ($kind) {
        'host', 'ip', 'device' => str_contains($hay, $value),
        'domain' => str_contains($hay, $value),
        'service', 'app' => strtolower((string)($flow['service'] ?? '')) === $value || str_contains($hay, ' ' . $value . ' '),
        'port' => watchlist_port_matches($value, $flow, $hay),
        'text', 'event' => str_contains($hay, $value),
        default => str_contains($hay, $value),
    };
}

function watchlist_port_matches(string $value, array $flow, string $hay): bool
{
    $value = preg_replace('/\D/', '', $value) ?? '';
    if ($value === '') {
        return false;
    }
    foreach (['sport', 'dport'] as $field) {
        if ((string)($flow[$field] ?? '') === $value) {
            return true;
        }
    }
    return preg_match('/\b(?:port|p)?' . preg_quote($value, '/') . '\b/i', $hay) === 1;
}

function watchlist_dedupe_events(array $events): array
{
    $seen = [];
    $out = [];
    foreach ($events as $event) {
        $key = strtolower((string)($event['ticker'] ?? ''));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $event;
        if (count($out) >= 8) {
            break;
        }
    }
    return $out;
}

function known_unknown_device_event(array $flows): ?array
{
    $known = [];
    $unknown = [];
    foreach ($flows as $flow) {
        foreach (['src', 'dst'] as $field) {
            $label = (string)($flow[$field] ?? '');
            if (!endpoint_is_lan($label)) {
                continue;
            }
            $key = lan_endpoint_key($label);
            if ($key === '') {
                continue;
            }
            if (str_contains($label, '/LAN.')) {
                $known[$key] = true;
            } else {
                $unknown[$key] = true;
            }
        }
    }
    $knownCount = count($known);
    $unknownCount = count($unknown);
    if (($knownCount + $unknownCount) <= 0) {
        return null;
    }
    $severity = $unknownCount > 0 ? 'LOW' : 'INFO';
    return soc_event('DEVICE', $severity, sprintf('Known devices %d | unknown %d | name unknowns in socx_hosts.conf',
        $knownCount,
        $unknownCount));
}

function lan_endpoint_key(string $label): string
{
    if (preg_match('/LAN\.(\d+)/', $label, $m)) {
        return 'LAN.' . $m[1];
    }
    if (preg_match('/192\.168\.1\.(\d+)/', $label, $m)) {
        return 'LAN.' . $m[1];
    }
    return '';
}

function collect_miranda_insight(float $now): array
{
    $file = getenv('SOCX_MIRANDA_ANALYSIS_CACHE') ?: '/tmp/socx-miranda-analysis.env';
    if (!is_readable($file)) {
        return ['available' => false, 'reason' => 'no MIRANDA cache'];
    }
    $data = parse_env_file($file);
    $updated = isset($data['updated']) && is_numeric($data['updated']) ? (float)$data['updated'] : 0.0;
    $ageSeconds = $updated > 0 ? max(0.0, $now - $updated) : null;
    $maxAge = (float)(getenv('SOCX_MIRANDA_ANALYSIS_MAX_AGE') ?: 900);
    $severity = strtoupper((string)($data['severity'] ?? 'INFO'));
    $reason = trim((string)($data['reason'] ?? 'SOCX analyzed'));
    return [
        'available' => true,
        'fresh' => $ageSeconds === null || $ageSeconds <= $maxAge,
        'severity' => $severity !== '' ? $severity : 'INFO',
        'reason' => $reason !== '' ? $reason : 'SOCX analyzed',
        'age_seconds' => $ageSeconds,
        'age' => $ageSeconds === null ? '' : format_age_seconds($ageSeconds),
        'confidence' => (string)($data['confidence'] ?? ''),
    ];
}

function miranda_insight_event(array $insight): array
{
    $severity = strtoupper((string)($insight['severity'] ?? 'INFO'));
    if (empty($insight['fresh'])) {
        $severity = 'WARN';
    }
    $reason = trim((string)($insight['reason'] ?? 'SOCX analyzed'));
    $age = trim((string)($insight['age'] ?? ''));
    $conf = trim((string)($insight['confidence'] ?? ''));
    $msg = sprintf('MIRANDA %s%s | %s',
        !empty($insight['fresh']) ? 'analysis' : 'analysis stale',
        $age !== '' ? ' age ' . $age : '',
        $reason !== '' ? $reason : 'SOCX analyzed');
    return soc_event('AI', $severity, $msg, ['confidence' => $conf]);
}

function collect_pi_llm_insight(float $now): array
{
    $file = getenv('SOCX_PI_LLM_ANALYSIS_CACHE') ?: '/tmp/socx-pi-llm-analysis.env';
    $discovery = collect_pi_discovery_insight($now);
    if (!is_readable($file)) {
        return ['available' => !empty($discovery['available']), 'reason' => 'no Pi 3-LLM cache', 'discovery' => $discovery];
    }
    $data = parse_env_file($file);
    $updated = isset($data['updated']) && is_numeric($data['updated']) ? (float)$data['updated'] : 0.0;
    $ageSeconds = $updated > 0 ? max(0.0, $now - $updated) : null;
    $maxAge = (float)(getenv('SOCX_PI_LLM_ANALYSIS_MAX_AGE') ?: 900);
    $status = strtolower((string)($data['status'] ?? 'unknown'));
    $severity = strtoupper((string)($data['severity'] ?? ($status === 'ok' ? 'INFO' : 'WARN')));
    return [
        'available' => true,
        'fresh' => $ageSeconds === null || $ageSeconds <= $maxAge,
        'status' => $status,
        'severity' => $severity !== '' ? $severity : 'INFO',
        'reason' => trim((string)($data['reason'] ?? 'Pi 3-LLM analyzed SOCX')),
        'age_seconds' => $ageSeconds,
        'age' => $ageSeconds === null ? '' : format_age_seconds($ageSeconds),
        'confidence' => (string)($data['confidence'] ?? ''),
        'roles' => (string)($data['roles'] ?? ''),
        'discovery' => $discovery,
    ];
}

function collect_pi_discovery_insight(float $now): array
{
    $file = getenv('SOCX_PI_DISCOVERY_CACHE') ?: '/tmp/socx-pi-discovery.env';
    if (!is_readable($file)) {
        return ['available' => false, 'status' => 'missing', 'reason' => 'Pi discovery cache missing'];
    }
    $data = parse_env_file($file);
    $updated = isset($data['updated']) && is_numeric($data['updated']) ? (float)$data['updated'] : (float)@filemtime($file);
    $age = $updated > 0 ? max(0.0, $now - $updated) : null;
    $maxAge = (float)(getenv('SOCX_PI_DISCOVERY_MAX_AGE') ?: 1800);
    return [
        'available' => true,
        'fresh' => $age === null || $age <= $maxAge,
        'status' => strtolower((string)($data['status'] ?? 'unknown')),
        'ip' => (string)($data['ip'] ?? ''),
        'service' => (string)($data['service'] ?? ''),
        'source' => (string)($data['source'] ?? ''),
        'url' => (string)($data['pi_llm_url'] ?? ''),
        'age_seconds' => $age,
        'age' => $age === null ? '' : format_age_seconds($age),
        'reason' => (string)($data['note'] ?? ''),
    ];
}

function pi_llm_insight_event(array $insight): array
{
    $severity = strtoupper((string)($insight['severity'] ?? 'INFO'));
    if (empty($insight['fresh']) || !in_array((string)($insight['status'] ?? ''), ['ok', 'off'], true)) {
        $severity = 'WARN';
    }
    $reason = trim((string)($insight['reason'] ?? 'Pi 3-LLM analyzed SOCX'));
    $age = trim((string)($insight['age'] ?? ''));
    $roles = trim((string)($insight['roles'] ?? ''));
    $conf = trim((string)($insight['confidence'] ?? ''));
    $discovery = is_array($insight['discovery'] ?? null) ? $insight['discovery'] : [];
    $where = '';
    if (!empty($discovery['available'])) {
        $ip = trim((string)($discovery['ip'] ?? ''));
        $svc = trim((string)($discovery['service'] ?? ''));
        $dAge = trim((string)($discovery['age'] ?? ''));
        $where = $ip !== '' ? sprintf(' @%s%s%s', $ip, $svc !== '' ? '/' . $svc : '', $dAge !== '' ? ' seen ' . $dAge : '') : '';
        if (empty($discovery['fresh'])) {
            $severity = 'WARN';
        }
    }
    $msg = sprintf('SOCX AI says: %s%s%s | %s',
        !empty($insight['fresh']) ? 'analysis' : 'analysis stale',
        $roles !== '' ? ' roles ' . $roles : '',
        $age !== '' ? ' age ' . $age : $where,
        $reason !== '' ? $reason : 'SOCX analyzed');
    if ($where !== '' && $age !== '') {
        $msg .= $where;
    }
    return soc_event('AI', $severity, $msg, ['confidence' => $conf]);
}

function autopilot_status_event(float $now): ?array
{
    if (!env_bool('SOCX_AUTOPILOT_EVENTS_ENABLED', true)) {
        return null;
    }
    $file = getenv('SOCX_AUTOPILOT_CACHE') ?: '/tmp/socx-autopilot.env';
    if (!is_readable($file)) {
        return null;
    }
    $data = parse_env_file($file);
    $updated = isset($data['updated']) && is_numeric($data['updated']) ? (float)$data['updated'] : (float)@filemtime($file);
    $age = $updated > 0 ? max(0.0, $now - $updated) : null;
    $maxAge = (float)(getenv('SOCX_AUTOPILOT_MAX_AGE') ?: 180);
    $mode = strtoupper(trim((string)($data['mode'] ?? 'UNKNOWN')));
    $score = trim((string)($data['score'] ?? ''));
    $summary = trim((string)($data['summary'] ?? 'read-only SOCX autonomy'));
    $actions = trim((string)($data['actions'] ?? 'keep monitoring'));
    $severity = match ($mode) {
        'NORMAL' => 'INFO',
        'WATCH', 'COOLDOWN' => 'LOW',
        'INVESTIGATE' => 'WARN',
        'INCIDENT' => 'HIGH',
        default => 'WARN',
    };
    if ($age !== null && $age > $maxAge) {
        $severity = 'WARN';
        $mode = 'STALE';
    }
    $ageText = $age === null ? '' : ' age ' . format_age_seconds($age);
    $msg = sprintf('Autopilot %s%s%s | %s | %s',
        $mode,
        $score !== '' ? ' score ' . $score : '',
        $ageText,
        $summary !== '' ? $summary : 'read-only SOCX autonomy',
        $actions !== '' ? truncate_text($actions, 70) : 'keep monitoring');
    return soc_event('AUTO', $severity, $msg);
}

function socx_health_score(array $wanHealth, array $vpnStatus, array $ups, array $mem, array $cpu, array $speedtest, array $pulse): array
{
    $score = 100;
    $reasons = [];

    $wanScore = (int)($wanHealth['score'] ?? 100);
    if ($wanScore < 90) {
        $score -= min(25, max(0, 100 - $wanScore));
        $reasons[] = 'WAN ' . (string)($wanHealth['label'] ?? 'watch');
    }

    $vpnState = strtoupper((string)($vpnStatus['status'] ?? 'UNKNOWN'));
    if ($vpnState === 'DOWN') {
        $score -= 25;
        $reasons[] = 'VPN down';
    } elseif ($vpnState === 'PARTIAL') {
        $score -= 12;
        $reasons[] = 'VPN partial';
    } elseif ($vpnState === 'UNKNOWN') {
        $score -= 10;
        $reasons[] = 'VPN unknown';
    }

    if (empty($ups['online'])) {
        $score -= 15;
        $reasons[] = 'UPS unknown';
    } elseif (is_numeric($ups['runtime_seconds'] ?? null) && (int)$ups['runtime_seconds'] < 1200) {
        $score -= 8;
        $reasons[] = 'UPS runtime';
    }

    $memPct = (int)($mem['used_pct'] ?? 0);
    if ($memPct >= 92) {
        $score -= 12;
        $reasons[] = 'RAM high';
    } elseif ($memPct >= 85) {
        $score -= 6;
        $reasons[] = 'RAM watch';
    }
    if ((float)($cpu['used'] ?? 0) >= 90.0) {
        $score -= 8;
        $reasons[] = 'CPU high';
    }

    $spdStatus = strtolower((string)($speedtest['status'] ?? 'waiting'));
    if (!in_array($spdStatus, ['ok', 'disabled', 'off'], true)) {
        $score -= 5;
        $reasons[] = 'Speedtest ' . strtoupper($spdStatus ?: 'WAIT');
    }

    $drops = (int)($pulse['fw_drops_min'] ?? 0);
    $dns = (int)($pulse['dnsbl_min'] ?? 0);
    $ids = (int)($pulse['ids_alerts'] ?? 0);
    $idsHigh = (int)($pulse['ids_high'] ?? 0);
    $idsWatch = (int)($pulse['ids_watch'] ?? max(0, $ids - $idsHigh));
    if ($idsHigh > 0) {
        $score -= min(12, $idsHigh * 4);
        $reasons[] = 'IDS high';
    } elseif ($idsWatch >= 10) {
        $score -= 4;
        $reasons[] = 'IDS watch';
    }
    if ($drops > 60) {
        $score -= 5;
        $reasons[] = 'scan burst';
    }
    if ($dns > 30) {
        $score -= 4;
        $reasons[] = 'DNSBL burst';
    }

    $score = max(0, min(100, $score));
    $severity = $score >= 90 ? 'INFO' : ($score >= 75 ? 'LOW' : ($score >= 55 ? 'WARN' : 'HIGH'));
    $reason = $reasons ? implode(', ', array_slice($reasons, 0, 3)) : 'all primary signals nominal';
    return ['score' => $score, 'severity' => $severity, 'reason' => $reason];
}

function socx_health_score_event(array $health): array
{
    $score = isset($health['score']) && is_numeric($health['score']) ? (int)$health['score'] : 0;
    $severity = (string)($health['severity'] ?? 'INFO');
    $reason = (string)($health['reason'] ?? 'all primary signals nominal');
    return soc_event('HEALTH', $severity, sprintf('SOCX health %d/100 | %s', $score, $reason));
}

function socx_daily_digest_event(int $fwBlocked, int $dnsblHits, int $idsHits, int $wanRate, int $lanRate, array $ups): ?array
{
    $vnstat = trim(run_cmd('vnstat --oneline 2>/dev/null | /usr/bin/head -1'));
    $traffic = '';
    if ($vnstat !== '') {
        $parts = explode(';', $vnstat);
        if (count($parts) >= 6) {
            $traffic = sprintf('today %s total', trim((string)$parts[5]));
        }
    }
    if ($traffic === '') {
        $traffic = sprintf('now WAN %s/s LAN %s/s', short_bytes(max(0, $wanRate)), short_bytes(max(0, $lanRate)));
    }
    $upsText = !empty($ups['online']) ? sprintf('UPS %s%% %s', $ups['battery'], $ups['runtime']) : 'UPS watch';
    return soc_event('SOCX', 'INFO', sprintf('Digest: FW %d recent | DNS %d | IDS %d | %s | %s',
        $fwBlocked,
        $dnsblHits,
        $idsHits,
        $traffic,
        $upsText));
}

function collect_ai_lab_events(array &$state, array $metrics, bool $routineDue): array
{
    if (!env_bool('SOCX_AI_LAB_ENABLED', true)) {
        return [];
    }
    $events = [];
    $flows = is_array($metrics['flows'] ?? null) ? $metrics['flows'] : [];
    $aiFlows = ai_provider_flows($flows);
    if ($aiFlows) {
        $top = $aiFlows[0];
        $events[] = soc_event('AI', 'INFO', sprintf('%s traffic %s %s | %s',
            ai_provider_label((string)($top['provider'] ?? 'AI')),
            flow_path_text($top, 26),
            compact_rate_pair((string)($top['up'] ?? '0B'), (string)($top['down'] ?? '0B'), 9),
            service_human_label((string)($top['service'] ?? 'ai'))));
    }

    if (!$routineDue && (($state['last_ai_lab_event_at'] ?? 0.0) > 0)) {
        return $events;
    }
    $state['last_ai_lab_event_at'] = microtime(true);

    $procs = strtolower(implode(' ', array_map(static fn(array $p): string => (string)($p['name'] ?? '') . ' ' . (string)($p['cmd'] ?? ''), $metrics['procs'] ?? [])));
    $signals = [];
    foreach ([
        'ollama' => 'Ollama',
        'vllm' => 'vLLM',
        'text-generation' => 'TGI',
        'llama' => 'llama.cpp',
        'miranda' => 'MIRANDA',
        'nvidia' => 'NVIDIA',
    ] as $needle => $label) {
        if (str_contains($procs, $needle) || process_running($needle)) {
            $signals[] = $label;
        }
    }
    if ($signals) {
        $events[] = soc_event('AI', 'INFO', 'Local AI signals online: ' . implode(', ', array_slice(array_unique($signals), 0, 5)));
    }
    $piInsight = collect_pi_llm_insight(microtime(true));
    if (!empty($piInsight['available'])) {
        $events[] = pi_llm_insight_event($piInsight);
    }

    $checks = ai_lab_endpoint_checks();
    if ($checks) {
        $online = [];
        $offline = [];
        foreach ($checks as $check) {
            if (ai_lab_endpoint_online($check)) {
                $online[] = $check['label'];
            } else {
                $offline[] = $check['label'];
            }
        }
        $total = count($checks);
        $state['ai_lab_status'] = [
            'online' => count($online),
            'total' => $total,
            'online_names' => array_values($online),
            'offline' => array_values($offline),
            'checked_at' => microtime(true),
        ];
        $severity = $offline ? ($online ? 'LOW' : 'WARN') : 'INFO';
        $events[] = soc_event('AI', $severity, sprintf('AI lab endpoints %d/%d online%s',
            count($online),
            $total,
            $offline ? ' | offline ' . implode(', ', array_slice($offline, 0, 3)) : ''));
    } else {
        $state['ai_lab_status'] = [
            'online' => 0,
            'total' => 0,
            'online_names' => [],
            'offline' => [],
            'checked_at' => microtime(true),
        ];
        $events[] = soc_event('AI', 'LOW', 'AI lab ready | configure /usr/local/etc/socx_ai_lab.conf for MIRANDA, Ollama, vLLM, xAI, NVIDIA Build');
    }
    return $events;
}

function ai_lab_endpoint_checks(): array
{
    $checks = [];
    $env = getenv('SOCX_AI_LAB_ENDPOINTS');
    if ($env !== false && trim($env) !== '') {
        foreach (preg_split('/\s*,\s*/', trim($env)) ?: [] as $entry) {
            $check = parse_ai_lab_endpoint($entry);
            if ($check !== null) {
                $checks[] = $check;
            }
        }
    }
    foreach (['/usr/local/etc/socx_ai_lab.conf', '/root/socx_ai_lab.conf'] as $file) {
        if (!is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $check = parse_ai_lab_endpoint($line);
            if ($check !== null) {
                $checks[] = $check;
            }
        }
    }
    return array_slice($checks, 0, 12);
}

function parse_ai_lab_endpoint(string $entry): ?array
{
    $entry = trim($entry);
    if ($entry === '') {
        return null;
    }
    $label = '';
    $target = $entry;
    if (str_contains($entry, '=')) {
        [$label, $target] = array_map('trim', explode('=', $entry, 2));
    }
    $target = trim($target);
    if ($target === '') {
        return null;
    }
    $host = '';
    $port = 0;
    if (preg_match('/^https?:\/\/([^\/:]+)(?::(\d+))?/i', $target, $m)) {
        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : (str_starts_with(strtolower($target), 'https://') ? 443 : 80);
    } elseif (preg_match('/^([^:]+):(\d+)$/', $target, $m)) {
        $host = $m[1];
        $port = (int)$m[2];
    }
    if ($host === '' || $port <= 0) {
        return null;
    }
    if ($label === '') {
        $label = ai_provider_label($host);
    }
    $label = truncate_text(preg_replace('/[^\w.\- ]/', '', $label) ?: $host, 18);
    return ['label' => $label, 'host' => $host, 'port' => $port, 'target' => $target];
}

function ai_lab_endpoint_online(array $check): bool
{
    $errno = 0;
    $errstr = '';
    $timeout = max(0.2, min(2.0, (float)(getenv('SOCX_AI_LAB_TIMEOUT') ?: 0.6)));
    $fp = @stream_socket_client('tcp://' . $check['host'] . ':' . (int)$check['port'], $errno, $errstr, $timeout);
    if (is_resource($fp)) {
        fclose($fp);
        return true;
    }
    return false;
}

function ai_provider_flows(array $flows): array
{
    $rows = [];
    foreach ($flows as $flow) {
        $service = strtolower((string)($flow['service'] ?? ''));
        $path = strtolower((string)($flow['src'] ?? '') . ' ' . (string)($flow['dst'] ?? ''));
        $provider = ai_provider_from_text($service . ' ' . $path);
        if ($provider === '') {
            continue;
        }
        $flow['provider'] = $provider;
        $rows[] = $flow;
    }
    usort($rows, static fn(array $a, array $b): int => ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0)));
    return array_slice($rows, 0, 5);
}

function ai_provider_from_text(string $text): string
{
    $text = strtolower($text);
    return match (true) {
        str_contains($text, 'xai') || str_contains($text, 'grok') => 'xai',
        str_contains($text, 'nvidia') || str_contains($text, 'ngc') || str_contains($text, 'build.nvidia') => 'nvidia',
        str_contains($text, 'openai') => 'openai',
        str_contains($text, 'anthropic') || str_contains($text, 'claude') => 'anthropic',
        str_contains($text, 'gemini') || str_contains($text, 'googleai') => 'gemini',
        str_contains($text, 'huggingface') || str_contains($text, 'hf') => 'huggingface',
        str_contains($text, 'ollama') || str_contains($text, 'vllm') || str_contains($text, 'llm') || str_contains($text, 'tgi') => 'local-llm',
        default => '',
    };
}

function ai_provider_label(string $provider): string
{
    $provider = strtolower($provider);
    return match (true) {
        str_contains($provider, 'xai'), str_contains($provider, 'grok') => 'xAI/Grok',
        str_contains($provider, 'nvidia'), str_contains($provider, 'ngc') => 'NVIDIA Build',
        str_contains($provider, 'openai') => 'OpenAI',
        str_contains($provider, 'anthropic'), str_contains($provider, 'claude') => 'Anthropic',
        str_contains($provider, 'gemini'), str_contains($provider, 'google') => 'Gemini',
        str_contains($provider, 'hugging'), $provider === 'hf' => 'Hugging Face',
        str_contains($provider, 'ollama') => 'Ollama',
        str_contains($provider, 'vllm') => 'vLLM',
        str_contains($provider, 'miranda') => 'MIRANDA',
        str_contains($provider, 'llm') => 'Local LLM',
        default => $provider !== '' ? strtoupper($provider) : 'AI Lab',
    };
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

function top_network_flow(array $flows): ?array
{
    $fallback = null;
    foreach ($flows as $flow) {
        if (($flow['class'] ?? '') === 'idle') {
            continue;
        }
        $path = flow_path_text($flow, 30);
        if ($path === '' || str_contains($path, 'waiting')) {
            continue;
        }
        $service = strtolower((string)($flow['service'] ?? ''));
        if (in_array($service, ['ping', 'snmp', 'mdns', 'syslog', 'sysl', 'ntp', 'dhcp'], true)) {
            $fallback ??= $flow;
            continue;
        }
        return $flow;
    }
    return $fallback;
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

function backup_safety_event(): ?array
{
    $config = getenv('SOCX_PFSENSE_CONFIG') ?: '/cf/conf/config.xml';
    if (!is_readable($config)) {
        return soc_event('BACKUP', 'WARN', 'pfSense config.xml unreadable | backup safety unknown');
    }
    $age = max(0, time() - (int)@filemtime($config));
    $backupCount = 0;
    foreach (['/cf/conf/backup/*.xml', '/cf/conf/backup/config-*.xml', '/root/socx-reports/*.txt'] as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            if (is_readable($file)) {
                $backupCount++;
            }
        }
    }
    $beText = backup_boot_environment_text();
    $severity = $backupCount > 0 ? 'INFO' : 'LOW';
    if ($age > 86400 * 14) {
        $severity = 'WARN';
    }
    return soc_event('BACKUP', $severity, sprintf('Config age %s | backups %d%s',
        format_age_seconds((float)$age),
        $backupCount,
        $beText !== '' ? ' | ' . $beText : ''));
}

function backup_boot_environment_text(): string
{
    foreach (['/sbin/bectl list -H 2>/dev/null', '/usr/sbin/beadm list 2>/dev/null'] as $cmd) {
        $out = trim(run_cmd($cmd));
        if ($out === '') {
            continue;
        }
        $count = 0;
        $active = '';
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^BE\s+/i', $line)) {
                continue;
            }
            $count++;
            if (preg_match('/\b(NR|R|N)\b/', $line) && $active === '') {
                $parts = preg_split('/\s+/', $line) ?: [];
                $active = (string)($parts[0] ?? '');
            }
        }
        if ($count > 0) {
            return sprintf('bootenv %d%s', $count, $active !== '' ? ' active ' . truncate_text($active, 18) : '');
        }
    }
    return '';
}

function wireguard_status_event(): ?array
{
    $cmd = vpn_command('/usr/bin/wg show all latest-handshakes', 1);
    if (!$cmd['ok'] && trim($cmd['output']) === '') {
        $cmd = vpn_command('/usr/local/bin/wg show all latest-handshakes', 1);
    }
    $raw = trim((string)$cmd['output']);
    if ($raw === '') {
        return null;
    }
    $handshakes = parse_wireguard_handshakes($raw);
    if (!$handshakes) {
        return soc_event('VPN', 'WARN', 'WireGuard handshake unavailable | do not trust interface-only state');
    }
    $fresh = 0;
    $stale = 0;
    foreach ($handshakes as $handshake) {
        $age = $handshake['age'] ?? null;
        if ($age !== null && (float)$age <= vpn_wireguard_handshake_max_age()) {
            $fresh++;
        } else {
            $stale++;
        }
    }
    if ($fresh > 0 && $stale === 0) {
        return soc_event('VPN', 'INFO', sprintf('WireGuard handshakes fresh: %d peers under %.0fs', $fresh, vpn_wireguard_handshake_max_age()));
    }
    return soc_event('VPN', 'WARN', sprintf('WireGuard handshakes stale/missing: %d fresh, %d stale', $fresh, $stale));
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
    $severity = suricata_event_severity($msg, $priority, $category, $line);
    $proto = 'IP';
    $src = 'network';
    $dst = 'internet';
    if (preg_match('/\{([A-Z0-9]+)\}\s+([0-9a-fA-F:.]+)(?::(\d+))?\s+->\s+([0-9a-fA-F:.]+)(?::(\d+))?/', $line, $m)) {
        $proto = strtoupper($m[1]);
        $src = endpoint_label($m[2], $m[3] ?? '', $hosts);
        $dst = endpoint_label($m[4], $m[5] ?? '', $hosts);
    }
    $verb = $category === 'IPS' ? 'blocked' : 'alert';
    $context = infer_packet_context($category, $category === 'IPS' ? 'DROP' : 'ALERT', 'ALRT', $src, $dst, strtolower($category), '', '', $msg);
    return soc_event($category, $severity, sprintf('%s -> %s Suricata %s: %s | inspect host', $src, $dst, $verb, truncate_modern_text($msg, 64)), [
        'feed_only' => false,
        'proto' => $proto,
        'dir' => 'ALRT',
        'src' => $src,
        'dst' => $dst,
        'service' => strtolower($category),
        'verdict' => $category === 'IPS' ? 'DROP' : 'ALERT',
        'class' => strtolower($category),
        'context' => $context,
    ]);
}

function suricata_event_severity(string $msg, int $priority, string $category, string $line = ''): string
{
    $text = strtolower($msg . ' ' . $line);
    if (preg_match('/\b(suricata stream|ethertype unknown|generic protocol command decode|invalid ack|invalid timestamp|bad window|wrong seq|retransmission|tcp segment|checksum|decoder event|http unable to match response to request|raw pkt)\b/i', $text)) {
        return 'INFO';
    }
    if (preg_match('/\b(malware|trojan|ransom|command and control|command-and-control|c2 beacon|cnc|callback|exploit kit|exploit attempt|shellcode|botnet|coinminer|credential|phish|blacklist|known bad|known-bad)\b/i', $text)) {
        return $priority <= 2 ? 'CRIT' : 'HIGH';
    }
    if (strtoupper($category) === 'IPS' || preg_match('/\b(drop|blocked)\b/i', $text)) {
        return $priority <= 2 ? 'HIGH' : 'WARN';
    }
    if (preg_match('/\b(ike invalid proposal|scan|nmap|bruteforce|brute force|scanner)\b/i', $text)) {
        return $priority <= 1 ? 'HIGH' : 'WARN';
    }
    if ($priority <= 1) {
        return 'WARN';
    }
    if ($priority === 2) {
        return 'WARN';
    }
    return 'INFO';
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
        'DROP' => 500,
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
        'WATCH' => 45,
        'WAN', 'VPN', 'UPS', 'DNS' => 40,
        'FW' => 30,
        'PULSE' => 25,
        'AI', 'LAB', 'INTEL', 'TTP', 'DETECT', 'EVID', 'CLOUD', 'SRC' => 22,
        'ARP', 'DHCP', 'DEVICE', 'IFACE' => 20,
        'SOCX' => 18,
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
        'FW' => 4,
        'DNSBL' => 4,
        'FLOW' => 5,
        'PF' => 5,
        'PULSE' => 4,
        'WATCH' => 4,
        'AI' => 8,
        'LAB' => 6,
        'INTEL' => 3,
        'TTP' => 3,
        'DETECT' => 3,
        'EVID' => 3,
        'CLOUD' => 3,
        'SRC' => 2,
        'SOCX' => 3,
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
        'PULSE' => 4,
        'WATCH' => 4,
        'INTEL' => 3,
        'TTP' => 3,
        'DETECT' => 3,
        'EVID' => 3,
        'CLOUD' => 3,
        'SRC' => 2,
        'SOCX' => 3,
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
        $rows[] = '[DNSBL][INFO] Additional DNS blocks summarized x' . $dnsblOverflow . ' | no action';
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
    $categoryOrder = ['WATCH', 'INC', 'PULSE', 'INTEL', 'TTP', 'DETECT', 'EVID', 'CLOUD', 'SRC', 'FLOW', 'UPS', 'PF', 'DNSBL', 'WAN', 'DNS', 'VPN', 'IDS', 'IPS', 'DHCP', 'ARP', 'IFACE', 'SYS', 'DEVICE', 'SOCX', 'FW'];
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
    $alertMode = strtolower((string)(getenv('SOCX_ALERT_MODE') ?: 'auto'));
    $boost = in_array($alertMode, ['auto', 'alert', 'aggressive', '1', 'true', 'yes'], true);
    if (str_contains($event, '[CRIT]')) {
        return $boost ? max(8, (int)($cfg['crit_seconds'] ?? 8)) : (int)($cfg['crit_seconds'] ?? 8);
    }
    if (str_contains($event, '[HIGH]')) {
        return $boost ? max(4, (int)($cfg['high_seconds'] ?? 6)) : (int)($cfg['high_seconds'] ?? 6);
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
    $svc = service_name_for_ports($dport, $sport, $proto);
    $time = preg_match('/(\d{2}:\d{2}:\d{2})/', $line, $m) ? $m[1] : date('H:i:s');
    $severity = firewall_event_severity($verdict, $dir, $srcLabel, $dstLabel, $dport ?: $sport);
    $ticker = firewall_ticker_text($action, $severity, (string)$dir, $srcLabel, $dstLabel, $svc);
    $context = infer_packet_context('FW', $verdict, $dir, $srcLabel, $dstLabel, $svc, $sport, $dport, $ticker);
    return [
        'time' => $time,
        'proto' => $proto,
        'dir' => in_array($dir, ['IN', 'OUT'], true) ? $dir : 'FLOW',
        'src' => $srcLabel,
        'dst' => $dstLabel,
        'service' => $svc,
        'sport' => $sport,
        'dport' => $dport,
        'size' => $len === '0' ? 'ctrl' : $len . 'B',
        'bytes' => (int)$len,
        'verdict' => $verdict,
        'class' => $verdict === 'DROP' ? 'blocked' : (($svc === 'dns' || $svc === 'https') ? 'internet' : 'firewall'),
        'category' => 'FW',
        'severity' => $severity,
        'context' => $context,
        'ticker' => $ticker,
    ];
}

function firewall_event_severity(string $verdict, string $dir, string $srcLabel, string $dstLabel, string $port): string
{
    if (strtoupper($verdict) !== 'DROP') {
        return 'LOW';
    }
    $targetWan = endpoint_is_wan($dstLabel) || endpoint_is_external($dstLabel);
    $scannerPort = in_array((string)$port, ['21', '22', '23', '25', '53', '80', '110', '143', '443', '445', '1433', '3306', '3389', '5900', '8080', '8443'], true);
    if (strtoupper($dir) === 'IN' && endpoint_is_external($srcLabel) && $targetWan && $scannerPort) {
        return 'HIGH';
    }
    return 'MED';
}

function infer_packet_context(string $category, string $verdict, string $dir, string $srcLabel, string $dstLabel, string $service, string $sport, string $dport, string $message = ''): array
{
    $category = strtoupper($category);
    $verdict = strtoupper($verdict);
    $dir = strtoupper($dir);
    $labels = [];
    $text = strtolower($message . ' ' . $service . ' ' . $srcLabel . ' ' . $dstLabel);

    if ($category === 'DNSBL' || $service === 'dnsbl' || str_contains($verdict, 'DNSBL') || $verdict === 'SINKHOLE') {
        $labels[] = 'dnsbl';
        $labels[] = 'known-bad';
    }
    if ($category === 'IDS' || $category === 'IPS' || $verdict === 'ALERT') {
        $labels[] = 'suricata';
        $labels[] = 'threat-intel';
    }
    if (preg_match('/\b(malware|trojan|ransom|exploit)\b/i', $text)) {
        $labels[] = 'malware';
    }
    if (preg_match('/\b(botnet|c2|command-and-control|beacon)\b/i', $text)) {
        $labels[] = 'botnet?';
    }
    if (preg_match('/\b(tor|exit node)\b/i', $text)) {
        $labels[] = 'tor?';
    }
    $targetWan = endpoint_is_wan($dstLabel) || endpoint_is_external($dstLabel);
    if ($verdict === 'DROP' && $dir === 'IN' && endpoint_is_external($srcLabel) && $targetWan) {
        $labels[] = 'scanner';
        $labels[] = 'abuse:high';
    }
    if ($verdict === 'DROP' && in_array($dport ?: $sport, ['21', '22', '23', '25', '445', '1433', '3306', '3389', '5900', '8080', '8443'], true)) {
        $labels[] = 'scanner';
    }

    return array_values(array_unique(array_filter($labels)));
}

function firewall_ticker_text(string $action, string $severity, string $dir, string $srcLabel, string $dstLabel, string $svc): string
{
    $src = compact_endpoint_label($srcLabel, true);
    $dst = compact_endpoint_label($dstLabel, true);
    $service = service_human_label($svc);
    $blocked = strtolower($action) === 'block';
    $dir = strtoupper($dir);

    if ($blocked) {
        if ($dir === 'IN' && endpoint_is_external($srcLabel) && (endpoint_is_external($dstLabel) || endpoint_is_wan($dstLabel))) {
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
    $friendlyDomain = app_label_from_text($domain) ?: $domain;
    $verdict = stripos($line, 'sink') !== false ? 'SINKHOLE' : 'DNSBL HIT';
    $src = endpoint_label($ipm[1], '', $hosts);
    return [
        'time' => date('H:i:s'),
        'proto' => 'DNS',
        'dir' => 'OUT',
        'src' => $src,
        'dst' => $friendlyDomain,
        'raw_dst' => $domain,
        'service' => 'dnsbl',
        'size' => '0B',
        'bytes' => 0,
        'verdict' => $verdict,
        'class' => 'dnsbl',
        'category' => 'DNSBL',
        'severity' => 'LOW',
        'context' => ['dnsbl', 'known-bad'],
        'ticker' => sprintf('[DNSBL][LOW] %s DNS blocked %s', compact_endpoint_label($src, true), compact_domain_label($domain, 18)),
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
        [$ageText, $expiresText] = parse_pf_state_times($line);
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
            'dir' => $pending['dir'],
            'state' => $pending['state'],
            'age' => $ageText,
            'expires' => $expiresText,
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
    if (!preg_match('/^(\S+)\s+([a-z0-9]+)\s+(.+?)\s+(->|<-)\s+(.+?)\s{2,}(\S+)/i', $line, $m)) {
        return null;
    }
    $iface = $m[1];
    if (in_array($iface, ['lo0', 'pflog0', 'pfsync0'], true)) {
        return null;
    }
    $proto = strtoupper($m[2]);
    $state = strtoupper((string)($m[6] ?? ''));
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
    $dir = $m[4] === '<-' ? 'IN' : 'OUT';
    if (is_pf_flow_noise_ip($src['ip']) || is_pf_flow_noise_ip($dst['ip'])) {
        return null;
    }
    $service = service_name_for_ports($dst['port'], $src['port'], $proto);
    $class = str_starts_with($iface, 'tun') || str_starts_with($iface, 'wg')
        ? 'vpn'
        : (in_array($service, ['ollama', 'vllm', 'llm', 'tgi', 'xai', 'nvidia-ai', 'openai', 'anthropic', 'gemini', 'huggingface'], true)
            ? 'ai'
            : (($service === 'dns' || $service === 'https') ? 'internet' : 'state'));
    return [
        'iface' => $iface,
        'proto' => $proto,
        'src' => $src['label'],
        'dst' => $dst['label'],
        'service' => $service,
        'class' => $class,
        'dir' => $dir,
        'state' => $state,
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

function parse_pf_state_times(string $line): array
{
    $age = '--';
    $expires = '--';
    if (preg_match('/age\s+([0-9:]+)/', $line, $m)) {
        $age = pf_time_short($m[1]);
    }
    if (preg_match('/expires in\s+([0-9:]+)/', $line, $m)) {
        $expires = pf_time_short($m[1]);
    }
    return [$age, $expires];
}

function pf_time_short(string $time): string
{
    $parts = array_map('intval', explode(':', $time));
    if (count($parts) === 3) {
        [$h, $m, $s] = $parts;
        if ($h >= 24) {
            return (int)floor($h / 24) . 'd' . ($h % 24) . 'h';
        }
        if ($h > 0) {
            return $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        }
        return $m . 'm' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
    }
    if (count($parts) === 2) {
        return $parts[0] . 'm' . str_pad((string)$parts[1], 2, '0', STR_PAD_LEFT);
    }
    return $time;
}

function packet_radar_from_cache(): array
{
    if (!env_bool('SOCX_PACKET_RADAR_ENABLED', true)) {
        return [];
    }
    $path = getenv('SOCX_PACKET_RADAR_CACHE') ?: '/tmp/socx-packet-radar.log';
    if (!is_readable($path)) {
        return [];
    }
    $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($raw) || !$raw) {
        return [];
    }
    $rows = [];
    foreach (array_reverse(array_slice($raw, -60)) as $line) {
        $line = trim(strip_ansi((string)$line));
        if ($line === '' || preg_match('/^(TCPDUMPX|time\s|[-─]|tcpdump:|listening on)/i', $line)) {
            continue;
        }
        $packet = packet_radar_parse_line($line);
        if ($packet !== null) {
            $rows[] = $packet;
        }
        if (count($rows) >= 24) {
            break;
        }
    }
    return $rows;
}

function packet_radar_parse_line(string $line): ?array
{
    if (!preg_match('/^(\d{2}:\d{2}(?::\d{2})?)\s+(.+)$/', $line, $m)) {
        return null;
    }
    $time = strlen($m[1]) === 5 ? date('H:') . substr($m[1], 0, 2) : $m[1];
    $body = trim($m[2]);
    $proto = 'IP';
    $dir = 'FLOW';
    $src = 'network';
    $dst = 'internet';
    $service = 'flow';
    $size = 'live';

    if (preg_match('/^(TCP|UDP|IP6?|ICMP6?|ARP)\s+(IN|OUT|LCL|FLOW|BCAST)\s+(.+?)\s{2,}(.+)$/', $body, $parts)) {
        $proto = strtoupper($parts[1]);
        $dir = strtoupper($parts[2]);
        $flow = trim($parts[3]);
        $detail = trim($parts[4]);
        if (preg_match('/^(.+?)\s*->\s*(.+)$/', $flow, $fm)) {
            $src = trim($fm[1]);
            $dst = trim($fm[2]);
        } else {
            $src = $flow;
            $dst = '';
        }
        $service = packet_radar_service_from_detail($detail);
        if (preg_match('/\b(\d+B)\b/', $detail, $sm)) {
            $size = $sm[1];
        }
        $story = packet_radar_story($dir, $proto, $src, $dst, $detail);
    } else {
        $detail = $body;
        $story = $body;
    }

    return [
        'time' => $time,
        'proto' => $proto,
        'dir' => $dir,
        'src' => $src,
        'dst' => $dst,
        'service' => $service,
        'size' => $size,
        'bytes' => packet_size_bytes($size),
        'verdict' => 'LIVE',
        'category' => 'RADAR',
        'severity' => 'INFO',
        'context' => ['tcpdump'],
        'story' => $story,
    ];
}

function packet_radar_service_from_detail(string $detail): string
{
    $detail = strtolower($detail);
    foreach (['dns', 'mdns', 'https', 'web', 'ssh', 'vpn', 'ntp', 'icmp', 'snmp', 'syslog'] as $svc) {
        if (str_contains($detail, $svc)) {
            return $svc === 'https' ? 'tls' : $svc;
        }
    }
    if (preg_match('/\bport\s+(\d+)\b/', $detail, $m)) {
        return service_name((string)$m[1]);
    }
    return 'flow';
}

function packet_radar_story(string $dir, string $proto, string $src, string $dst, string $detail): string
{
    $verb = match ($dir) {
        'IN' => 'inbound',
        'OUT' => 'outbound',
        'LCL' => 'local',
        'BCAST' => 'broadcast',
        default => 'flow',
    };
    $detail = preg_replace('/\s*\[[#.]+\]\s*/', ' ', $detail) ?? $detail;
    $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? $detail);
    return sprintf('%s %s %s -> %s %s', $verb, $proto, $src, $dst, $detail);
}

function packet_size_bytes(string $size): int
{
    if (preg_match('/^(\d+)B$/i', trim($size), $m)) {
        return (int)$m[1];
    }
    return 0;
}

function packet_radar_merge(array $radar, array $events): array
{
    $radar = packet_radar_aggregate($radar);
    if (!$radar) {
        return $events;
    }
    $merged = [];
    $seen = [];
    foreach (array_merge($radar, $events) as $row) {
        $key = implode('|', [
            (string)($row['time'] ?? ''),
            (string)($row['src'] ?? ''),
            (string)($row['dst'] ?? ''),
            (string)($row['story'] ?? ''),
            (string)($row['verdict'] ?? ''),
        ]);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $row;
        if (count($merged) >= 48) {
            break;
        }
    }
    return $merged;
}

function packet_radar_aggregate(array $radar): array
{
    if (count($radar) < 5) {
        return $radar;
    }
    $groups = [];
    $other = [];
    foreach ($radar as $row) {
        $svc = strtolower((string)($row['service'] ?? ''));
        $src = (string)($row['src'] ?? '');
        $dst = (string)($row['dst'] ?? '');
        $isTunnel = $svc === 'vpn'
            || str_contains((string)($row['story'] ?? ''), 'port 1443')
            || str_contains((string)($row['story'] ?? ''), 'port 51821');
        if (!$isTunnel) {
            $other[] = $row;
            continue;
        }
        $key = $src . '|' . $dst . '|vpn';
        if (!isset($groups[$key])) {
            $groups[$key] = ['row' => $row, 'count' => 0, 'bytes' => 0];
        }
        $groups[$key]['count']++;
        $groups[$key]['bytes'] += (int)($row['bytes'] ?? 0);
    }
    $agg = [];
    foreach ($groups as $group) {
        $row = $group['row'];
        if ((int)$group['count'] < 4) {
            $other[] = $row;
            continue;
        }
        $row['service'] = 'vpn';
        $row['size'] = short_bytes((int)$group['bytes']);
        $row['bytes'] = (int)$group['bytes'];
        $row['story'] = sprintf('VPN burst %s -> %s x%d %s',
            compact_endpoint_label((string)($row['src'] ?? 'WAN'), true),
            compact_endpoint_label((string)($row['dst'] ?? 'remote'), true),
            (int)$group['count'],
            short_bytes((int)$group['bytes']));
        $agg[] = $row;
    }
    return array_slice(array_merge($agg, $other), 0, 48);
}

function packets_from_events(array $events): array
{
    $rows = [];
    $seen = [];
    $scanPorts = [];
    $scanCount = 0;
    foreach ($events as $e) {
        if (!empty($e['feed_only'])) {
            continue;
        }
        if (is_wan_scan_packet($e)) {
            $scanCount++;
            $port = (string)($e['dport'] ?? '');
            if ($port !== '') {
                $scanPorts[$port] = (int)($scanPorts[$port] ?? 0) + 1;
            }
        }
        $key = implode('|', [
            (string)($e['time'] ?? ''),
            (string)($e['proto'] ?? ''),
            (string)($e['dir'] ?? ''),
            (string)($e['src'] ?? ''),
            (string)($e['dst'] ?? ''),
            (string)($e['service'] ?? ''),
            (string)($e['verdict'] ?? ''),
        ]);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $rows[] = [
            'time' => (string)($e['time'] ?? date('H:i:s')),
            'proto' => (string)($e['proto'] ?? 'IP'),
            'dir' => (string)($e['dir'] ?? 'FLOW'),
            'src' => (string)($e['src'] ?? 'network'),
            'dst' => (string)($e['dst'] ?? 'internet'),
            'service' => (string)($e['service'] ?? 'unknown'),
            'sport' => (string)($e['sport'] ?? ''),
            'dport' => (string)($e['dport'] ?? ''),
            'size' => (string)($e['size'] ?? 'ctrl'),
            'bytes' => (int)($e['bytes'] ?? 0),
            'verdict' => (string)($e['verdict'] ?? 'EVENT'),
            'category' => event_category($e),
            'severity' => (string)($e['severity'] ?? ''),
            'context' => $e['context'] ?? [],
            'story' => (string)($e['story'] ?? ''),
        ];
    }
    if ($scanCount >= 8) {
        arsort($scanPorts);
        $topPorts = array_slice(array_keys($scanPorts), 0, 3);
        $summary = [
            'time' => date('H:i:s'),
            'proto' => 'TCP',
            'dir' => 'IN',
            'src' => 'WAN',
            'dst' => 'pfSense',
            'service' => 'scan',
            'size' => 'ctrl',
            'bytes' => 0,
            'verdict' => 'DROP',
            'category' => 'FW',
            'severity' => 'HIGH',
            'context' => ['scanner', 'abuse:high'],
            'story' => sprintf('%d WAN scans stopped in 60s | ports: %s', $scanCount, $topPorts ? implode(', ', $topPorts) : 'mixed'),
        ];
        $keptScans = 0;
        $filtered = [];
        foreach ($rows as $row) {
            if (is_wan_scan_packet($row)) {
                $keptScans++;
                if ($keptScans > 3) {
                    continue;
                }
            }
            $filtered[] = $row;
        }
        $rows = array_merge([$summary], $filtered);
    }
    if (!$rows) {
        $rows[] = ['time' => date('H:i:s'), 'proto' => 'PF', 'dir' => 'LCL', 'src' => 'pfSense', 'dst' => 'waiting', 'service' => 'log', 'size' => 'ctrl', 'verdict' => 'PASS', 'category' => 'PF'];
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
    return service_name_for_port($port, '');
}

function service_name_for_port(string $port, string $proto = ''): string
{
    $port = trim($port);
    if ($port === '') {
        return 'unknown';
    }
    $override = service_override_name($port);
    if ($override !== '') {
        return $override;
    }
    $known = match ($port) {
        '1' => 'tcpmux',
        '8' => 'ping',
        '20', '21' => 'ftp',
        '53' => 'dns',
        '67', '68' => 'dhcp',
        '80', '81', '808', '8000', '8001', '8080', '8081', '8888' => 'http',
        '844' => 'https',
        '443', '5443', '8443', '9443' => 'https',
        '123' => 'ntp',
        '500', '1194', '1443', '4500', '51820', '51821' => 'vpn',
        '27015', '27016', '27017', '27018', '27019', '27020', '27021', '27022', '27023', '27024', '27025', '27026', '27027', '27028', '27029', '27030', '27031', '27032', '27033', '27034', '27035', '27036', '27037', '27038', '27039', '27040', '27041', '27042', '27043', '27044', '27045', '27046', '27047', '27048', '27049', '27050' => 'steam',
        '1883' => 'mqtt',
        '8883' => 'mqtts',
        '8886' => 'app',
        '22' => 'ssh',
        '25', '465', '587' => 'smtp',
        '110', '143', '993', '995' => 'mail',
        '137', '138', '139', '445' => 'smb',
        '514' => 'syslog',
        '520' => 'rip',
        '161' => 'snmp',
        '853' => 'dot',
        '1900' => 'ssdp',
        '1935' => 'rtmp',
        '3478', '3479', '3480' => 'stun',
        '32400', '32412', '32414', '32469' => 'plex',
        '3493' => 'nut',
        '5353' => 'mdns',
        '4317', '4318' => 'otel',
        '50051' => 'grpc',
        '5601' => 'kibana',
        '5672' => 'amqp',
        '7860' => 'gradio',
        '6333' => 'qdrant',
        '7000', '7001', '7002' => 'lab',
        '7474', '7687' => 'neo4j',
        '8093' => 'miranda',
        '8094' => 'socweb',
        '8095' => 'pi3llm',
        '6379' => 'redis',
        '8265', '10001' => 'ray',
        '8501' => 'streamlit',
        '8889' => 'jupyter',
        '8089' => 'splunk',
        '8728' => 'router',
        '9042' => 'cass',
        '9091' => 'trans',
        '9090', '9100' => 'metrics',
        '9092' => 'kafka',
        '9200', '9300' => 'elastic',
        '9418' => 'git',
        '3000' => 'grafana',
        '5000', '5001' => 'mlflow',
        '8002', '8003', '8004', '8088', '8899' => 'vllm',
        '8082', '8123' => 'home',
        '8086' => 'metrics',
        '11400' => 'llm',
        '11434' => 'ollama',
        '11435' => 'llm',
        '19090' => 'triton',
        '25565' => 'game',
        '3389' => 'rdp',
        '5900' => 'vnc',
        '6667' => 'irc',
        '5223' => 'apns',
        '5228', '5229', '5230' => 'gcm',
        '5938' => 'teamviewer',
        default => '',
    };
    if ($known !== '') {
        return $known;
    }
    $system = service_name_from_system($port, $proto);
    return $system !== '' ? $system : 'port' . $port;
}

function service_name_for_ports(string $dstPort, string $srcPort, string $proto): string
{
    $proto = strtoupper(trim($proto));
    if (str_starts_with($proto, 'ICMP')) {
        return 'ping';
    }
    if (in_array($proto, ['ESP', 'GRE', 'IPSEC'], true)) {
        return 'vpn';
    }
    $dstName = $dstPort !== '' ? service_name_for_port($dstPort, $proto) : '';
    $srcName = $srcPort !== '' ? service_name_for_port($srcPort, $proto) : '';
    if ($dstName !== '' && !str_starts_with($dstName, 'port')) {
        return $dstName;
    }
    if ($srcName !== '' && !str_starts_with($srcName, 'port')) {
        return $srcName;
    }
    $name = $dstName !== '' ? $dstName : ($srcName !== '' ? $srcName : 'unknown');
    if (str_starts_with($name, 'port')) {
        socx_learn_unknown_service($name, $proto);
    }
    return $name;
}

function socx_learn_unknown_service(string $name, string $proto): void
{
    static $seen = [];
    if (!preg_match('/^port(\d+)$/', $name, $m)) {
        return;
    }
    $port = $m[1];
    if ($port === '' || (int)$port <= 0) {
        return;
    }
    $portNum = (int)$port;
    if ($portNum >= 32768) {
        return;
    }
    $interestingHighPorts = [
        4000 => true,
        5355 => true,
        5443 => true,
        8000 => true,
        8001 => true,
        8002 => true,
        8081 => true,
        8089 => true,
        8093 => true,
        8094 => true,
        8728 => true,
        9042 => true,
        9091 => true,
        11434 => true,
    ];
    if ($portNum > 1024 && !isset($interestingHighPorts[$portNum])) {
        return;
    }
    $key = strtoupper($proto) . '/' . $port;
    $now = time();
    if (isset($seen[$key]) && ($now - $seen[$key]) < 60) {
        return;
    }
    $seen[$key] = $now;
    $file = getenv('SOCX_UNKNOWN_SERVICES_LOG') ?: '/var/db/socx_unknown_services.log';
    $line = sprintf("%s %s port=%s proto=%s hint=add_to_/usr/local/etc/socx_services.conf\n",
        date('c'),
        $key,
        $port,
        strtoupper($proto));
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function service_override_name(string $port): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $files = [];
        $env = getenv('SOCX_SERVICES_FILE');
        if ($env !== false && trim($env) !== '') {
            $files[] = trim($env);
        }
        $files[] = '/usr/local/etc/socx_services.conf';
        $files[] = '/root/socx_services.conf';
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$overridePort, $name] = array_map('trim', explode('=', $line, 2));
                $overridePort = preg_replace('/\D/', '', $overridePort) ?? '';
                $name = normalize_service_name($name);
                if ($overridePort !== '' && $name !== '') {
                    $cache[$overridePort] = $name;
                }
            }
        }
    }
    return $cache[$port] ?? '';
}

function service_name_from_system(string $port, string $proto): string
{
    if (!function_exists('getservbyport') || !ctype_digit($port)) {
        return '';
    }
    $proto = strtolower($proto);
    $protocols = in_array($proto, ['tcp', 'udp'], true) ? [$proto] : ['tcp', 'udp'];
    foreach ($protocols as $protocol) {
        $name = @getservbyport((int)$port, $protocol);
        if (is_string($name) && $name !== '') {
            $name = normalize_service_name($name);
            if ($name !== '') {
                return $name;
            }
        }
    }
    return '';
}

function normalize_service_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9+._-]/', '', $name) ?? '';
    return truncate_text($name, 18);
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

function compact_rate_tiny(string $rate, bool $showDecimal = true): string
{
    $rate = trim(str_replace('/s', '', $rate));
    if ($rate === '' || $rate === '--' || $rate === '?') {
        return '?';
    }
    if (preg_match('/^([0-9]+)(?:\.([0-9])\d*)?([KMGTP]?)(?:B)?$/i', $rate, $m)) {
        $whole = $m[1];
        $decimal = $m[2] ?? '';
        $unit = strtoupper($m[3] ?? '');
        if ($unit === '') {
            $unit = 'B';
        }
        if ($showDecimal && (int)$whole < 10 && $decimal !== '' && $unit !== 'B') {
            return $whole . '.' . $decimal . $unit;
        }
        return $whole . $unit;
    }
    return truncate_text($rate, 5);
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
        'dnsbl' => 'dnsblk',
        'blocked' => 'block',
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
        str_contains($service, 'netflix') => 'nflx',
        str_contains($service, 'prime') => 'prim',
        str_contains($service, 'youtube') => 'ytub',
        str_contains($service, 'disney') => 'dis+',
        str_contains($service, 'hulu') => 'hulu',
        str_contains($service, 'apple') || str_contains($service, 'icloud') => 'appl',
        str_contains($service, 'hugging') => 'hf',
        str_contains($service, 'civit') => 'civt',
        str_contains($service, 'ibm quantum') => 'qisk',
        str_contains($service, 'https') => 'tls',
        str_contains($service, 'http') => 'web',
        $service === 'dns', $service === 'dnsbl' => 'dns',
        $service === 'ssh' => 'ssh',
        $service === 'vpn' => 'vpn',
        $service === 'ntp' => 'ntp',
        $service === 'smb' => 'smb',
        $service === 'syslog' => 'sysl',
        $service === 'rip' || $service === 'route' => 'rip',
        $service === 'snmp' => 'snmp',
        $service === 'ssdp' => 'ssdp',
        $service === 'rtmp' => 'rtmp',
        $service === 'stun' => 'stun',
        $service === 'nut' => 'nut',
        $service === 'ollama' => 'olma',
        $service === 'vllm' => 'vllm',
        $service === 'llm' => 'llm',
        $service === 'tgi' => 'tgi',
        $service === 'gradio' => 'grad',
        $service === 'jupyter' => 'jupy',
        $service === 'ray' => 'ray',
        $service === 'mlflow' => 'mlfl',
        $service === 'triton' => 'trtn',
        $service === 'grafana' => 'graf',
        $service === 'openai' => 'oai',
        $service === 'xai' => 'xai',
        $service === 'nvidia-ai' => 'ngc',
        $service === 'anthropic' => 'anth',
        $service === 'gemini' => 'gemi',
        $service === 'huggingface' => 'hf',
        $service === 'redis' => 'rdis',
        $service === 'metrics' => 'metr',
        $service === 'streamlit' => 'strm',
        $service === 'qdrant' => 'qdr',
        $service === 'neo4j' => 'neo4',
        $service === 'elastic' => 'elas',
        $service === 'kibana' => 'kiba',
        $service === 'home' => 'home',
        $service === 'otel' => 'otel',
        $service === 'kafka' => 'kafk',
        $service === 'grpc' => 'grpc',
        $service === 'amqp' => 'amqp',
        $service === 'mqtt' => 'mqtt',
        $service === 'ping' => 'ping',
        $service === 'plex' => 'plex',
        $service === 'steam' => 'game',
        $service === 'mqtts' => 'mqtt',
        $service === 'app' => 'app',
        $service === 'dhcp' => 'dhcp',
        $service === 'mdns' => 'mdns',
        $service === 'smtp', $service === 'mail' => 'mail',
        $service === 'apns' => 'apns',
        $service === 'gcm' => 'gcm',
        $service === 'teamviewer' => 'team',
        $service === 'rdp' => 'rdp',
        $service === 'vnc' => 'vnc',
        $service === 'irc' => 'irc',
        $service === 'ftp' => 'ftp',
        $service === 'dot' => 'dot',
        $service === 'tcpmux' => 'mux',
        $service === 'unknown' => 'oth',
        preg_match('/^port(\d+)$/', $service, $m) === 1 => 'p' . substr($m[1], 0, 3),
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
        'sysl' => 'syslog',
        'rip' => 'routing',
        'snmp' => 'SNMP',
        'ssdp' => 'SSDP',
        'nut' => 'NUT/UPS',
        'olma' => 'Ollama',
        'vllm' => 'vLLM',
        'llm' => 'Local LLM',
        'tgi' => 'Text Gen',
        'grad' => 'Gradio',
        'jupy' => 'Jupyter',
        'ray' => 'Ray',
        'mlfl' => 'MLflow',
        'trtn' => 'Triton',
        'graf' => 'Grafana',
        'oai' => 'OpenAI',
        'xai' => 'xAI/Grok',
        'ngc' => 'NVIDIA Build',
        'anth' => 'Anthropic',
        'gemi' => 'Gemini',
        'hf' => 'Hugging Face',
        'civt' => 'Civitai',
        'qisk' => 'IBM Quantum',
        'nflx' => 'Netflix',
        'prim' => 'Prime Video',
        'ytub' => 'YouTube',
        'dis+' => 'Disney+',
        'hulu' => 'Hulu',
        'appl' => 'Apple/iCloud',
        'rdis' => 'Redis',
        'metr' => 'metrics',
        'ping' => 'ping',
        'plex' => 'Plex',
        'game' => 'game/Steam',
        'mqtt' => 'MQTT/TLS',
        'app' => 'app flow',
        'dhcp' => 'DHCP',
        'mdns' => 'mDNS',
        'mail' => 'mail',
        'apns' => 'Apple Push',
        'gcm' => 'Google Push',
        'team' => 'TeamViewer',
        'rdp' => 'RDP',
        'vnc' => 'VNC',
        'irc' => 'IRC',
        'ftp' => 'FTP',
        'dot' => 'DNS-over-TLS',
        'mux' => 'TCP mux',
        'oth', '?' => 'other service',
        default => preg_match('/^p(\d+)/', $short, $m) ? 'port ' . $m[1] : strtoupper($short),
    };
}

function app_label_from_text(string $text): string
{
    $text = strtolower($text);
    return match (true) {
        preg_match('/netflix|nflxvideo/', $text) === 1 => 'Netflix',
        preg_match('/primevideo|amazonvideo|aiv-cdn|atv-ps|media-amazon/', $text) === 1 => 'Prime Video',
        preg_match('/youtube|googlevideo|ytimg/', $text) === 1 => 'YouTube',
        preg_match('/disney|disneyplus|dssott/', $text) === 1 => 'Disney+',
        str_contains($text, 'hulu') => 'Hulu',
        preg_match('/max\.com|hbomax|hbo/', $text) === 1 => 'Max',
        preg_match('/peacocktv|peacock/', $text) === 1 => 'Peacock',
        preg_match('/paramountplus|cbsivideo|cbsaavideo/', $text) === 1 => 'Paramount+',
        str_contains($text, 'roku') => 'Roku',
        str_contains($text, 'plex') => 'Plex',
        preg_match('/apple|icloud|mzstatic|itunes|aaplimg|appldnld|tv\.apple/', $text) === 1 => 'Apple/iCloud',
        preg_match('/huggingface|hf\.co/', $text) === 1 => 'Hugging Face',
        str_contains($text, 'civitai') => 'Civitai',
        preg_match('/quantum-computing\.ibm|cloud\.ibm|ibm\.com/', $text) === 1 => 'IBM Quantum',
        preg_match('/openai|chatgpt/', $text) === 1 => 'OpenAI',
        preg_match('/anthropic|claude/', $text) === 1 => 'Anthropic',
        preg_match('/x\.ai|grok/', $text) === 1 => 'xAI/Grok',
        preg_match('/nvidia|build\.nvidia/', $text) === 1 => 'NVIDIA AI',
        str_contains($text, 'ollama') => 'Ollama',
        str_contains($text, 'vllm') => 'vLLM',
        preg_match('/googleapis|gstatic|googleusercontent/', $text) === 1 => 'Google APIs',
        preg_match('/doubleclick|googlesyndication|googleadservices/', $text) === 1 => 'Google Ads',
        preg_match('/microsoft|windowsupdate|office365|live\.com|msn\.com|azure/', $text) === 1 => 'Microsoft',
        preg_match('/amazonaws|cloudfront/', $text) === 1 => 'AWS/CloudFront',
        preg_match('/facebook|fbcdn|instagram|whatsapp/', $text) === 1 => 'Meta',
        str_contains($text, 'discord') => 'Discord',
        str_contains($text, 'spotify') => 'Spotify',
        preg_match('/steam|steampowered/', $text) === 1 => 'Steam',
        default => '',
    };
}

function endpoint_pair_text(string $srcRaw, string $dstRaw, int $width): string
{
    $src = compact_endpoint_label($srcRaw, false);
    $dst = compact_endpoint_label($dstRaw, false);
    $miniSrc = compact_endpoint_label($srcRaw, true);
    $miniDst = compact_endpoint_label($dstRaw, true);
    $candidates = [
        $src . ' -> ' . $dst,
        $miniSrc . ' -> ' . $miniDst,
        $src . '>' . $dst,
        $miniSrc . '>' . $miniDst,
    ];
    foreach ($candidates as $candidate) {
        if (cell_len($candidate) <= $width) {
            return $candidate;
        }
    }
    return truncate_modern_text(end($candidates), $width);
}

function flow_path_text(array $flow, int $width): string
{
    return endpoint_pair_text((string)($flow['src'] ?? ''), (string)($flow['dst'] ?? ''), $width);
}

function packet_flow_text(array $packet, int $width): string
{
    return endpoint_pair_text((string)($packet['src'] ?? ''), (string)($packet['dst'] ?? ''), $width);
}

function packet_event_tag(array $packet, int $width): string
{
    $category = strtoupper((string)($packet['category'] ?? ''));
    $verdict = strtoupper((string)($packet['verdict'] ?? ''));
    $service = strtolower((string)($packet['service'] ?? ''));

    $tag = match (true) {
        $verdict === 'SINKHOLE' => 'DNS SINK',
        $category === 'DNSBL' || $service === 'dnsbl' || str_contains($verdict, 'DNSBL') => 'DNS BLOCK',
        $category === 'IPS' => $verdict === 'DROP' ? 'IPS BLOCK' : 'IPS',
        $category === 'IDS' || $verdict === 'ALERT' => 'IDS ALERT',
        $verdict === 'DROP' => 'FIREWALL BLOCK',
        $verdict === 'PASS' => 'ALLOW',
        $category !== '' => $category,
        default => $verdict !== '' ? $verdict : 'EVENT',
    };

    return truncate_text($tag, $width);
}

function humanize_packet_story(string $story): string
{
    $story = preg_replace('/^(\d+)\s+WAN\s+scans\s+blocked\s+in\s+60s\s*\|\s*top ports:\s*/i', '$1 WAN scans stopped in 60s | ports ', $story) ?? $story;
    $story = preg_replace('/\bDNSBL\s+hit:\s*/i', 'DNS blocked ', $story) ?? $story;
    $story = str_replace(
        ['abuse:high', 'known-bad', 'top ports:'],
        ['abuse high', 'known bad', 'ports:'],
        $story
    );
    return $story;
}

function packet_story_text(array $packet, int $width): string
{
    if (!empty($packet['story'])) {
        return truncate_modern_text(humanize_packet_story((string)$packet['story']), $width);
    }
    $category = strtoupper((string)($packet['category'] ?? ''));
    $verdict = strtoupper((string)($packet['verdict'] ?? ''));
    $service = strtolower((string)($packet['service'] ?? ''));
    $dir = strtoupper((string)($packet['dir'] ?? ''));
    $proto = strtoupper((string)($packet['proto'] ?? 'IP'));
    $srcRaw = (string)($packet['src'] ?? '');
    $dstRaw = (string)($packet['dst'] ?? '');
    $mini = $width < 52;
    $src = compact_endpoint_label($srcRaw, $mini);
    $dst = compact_endpoint_label($dstRaw, $mini);
    $svc = service_human_label($service);
    $bytes = (string)($packet['size'] ?? '');
    $bytesText = ($bytes !== '' && $bytes !== '0B' && $bytes !== 'ctrl') ? ' ' . $bytes : '';
    $context = packet_context_suffix($packet);
    $portText = packet_port_text($packet);

    if ($category === 'DNSBL' || $service === 'dnsbl' || str_contains($verdict, 'DNSBL') || $verdict === 'SINKHOLE') {
        $context = packet_context_suffix($packet, ['dnsbl']);
        $domain = compact_domain_label($dstRaw !== '' ? $dstRaw : $dst, max(8, $width - cell_len($src) - 22));
        $verb = $verdict === 'SINKHOLE' ? 'DNS sinkholed' : 'DNS blocked';
        return truncate_modern_text(sprintf('%s %s %s%s', $src, $verb, $domain, $context), $width);
    }

    if ($category === 'IDS' || $category === 'IPS' || $verdict === 'ALERT') {
        $verb = $verdict === 'DROP' ? 'IPS blocked' : 'IDS alert';
        return truncate_modern_text(sprintf('%s %s -> %s %s%s', $verb, $src, $dst, $svc, $context), $width);
    }

    if ($verdict === 'DROP') {
        $srcExternal = endpoint_is_external($srcRaw) || endpoint_is_external($src);
        $dstExternal = endpoint_is_external($dstRaw) || endpoint_is_external($dst) || endpoint_is_wan($dstRaw) || endpoint_is_wan($dst);
        if ($dir === 'IN' && $srcExternal && $dstExternal) {
            $prefix = in_array('scanner', (array)($packet['context'] ?? []), true) ? 'WAN scan blocked' : 'WAN blocked';
            $context = packet_context_suffix($packet, ['scanner']);
            return truncate_modern_text(sprintf('%s %s%s%s', $prefix, $src, packet_port_text($packet, true), $context), $width);
        }
        if (endpoint_is_lan($srcRaw) || endpoint_is_lan($src)) {
            return truncate_modern_text(sprintf('LAN policy blocked %s -> %s %s%s', $src, $dst, $svc, $context), $width);
        }
        return truncate_modern_text(sprintf('Firewall blocked %s -> %s %s%s', $src, $dst, $svc, $context), $width);
    }

    if ($verdict === 'PASS') {
        return truncate_modern_text(sprintf('Allowed %s -> %s %s%s', $src, $dst, $svc, $bytesText), $width);
    }

    return truncate_modern_text(sprintf('%s %s -> %s %s %s%s', $proto, $src, $dst, $svc, $verdict, $bytesText), $width);
}

function compact_domain_label(string $domain, int $width): string
{
    $domain = trim(preg_replace('/:\d+$/', '', $domain) ?? $domain);
    if ($domain === '' || $width <= 0) {
        return '';
    }
    $app = app_label_from_text($domain);
    if ($app !== '') {
        return truncate_modern_text($app, $width);
    }
    if (cell_len($domain) <= $width) {
        return $domain;
    }
    if (filter_var($domain, FILTER_VALIDATE_IP) || str_starts_with($domain, 'LAN.') || str_starts_with($domain, 'EXT.')) {
        return truncate_modern_text(compact_endpoint_label($domain, $width < 12), $width);
    }
    $parts = array_values(array_filter(explode('.', $domain), static fn(string $part): bool => $part !== ''));
    if (count($parts) >= 2) {
        $root = implode('.', array_slice($parts, -2));
        if (cell_len($root) <= $width) {
            $prefix = $parts[0];
            $candidate = truncate_text($prefix, max(3, $width - cell_len($root) - 1)) . '.' . $root;
            if (cell_len($candidate) <= $width) {
                return $candidate;
            }
            return truncate_modern_text($root, $width);
        }
    }
    return truncate_modern_text($domain, $width);
}

function packet_context_suffix(array $packet, array $omit = []): string
{
    $context = $packet['context'] ?? [];
    if (!is_array($context)) {
        $context = preg_split('/\s*,\s*/', (string)$context) ?: [];
    }
    if (!empty($packet['country'])) {
        $context[] = strtoupper((string)$packet['country']);
    }
    $omit = array_map('strtolower', $omit);
    $context = array_values(array_unique(array_filter(array_map(static fn($label): string => human_context_label((string)$label), $context), static fn(string $label): bool => !in_array(strtolower($label), $omit, true))));
    if (!$context) {
        return '';
    }
    return ' [' . implode(' ', array_slice($context, 0, 3)) . ']';
}

function human_context_label(string $label): string
{
    $raw = trim($label);
    if (preg_match('/^[A-Z]{2}$/', $raw) === 1) {
        return $raw;
    }
    $label = strtolower($raw);
    return match ($label) {
        'abuse:high' => 'abuse high',
        'known-bad' => 'known bad',
        'dnsbl' => 'dnsbl',
        'scanner' => 'scanner',
        'tor?' => 'tor?',
        'botnet?' => 'botnet?',
        default => $label,
    };
}

function packet_port_text(array $packet, bool $compact = false): string
{
    $port = (string)($packet['dport'] ?? '');
    if ($port === '') {
        $port = (string)($packet['sport'] ?? '');
    }
    return $port !== '' ? ($compact ? ' p' . $port : ' port ' . $port) : '';
}

function endpoint_is_lan(string $label): bool
{
    $lower = strtolower($label);
    return str_contains($label, '/LAN.')
        || preg_match('/^LAN\.\d+$/', $label) === 1
        || preg_match('/^L\d+$/', $label) === 1
        || $label === 'LANv6'
        || $label === 'L6'
        || str_starts_with($lower, 'fd')
        || str_starts_with($lower, 'fc');
}

function endpoint_is_external(string $label): bool
{
    return str_starts_with($label, 'EXT.') || preg_match('/^E\d/', $label) === 1 || $label === 'EXTv6' || $label === 'E6';
}

function endpoint_is_wan(string $label): bool
{
    $label = strtoupper(trim($label));
    return $label === 'WAN' || str_starts_with($label, 'WAN:') || str_starts_with($label, 'WAN.');
}

function is_wan_scan_packet(array $packet): bool
{
    $category = strtoupper((string)($packet['category'] ?? ''));
    $verdict = strtoupper((string)($packet['verdict'] ?? ''));
    $dir = strtoupper((string)($packet['dir'] ?? ''));
    $src = (string)($packet['src'] ?? '');
    $dst = (string)($packet['dst'] ?? '');
    if ($category !== 'FW' || $verdict !== 'DROP' || $dir !== 'IN') {
        return false;
    }
    return endpoint_is_external($src) && (endpoint_is_wan($dst) || endpoint_is_external($dst));
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
    if (filter_var($label, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return compact_endpoint_label(lan_label($label), $mini);
    }
    if (preg_match('/^([^\/]+)\/(LAN\.\d+)$/', $label, $m)) {
        $name = trim($m[1]);
        return $mini ? str_replace('LAN.', 'L', $m[2]) : truncate_text($name !== '' ? $name : $m[2], 12);
    }
    if (preg_match('/\/(LAN\.\d+)/', $label, $m)) {
        return $mini ? str_replace('LAN.', 'L', $m[1]) : $m[1];
    }
    if (preg_match('/^LAN\.(\d+)$/', $label, $m)) {
        return $mini ? 'L' . $m[1] : $label;
    }
    if (preg_match('/^EXT\.(\d+(?:\.\d+)?)$/', $label, $m)) {
        return $mini ? 'E' . $m[1] : truncate_text($label, 12);
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
        return $mini ? str_replace('EXT.', 'E', truncate_text($label, 12)) : truncate_text($label, 12);
    }
    if (str_contains($label, '.')) {
        return compact_domain_label($label, $mini ? 8 : 16);
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
            'env_label1' => '',
            'env_label2' => '',
            'env_temp1' => '?',
            'env_temp2' => '?',
            'env_humidity1' => '?',
            'env_humidity2' => '?',
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
        'env_label1' => '',
        'env_label2' => '',
        'env_temp1' => '?',
        'env_temp2' => '?',
        'env_humidity1' => '?',
        'env_humidity2' => '?',
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

function colorize_line($line, bool $color): string
{
    $line = canvas_line($line);
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
        'purple' => "\033[38;5;201;1m",
        'dim' => "\033[38;5;245m",
        'white' => "\033[38;5;255;1m",
        'crit' => "\033[5;7;38;5;196;1m",
    ];
    if (terminal_night_active()) {
        $c['cyan'] = "\033[38;5;38m";
        $c['green'] = "\033[38;5;71m";
        $c['yellow'] = "\033[38;5;179m";
        $c['red'] = "\033[38;5;167m";
        $c['blue'] = "\033[38;5;68m";
        $c['purple'] = "\033[38;5;134m";
        $c['white'] = "\033[38;5;252m";
        $c['crit'] = "\033[7;38;5;160m";
    }
    $line = color_replace('/(-{2,})/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([+=|])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([┌┐└┘─│├┤┬┴┼])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(VPN DOWN(?:\s+\d+\/\d+)?|VPN:DOWN(?:\s+\d+\/\d+)?|WAN DEGRADED|VPN DEGRADED|INCIDENT|DATA STALE|STALE|DEGRADED|SPD:ERR)\b/i', $c['red'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(VPN PARTIAL(?:\s+\d+\/\d+)?|VPN:PARTIAL(?:\s+\d+\/\d+)?|VPN UNKNOWN|VPN:UNKNOWN|SPD:WAIT|SPD:RUN)\b/i', $c['yellow'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(VPN N\/A|VPN:N\/A|SPD:OFF)/i', $c['dim'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(WAN UP|WAN:UP|VPN UP(?:\s+\d+\/\d+)?|VPN:UP(?:\s+\d+\/\d+)?|DNS OK|DNS:OK|UPS ONLINE|UPS PROTECTED|DATA LIVE|LIVE|PASS|ALLOW|ONLINE|HEALTHY|STABLE|OK|UP|EST)\b/i', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[CRIT\])/', $c['crit'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[DROP\]|\[HIGH\])|\b(FW BLOCK|FIREWALL BLOCK|IPS BLOCK|DROP|REJECT|BLOCK|blocked|failed|failure|critical|CRIT|HIGH|DOWN)\b/i', $c['red'] . '$0' . $c['reset'], $line);
    $line = color_replace('/(\[WARN\]|\[MED\])|\b(DNS DENY|WARN|warning|MED|PARTIAL|UNKNOWN|WATCH|SECURITY WATCH|stale|rising|falling|latency|loss|scanner|suspicious|burst|high-rate|high usage|SYN|FIN|SING|MULT)\b/i', $c['yellow'] . '$0' . $c['reset'], $line);
    $line = color_replace('/(\[INFO\]|\[LOW\])/', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[FW\]|\[VPN\]|\[WAN\]|\[DHCP\]|\[ARP\]|\[FLOW\]|\[RADAR\]|\[PF\]|\[UPS\]|\[SYS\]|\[DNS\]|\[IFACE\]|\[DEVICE\]|\[PULSE\]|\[INC\]|\[SOCX\]|\[DOCTOR\]|\[BACKUP\]|\[CHANGE\]|\[AI\]|\[AUTO\]|\[LAB\]|\[INTEL\]|\[TTP\]|\[DETECT\]|\[EVID\]|\[CLOUD\]|\[SRC\])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[DNSBL\]|\[IDS\]|\[IPS\])|\b(DNS BLOCK|DNS SINK|DNSBL HIT|SINKHOLE|DNSBL|Suricata|suricata|Sigma|YARA|CVE|CPE|CWE|CAPEC|CVSS|EPSS|KEV|ATT&CK|D3FEND|OpenAI|Anthropic|Gemini|xAI|Grok|NVIDIA Build|Hugging Face|Ollama|vLLM|MIRANDA|Local LLM|reputation|threat-intel|known-bad|known bad|malware|botnet|C2|abuse:high|abuse high|tor\?)\b/i', $c['purple'] . '$0' . $c['reset'], $line);
    $line = color_replace('/\b(contain|quarantine|preserve|evidence|pcap|pfctl|config\.xml|CloudTrail|AzureActivity|VPC Flow|Windows|Linux|macOS|memory)\b/i', $c['yellow'] . '$0' . $c['reset'], $line);
    $line = color_replace('/\b(CPU|RAM|ARC|SWAP|PF|LAN|WAN|IN|OUT|VPN|UPS|NETWORK|MEMORY|TOTAL|IFTOPX|TCPDUMPX|PACKET RADAR|PFTOP|LIVE STATES|SOCX MODERN WALL|SOCX WALL|EVENT FEED|LIVE PACKETS|PROCESS TREE|PF STATES|THREAT PULSE|INCIDENT|SPEEDTEST|SPD|CLIENT|ROUTER|DIRECT|NYC|RCN-DE|RCN-VA|PATH|PATHS|AUTO|NIGHT|BASE|DOCTOR|BACKUP|CHANGE|PI|Mbps|STATES|SEARCH|TRAFFIC|TCP|UDP|ICMP|DIR|APP|PATH|TYPE|STAT|STATE|LEFT|PRO|SVC|RATE|FLOW|RADAR|AGE|EXP|PROTO|TEMP|HUMID|LOAD)\b/i', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(tls|web|dns|dnsblk|ssh|vpn|ntp|smb|sysl|rip|snmp|ssdp|rtmp|stun|nut|olma|vllm|llm|tgi|grad|jupy|ray|mlfl|trtn|graf|oai|xai|ngc|anth|gemi|hf|rdis|metr|ping|plex|dhcp|mdns|mail|apns|gcm|team|rdp|vnc|irc|ftp|dot|mux|oth|block|p\d{1,5})\b/i', $c['blue'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[[#!.]+\])/', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([█▇▆▅▄▃▂▁▓]+)/u', $c['green'] . '$1' . $c['reset'], $line);
    $line = color_replace('/([↓↑])/u', $c['yellow'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(→)/u', $c['dim'] . '$1' . $c['reset'], $line);
    $line = str_replace('◆', $c['cyan'] . '◆' . $c['reset'], $line);
    $line = str_replace($separatorMarker, $c['cyan'] . '◆' . $c['reset'], $line);
    $line = color_replace('/\bx(\d+)\b/', $c['yellow'] . 'x$1' . $c['reset'], $line);
    return $line . $c['reset'];
}

function color_replace(string $pattern, string $replacement, $line): string
{
    $line = canvas_line($line);
    $next = preg_replace($pattern, $replacement, $line);
    return is_string($next) ? $next : $line;
}

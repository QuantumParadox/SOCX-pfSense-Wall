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
$renderInterval = min($interval, $tickerConfig['interval_ms'] / 1000);
$renderInterval = max(0.025, $renderInterval);
$once = (bool)$opts['once'];
$state = [
    'net' => [],
    'time' => microtime(true),
    'tick' => 0,
    'ticker' => 0,
    'ticker_offset' => 0,
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
    'wan_history' => [],
    'lan_history' => [],
    'pf_history' => [],
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
            if (!$state['ticker_queue'] || (!$opts['demo'] && ($state['events_fresh'] ?? false))) {
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
    } else {
        $renderStart = microtime(true);
        echo render_ticker_update($frame, $cols, $rows, $color, $theme);
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
    echo "Demo preview: socx-wall.php --demo --mode wall --theme modern-btop --ticker-smooth --width 160 --height 42\n";
    echo "Capture preview: socx-wall.php --demo --once --mode wall --theme modern-btop --force-unicode --capture /tmp/socx-frame.txt\n";
}

function ticker_config(array $opts): array
{
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
    $defaultInterval = ((bool)($opts['ticker_smooth'] ?? false) || getenv('SOCX_TICKER_SMOOTH') === 'true') ? 75 : 25;
    $intervalMs = (int)$opts['ticker_interval_ms'] > 0 ? (int)$opts['ticker_interval_ms'] : (is_numeric($envInterval) ? (int)$envInterval : $defaultInterval);
    if (tmux_detected()) {
        $intervalMs = max(50, $intervalMs);
    }
    $intervalMs = max(25, min(500, $intervalMs));

    $envMax = getenv('SOCX_TICKER_MAX_EVENTS');
    $maxEvents = (int)$opts['ticker_max_events'] > 0 ? (int)$opts['ticker_max_events'] : (is_numeric($envMax) ? (int)$envMax : 25);
    $maxEvents = max(5, min(100, $maxEvents));

    $envDedupe = getenv('SOCX_TICKER_DEDUPE_SECONDS');
    $dedupe = (int)$opts['ticker_dedupe_seconds'] > 0 ? (int)$opts['ticker_dedupe_seconds'] : (is_numeric($envDedupe) ? (int)$envDedupe : 10);
    $dedupe = max(1, min(120, $dedupe));

    return [
        'speed' => $speed,
        'step' => $step,
        'interval_ms' => $intervalMs,
        'max_events' => $maxEvents,
        'dedupe_seconds' => $dedupe,
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
    $flows = flows_from_events($events);
    $packets = packets_from_events($events);

    $state['time'] = $now;
    $iflan = getenv('SOCX_IFLAN') ?: 'ix0';
    $ifwan = getenv('SOCX_IFWAN') ?: 'ix1';
    $wan = $net[$ifwan] ?? first_net($net);
    $lan = $net[$iflan] ?? first_net($net);
    $ups = collect_ups_metrics($state, $now);
    update_metric_histories($state, $now, $wan, $lan, $pf);

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
        'events' => array_column($events, 'ticker'),
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
            'status' => 'ONLINE',
            'watts' => $upsWatts,
            'load' => max(1, min(100, (int)round($upsWatts / 22))),
            'battery' => 100,
            'runtime' => '40m',
            'linev' => '120.1',
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
        ],
        'flows' => [
            ['src' => host_label('192.168.1.161', $hosts), 'dst' => 'LAN.180', 'up' => '9.38K', 'down' => '5.31K', 'class' => 'firewall', 'graph' => '[#######.]'],
            ['src' => host_label('192.168.1.102', $hosts), 'dst' => 'EXT.61.55', 'up' => '1.20K', 'down' => '1.06K', 'class' => 'internet', 'graph' => '[##......]'],
            ['src' => host_label('192.168.1.127', $hosts), 'dst' => 'EXT.199.64', 'up' => '37.2K', 'down' => '36.2K', 'class' => 'internet', 'graph' => '[######..]'],
            ['src' => host_label('192.168.1.121', $hosts), 'dst' => 'EXT.104.10', 'up' => '25.1K', 'down' => '1.53K', 'class' => 'internet', 'graph' => '[####....]'],
            ['src' => host_label('192.168.1.161', $hosts), 'dst' => 'beacons.gvt2.com.long.domain.example', 'up' => '120B', 'down' => '98B', 'class' => 'dnsbl', 'graph' => '[!.......]'],
        ],
        'packets' => [
            ['time' => '16:42:16', 'proto' => 'TCP', 'dir' => 'OUT', 'src' => host_label('192.168.1.102', $hosts), 'dst' => 'EXT.104.10', 'service' => 'https', 'size' => '39B', 'verdict' => 'PASS'],
            ['time' => '16:42:16', 'proto' => 'TCP', 'dir' => 'IN', 'src' => 'EXT.72.14', 'dst' => 'WAN', 'service' => 'https', 'size' => 'ctrl', 'verdict' => 'PASS'],
            ['time' => '16:42:17', 'proto' => 'UDP', 'dir' => 'OUT', 'src' => 'WAN', 'dst' => 'EXT.133.233', 'service' => 'vpn', 'size' => '1B', 'verdict' => 'PASS'],
            ['time' => '16:42:18', 'proto' => 'TCP', 'dir' => 'OUT', 'src' => host_label('192.168.1.161', $hosts), 'dst' => 'beacons.gvt2.com.long.domain.example', 'service' => 'http', 'size' => '0B', 'verdict' => 'BLOCK'],
            ['time' => '16:42:18', 'proto' => 'UDP', 'dir' => 'OUT', 'src' => 'WAN', 'dst' => '147.185.133.70:137', 'service' => 'unknown', 'size' => '0B', 'verdict' => 'BLOCK'],
        ],
        'events' => [
            '[DNSBL][LOW]  ' . host_label('192.168.1.161', $hosts) . ' -> beacons.gvt2.com blocked',
            '[FW][MED]    ' . host_label('192.168.1.161', $hosts) . ' -> 147.185.133.70:137 blocked',
            '[IDS][HIGH]  ' . host_label('192.168.1.102', $hosts) . ' -> suspicious outbound beacon',
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
    $cardWidths = weighted_widths($cols - ($cardGap * 4), [17, 13, 18, 15, 22]);
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
    $panel = panel_obj(0, $layout['ticker_y'], $cols, $layout['ticker_h'], 'EVENT TICKER', static fn(array $f, array $p): array => ticker_rows($f, $p));
    $ticker = ticker_rows($frame, $panel)[0] ?? '';
    $v = ($theme === 'modern-btop' && modern_border_style() === 'unicode') ? unicode_border_chars()['v'] : '|';
    $line = $v . pad_or_clip($ticker, $cols - 2) . $v;
    $ansiRow = $layout['ticker_y'] + 2;
    return "\033[" . $ansiRow . ";1H" . colorize_line($line, $color);
}

function modern_btop_layout(int $cols, int $rows): array
{
    $headerH = 3;
    $cardsH = $rows >= 38 ? 9 : 8;
    $tickerH = 3;
    $contentH = max(10, $rows - $headerH - $cardsH - $tickerH);
    $packetsH = min(12, max(5, intdiv($contentH, 2)));
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
    $pad = modern_border_style() === 'unicode' && $width >= 10 ? 2 : 1;
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
    $speed = strtoupper((string)(getenv('SOCX_TICKER_SPEED') ?: 'FAST'));
    return 'EVENT FEED [' . truncate_text($speed, 6) . ']';
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
            sprintf('WAN %s %s', down_marker(), compact_rate($f['wan']['down'])),
            sprintf('WAN %s %s', up_marker(), compact_rate($f['wan']['up'])),
            sprintf('LAN %s %s', down_marker(), compact_rate($f['lan']['down'])),
            sprintf('LAN %s %s', up_marker(), compact_rate($f['lan']['up'])),
            fit_sparkline($f['wan_history'] ?? [], $w),
        ];
    }
    return [
        sprintf('WAN  %s %-8s %s %-8s', down_marker(), $f['wan']['down'], up_marker(), $f['wan']['up']),
        'WAN  ' . fit_sparkline($f['wan_history'] ?? [], $w - 5),
        sprintf('LAN  %s %-8s %s %-8s', down_marker(), $f['lan']['down'], up_marker(), $f['lan']['up']),
        'LAN  ' . fit_sparkline($f['lan_history'] ?? [], $w - 5),
        truncate_text(($f['wan']['link'] ?? '') . '  ' . ($f['wan']['rtt'] ?? ''), $w),
    ];
}

function modern_pf_rows(array $f, array $p): array
{
    $pf = $f['pf'];
    $w = modern_content_width($p);
    if ($w < 22) {
        return [
            sprintf('st %s', $pf['states'] ?? '?'),
            sprintf('sr %s', compact_rate($pf['searches_rate'] ?? '?')),
            sprintf('blk %s', compact_num((int)($pf['blocked'] ?? 0))),
            sprintf('pass %s', compact_num((int)($pf['passed'] ?? 0))),
            fit_sparkline($f['pf_history'] ?? [], $w),
        ];
    }
    return [
        sprintf('states  %s', $pf['states'] ?? '?'),
        sprintf('search  %s', $pf['searches_rate'] ?? '?'),
        sprintf('blocked %s', compact_num((int)($pf['blocked'] ?? 0))),
        sprintf('passed  %s', compact_num((int)($pf['passed'] ?? 0))),
        fit_sparkline($f['pf_history'] ?? [], $w),
    ];
}

function modern_cpu_card_rows(array $f, array $p): array
{
    $cpu = $f['cpu'];
    $tick = (int)($f['tick'] ?? 0);
    $w = modern_content_width($p);
    if ($w < 22) {
        $rows = [sprintf('%d%% %s %s', $cpu['used'], str_replace('GHz', 'G', $cpu['freq']), modern_temp_text($cpu['temp']) )];
        foreach (array_slice($cpu['cores'], 0, max(1, $p['height'] - 4)) as $core) {
            $used = (int)round((float)$core['used']);
            $barW = max(2, min(6, $w - 9));
            $rows[] = sprintf('C%s %s %2d%%', $core['id'], animated_meter($used, $barW, $tick + (int)$core['id']), $used);
        }
        $rows[] = 'ld ' . implode(' ', array_slice($cpu['load'], 0, 2));
        return $rows;
    }
    $barW = max(8, min(18, $w - 11));
    $rows = [sprintf('%3d%%  %-7s  %s', $cpu['used'], $cpu['freq'], modern_temp_text($cpu['temp']))];
    foreach (array_slice($cpu['cores'], 0, max(1, $p['height'] - 4)) as $core) {
        $used = (int)round((float)$core['used']);
        $rows[] = sprintf('C%-2s %s %3d%%', $core['id'], animated_meter($used, $barW, $tick + (int)$core['id']), $used);
    }
    $rows[] = 'load ' . implode(' ', $cpu['load']);
    return $rows;
}

function modern_memory_rows(array $f, array $p): array
{
    $mem = $f['mem'];
    $w = modern_content_width($p);
    $arcPct = percent((int)$mem['arc_total'], max(1, (int)$mem['total']));
    if ($w < 22) {
        return [
            sprintf('RAM %d%%', (int)$mem['used_pct']),
            fit_bar((int)$mem['used_pct'], $w),
            sprintf('%s/%s', bytes_text((int)$mem['used']), bytes_text((int)$mem['total'])),
            sprintf('ARC %d%%', $arcPct),
            fit_bar($arcPct, $w),
            'free ' . bytes_text((int)$mem['free']),
        ];
    }
    return [
        sprintf('RAM %s / %s', bytes_text((int)$mem['used']), bytes_text((int)$mem['total'])),
        sprintf('%3d%% %s', (int)$mem['used_pct'], fit_bar((int)$mem['used_pct'], $w - 5)),
        sprintf('ARC %s / %s', bytes_text((int)$mem['arc_total']), bytes_text((int)$mem['total'])),
        sprintf('%3d%% %s', $arcPct, fit_bar($arcPct, $w - 5)),
        'free ' . bytes_text((int)$mem['free']),
    ];
}

function modern_ups_rows(array $f, array $p): array
{
    $ups = normalize_ups($f['ups'] ?? []);
    $w = modern_content_width($p);
    if (!$ups['online']) {
        return ['UPS unavailable', 'collector waiting', '', sparkline([], max(8, $w))];
    }
    $watts = center_text(sprintf('%s W', $ups['watts']), $w);
    if ($w < 22) {
        return [
            $watts,
            sprintf('load %s%% batt %s%%', $ups['load'], $ups['battery']),
            sprintf('run %s', $ups['runtime']),
            sprintf('pk %s av %s', $ups['peak60'], $ups['avg60']),
            fit_sparkline($ups['history'], $w),
        ];
    }
    return [
        $watts,
        sprintf('load %s%%  batt %s%%', $ups['load'], $ups['battery']),
        sprintf('run %s', $ups['runtime']),
        sprintf('pk %s  avg %s', $ups['peak60'], $ups['avg60']),
        fit_sparkline($ups['history'], $w),
    ];
}

function modern_process_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $cmdW = max(8, $w - 27);
    $rows = [sprintf('%-6s %-7s %-6s %5s %s', 'PID', 'USER', 'MEM', 'CPU%', 'COMMAND')];
    foreach (array_slice($f['procs'], 0, max(1, $p['height'] - 3)) as $proc) {
        $cmd = $cmdW < 10 ? (string)$proc['name'] : (string)$proc['cmd'];
        $rows[] = sprintf('%-6s %-7s %-6s %5.1f %s',
            truncate_text((string)$proc['pid'], 6),
            truncate_text((string)$proc['user'], 7),
            truncate_text(bytes_text((int)$proc['rss']), 6),
            (float)$proc['cpu'],
            truncate_modern_text($cmd, $cmdW));
    }
    return $rows;
}

function modern_flow_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    if ($w < 66) {
        $rows = ['# FLOW             RATE    CLS METER'];
        $max = max(1, $p['height'] - 3);
        $rateW = 7;
        $classW = 3;
        $graphW = max(4, min(8, $w - 29));
        $flowW = max(8, $w - $rateW - $classW - $graphW - 8);
        foreach (array_slice($f['flows'], 0, $max) as $idx => $flow) {
            $flowText = truncate_modern_text($flow['src'] . ' -> ' . $flow['dst'], $flowW);
            $rate = compact_rate_pair($flow['up'], $flow['down'], $rateW);
            $rows[] = sprintf('%02d %-*s %-*s %-*s %-*s',
                $idx + 1,
                $flowW,
                $flowText,
                $rateW,
                $rate,
                $classW,
                flow_class_short($flow['class']),
                $graphW,
                compact_flow_bar($flow['up'], $flow['down'], $flow['class'], $graphW));
        }
        return $rows;
    }
    $srcW = max(10, min(22, intdiv($w, 4)));
    $meterW = 8;
    $dstW = max(10, $w - $srcW - $meterW - 46);
    $rows = [sprintf('%-2s %-*s -> %-*s %-7s %-7s %-8s %s', '#', $srcW, 'SOURCE', $dstW, 'DESTINATION', 'UP', 'DOWN', 'CLASS', 'METER')];
    foreach (array_slice($f['flows'], 0, max(1, $p['height'] - 3)) as $idx => $flow) {
        $rows[] = sprintf('%02d %-*s -> %-*s %-7s %-7s %-8s %s',
            $idx + 1,
            $srcW, truncate_modern_text($flow['src'], $srcW),
            $dstW, truncate_modern_text($flow['dst'], $dstW),
            truncate_text($flow['up'], 7),
            truncate_text($flow['down'], 7),
            truncate_text($flow['class'], 8),
            compact_flow_bar($flow['up'], $flow['down'], $flow['class'], $meterW));
    }
    return $rows;
}

function modern_packet_rows(array $f, array $p): array
{
    $w = modern_content_width($p);
    $flowW = max(24, $w - 45);
    $rows = [sprintf('%-8s %-5s %-4s %-*s %-7s %-5s %-7s', 'TIME', 'PROTO', 'DIR', $flowW, 'SOURCE -> DESTINATION', 'SVC', 'SIZE', 'VERDICT')];
    foreach (array_slice($f['packets'], 0, max(1, $p['height'] - 3)) as $pkt) {
        $flow = $pkt['src'] . ' -> ' . $pkt['dst'];
        $rows[] = sprintf('%-8s %-5s %-4s %-*s %-7s %-5s %-7s',
            $pkt['time'], $pkt['proto'], $pkt['dir'], $flowW, truncate_text($flow, $flowW),
            truncate_text($pkt['service'], 7), truncate_text($pkt['size'], 5), $pkt['verdict']);
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
    $width = modern_content_width($p);
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
    $pf = ['states' => '?', 'searches_rate' => '?', 'passed' => 0, 'blocked' => 0];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/current entries\s+(\d+)/', $line, $m)) {
            $pf['states'] = $m[1];
        } elseif (preg_match('/searches\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['searches_rate'] = $m[1] . '/s';
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

    return [
        'online' => $watts !== null,
        'status' => (string)($raw['status'] ?? ($watts !== null ? 'ONLINE' : '')),
        'watts' => $watts ?? '?',
        'load' => isset($raw['load']) && is_numeric($raw['load']) ? (string)(int)round((float)$raw['load']) : '?',
        'battery' => isset($raw['battery']) && is_numeric($raw['battery']) ? (string)(int)round((float)$raw['battery']) : '?',
        'runtime' => isset($raw['runtime']) && is_numeric($raw['runtime']) ? format_runtime((float)$raw['runtime']) : '?',
        'linev' => isset($raw['linev']) && is_numeric($raw['linev']) ? sprintf('%.1f', (float)$raw['linev']) : '?',
        'updated_age' => $updated,
        'peak60' => $peak,
        'avg60' => $avg,
        'history' => $history,
    ];
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

function collect_events(array $hosts): array
{
    $events = [];
    $filter = tail_lines('/var/log/filter.log', 120);
    foreach ($filter as $line) {
        $event = parse_filter_event($line, $hosts);
        if ($event !== null) {
            $events[] = $event;
        }
    }
    foreach (['/var/log/pfblockerng/dnsbl.log', '/var/log/pfblockerng/dns_reply.log'] as $file) {
        foreach (tail_lines($file, 20) as $line) {
            $event = parse_dnsbl_event($line, $hosts);
            if ($event !== null) {
                $events[] = $event;
            }
        }
    }
    return array_slice(array_reverse($events), 0, 40);
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

function update_ticker_queue(array &$state, array $events, float $now): void
{
    $cfg = $state['ticker_config'] ?? ticker_config([]);
    $maxEvents = (int)$cfg['max_events'];
    $dedupeSeconds = (int)$cfg['dedupe_seconds'];

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
                    break;
                }
            }
            unset($item);
            continue;
        }

        $state['ticker_seen'][$key] = ['last' => $now, 'count' => 1];
        array_unshift($state['ticker_queue'], ['key' => $key, 'text' => $text, 'count' => 1, 'last' => $now]);

        if (is_high_or_crit($text)) {
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
    foreach ($state['ticker_queue'] ?? [] as $item) {
        $text = (string)$item['text'];
        $count = (int)($item['count'] ?? 1);
        if ($count > 1) {
            $text .= ' x' . $count;
        }
        $rows[] = $text;
    }
    if (!$rows && isset($state['last_frame']['events'])) {
        foreach ($state['last_frame']['events'] as $event) {
            $rows[] = clean_ticker_event((string)$event);
        }
    }
    return $rows;
}

function advance_ticker(array &$state, float $now): void
{
    if ($now < (float)($state['ticker_hold_until'] ?? 0.0)) {
        $state['last_ticker_advance_at'] = $now;
        return;
    }
    $cfg = $state['ticker_config'] ?? ticker_config([]);
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
    $proto = strtoupper($fields[16] ?? ($fields[15] ?? 'IP'));
    $len = preg_replace('/\D/', '', $fields[17] ?? '') ?: '0';
    $src = $fields[18] ?? '';
    $dst = $fields[19] ?? '';
    $sport = $fields[20] ?? '';
    $dport = $fields[21] ?? '';
    if ($src === '' || $dst === '') {
        return null;
    }
    $srcLabel = endpoint_label($src, $sport, $hosts);
    $dstLabel = endpoint_label($dst, $dport, $hosts);
    $verdict = $action === 'block' ? 'BLOCK' : 'PASS';
    $svc = service_name($dport ?: $sport);
    $time = preg_match('/(\d{2}:\d{2}:\d{2})/', $line, $m) ? $m[1] : date('H:i:s');
    $severity = $verdict === 'BLOCK' ? 'MED' : 'LOW';
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
        'class' => $verdict === 'BLOCK' ? 'blocked' : (($svc === 'dns' || $svc === 'https') ? 'internet' : 'firewall'),
        'ticker' => sprintf('[FW][%s] %s -> %s %s', $severity, $srcLabel, $dstLabel, strtolower($verdict)),
    ];
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
        'verdict' => 'DNSBL',
        'class' => 'dnsbl',
        'ticker' => sprintf('[DNSBL][LOW] %s -> %s blocked', $src, $domain),
    ];
}

function flows_from_events(array $events): array
{
    $agg = [];
    foreach ($events as $e) {
        $key = $e['src'] . '|' . $e['dst'] . '|' . $e['class'];
        if (!isset($agg[$key])) {
            $agg[$key] = ['src' => $e['src'], 'dst' => $e['dst'], 'up_bytes' => 0, 'down_bytes' => 0, 'class' => $e['class'], 'total' => 0];
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
            'graph' => graph_bar((int)$row['total'], $max, $row['class'] === 'dnsbl' || $row['class'] === 'blocked'),
        ];
    }
    if (!$rows) {
        $rows[] = ['src' => 'pfSense', 'dst' => 'waiting', 'up' => '0B', 'down' => '0B', 'class' => 'idle', 'graph' => '[........]'];
    }
    return $rows;
}

function packets_from_events(array $events): array
{
    $rows = [];
    foreach (array_slice($events, 0, 12) as $e) {
        $rows[] = ['time' => $e['time'], 'proto' => $e['proto'], 'dir' => $e['dir'], 'src' => $e['src'], 'dst' => $e['dst'], 'service' => $e['service'], 'size' => $e['size'], 'verdict' => $e['verdict']];
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
        '500', '4500' => 'vpn',
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

function compact_rate(string $rate): string
{
    $rate = str_replace('/s', '', $rate);
    return truncate_text($rate, 8);
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
        'dnsbl' => 'dns',
        'blocked' => 'blk',
        'firewall' => 'fw',
        'internet' => 'net',
        default => truncate_text($class, 3),
    };
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
            'status' => '',
            'watts' => '?',
            'load' => '?',
            'battery' => '?',
            'runtime' => '?',
            'linev' => '?',
            'peak60' => '?',
            'avg60' => '?',
            'history' => [],
        ];
    }
    return parse_ups_status_line((string)$ups) + [
        'online' => $ups !== '',
        'watts' => '?',
        'load' => '?',
        'battery' => '?',
        'runtime' => '?',
        'linev' => '?',
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
    return sprintf('UPS %sW load %s%% batt %s%% run %s', $ups['watts'], $ups['load'], $ups['battery'], $ups['runtime']);
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
    $line = color_replace('/\b(BLOCK|blocked|HIGH|\[HIGH\])\b/', $c['red'] . '$1' . $c['reset'], $line);
    $line = color_replace('/\b(DNSBL|WARN|warning|MED|LOW|\[LOW\]|\[FW\]|\[IDS\]|\[MED\]|\[DNSBL\])\b/', $c['yellow'] . '$1' . $c['reset'], $line);
    $line = color_replace('/(\[CRIT\])/', $c['crit'] . '$1' . $c['reset'], $line);
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

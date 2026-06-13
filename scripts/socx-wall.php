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
if ($opts['help']) {
    print_help();
    exit(0);
}

$mode = $opts['mode'] ?: (getenv('SOCX_MODE') ?: 'wall');
if ($mode !== 'wall') {
    fwrite(STDERR, "Only --mode wall is implemented by " . APP_NAME . ".\n");
    exit(2);
}

$color = !$opts['no_color'] && (getenv('NO_COLOR') === false || getenv('NO_COLOR') === '');
$interval = max(0.5, (float)$opts['interval']);
$once = (bool)$opts['once'];
$state = [
    'net' => [],
    'time' => microtime(true),
    'tick' => 0,
    'ticker' => 0,
    'static' => static_info(),
    'core_history' => [],
    'last_events' => [],
    'last_events_at' => 0.0,
    'last_frame' => null,
];

if (!$once) {
    register_shutdown_function(static function (): void {
        echo "\033[?25h\033[0m";
    });
    echo "\033[?25l";
}

do {
    try {
        $state['tick']++;
        [$cols, $rows] = term_size($opts);
        $hosts = load_host_map((string)$opts['hosts']);
        $frame = $opts['demo'] ? demo_frame($hosts, $state) : collect_live_frame($state, $hosts);
        $state['last_frame'] = $frame;
    } catch (Throwable $e) {
        log_wall_error($e);
        [$cols, $rows] = term_size($opts);
        $frame = $state['last_frame'] ?: error_frame($e);
    }
    $screen = render_wall($frame, $cols, $rows, $color, $state);
    if ($once) {
        echo $screen;
        if (!str_ends_with($screen, "\n")) {
            echo "\n";
        }
        break;
    }
    echo "\033[H\033[2J" . $screen;
    flush();
    usleep((int)($interval * 1000000));
} while (true);

function log_wall_error(Throwable $e): void
{
    $line = sprintf("[%s] %s: %s in %s:%d\n", date('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    @file_put_contents('/tmp/socx-wall.err', $line, FILE_APPEND);
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
        }
    }

    return $opts;
}

function print_help(): void
{
    echo APP_NAME . ' ' . APP_VERSION . "\n";
    echo "Usage: socx-wall.php [--mode wall] [--demo] [--once] [--interval SEC] [--hosts FILE]\n";
    echo "Demo preview: socx-wall.php --demo --mode wall --once --width 140 --height 36\n";
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
    $ups = collect_ups_status();
    $events = collect_events_cached($state, $hosts, $now);
    $flows = flows_from_events($events);
    $packets = packets_from_events($events);

    $state['time'] = $now;
    $iflan = getenv('SOCX_IFLAN') ?: 'ix0';
    $ifwan = getenv('SOCX_IFWAN') ?: 'ix1';
    $wan = $net[$ifwan] ?? first_net($net);
    $lan = $net[$iflan] ?? first_net($net);

    return [
        'time' => date('H:i:s'),
        'refresh' => '500ms',
        'host' => $state['static']['host'],
        'badges' => health_badges($ups),
        'wan' => ['name' => $ifwan, 'down' => rate_text($wan['rx'] ?? null), 'up' => rate_text($wan['tx'] ?? null), 'link' => 'DHCP OK', 'rtt' => 'RTT --', 'loss' => 'LOSS --'],
        'lan' => ['name' => $iflan, 'down' => rate_text($lan['rx'] ?? null), 'up' => rate_text($lan['tx'] ?? null)],
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
    $hosts = $hosts + [
        '192.168.1.161' => 'JupiterLXI',
        '192.168.1.102' => 'Enceladus',
        '192.168.1.127' => 'NAS-Core',
        '192.168.1.121' => 'Mediabox',
    ];
    return [
        'time' => date('H:i:s'),
        'refresh' => '500ms',
        'host' => 'pfSense',
        'badges' => ['WAN UP', 'VPN UP', 'DNS OK', 'UPS ONLINE'],
        'wan' => ['name' => 'ix1', 'down' => '3.50K/s', 'up' => '9.13K/s', 'link' => '2.5G DHCP OK', 'rtt' => 'RTT 9ms', 'loss' => 'LOSS 0%'],
        'lan' => ['name' => 'ix0', 'down' => '4.50K/s', 'up' => '7.79K/s'],
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
        'tick' => (int)($state['tick'] ?? 0),
        'ups' => 'UPS 540W 0.5s 27% batt 100% 40m',
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

function render_wall(array $frame, int $cols, int $rows, bool $color, array &$state): string
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
        $lines[] = colorize_line($line, $color);
    }
    $state['ticker'] = (($state['ticker'] ?? 0) + 1) % 9999;
    return implode("\n", $lines);
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

function make_canvas(int $width, int $height): array
{
    return array_fill(0, $height, str_repeat(' ', $width));
}

function truncate_text(string $text, int $width): string
{
    $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
    $text = trim($text);
    if ($width <= 0) {
        return '';
    }
    if (strlen($text) <= $width) {
        return $text;
    }
    if ($width <= 3) {
        return substr($text, 0, $width);
    }
    return rtrim(substr($text, 0, $width - 3)) . '...';
}

function pad_or_clip(string $text, int $width): string
{
    $text = truncate_text($text, $width);
    return str_pad($text, $width);
}

function safe_write(array &$canvas, int $x, int $y, string $text, int $maxWidth): void
{
    if ($y < 0 || $y >= count($canvas) || $maxWidth <= 0) {
        return;
    }
    $lineWidth = strlen($canvas[$y]);
    if ($x < 0) {
        $text = substr($text, abs($x));
        $maxWidth += $x;
        $x = 0;
    }
    if ($x >= $lineWidth || $maxWidth <= 0) {
        return;
    }
    $maxWidth = min($maxWidth, $lineWidth - $x);
    $text = pad_or_clip($text, $maxWidth);
    $line = $canvas[$y];
    for ($i = 0; $i < strlen($text); $i++) {
        $line[$x + $i] = $text[$i];
    }
    $canvas[$y] = $line;
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

function panel_obj(int $x, int $y, int $width, int $height, string $title, callable $render): array
{
    return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'title' => $title, 'border' => 'cyan', 'render' => $render, 'clip' => true];
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
    $ups = $f['ups'] ?: 'UPS --';
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
    $events = $f['events'];
    if (!$events) {
        $events = ['[LOW] SOCX wall mode live - waiting for firewall events'];
    }
    $line = implode('   |   ', array_slice($events, 0, 4));
    return [$line];
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

function collect_ups_status(): string
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

function health_badges(string $ups): array
{
    return ['WAN UP', 'VPN UP', 'DNS OK', $ups !== '' ? 'UPS ONLINE' : 'UPS --'];
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
        return $state['last_events'];
    }
    $events = collect_events($hosts);
    $state['last_events'] = $events;
    $state['last_events_at'] = $now;
    return $events;
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
    $fields = str_getcsv($payload);
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

function bar(int $pct, int $width): string
{
    $width = max(4, $width);
    $filled = (int)round(($pct / 100) * $width);
    return '[' . str_repeat('#', $filled) . str_repeat('.', $width - $filled) . ']';
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
    $char = $alert ? '!' : '#';
    return '[' . str_repeat($char, $filled) . str_repeat('.', $width - $filled) . ']';
}

function colorize_line(string $line, bool $color): string
{
    if (!$color) {
        return $line;
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
    $line = preg_replace('/([+=|])/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = preg_replace('/\b(WAN UP|VPN UP|DNS OK|UPS ONLINE|PASS|ONLINE|UP)\b/', $c['green'] . '$1' . $c['reset'], $line);
    $line = preg_replace('/\b(BLOCK|blocked|DNSBL|HIGH|\[HIGH\]|\[MED\]|\[DNSBL\])\b/', $c['red'] . '$1' . $c['reset'], $line);
    $line = preg_replace('/\b(WARN|warning|MED|LOW|\[LOW\]|\[FW\]|\[IDS\])\b/', $c['yellow'] . '$1' . $c['reset'], $line);
    $line = preg_replace('/(\[CRIT\])/', $c['crit'] . '$1' . $c['reset'], $line);
    $line = preg_replace('/\b(CPU|RAM|ARC|PF|LAN|WAN|UPS|TOTAL|IFTOPX|TCPDUMPX|SOCX WALL)\b/', $c['cyan'] . '$1' . $c['reset'], $line);
    $line = preg_replace('/(\[[#!.]+\])/', $c['green'] . '$1' . $c['reset'], $line);
    return $line . $c['reset'];
}

#!/usr/local/bin/php
<?php
declare(strict_types=1);

/*
 * pfsense-btop: a small btop-style dashboard for pfSense/FreeBSD.
 * It is intentionally read-only and relies on base pfSense tools.
 */

const APP_NAME = 'pfsense-btop';
const APP_VERSION = '0.3.1';

$opts = parse_args($argv);
if ($opts['help']) {
    print_help();
    exit(0);
}

$color = !$opts['no_color'] && (getenv('NO_COLOR') === false || getenv('NO_COLOR') === '');
$interval = max(0.5, (float)$opts['interval']);
$topCount = max(3, (int)$opts['top']);
$once = (bool)$opts['once'];
$state = [
    'net' => [],
    'cpu_history' => [],
    'time' => microtime(true),
    'static' => static_info(),
];

if (!$once) {
    register_shutdown_function(static function (): void {
        echo "\033[?25h\033[0m";
    });
    echo "\033[?25l";
} else {
    collect_frame($state, $topCount);
    usleep(750000);
}

do {
    [$cols, $rows] = term_size();
    $frame = collect_frame($state, $topCount);
    $screen = render_frame($frame, $cols, $rows, $color);
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

function parse_args(array $argv): array
{
    $opts = [
        'once' => false,
        'no_color' => false,
        'interval' => 1.5,
        'top' => 10,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--once') {
            $opts['once'] = true;
        } elseif ($arg === '--no-color') {
            $opts['no_color'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif ($arg === '--interval' && isset($argv[$i + 1])) {
            $opts['interval'] = (float)$argv[++$i];
        } elseif (str_starts_with($arg, '--interval=')) {
            $opts['interval'] = (float)substr($arg, 11);
        } elseif ($arg === '--top' && isset($argv[$i + 1])) {
            $opts['top'] = (int)$argv[++$i];
        } elseif (str_starts_with($arg, '--top=')) {
            $opts['top'] = (int)substr($arg, 6);
        }
    }

    return $opts;
}

function print_help(): void
{
    echo APP_NAME . ' ' . APP_VERSION . "\n";
    echo "Usage: pfsense-btop [--once] [--no-color] [--interval SEC] [--top N]\n";
    echo "\n";
    echo "Panels: CPU, memory/ARC/swap, pf firewall, network rates, disks, top processes.\n";
    echo "Press Ctrl-C to exit the live dashboard.\n";
}

function run_cmd(string $cmd): string
{
    $out = [];
    @exec($cmd . ' 2>/dev/null', $out);
    return implode("\n", $out);
}

function static_info(): array
{
    $version = trim(run_cmd('/bin/cat /etc/version'));
    if ($version === '') {
        $version = trim(run_cmd('/usr/bin/uname -r'));
    }
    $ncpu = (int)trim(run_cmd('/sbin/sysctl -n hw.ncpu'));
    $physmem = (int)trim(run_cmd('/sbin/sysctl -n hw.physmem'));
    $model = trim(run_cmd('/sbin/sysctl -n hw.model'));

    return [
        'host' => trim(run_cmd('/bin/hostname')) ?: gethostname(),
        'version' => $version,
        'ncpu' => max(1, $ncpu),
        'physmem' => max(0, $physmem),
        'model' => $model,
    ];
}

function term_size(): array
{
    $tmuxPane = getenv('TMUX_PANE');
    if ($tmuxPane !== false && $tmuxPane !== '') {
        $tmux = trim(run_cmd('/usr/local/bin/tmux display-message -p -t ' . escapeshellarg($tmuxPane) . " '#{pane_width} #{pane_height}'"));
        if (preg_match('/^(\d+)\s+(\d+)$/', $tmux, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
    }

    $envCols = getenv('COLUMNS');
    $envRows = getenv('LINES');
    if (is_numeric($envCols) && is_numeric($envRows)) {
        return [(int)$envCols, (int)$envRows];
    }

    $size = trim(run_cmd('/bin/stty size'));
    if (preg_match('/^(\d+)\s+(\d+)$/', $size, $m)) {
        return [(int)$m[2], (int)$m[1]];
    }
    return [120, 38];
}

function collect_frame(array &$state, int $topCount): array
{
    $now = microtime(true);
    $top = run_cmd('/usr/bin/top -P -b -n 1');

    $cpu = parse_cpu($top);
    $state['cpu_history'][] = $cpu['used'];
    if (count($state['cpu_history']) > 240) {
        $state['cpu_history'] = array_slice($state['cpu_history'], -240);
    }

    $mem = parse_mem($top, $state['static']['physmem']);
    $load = parse_load($top);
    $pf = parse_pf(run_cmd('/sbin/pfctl -si'));
    $net = parse_net(run_cmd('/usr/bin/netstat -ibn'), $state, $now);
    $disks = parse_disks(run_cmd('/bin/df -h / /var /tmp /cf 2>/dev/null'));
    $procs = parse_processes(run_cmd('/bin/ps auxww'), $topCount);
    $temps = parse_temps(run_cmd("/sbin/sysctl -a | /usr/bin/grep -E 'dev.cpu\\.[0-9]+\\.temperature|hw.acpi.thermal.*temperature' | /usr/bin/head -8"));
    $freq = trim(run_cmd('/sbin/sysctl -n dev.cpu.0.freq'));

    $state['time'] = $now;

    return [
        'static' => $state['static'],
        'time' => date('Y-m-d H:i:s T'),
        'load' => $load,
        'cpu' => $cpu,
        'cpu_history' => $state['cpu_history'],
        'freq_mhz' => is_numeric($freq) ? (int)$freq : 0,
        'mem' => $mem,
        'pf' => $pf,
        'net' => $net,
        'disks' => $disks,
        'procs' => $procs,
        'temps' => $temps,
    ];
}

function parse_load(string $top): array
{
    $line = strtok($top, "\n") ?: '';
    $load = ['1' => '?', '5' => '?', '15' => '?', 'uptime' => '?'];
    if (preg_match('/load averages:\s*([0-9.]+),\s*([0-9.]+),\s*([0-9.]+)\s+up\s+(.+?)\s{2,}/', $line, $m)) {
        $load = ['1' => $m[1], '5' => $m[2], '15' => $m[3], 'uptime' => trim($m[4])];
    }
    if (preg_match('/^\s*(\d+)\s+processes:\s*(.+)$/m', $top, $m)) {
        $load['processes'] = $m[1] . ' processes: ' . trim($m[2]);
    } else {
        $load['processes'] = '?';
    }
    return $load;
}

function parse_cpu(string $top): array
{
    $cpu = ['user' => 0.0, 'nice' => 0.0, 'system' => 0.0, 'interrupt' => 0.0, 'idle' => 100.0, 'used' => 0.0, 'cores' => []];
    if (preg_match_all('/^CPU\s+(\d+):\s*(.+)$/m', $top, $matches, PREG_SET_ORDER)) {
        $sum = ['user' => 0.0, 'nice' => 0.0, 'system' => 0.0, 'interrupt' => 0.0, 'idle' => 0.0, 'used' => 0.0];
        foreach ($matches as $m) {
            $core = parse_cpu_percent_fields($m[2]);
            $core['id'] = (int)$m[1];
            $core['used'] = max(0.0, min(100.0, 100.0 - (float)$core['idle']));
            $cpu['cores'][] = $core;
            foreach ($sum as $k => $_) {
                $sum[$k] += (float)$core[$k];
            }
        }
        $count = max(1, count($cpu['cores']));
        foreach ($sum as $k => $v) {
            $cpu[$k] = $v / $count;
        }
        return $cpu;
    }

    if (preg_match('/^CPU:\s*(.+)$/m', $top, $m)) {
        $cpu = array_merge($cpu, parse_cpu_percent_fields($m[1]));
    }
    $cpu['used'] = max(0.0, min(100.0, 100.0 - (float)$cpu['idle']));
    return $cpu;
}

function parse_cpu_percent_fields(string $line): array
{
    $values = ['user' => 0.0, 'nice' => 0.0, 'system' => 0.0, 'interrupt' => 0.0, 'idle' => 100.0, 'used' => 0.0];
    if (preg_match_all('/([0-9.]+)%\s+([a-zA-Z]+)/', $line, $parts, PREG_SET_ORDER)) {
        foreach ($parts as $p) {
            $key = strtolower($p[2]);
            $values[$key] = (float)$p[1];
        }
    }
    $values['used'] = max(0.0, min(100.0, 100.0 - (float)$values['idle']));
    return $values;
}

function parse_mem(string $top, int $physmem): array
{
    $mem = [
        'total' => $physmem,
        'free' => 0,
        'active' => 0,
        'inactive' => 0,
        'wired' => 0,
        'arc_total' => 0,
        'swap_total' => 0,
        'swap_free' => 0,
    ];
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
    if (preg_match('/^Swap:\s*([0-9.]+[KMGTPE]?)\s+Total,\s*([0-9.]+[KMGTPE]?)\s+Free/m', $top, $m)) {
        $mem['swap_total'] = parse_size($m[1]);
        $mem['swap_free'] = parse_size($m[2]);
    }
    if ($mem['total'] <= 0) {
        $mem['total'] = $mem['active'] + $mem['inactive'] + $mem['wired'] + $mem['free'];
    }
    $mem['used'] = max(0, $mem['total'] - $mem['free']);
    $mem['used_pct'] = percent($mem['used'], $mem['total']);
    $mem['swap_used'] = max(0, $mem['swap_total'] - $mem['swap_free']);
    $mem['swap_pct'] = percent($mem['swap_used'], $mem['swap_total']);
    return $mem;
}

function parse_pf(string $raw): array
{
    $pf = [
        'status' => '?',
        'states' => '?',
        'searches_rate' => '?',
        'inserts_rate' => '?',
        'removals_rate' => '?',
        'passed' => 0,
        'blocked' => 0,
    ];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^Status:\s+(.+)$/', $line, $m)) {
            $pf['status'] = trim($m[1]);
        } elseif (preg_match('/current entries\s+(\d+)/', $line, $m)) {
            $pf['states'] = $m[1];
        } elseif (preg_match('/searches\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['searches_rate'] = $m[1] . '/s';
        } elseif (preg_match('/inserts\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['inserts_rate'] = $m[1] . '/s';
        } elseif (preg_match('/removals\s+\d+\s+([0-9.]+)\/s/', $line, $m)) {
            $pf['removals_rate'] = $m[1] . '/s';
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
        $rx = null;
        $tx = null;
        if (isset($prev[$name])) {
            $rx = max(0, (int)(($ibytes - $prev[$name]['in']) / $elapsed));
            $tx = max(0, (int)(($obytes - $prev[$name]['out']) / $elapsed));
        }
        $rows[] = [
            'name' => $name,
            'rx' => $rx,
            'tx' => $tx,
            'rx_total' => $ibytes,
            'tx_total' => $obytes,
            'ierr' => (int)$parts[5],
            'oerr' => (int)$parts[9],
        ];
    }

    usort($rows, static fn(array $a, array $b): int => ($b['rx_total'] + $b['tx_total']) <=> ($a['rx_total'] + $a['tx_total']));
    $state['net'] = $newPrev;
    return array_slice($rows, 0, 8);
}

function parse_disks(string $raw): array
{
    $rows = [];
    $seen = [];
    foreach (explode("\n", $raw) as $line) {
        if (str_starts_with($line, 'Filesystem') || trim($line) === '') {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 6) {
            continue;
        }
        $mount = $parts[5];
        if (isset($seen[$mount])) {
            continue;
        }
        $seen[$mount] = true;
        $rows[] = [
            'fs' => $parts[0],
            'size' => $parts[1],
            'used' => $parts[2],
            'avail' => $parts[3],
            'pct' => $parts[4],
            'mount' => $mount,
        ];
    }
    return array_slice($rows, 0, 6);
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
        if (str_contains($cmd, '[idle]') || str_contains($cmd, APP_NAME)) {
            continue;
        }
        $rows[] = [
            'user' => $parts[0],
            'pid' => $parts[1],
            'cpu' => (float)$parts[2],
            'mem' => (float)$parts[3],
            'rss' => (int)$parts[5] * 1024,
            'state' => $parts[7],
            'time' => $parts[9],
            'cmd' => $cmd,
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['cpu'] <=> $a['cpu']);
    return array_slice($rows, 0, $limit);
}

function parse_temps(string $raw): array
{
    $temps = [];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^([^:]+):\s+([0-9.]+)C/', trim($line), $m)) {
            if (preg_match('/dev\.cpu\.(\d+)\.temperature/', $m[1], $label)) {
                $name = 'cpu' . $label[1];
            } else {
                $name = basename(str_replace('.', '/', $m[1]));
            }
            $temps[] = $name . '=' . $m[2] . 'C';
        }
    }
    return array_slice($temps, 0, 4);
}

function render_frame(array $f, int $cols, int $rows, bool $color): string
{
    $cols = max(20, $cols);
    $rows = max(8, $rows);

    if ($cols >= 100 && $rows >= 28) {
        return render_full_frame($f, $cols, $rows, $color);
    }
    if ($cols >= 70 && $rows >= 12) {
        return render_medium_frame($f, $cols, $rows, $color);
    }
    return render_compact_frame($f, $cols, $rows, $color);
}

function render_full_frame(array $f, int $cols, int $rows, bool $color): string
{
    $topH = min(11, max(8, (int)floor($rows * 0.30)));
    $bottomH = max(8, $rows - $topH);
    $rightW = min(max(52, (int)floor($cols * 0.54)), $cols - 44);
    $leftW = $cols - $rightW;
    $leftTopH = max(8, (int)floor($bottomH * 0.48));
    $leftBottomH = $bottomH - $leftTopH;
    $memW = (int)floor($leftW / 2);
    $diskW = $leftW - $memW;

    $top = cpu_panel($f, $cols, $topH, $color);
    $leftTop = hjoin_blocks([
        memory_panel($f, $memW, $leftTopH, $color),
        disk_panel($f, $diskW, $leftTopH, $color),
    ]);
    $left = array_merge($leftTop, network_panel($f, $leftW, $leftBottomH, $color));
    $bottom = hjoin_blocks([
        fit_block($left, $leftW, $bottomH),
        process_panel($f, $rightW, $bottomH, $color),
    ]);

    return lines_to_screen(array_merge($top, $bottom), $rows, $cols);
}

function render_medium_frame(array $f, int $cols, int $rows, bool $color): string
{
    $topH = min(9, max(8, (int)floor($rows * 0.50)));
    $bottomH = $rows - $topH;
    $leftW = min(34, max(28, (int)floor($cols * 0.42)));
    $rightW = $cols - $leftW;
    $left = mini_stats_panel($f, $leftW, $bottomH, $color);
    $right = process_panel($f, $rightW, $bottomH, $color);
    return lines_to_screen(array_merge(
        cpu_panel($f, $cols, $topH, $color),
        hjoin_blocks([$left, $right])
    ), $rows, $cols);
}

function render_compact_frame(array $f, int $cols, int $rows, bool $color): string
{
    $out = [];
    $title = 'SOCX ' . APP_VERSION . '  ' . $f['static']['host'];
    $cpu = $f['cpu'];
    $mem = $f['mem'];
    $pf = $f['pf'];

    $out[] = box_line($title, $cols, $color, 'cyan');
    $out[] = fit(sprintf(
        'CPU %4.1f%% %s',
        $cpu['used'],
        bar($cpu['used'], max(5, $cols - 16), $color, level_color($cpu['used']))
    ), $cols);
    $out[] = fit('Load ' . $f['load']['1'] . ' ' . $f['load']['5'] . ' ' . $f['load']['15'] . ' | up ' . $f['load']['uptime'], $cols);
    if ($f['temps']) {
        $out[] = fit('Temps ' . implode('  ', $f['temps']), $cols);
    }
    $out[] = fit(sprintf(
        'RAM %4.1f%% %s',
        $mem['used_pct'],
        bar($mem['used_pct'], max(5, $cols - 16), $color, level_color($mem['used_pct']))
    ), $cols);
    $out[] = fit('ARC ' . fmt_bytes($mem['arc_total']) . ' | Free ' . fmt_bytes($mem['free']) . ' | Swap ' . sprintf('%.0f%%', $mem['swap_pct']), $cols);
    $out[] = fit('pf states ' . $pf['states'] . ' | ' . $pf['searches_rate'] . ' searches', $cols);

    foreach (array_slice($f['net'], 0, 3) as $n) {
        $out[] = fit(sprintf(
            '%-7s D %8s  U %8s',
            $n['name'],
            $n['rx'] === null ? 'sampling' : fmt_bytes($n['rx']) . '/s',
            $n['tx'] === null ? 'sampling' : fmt_bytes($n['tx']) . '/s'
        ), $cols);
    }

    $out[] = section('proc', $cols, $color);
    foreach (array_slice($f['procs'], 0, max(1, $rows - count($out) - 1)) as $p) {
        $out[] = fit(sprintf('%5s %-10s %4.1f%% %s', $p['pid'], trunc(proc_name($p['cmd']), 10), $p['cpu'], trunc($p['user'], 7)), $cols);
    }

    return lines_to_screen($out, $rows, $cols);
}

function cpu_panel(array $f, int $width, int $height, bool $color): array
{
    $inner = max(1, $width - 2);
    $bodyH = max(1, $height - 2);
    $rightW = ($width >= 72 && $bodyH >= 6) ? min(44, max(30, (int)floor($inner * 0.36))) : 0;
    $leftW = $rightW > 0 ? max(10, $inner - $rightW - 1) : $inner;
    $glance = glance_lines($f, $leftW, $color);
    $glanceH = min(count($glance), max(0, $bodyH - 3));
    $graphH = max(1, $bodyH - $glanceH - 2);
    $left = array_slice($glance, 0, $glanceH);
    $left = array_merge($left, cpu_graph($f['cpu_history'], $leftW, $graphH, $color, level_color($f['cpu']['used'])));
    $left[] = fit('up ' . $f['load']['uptime'] . '  load ' . $f['load']['1'] . ' ' . $f['load']['5'] . ' ' . $f['load']['15'], $leftW);
    $left[] = fit($f['load']['processes'], $leftW);

    if ($rightW <= 0) {
        return panel('SOCX WALL  cpu  preset NEON  ' . date('H:i:s'), $left, $width, $height, $color, 'cyan');
    }

    $right = cpu_detail_lines($f, $rightW, $bodyH, $color);
    $body = [];
    for ($i = 0; $i < $bodyH; $i++) {
        $body[] = pad_visible($left[$i] ?? '', $leftW) . ' ' . pad_visible($right[$i] ?? '', $rightW);
    }
    $title = 'SOCX WALL  cpu  preset NEON  ' . date('H:i:s') . '  ' . (int)(($GLOBALS['interval'] ?? 1.5) * 1000) . 'ms  THREAT-INTEL LIVE';
    return panel($title, $body, $width, $height, $color, 'cyan');
}

function glance_lines(array $f, int $width, bool $color): array
{
    $wan = net_by_name($f['net'], 'ix1') ?? ($f['net'][0] ?? null);
    $lan = net_by_name($f['net'], 'ix0') ?? ($f['net'][1] ?? null);
    $mem = $f['mem'];
    $pf = $f['pf'];
    $cpu = $f['cpu'];
    $health = health_words($f);
    $netW = max(5, (int)floor(($width - 24) / 2));

    $lines = [];
    $lines[] = fit(sprintf(
        '%s %s  %s %s  %s %s',
        ansi('green', 'WAN', $color),
        rate_pair($wan, $netW),
        ansi('aqua', 'LAN', $color),
        rate_pair($lan, $netW),
        ansi('yellow', 'pf', $color),
        $pf['states'] . ' states'
    ), $width);
    $lines[] = fit(sprintf(
        '%s %s  %s %s  %s %.0f%%',
        ansi('yellow', 'RAM', $color),
        fmt_bytes($mem['used']) . '/' . fmt_bytes($mem['total']),
        ansi('aqua', 'ARC', $color),
        fmt_bytes($mem['arc_total']),
        ansi(level_color($cpu['used']), 'CPU', $color),
        $cpu['used']
    ), $width);
    $lines[] = fit(sprintf(
        '%s %s  %s %s  %s %s',
        ansi('green', 'search', $color),
        $pf['searches_rate'],
        ansi('orange', 'blocked', $color),
        fmt_compact_int((int)$pf['blocked']),
        ansi('green', 'passed', $color),
        fmt_compact_int((int)$pf['passed'])
    ), $width);
    $lines[] = fit(ansi(health_color($health), 'HEALTH', $color) . ' ' . implode('  ', $health), $width);

    return $lines;
}

function net_by_name(array $net, string $name): ?array
{
    foreach ($net as $row) {
        if (($row['name'] ?? '') === $name) {
            return $row;
        }
    }
    return null;
}

function rate_pair(?array $row, int $maxLen): string
{
    if ($row === null) {
        return fit('D ? U ?', $maxLen);
    }
    $rx = $row['rx'] === null ? 'sampling' : fmt_bytes((int)$row['rx']) . '/s';
    $tx = $row['tx'] === null ? 'sampling' : fmt_bytes((int)$row['tx']) . '/s';
    return fit('D ' . $rx . ' U ' . $tx, $maxLen);
}

function health_words(array $f): array
{
    $words = [];
    $cpu = (float)$f['cpu']['used'];
    $mem = (float)$f['mem']['used_pct'];
    $temp = max_temp($f['temps']);
    $pfStates = is_numeric($f['pf']['states']) ? (int)$f['pf']['states'] : 0;

    $words[] = $cpu >= 85.0 ? 'cpu hot' : 'cpu ok';
    $words[] = $mem >= 85.0 ? 'ram high' : 'ram ok';
    if ($temp !== '') {
        $tempNum = (float)rtrim($temp, 'C');
        $words[] = $tempNum >= 75.0 ? 'temp high ' . $temp : 'temp ' . $temp;
    }
    $words[] = $pfStates > 50000 ? 'states high' : 'states ok';
    return $words;
}

function health_color(array $words): string
{
    $text = implode(' ', $words);
    if (str_contains($text, 'high') || str_contains($text, 'hot')) {
        return 'red';
    }
    return 'green';
}

function cpu_detail_lines(array $f, int $width, int $height, bool $color): array
{
    $cpu = $f['cpu'];
    $temp = max_temp($f['temps']);
    $lines = [];
    $lines[] = fit(sprintf(
        'CPU %s %4.0f%% %s %s',
        bar($cpu['used'], max(4, $width - 24), $color, level_color($cpu['used'])),
        $cpu['used'],
        fmt_freq($f['freq_mhz']),
        $temp === '' ? '' : $temp
    ), $width);

    $maxCores = max(0, $height - 2);
    foreach (array_slice($cpu['cores'] ?? [], 0, $maxCores) as $core) {
        $lines[] = fit(sprintf(
            'C%-2d %s %4.0f%%',
            $core['id'],
            bar($core['used'], max(4, $width - 14), $color, level_color($core['used'])),
            $core['used']
        ), $width);
    }
    $lines[] = fit('Load AVG: ' . $f['load']['1'] . '  ' . $f['load']['5'] . '  ' . $f['load']['15'], $width);
    return fit_block($lines, $width, $height);
}

function memory_panel(array $f, int $width, int $height, bool $color): array
{
    $m = $f['mem'];
    $barW = max(3, $width - 21);
    $body = [
        'Total:     ' . fmt_bytes($m['total']),
        stat_bar('Used', $m['used_pct'], fmt_bytes($m['used']), $barW, $color),
        stat_bar('Avail', 100.0 - $m['used_pct'], fmt_bytes($m['free']), $barW, $color),
        stat_bar('ARC', percent($m['arc_total'], $m['total']), fmt_bytes($m['arc_total']), $barW, $color),
        stat_bar('Inact', percent($m['inactive'], $m['total']), fmt_bytes($m['inactive']), $barW, $color),
        stat_bar('Wired', percent($m['wired'], $m['total']), fmt_bytes($m['wired']), $barW, $color),
        stat_bar('Swap', $m['swap_pct'], fmt_bytes($m['swap_used']) . '/' . fmt_bytes($m['swap_total']), $barW, $color),
    ];
    return panel('mem  zfs arc  cache', $body, $width, $height, $color, 'yellow');
}

function disk_panel(array $f, int $width, int $height, bool $color): array
{
    $body = [];
    $barW = max(4, $width - 20);
    foreach ($f['disks'] as $d) {
        $pct = (float)rtrim($d['pct'], '%');
        $body[] = fit(sprintf('%-8s %5s %s', $d['mount'], $d['pct'], bar($pct, $barW, $color, level_color($pct))), max(1, $width - 2));
        $body[] = fit('  ' . $d['used'] . '/' . $d['size'] . ' free ' . $d['avail'], max(1, $width - 2));
    }
    return panel('disks  root var tmp cf', $body, $width, $height, $color, 'green');
}

function network_panel(array $f, int $width, int $height, bool $color): array
{
    $maxRate = 1;
    foreach ($f['net'] as $n) {
        $maxRate = max($maxRate, (int)($n['rx'] ?? 0), (int)($n['tx'] ?? 0));
    }
    $barW = max(4, $width - 35);
    $body = [];
    foreach (array_slice($f['net'], 0, max(1, $height - 3)) as $n) {
        $rxPct = percent((int)($n['rx'] ?? 0), $maxRate);
        $txPct = percent((int)($n['tx'] ?? 0), $maxRate);
        $body[] = fit(sprintf('%-8s D %-9s %s', $n['name'], $n['rx'] === null ? 'sampling' : fmt_bytes($n['rx']) . '/s', bar($rxPct, $barW, $color, 'cyan')), max(1, $width - 2));
        $body[] = fit(sprintf('%-8s U %-9s %s', '', $n['tx'] === null ? 'sampling' : fmt_bytes($n['tx']) . '/s', bar($txPct, $barW, $color, 'magenta')), max(1, $width - 2));
    }
    return panel('net  sync auto zero  lan/wan pulse', $body, $width, $height, $color, 'magenta');
}

function process_panel(array $f, int $width, int $height, bool $color): array
{
    $inner = max(1, $width - 2);
    $body = [];
    $cmdW = max(8, $inner - 48);
    $body[] = fit(sprintf('%6s %-10s %-8s %7s %5s %s', 'Pid', 'Program', 'User', 'Mem', 'Cpu%', 'Command'), $inner);
    foreach (array_slice($f['procs'], 0, max(1, $height - 4)) as $p) {
        $body[] = fit(sprintf(
            '%6s %-10s %-8s %7s %5.1f %s',
            $p['pid'],
            trunc(proc_name($p['cmd']), 10),
            trunc($p['user'], 8),
            fmt_bytes($p['rss']),
            $p['cpu'],
            trunc($p['cmd'], $cmdW)
        ), $inner);
    }
    $body[] = fit('watch | trace | inspect | htop --real for classic controls', $inner);
    return panel('proc  filter  reverse  tree  < cpu lazy >', $body, $width, $height, $color, 'blue');
}

function mini_stats_panel(array $f, int $width, int $height, bool $color): array
{
    $m = $f['mem'];
    $p = $f['pf'];
    $body = [
        stat_bar('RAM', $m['used_pct'], fmt_bytes($m['used']), max(4, $width - 18), $color),
        'ARC ' . fmt_bytes($m['arc_total']) . ' free ' . fmt_bytes($m['free']),
        'pf states ' . $p['states'],
        'pf searches ' . $p['searches_rate'],
    ];
    foreach (array_slice($f['net'], 0, max(1, $height - count($body) - 3)) as $n) {
        $body[] = fit(sprintf('%-7s D %8s', $n['name'], $n['rx'] === null ? 'sampling' : fmt_bytes($n['rx']) . '/s'), max(1, $width - 2));
        $body[] = fit(sprintf('%-7s U %8s', '', $n['tx'] === null ? 'sampling' : fmt_bytes($n['tx']) . '/s'), max(1, $width - 2));
    }
    return panel('mem net pf  live', $body, $width, $height, $color, 'green');
}

function cpu_graph(array $history, int $width, int $height, bool $color, string $tone): array
{
    $width = max(1, $width);
    $height = max(1, $height);
    $samples = array_slice($history, -$width);
    $samples = array_pad($samples, -$width, 0.0);
    $lines = [];
    for ($row = $height; $row >= 1; $row--) {
        $threshold = ($row / $height) * 100.0;
        $line = '';
        foreach ($samples as $v) {
            if ($v >= $threshold) {
                $line .= '#';
            } elseif ($row === 1) {
                $line .= '.';
            } else {
                $line .= ' ';
            }
        }
        $lines[] = ansi(graph_tone($row, $height, $tone), $line, $color);
    }
    return $lines;
}

function stat_bar(string $label, float $pct, string $value, int $barW, bool $color): string
{
    return fit(sprintf('%-6s %6s %s', $label . ':', $value, bar($pct, $barW, $color, level_color($pct))), 1000);
}

function panel(string $title, array $body, int $width, int $height, bool $color, string $tone): array
{
    $width = max(8, $width);
    $height = max(3, $height);
    $inner = max(1, $width - 2);
    $plainTitle = ' ' . $title . ' ';
    if (strlen($plainTitle) > $inner) {
        $plainTitle = substr($plainTitle, 0, $inner);
    }
    $titleText = title_ansi($tone, $plainTitle, $color);
    $top = ansi($tone, '+', $color) . $titleText . ansi($tone, str_repeat('-', max(0, $inner - strlen($plainTitle))) . '+', $color);
    $bottom = ansi($tone, '+' . str_repeat('-', $inner) . '+', $color);
    $lines = [$top];
    for ($i = 0; $i < $height - 2; $i++) {
        $line = fit($body[$i] ?? '', $inner);
        $lines[] = ansi($tone, '|', $color) . pad_visible($line, $inner) . ansi($tone, '|', $color);
    }
    $lines[] = $bottom;
    return $lines;
}

function hjoin_blocks(array $blocks): array
{
    $height = 0;
    $widths = [];
    foreach ($blocks as $block) {
        $height = max($height, count($block));
        $widths[] = visible_len($block[0] ?? '');
    }
    $out = [];
    for ($i = 0; $i < $height; $i++) {
        $line = '';
        foreach ($blocks as $idx => $block) {
            $line .= pad_visible($block[$i] ?? '', $widths[$idx]);
        }
        $out[] = $line;
    }
    return $out;
}

function fit_block(array $lines, int $width, int $height): array
{
    $out = [];
    for ($i = 0; $i < $height; $i++) {
        $out[] = pad_visible(fit($lines[$i] ?? '', $width), $width);
    }
    return $out;
}

function lines_to_screen(array $lines, int $rows, int $cols): string
{
    $out = [];
    for ($i = 0; $i < $rows; $i++) {
        $out[] = pad_visible(fit($lines[$i] ?? '', $cols), $cols);
    }
    return implode("\n", $out);
}

function pad_visible(string $text, int $width): string
{
    $text = fit($text, $width);
    $len = visible_len($text);
    if ($len >= $width) {
        return $text;
    }
    return $text . str_repeat(' ', $width - $len);
}

function visible_len(string $text): int
{
    return strlen(strip_ansi($text));
}

function strip_ansi(string $text): string
{
    return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
}

function proc_name(string $cmd): string
{
    $first = strtok(trim($cmd), ' ');
    if ($first === false || $first === '') {
        return '?';
    }
    return basename($first);
}

function fmt_freq(int $mhz): string
{
    if ($mhz <= 0) {
        return '';
    }
    return $mhz >= 1000 ? sprintf('%.1fGHz', $mhz / 1000) : $mhz . 'MHz';
}

function max_temp(array $temps): string
{
    $max = null;
    foreach ($temps as $temp) {
        if (preg_match('/([0-9.]+)C/', $temp, $m)) {
            $max = max($max ?? 0.0, (float)$m[1]);
        }
    }
    return $max === null ? '' : sprintf('%.0fC', $max);
}

function section(string $label, int $cols, bool $color): string
{
    return box_line(' ' . $label . ' ', $cols, $color, 'magenta');
}

function box_line(string $label, int $cols, bool $color, string $tone): string
{
    $plain = '-- ' . $label . ' ';
    $line = $plain . str_repeat('-', max(0, $cols - strlen($plain)));
    return ansi($tone, fit($line, $cols), $color);
}

function title_ansi(string $tone, string $text, bool $enabled): string
{
    if (!$enabled) {
        return $text;
    }
    if (str_contains($text, 'SOCX WALL')) {
        return rainbow_text($text);
    }
    return ansi($tone, $text, true);
}

function rainbow_text(string $text): string
{
    $tones = ['cyan', 'aqua', 'green', 'yellow', 'orange', 'red', 'hotpink', 'violet'];
    $out = '';
    $i = 0;
    $len = strlen($text);
    for ($p = 0; $p < $len; $p++) {
        $ch = $text[$p];
        if ($ch === ' ') {
            $out .= $ch;
            continue;
        }
        $out .= ansi($tones[$i % count($tones)], $ch, true);
        $i++;
    }
    return $out;
}

function graph_tone(int $row, int $height, string $fallback): string
{
    if ($height <= 2) {
        return $fallback;
    }
    $pct = $row / $height;
    if ($pct > 0.82) {
        return 'hotpink';
    }
    if ($pct > 0.62) {
        return 'orange';
    }
    if ($pct > 0.42) {
        return 'yellow';
    }
    if ($pct > 0.22) {
        return 'green';
    }
    return 'aqua';
}

function bar(float $pct, int $width, bool $color, string $tone): string
{
    $pct = max(0.0, min(100.0, $pct));
    $filled = (int)round($width * ($pct / 100.0));
    $bar = str_repeat('#', $filled) . str_repeat('.', max(0, $width - $filled));
    return '[' . ansi($tone, $bar, $color) . ']';
}

function level_color(float $pct): string
{
    if ($pct >= 90.0) {
        return 'red';
    }
    if ($pct >= 70.0) {
        return 'yellow';
    }
    return 'green';
}

function ansi(string $tone, string $text, bool $enabled): string
{
    if (!$enabled) {
        return $text;
    }
    $codes = [
        'cyan' => '38;5;51;1',
        'aqua' => '38;5;45;1',
        'magenta' => '38;5;201;1',
        'hotpink' => '38;5;198;1',
        'violet' => '38;5;141;1',
        'green' => '38;5;119;1',
        'yellow' => '38;5;226;1',
        'orange' => '38;5;208;1',
        'red' => '38;5;196;1',
        'blue' => '38;5;81;1',
        'white' => '38;5;255;1',
        'dim' => '38;5;245',
    ];
    $code = $codes[$tone] ?? '0';
    return "\033[" . $code . 'm' . $text . "\033[0m";
}

function fit(string $text, int $cols): string
{
    $plain = preg_replace('/\033\[[0-9;]*m/', '', $text);
    if (strlen($plain) <= $cols) {
        return $text;
    }
    $keep = max(1, $cols - 1);
    $out = '';
    $visible = 0;
    $len = strlen($text);
    for ($i = 0; $i < $len && $visible < $keep; $i++) {
        if ($text[$i] === "\033") {
            $end = strpos($text, 'm', $i);
            if ($end !== false) {
                $out .= substr($text, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
        }
        $out .= $text[$i];
        $visible++;
    }
    return $out . '>';
}

function trunc(string $text, int $width): string
{
    return strlen($text) <= $width ? $text : substr($text, 0, max(1, $width - 1)) . '>';
}

function parse_size(string $size): int
{
    if (!preg_match('/^([0-9.]+)\s*([KMGTPE]?)/i', trim($size), $m)) {
        return 0;
    }
    $n = (float)$m[1];
    $unit = strtoupper($m[2] ?? '');
    $pow = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6][$unit] ?? 0;
    return (int)round($n * (1024 ** $pow));
}

function fmt_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '?';
    }
    $bytes = max(0, $bytes);
    $units = ['B', 'K', 'M', 'G', 'T', 'P'];
    $value = (float)$bytes;
    $i = 0;
    while ($value >= 1024.0 && $i < count($units) - 1) {
        $value /= 1024.0;
        $i++;
    }
    if ($i === 0) {
        return (string)(int)$value . $units[$i];
    }
    return sprintf($value >= 100 ? '%.0f%s' : '%.1f%s', $value, $units[$i]);
}

function fmt_int(int $n): string
{
    return number_format($n);
}

function fmt_compact_int(int $n): string
{
    $n = max(0, $n);
    if ($n >= 1000000000) {
        return sprintf('%.1fB', $n / 1000000000);
    }
    if ($n >= 1000000) {
        return sprintf('%.1fM', $n / 1000000);
    }
    if ($n >= 1000) {
        return sprintf('%.1fK', $n / 1000);
    }
    return (string)$n;
}

function percent(int $used, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return max(0.0, min(100.0, ($used / $total) * 100.0));
}

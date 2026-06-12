#!/usr/local/bin/perl
use strict;
use warnings;
use utf8;

$| = 1;
binmode STDOUT, ':encoding(UTF-8)';

my $no_color = $ENV{'NO_COLOR'} || $ENV{'SOCX_NO_COLOR'};
my $width = ($ENV{'SOCX_WIDTH'} && $ENV{'SOCX_WIDTH'} =~ /^\d+$/) ? int($ENV{'SOCX_WIDTH'}) : 0;
my %C = (
    reset   => "\e[0m",
    dim     => "\e[38;5;245m",
    red     => "\e[38;5;196;1m",
    green   => "\e[38;5;119;1m",
    yellow  => "\e[38;5;226;1m",
    blue    => "\e[38;5;81;1m",
    magenta => "\e[38;5;201;1m",
    cyan    => "\e[38;5;51;1m",
    orange  => "\e[38;5;208;1m",
    white   => "\e[38;5;255;1m",
);

sub paint {
    my ($tone, $text) = @_;
    return $text if $no_color;
    return ($C{$tone} || '') . $text . $C{reset};
}

sub fit_plain {
    my ($text, $max) = @_;
    return $text if !$max || length($text) <= $max;
    return substr($text, 0, $max - 1) . '>';
}

sub pad_plain {
    my ($text, $max) = @_;
    $text = fit_plain($text, $max);
    return $text . (' ' x ($max - length($text))) if length($text) < $max;
    return $text;
}

sub endpoint_fit {
    my ($text, $max) = @_;
    $text =~ s/^\s+|\s+$//g;
    return fit_plain($text, $max);
}

sub endpoint_narrow {
    my ($text, $max) = @_;
    $text =~ s/^\s+|\s+$//g;
    if ($text =~ /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/) {
        for my $candidate ($text, "$2.$3.$4", "$3.$4", ".$4") {
            return $candidate if length($candidate) <= $max;
        }
    }
    if ($text =~ /:/) {
        my ($last) = $text =~ /([^:]+)$/;
        my $candidate = '...' . ($last ? ":$last" : '');
        return $candidate if length($candidate) <= $max;
    }
    return $text if length($text) <= $max;
    return substr($text, 0, $max - 1) . '~';
}

sub endpoint_label {
    my ($text, $narrow) = @_;
    $text =~ s/^\s+|\s+$//g;
    return 'pfSense' if $text eq '192.168.1.1';
    return 'BCAST' if $text eq '192.168.1.255' || $text eq '255.255.255.255';
    return 'mDNS' if $text eq '224.0.0.251';
    return 'SSDP' if $text eq '239.255.255.250';
    return 'MULTI' if $text =~ /^224\./ || $text =~ /^239\./;
    return 'Cloudflare' if $text eq '1.1.1.1';
    return 'GoogleDNS' if $text eq '8.8.8.8';
    return 'Quad9' if $text eq '9.9.9.9';
    return 'ISP-GW' if $text eq '74.46.12.1';
    return "LAN.$1" if $text =~ /^192\.168\.1\.(\d+)$/;
    return "ISP.$1.$2" if $text =~ /^74\.46\.(\d+)\.(\d+)$/;
    return 'LANv6' if $text =~ /^fd/i;
    return 'IPv6' if $text =~ /:/;
    if ($narrow && $text =~ /^\d+\.\d+\.(\d+)\.(\d+)$/) {
        return "EXT.$1.$2";
    }
    return $text;
}

sub is_routine_flow {
    my ($flow) = @_;
    for my $ep (($flow->{a} // ''), ($flow->{b} // '')) {
        return 1 if $ep eq '192.168.1.255' || $ep eq '255.255.255.255';
        return 1 if $ep =~ /^224\./ || $ep =~ /^239\./;
    }
    return 0;
}

sub rate_narrow {
    my ($rate) = @_;
    $rate = '?' if !defined $rate || $rate eq '';
    $rate =~ s/i?B$//;
    $rate =~ s/b$//;
    return $rate if length($rate) <= 6;
    return substr($rate, 0, 5) . '~';
}

sub rate_value {
    my ($rate) = @_;
    $rate = '' if !defined $rate;
    $rate =~ s/^\s+|\s+$//g;
    return 0 if $rate eq '' || $rate eq '?';
    return 0 if $rate =~ /^0+b?$/i;
    if ($rate =~ /^([0-9.]+)\s*([KMGTPE]?)(?:i?B|b)?(?:\/s)?$/i) {
        my $value = $1 + 0;
        my $unit = uc($2 // '');
        my %mul = ('' => 1, K => 1000, M => 1000000, G => 1000000000, T => 1000000000000);
        return $value * ($mul{$unit} // 1);
    }
    return 0;
}

sub percent_of {
    my ($value, $max) = @_;
    return 0 if !$max || $max <= 0;
    my $pct = ($value / $max) * 100;
    $pct = 0 if $pct < 0;
    $pct = 100 if $pct > 100;
    return $pct;
}

sub bar_plain {
    my ($pct, $wide) = @_;
    $wide = 4 if !$wide || $wide < 4;
    $wide = 12 if $wide > 12;
    my $filled = int(($pct / 100) * $wide + 0.5);
    $filled = 0 if $filled < 0;
    $filled = $wide if $filled > $wide;
    return '[' . ('#' x $filled) . ('.' x ($wide - $filled)) . ']';
}

sub pulse_char {
    my @chars = ('|', '/', '-', '\\');
    return $chars[time() % @chars];
}

sub colorize_line {
    my ($line) = @_;
    return undef if $width && $width < 70 && $line =~ /^MAC address/i;
    return undef if $width && $width < 70 && $line =~ /^Listening on/i;
    $line =~ s/^IP address is:\s*/IP /i if $width && $width < 70;
    $line =~ s/=+/-/g if $width && $width < 70;
    if ($width && length($line) > $width) {
        $line = substr($line, 0, $width - 1) . '>';
    }

    $line =~ s/(SOCX iftop.*)/paint('cyan', $1)/e;
    $line =~ s/(IFTOPX.*|flow radar|top talkers|TX upload.*|routine broadcast|refresh.*|pulse.*)/paint('cyan', $1)/e;
    $line =~ s/(\[[#.]+\])/paint('green', $1)/ge;
    $line =~ s/(=>)/paint('green', $1)/ge;
    $line =~ s/(<=)/paint('cyan', $1)/ge;
    $line =~ s/\b(Total|Peak|Cumulative|send|receive|rates?|TX|RX|TOTAL|PEAK|Listening on|interface|upload|download|both)\b/paint('yellow', $1)/ge;
    $line =~ s/\b(192\.168\.\d+\.\d+)\b/paint('green', $1)/ge;
    $line =~ s/\b(10\.\d+\.\d+\.\d+)\b/paint('cyan', $1)/ge;
    $line =~ s/\b(74\.46\.\d+\.\d+)\b/paint('green', $1)/ge;
    $line =~ s/\b(pfSense|LAN\.\d+|ISP-GW|ISP\.\d+\.\d+|WAN)\b/paint('green', $1)/ge;
    $line =~ s/\b(EXT\.\d+\.\d+|Cloudflare|GoogleDNS|Quad9|BCAST|mDNS|SSDP|MULTI)\b/paint('cyan', $1)/ge;
    $line =~ s/\b([0-9.]+\s*(?:[KMGT]?i?B|[KMGT]?b)(?:\/s)?)\b/paint('white', $1)/ge;
    $line =~ s/([─═╩]{3,}|-{3,}|={3,})/paint('cyan', $1)/ge;
    return $line;
}

sub emit {
    my ($line) = @_;
    $line = fit_plain($line, $width) if $width;
    my $colored = colorize_line($line);
    return if !defined $colored;
    print $colored;
    print $no_color ? "\n" : "$C{reset}\n";
}

sub render_compact {
    my (@raw) = @_;
    my $w = $width || 90;
    my $h = ($ENV{'SOCX_HEIGHT'} && $ENV{'SOCX_HEIGHT'} =~ /^\d+$/) ? int($ENV{'SOCX_HEIGHT'}) : 23;
    my $iface = '?';
    my $ip = '?';
    my @flows;
    my %totals;
    my $cur;

    for my $line (@raw) {
        chomp $line;
        $iface = $1 if $line =~ /^interface:\s*(\S+)/i;
        $ip = $1 if $line =~ /^IP address is:\s*(\S+)/i;
        if ($line =~ /^\s*(\d+)\s+(\S+)\s+(=>|<=)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/) {
            $cur = {
                idx => $1, a => $2, dir1 => $3,
                a2s => $4, a10s => $5, a40s => $6, acum => $7
            };
            next;
        }
        if ($cur && $line =~ /^\s+(\S+)\s+(=>|<=)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/) {
            $cur->{b} = $1;
            $cur->{dir2} = $2;
            $cur->{b2s} = $3;
            $cur->{b10s} = $4;
            $cur->{b40s} = $5;
            $cur->{bcum} = $6;
            push @flows, $cur;
            undef $cur;
            next;
        }
        if ($line =~ /^Total send rate:\s+(\S+)\s+(\S+)\s+(\S+)/) {
            $totals{send} = "$1 $2 $3";
        } elsif ($line =~ /^Total receive rate:\s+(\S+)\s+(\S+)\s+(\S+)/) {
            $totals{recv} = "$1 $2 $3";
        } elsif ($line =~ /^Total send and receive rate:\s+(\S+)\s+(\S+)\s+(\S+)/) {
            $totals{both} = "$1 $2 $3";
        } elsif ($line =~ /^Peak rate .*:\s+(\S+)\s+(\S+)\s+(\S+)/) {
            $totals{peak} = "$1 $2 $3";
        }
    }

    my $narrow = $w < 70;
    my $wall = $w <= 100;
    my $epw = int(($w - ($narrow ? 18 : 44)) / 2);
    if ($narrow) {
        $epw = 7 if $epw < 7;
        $epw = 18 if $epw > 18;
    } else {
        $epw = 12 if $epw < 12;
        $epw = 30 if $epw > 30;
    }
    my $max_flows = $wall ? ($h - 3) : $h - 7;
    $max_flows = 3 if $max_flows < 3;
    $max_flows = 20 if $wall && $max_flows > 20;
    $max_flows = 18 if !$wall && $max_flows > 18;

    my @send = split /\s+/, ($totals{send} // '?');
    my @recv = split /\s+/, ($totals{recv} // '?');
    my @both = split /\s+/, ($totals{both} // '?');
    my @peak = split /\s+/, ($totals{peak} // '?');

    my @now = localtime();
    my $clock = sprintf('%02d:%02d:%02d', $now[2], $now[1], $now[0]);
    my $refresh = $ENV{'SOCX_IFTOP_SECONDS'} || '2';
    my $pulse = pulse_char();
    if ($wall) {
        my $flow_w = $w - 32;
        $flow_w = 32 if $flow_w < 32;
        emit(sprintf('IFTOPX %-3s | %s FLOW RADAR  refresh %ss  pulse %s', $iface, $clock, $refresh, $pulse));
        emit(sprintf('%-3s %-*s %7s %7s %s', '#', $flow_w, 'flow', 'tx', 'rx', 'activity'));
    } else {
        emit(sprintf('IFTOPX %s  %s  top talkers  %s  refresh %ss pulse %s', $iface, endpoint_label($ip, 0), $clock, $refresh, $pulse));
        emit(sprintf('%-3s %-*s %1s %-*s %8s %8s %8s %8s', '#', $epw, 'source', '>', $epw, 'destination', 'TX 2s', 'RX 2s', '40s', 'activity'));
    }
    my @nonroutine = grep { !is_routine_flow($_) } @flows;
    my @routine = grep { is_routine_flow($_) } @flows;
    my @display_flows;
    if ($wall) {
        @display_flows = (@nonroutine, @routine);
    } else {
        @display_flows = scalar(@nonroutine) >= 3 ? @nonroutine : @flows;
    }
    my $hidden_routine = 0;
    $hidden_routine = @routine if !$wall && scalar(@nonroutine) >= 3;
    $hidden_routine = @display_flows - $max_flows if $wall && @display_flows > $max_flows;
    $hidden_routine = 0 if $hidden_routine < 0;

    my $max_rate = 1;
    for my $f (@display_flows) {
        $max_rate = rate_value($f->{a2s}) if rate_value($f->{a2s}) > $max_rate;
        $max_rate = rate_value($f->{b2s}) if rate_value($f->{b2s}) > $max_rate;
    }
    my $max_total_rate = 1;
    for my $f (@display_flows) {
        my $sum = rate_value($f->{a2s}) + rate_value($f->{b2s});
        $max_total_rate = $sum if $sum > $max_total_rate;
    }

    my $count = 0;
    for my $f (@display_flows) {
        last if $count >= $max_flows;
        $count++;
        my $a = $wall ? endpoint_label($f->{a} // '?', 1) : endpoint_fit(endpoint_label($f->{a} // '?', 0), $epw);
        my $b = $wall ? endpoint_label($f->{b} // '?', 1) : endpoint_fit(endpoint_label($f->{b} // '?', 0), $epw);
        if ($wall) {
            my $flow_w = $w - 32;
            $flow_w = 32 if $flow_w < 32;
            my $flow = endpoint_fit("$a -> $b", $flow_w);
            my $activity = bar_plain(percent_of(rate_value($f->{a2s}) + rate_value($f->{b2s}), $max_total_rate), 7);
            emit(sprintf('%02d  %-*s %7s %7s %s',
                $count, $flow_w, $flow,
                rate_narrow($f->{a2s}), rate_narrow($f->{b2s}), $activity));
        } else {
            my $activity = bar_plain(percent_of(rate_value($f->{a2s}) + rate_value($f->{b2s}), $max_total_rate), 6);
            emit(sprintf('%02d  %-*s %1s %-*s %8s %8s %8s %8s',
                $count, $epw, $a, '>', $epw, $b,
                $f->{a2s} // '?', $f->{b2s} // '?', $f->{a40s} // '?', $activity));
        }
    }

    if ($wall) {
        my $summary = 'TOTAL tx ' . rate_narrow($send[0]) . ' rx ' . rate_narrow($recv[0]) . ' both ' . rate_narrow($both[0]);
        $summary .= ' hid ' . $hidden_routine if $hidden_routine > 0;
        emit($summary);
    } else {
        emit('routine broadcast hidden: ' . $hidden_routine) if $hidden_routine > 0;
        emit('TOTAL send ' . ($totals{send} // '?') . ' | recv ' . ($totals{recv} // '?'));
        emit('PEAK  ' . ($totals{peak} // '?') . ' | BOTH ' . ($totals{both} // '?'));
    }
}

if ($ENV{'SOCX_IFTOP_COMPACT'}) {
    my @raw = <STDIN>;
    render_compact(@raw);
    exit 0;
}

while (my $line = <STDIN>) {
    chomp $line;
    my $colored = colorize_line($line);
    next if !defined $colored;
    print $colored;
    print $no_color ? "\n" : "$C{reset}\n";
}

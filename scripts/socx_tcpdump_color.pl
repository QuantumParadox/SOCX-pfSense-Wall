#!/usr/local/bin/perl
use strict;
use warnings;
use utf8;

$| = 1;
binmode STDOUT, ':encoding(UTF-8)';

my $no_color = $ENV{'NO_COLOR'} || $ENV{'SOCX_NO_COLOR'};
my $width = ($ENV{'SOCX_WIDTH'} && $ENV{'SOCX_WIDTH'} =~ /^\d+$/) ? int($ENV{'SOCX_WIDTH'}) : 0;
my $iface = $ENV{'SOCX_TCPDUMP_IFACE'} || '';
my $wan_ip = $ENV{'SOCX_WAN_ADDR'} || '';
my $show_health_pings = $ENV{'SOCX_SHOW_HEALTH_PINGS'} || '';
my $show_broadcast = $ENV{'SOCX_SHOW_BROADCAST'} || '';
my $structured = $ENV{'SOCX_COMPACT'} || ($width > 0 && $width < 120);
my $narrow = $width && $width < 58;
my $mid = $width && $width < 95;
my $packet_count = 0;

my %C = (
    reset  => "\e[0m",
    dim    => "\e[38;5;245m",
    red    => "\e[38;5;196;1m",
    green  => "\e[38;5;119;1m",
    yellow => "\e[38;5;226;1m",
    orange => "\e[38;5;208;1m",
    blue   => "\e[38;5;81;1m",
    cyan   => "\e[38;5;51;1m",
    white  => "\e[38;5;255;1m",
);

sub rule_plain {
    my ($wide) = @_;
    $wide = 1 if !$wide || $wide < 1;
    return '─' x $wide;
}

sub paint {
    my ($tone, $text) = @_;
    return $text if $no_color;
    return ($C{$tone} || '') . $text . $C{reset};
}

sub fit_words {
    my ($text, $max) = @_;
    return $text if !$max || length($text) <= $max;
    my $cut = substr($text, 0, $max - 3);
    $cut =~ s/\s+\S*$// if $cut =~ /\s/;
    $cut = substr($text, 0, $max - 3) if length($cut) < 12;
    return $cut . '...';
}

sub service {
    my ($port) = @_;
    return '' if !defined $port || $port eq '';
    return 'dns'   if $port eq '53';
    return 'web'   if $port eq '80';
    return 'https' if $port eq '443';
    return 'ntp'   if $port eq '123';
    return 'vpn'   if $port eq '500' || $port eq '4500';
    return 'ssh'   if $port eq '22';
    return 'mdns'  if $port eq '5353';
    return 'ssdp'  if $port eq '1900';
    return "port $port";
}

sub split_endpoint {
    my ($ep) = @_;
    $ep =~ s/^\s+|\s+$//g;
    $ep =~ s/:$//;
    if ($ep =~ /^(\d{1,3}(?:\.\d{1,3}){3})\.(\d+)$/) {
        return ($1, $2);
    }
    return ($ep, '');
}

sub ip_label {
    my ($ip) = @_;
    return 'WAN' if $wan_ip && $ip eq $wan_ip;
    return 'Cloudflare' if $ip eq '1.1.1.1';
    return 'Cloudflare' if $ip =~ /^162\.158\./;
    return 'GoogleDNS' if $ip eq '8.8.8.8';
    return 'Quad9' if $ip eq '9.9.9.9';
    return 'mDNS' if $ip eq '224.0.0.251';
    return 'SSDP' if $ip eq '239.255.255.250';
    return 'BCAST' if $ip eq '255.255.255.255';
    return 'BCAST' if $ip eq '192.168.1.255';
    return 'ISP-GW' if $ip eq '74.46.12.1';
    return "LAN.$1" if $ip =~ /^192\.168\.1\.(\d+)$/;
    return "ISP.$1.$2" if $ip =~ /^74\.46\.(\d+)\.(\d+)$/;
    return "EXT.$1.$2" if $narrow && $ip =~ /^\d+\.\d+\.(\d+)\.(\d+)$/;
    return $ip;
}

sub endpoint_label {
    my ($ep) = @_;
    my ($ip, $port) = split_endpoint($ep);
    return (ip_label($ip), service($port), $ip, $port);
}

sub tcp_flag {
    my ($info) = @_;
    return '' if !defined $info;
    return 'connect reply' if $info =~ /Flags\s+\[S\.\]/;
    return 'new connection' if $info =~ /Flags\s+\[S\]/;
    return 'reset' if $info =~ /Flags\s+\[R\.?\]/;
    return 'closing' if $info =~ /Flags\s+\[F\.?\]/;
    return 'data' if $info =~ /Flags\s+\[P\.?\]/;
    return 'ack' if $info =~ /Flags\s+\[\.\]/;
    return '';
}

sub length_tag {
    my ($info) = @_;
    return '' if !defined $info;
    return $1 if $info =~ /\blength\s+(\d+)/;
    return $1 if $info =~ /\btcp\s+(\d+)/i;
    return '';
}

sub proto_from_info {
    my ($proto, $info) = @_;
    return 'ARP' if $proto eq 'ARP';
    return 'ICMP6' if $info =~ /\bICMP6\b/i;
    return 'ICMP' if $info =~ /\bICMP\b/i;
    return 'UDP' if $info =~ /\bUDP\b/i;
    return 'TCP' if $info =~ /Flags\s+\[/ || $info =~ /\btcp\b/i;
    return $proto eq 'IP6' ? 'IP6' : 'IP';
}

sub note_for {
    my ($proto, $svc, $flag, $len, $src_label, $dst_label) = @_;
    my @parts;
    if ($proto =~ /^ICMP/) {
        push @parts, ($src_label =~ /Cloudflare|GoogleDNS|Quad9/ || $dst_label =~ /Cloudflare|GoogleDNS|Quad9/) ? 'health ping' : 'icmp notice';
    } elsif ($svc) {
        push @parts, $svc;
    }
    push @parts, $flag if $flag && (!$narrow || $flag ne 'ack');
    push @parts, "${len}B" if $len;
    return @parts ? join(' ', @parts) : lc($proto);
}

sub direction_for {
    my ($src_label, $dst_label) = @_;
    return 'BCAST' if $src_label eq 'BCAST' || $dst_label eq 'BCAST';
    my $src_local = $src_label =~ /^(WAN|LAN\.\d+|pfSense)$/;
    my $dst_local = $dst_label =~ /^(WAN|LAN\.\d+|pfSense)$/;
    return 'OUT' if $src_local && !$dst_local;
    return 'IN' if !$src_local && $dst_local;
    return 'LCL' if $src_local && $dst_local;
    return 'LCL' if $src_label =~ /^LAN\./ && $dst_label =~ /^LAN\./;
    return 'FLOW';
}

sub size_bar {
    my ($len, $wide) = @_;
    $wide ||= 6;
    $wide = 4 if $wide < 4;
    $wide = 10 if $wide > 10;
    my $value = ($len && $len =~ /^\d+$/) ? int($len) : 0;
    my $pct = $value / 1500;
    $pct = 1 if $pct > 1;
    my $filled = int(($pct * $wide) + 0.5);
    $filled = 1 if $value > 0 && $filled < 1;
    return '[' . ('#' x $filled) . ('.' x ($wide - $filled)) . ']';
}

sub compact_action {
    my ($proto, $svc, $flag, $len, $src_svc, $dst_svc) = @_;
    my @parts;
    push @parts, $svc if $svc;
    push @parts, $flag if $flag;
    if (!$svc && $dst_svc) {
        push @parts, $dst_svc;
    } elsif (!$svc && $src_svc) {
        push @parts, $src_svc;
    }
    push @parts, lc($proto) if !@parts;
    push @parts, ($len ? "${len}B" : 'ctrl');
    push @parts, size_bar($len, $narrow ? 6 : 8);
    return join(' ', @parts);
}

sub header_lines {
    my $name = $iface ? $iface : 'wan';
    if ($narrow) {
        return (
            fit_words("TCPDUMPX $name packet story", $width || 40),
            fit_words("dir service size pings/bcast hidden", $width || 40),
            rule_plain(($width && $width < 60) ? $width : 40),
        );
    }
    return (
        fit_words("TCPDUMPX $name packet story - routine pings/broadcast hidden", $width || 90),
        fit_words("time     proto dir  flow                                   detail", $width || 90),
        rule_plain(($width && $width < 120) ? $width : 90),
    );
}

sub format_packet {
    my ($line) = @_;

    if ($line =~ /^(\d{2}:\d{2}:\d{2})(?:\.\d+)?\s+ARP,\s+(.+)$/) {
        my ($ts, $rest) = ($1, $2);
        $ts = substr($ts, 3, 5) if $narrow;
        $rest =~ s/, length \d+//;
        if ($rest =~ /Request who-has (\S+) tell (\S+)/) {
            $rest = 'who has ' . (endpoint_label($1))[0] . ' tell ' . (endpoint_label($2))[0];
        }
        return $narrow
            ? (fit_words("$ts ARP", $width || 40), fit_words("     $rest", $width || 40))
            : (fit_words("$ts ARP  $rest", $width || 90));
    }

    return ($line) if $line !~ /^(\d{2}:\d{2}:\d{2})(?:\.\d+)?\s+(IP6?|ARP)\s+(.+)$/;
    my ($ts, $ip_proto, $rest) = ($1, $2, $3);
    return ($line) if $rest !~ /^(\S+)\s+>\s+(\S+):\s*(.*)$/;

    my ($src, $dst, $info) = ($1, $2, $3);
    my ($src_label, $src_svc) = endpoint_label($src);
    my ($dst_label, $dst_svc) = endpoint_label($dst);
    my $proto = proto_from_info($ip_proto, $info);
    if (!$show_health_pings && $proto =~ /^ICMP/ && ($src_label =~ /^(WAN|ISP-GW|Cloudflare)$/ || $dst_label =~ /^(WAN|ISP-GW|Cloudflare)$/)) {
        return ();
    }
    my $flag = tcp_flag($info);
    my $len = length_tag($info);
    my $svc = $dst_svc && $dst_svc !~ /^port / ? $dst_svc : ($src_svc && $src_svc !~ /^port / ? $src_svc : '');
    $svc = $dst_svc if !$svc && !$narrow && $dst_svc;
    my $note = note_for($proto, $svc, $flag, $len, $src_label, $dst_label);
    my $dir = direction_for($src_label, $dst_label);
    return () if !$show_broadcast && $dir eq 'BCAST';
    my $detail = compact_action($proto, $svc, $flag, $len, $src_svc, $dst_svc);

    if ($narrow) {
        $ts = substr($ts, 3, 5);
        my $flow = fit_words("$ts $proto $dir $src_label -> $dst_label", $width || 40);
        my $detail_line = fit_words("     $detail", $width || 40);
        return ($flow, $detail_line);
    }

    my $left = sprintf('%s %-4s %-3s  %s -> %s', $ts, $proto, $dir, $src_label, $dst_label);
    my $flow = fit_words(sprintf('%-58s %s', $left, $detail), $width || 90);
    return ($flow);
}

sub colorize {
    my ($line) = @_;
    $line =~ s/^(TCPDUMPX.*)/paint('cyan', $1)/e;
    $line =~ s/^(time\s+.*|[─═╩]{3,}|-{3,})/paint('cyan', $1)/e;
    $line =~ s/^(tcpdump:.*|listening on .*)/paint('dim', $1)/e;
    $line =~ s/^(\d{2}:\d{2}(?::\d{2})?)/paint('dim', $1)/e;
    $line =~ s/\b(ARP|ICMP6|ICMP|IP6|IP|UDP|TCP)\b/paint('green', $1)/ge;
    $line =~ s/\b(IN|OUT|LCL|FLOW|BCAST)\b/paint('yellow', $1)/ge;
    $line =~ s/(\[[#.]+\])/paint('green', $1)/ge;
    $line =~ s/\b(WAN|ISP-GW|ISP\.\d+\.\d+|LAN\.\d+)\b/paint('green', $1)/ge;
    $line =~ s/\b(Cloudflare|GoogleDNS|Quad9|mDNS|SSDP|BCAST)\b/paint('cyan', $1)/ge;
    $line =~ s/\b(dns|mdns|ssdp|health ping)\b/paint('cyan', $1)/ge;
    $line =~ s/\b(https|web|ssh|vpn|ntp)\b/paint('blue', $1)/ge;
    $line =~ s/\b(new connection|connect reply|data|closing|ack)\b/paint('yellow', $1)/ge;
    $line =~ s/\b(reset|blocked|denied|unreachable|bad|error)\b/paint('red', $1)/gei;
    $line =~ s/( -> |>|: )/paint('white', $1)/ge;
    $line =~ s/\b(\d+B)\b/paint('white', $1)/ge;
    return $line;
}

sub print_line {
    my ($line) = @_;
    $line = fit_words($line, $width) if $width;
    print colorize($line);
    print $no_color ? "\n" : "$C{reset}\n";
}

if ($structured) {
    print_line($_) for header_lines();
}

while (my $line = <STDIN>) {
    chomp $line;
    my @out = $structured ? format_packet($line) : ($line);
    next if !@out;
    if ($structured && $packet_count && (($narrow && $packet_count % 5 == 0) || (!$narrow && $packet_count % 10 == 0))) {
        print_line($_) for header_lines();
    }
    print_line($_) for @out;
    $packet_count++;
}

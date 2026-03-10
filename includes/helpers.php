<?php
require_once __DIR__ . '/../vendor/autoload.php';
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

function collectServerStats(array $server): array {
    $ssh = new SSH2($server['ip']);
    $key = file_get_contents('/etc/monitoring/monitor_id_rsa');
    $priv = PublicKeyLoader::load($key);
    if (!$ssh->login($server['user'], $priv)) {
        return ['status' => 'offline'];
    }

    $stats = ['status' => 'online'];

    // CPU: top
    $cpuOutput = $ssh->exec("LC_ALL=C top -bn1 | grep 'Cpu(s)'");
    if (preg_match('/(\d+\.\d+)\s*id/', $cpuOutput, $matches)) {
        $cpuUsage = 100 - (float)$matches[1];
        $stats['cpu'] = [
            'used' => $cpuUsage,
            'history' => [['t' => time(), 'v' => $cpuUsage]]
        ];
    } else {
        $stats['cpu'] = [['t' => time(), 'v' => null]];
    }

    // RAM: free -m
    //$memOutput = $ssh->exec("LF_ALL=C free -m | grep Mem:");
    //$memParts = preg_split('/\s+/', trim($memOutput));
    //if (count($memParts) >= 3) {
    //    $stats['memory'] = [
    //        'used' => (int)$memParts[2],
    //        'total' => (int)$memParts[1],
    //    ];
    //}

	// RAM: free -m
	$memInfo = $ssh->exec("cat /proc/meminfo | grep -E 'MemTotal|MemAvailable'");
	preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $totalMatches);
	preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $availMatches);
	
	if ($totalMatches && $availMatches) {
	    $totalMem = (int)($totalMatches[1] / 1024); // kB → MB
	    $usedMem = $totalMem - (int)($availMatches[1] / 1024);
	    $stats['memory'] = [
	        'used' => $usedMem,
	        'total' => $totalMem,
	    ];
	}

    // Диски
    $dfOutput = $ssh->exec("df -BG --output=source,size,used,avail,target -x tmpfs -x devtmpfs | tail -n +2");
    $diskStats = [];
    foreach (explode("\n", trim($dfOutput)) as $line) {
        if (!preg_match('#^/dev/sd[a-z]\d*#', $line)) continue;
        $parts = preg_split('/\s+/', $line);
        if (count($parts) === 5) {
            [$dev, $size, $used, $avail, $mount] = $parts;
            $diskStats[] = [
                'device' => $dev,
                'mount' => $mount,
                'used' => (int)filter_var($used, FILTER_SANITIZE_NUMBER_INT),
                'size' => (int)filter_var($size, FILTER_SANITIZE_NUMBER_INT),
            ];
        }
    }
    $stats['disks'] = $diskStats;

    // ✅ Службы
    $serviceStats = [];
    $services = array_filter(array_map('trim', explode(',', $server['services'] ?? '')));
    foreach ($services as $svc) {
        $svc = trim($svc);
        if ($svc === '') continue;

        $statusRaw = $ssh->exec("systemctl is-active " . escapeshellarg($svc));
        $status = trim($statusRaw);

        $serviceStats[] = [
            'name' => $svc,
            'status' => $status // может быть: active, inactive, failed, etc.
        ];
    }
    $stats['services'] = $serviceStats;

    return $stats;
}

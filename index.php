<?php
// Assicura l'output in codifica UTF-8
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Rome');

// Inclusione del file di configurazione dalla cartella pgs
require_once __DIR__ . '/pgs/config.inc.php';

if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $status_output = shell_exec('systemctl is-active nxdnreflector');
    $isRunning = (trim($status_output) === 'active');
    
    // Trova automaticamente il log odierno o l'ultimo disponibile utilizzando il percorso configurato
    $logDir = LOG_DIR_PATH;
    $logFile = $logDir . 'NXDNReflector-' . date('Y-m-d') . '.log';
    
    if (!file_exists($logFile)) {
        $files = glob($logDir . 'NXDNReflector-*.log');
        if (!empty($files)) {
            rsort($files);
            $logFile = $files[0]; // Prende l'ultimo log disponibile se quello odierno non esiste ancora
        }
    }

    $activeNodes = [];
    $rawTransits = [];
    
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'Adding') !== false) {
                if (preg_match('/Adding\s+([A-Za-z0-9_-]+)\s+\(([^)]+)\)/', $line, $matches)) {
                    $nodeName = trim($matches[1]);
                    if ($nodeName && $nodeName !== 'NXDNReflector') {
                        $activeNodes[trim($matches[1])] = ['ipport' => trim($matches[2]), 'protocol' => 'NXDN', 'time' => date('d/m/Y H:i:s', strtotime('+1 hour'))];
                    }
                }
            } elseif (strpos($line, 'Removing') !== false) {
                if (preg_match('/Removing\s+([A-Za-z0-9_-]+)/', $line, $matches)) {
                    unset($activeNodes[trim($matches[1])]);
                }
            }
            if (strpos($line, 'Transmission from') !== false || strpos($line, 'Received end of transmission') !== false) {
                $rawTransits[] = $line;
            }
        }
    }
    
    // Metriche RAM corrette
    $free_output = shell_exec('free -m');
    $ramUsage = 'N/D';
    if ($free_output) {
        $lines_free = explode("\n", trim($free_output));
        if (isset($lines_free[1])) {
            $cols = preg_split('/\s+/', $lines_free[1]);
            $totalRam = $cols[1];
            $usedRam = $cols[2];
            $ramPct = ($totalRam > 0) ? round(($usedRam / $totalRam) * 100) : 0;
            $ramUsage = $usedRam . 'MB / ' . $totalRam . 'MB (' . $ramPct . '%)';
        }
    }

    // Spazio Disco (/ root)
    $diskTotal = @disk_total_space("/");
    $diskFree = @disk_free_space("/");
    $diskUsage = 'N/D';
    if ($diskTotal && $diskFree) {
        $diskUsed = $diskTotal - $diskFree;
        $diskUsage = round($diskUsed / 1024 / 1024 / 1024, 1) . ' GB / ' . round($diskTotal / 1024 / 1024 / 1024, 1) . ' GB (' . round(($diskUsed / $diskTotal) * 100) . '%)';
    }

    // Uptime VPS
    $uptime_output = shell_exec('uptime -p');
    $uptimeStr = $uptime_output ? trim(str_replace('up ', '', $uptime_output)) : 'N/D';

    $processedTransits = [];
    $activeTransmissions = [];
    $ignoredCallsigns = ['0000', 'NXDNREFLECTOR', 'REFLECTOR', 'ALL', 'SYS'];

    $defaultGateway = 'Diretto';
    foreach ($activeNodes as $nodeName => $nodeData) {
        if (strtoupper($nodeName) !== 'XLX595') {
            $defaultGateway = $nodeName;
            break;
        }
    }
    if ($defaultGateway === 'Diretto' && !empty($activeNodes)) {
        $defaultGateway = key($activeNodes);
    }

    foreach ($rawTransits as $transit) {
        $timestampStr = date('Y-m-d H:i:s');
        if (preg_match('/([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})/', $transit, $tsMatch)) {
            $timestampStr = date('Y-m-d H:i:s', strtotime($tsMatch[1] . ' +1 hour'));
        }
        $logTime = strtotime($timestampStr);

        $lowerTransit = strtolower($transit);
        $isEnd = (strpos($lowerTransit, 'end of transmission') !== false);

        if (!$isEnd) {
            $callsignDisplay = 'N/D';
            $gatewayDisplay = $defaultGateway;

            if (preg_match('/Transmission\s+from\s+([A-Z0-9\/_-]{2,})\s+at\s+([A-Za-z0-9_-]+)/i', $transit, $mMatch)) {
                $candidateCall = strtoupper(trim($mMatch[1]));
                $candidateGw = trim($mMatch[2]);
                if (!in_array($candidateCall, $ignoredCallsigns)) {
                    $callsignDisplay = $candidateCall;
                    $gatewayDisplay = $candidateGw;
                }
            }

            if ($callsignDisplay !== 'N/D') {
                $activeTransmissions[$callsignDisplay] = [
                    'start_time' => $timestampStr,
                    'start_ts' => $logTime,
                    'gateway' => $gatewayDisplay
                ];
            }
        } else {
            if (!empty($activeTransmissions)) {
                end($activeTransmissions);
                $callsignDisplay = key($activeTransmissions);
                $startInfo = $activeTransmissions[$callsignDisplay];
                
                $durationSec = max(1, $logTime - $startInfo['start_ts']);
                if ($durationSec > 300) {
                    $durationSec = 3;
                }

                $processedTransits[] = [
                    'timestamp' => $startInfo['start_time'],
                    'callsign' => $callsignDisplay,
                    'gateway' => $startInfo['gateway'],
                    'duration' => $durationSec . 's'
                ];
                unset($activeTransmissions[$callsignDisplay]);
            }
        }
    }

    $lastLogTs = time();
    if (!empty($rawTransits)) {
        $lastLine = end($rawTransits);
        if (preg_match('/([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})/', $lastLine, $tsMatch)) {
            $lastLogTs = strtotime($tsMatch[1] . ' +1 hour');
        }
    }

    foreach ($activeTransmissions as $callsign => $info) {
        $elapsed = $lastLogTs - $info['start_ts'];
        if ($elapsed > 8) {
            $processedTransits[] = [
                'timestamp' => $info['start_time'],
                'callsign' => $callsign,
                'gateway' => $info['gateway'],
                'duration' => max(1, $elapsed) . 's'
            ];
            unset($activeTransmissions[$callsign]);
        } else {
            $processedTransits[] = [
                'timestamp' => $info['start_time'],
                'callsign' => $callsign,
                'gateway' => $info['gateway'],
                'duration' => 'TXIng'
            ];
        }
    }
    
    usort($processedTransits, function($a, $b) {
        return strcmp($a['timestamp'], $b['timestamp']);
    });

    $gatewayOnlineCount = count($activeNodes);
    $uniqueCallsigns = [];
    foreach ($processedTransits as $t) {
        if ($t['callsign'] !== 'N/D') {
            $uniqueCallsigns[$t['callsign']] = true;
        }
    }
    $distinctCallsignsCount = count($uniqueCallsigns);
    $totalQsoCount = count($processedTransits);

    $countXlx = 0;
    $countLocal = 0;
    foreach ($processedTransits as $t) {
        if (strtoupper($t['gateway']) === 'XLX595') {
            $countXlx++;
        } else {
            $countLocal++;
        }
    }
    $pctXlx = ($totalQsoCount > 0) ? round(($countXlx / $totalQsoCount) * 100) : 0;
    $pctLocal = ($totalQsoCount > 0) ? round(($countLocal / $totalQsoCount) * 100) : 0;

    $latestActive = null;
    $recentReversed = array_reverse($processedTransits);
    foreach ($recentReversed as $t) {
        if ($t['duration'] === 'TXIng') {
            $latestActive = $t;
            break;
        }
    }
    if (!$latestActive && !empty($recentReversed)) {
        $latestActive = $recentReversed[0];
    }

    ob_start();
    if ($latestActive) {
        $isLive = ($latestActive['duration'] === 'TXIng');
        $badgeClass = $isLive ? 'live-badge txing-blink' : 'live-badge completed';
        $badgeText = $isLive ? 'TX IN CORSO' : 'ULTIMO TRANSITO';
        echo "<div style='display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 10px;'>";
        echo "<div style='display: flex; align-items: center; gap: 10px;'><span class='" . $badgeClass . "'>" . $badgeText . "</span></div>";
        echo "<div style='font-size: 15px;'>Stazione: <strong class='callsign-big'>" . htmlspecialchars($latestActive['callsign']) . "</strong> via Gateway: <strong class='gateway-text'>" . htmlspecialchars($latestActive['gateway']) . "</strong></div>";
        echo "</div>";
    } else {
        echo "<div style='color: var(--text-muted); font-style: italic; width: 100%; text-align: center;'>Nessuna trasmissione recente.</div>";
    }
    $liveActivityHtml = ob_get_clean();

    ob_start();
    $recentCallsignsList = [];
    foreach ($recentReversed as $t) {
        if (!in_array($t['callsign'], $recentCallsignsList) && $t['callsign'] !== 'N/D') {
            $recentCallsignsList[] = $t['callsign'];
            if (count($recentCallsignsList) >= 4) break;
        }
    }
    if (!empty($recentCallsignsList)) {
        echo "<div style='display: flex; gap: 10px; flex-wrap: wrap;'>";
        foreach ($recentCallsignsList as $cs) {
            echo "<div class='recent-call-pill'><span class='callsign-big'>" . htmlspecialchars($cs) . "</span></div>";
        }
        echo "</div>";
    } else {
        echo "<div style='color: var(--text-muted); font-size: 13px;'>Nessun nominativo recente</div>";
    }
    $recentPillsHtml = ob_get_clean();

    ob_start();
    if (!empty($processedTransits)) {
        $recentTransits = array_reverse(array_slice($processedTransits, -25));
        foreach ($recentTransits as $t) {
            $isTxing = ($t['duration'] === 'TXIng');
            $durClass = $isTxing ? 'txing-blink' : '';
            $durStyle = $isTxing ? '' : 'color: var(--accent-color); font-weight: 600;';
            
            echo "<tr data-callsign='" . htmlspecialchars(strtolower($t['callsign'])) . "' data-gateway='" . htmlspecialchars(strtolower($t['gateway'])) . "'>";
            echo "<td><span class='timestamp'>" . htmlspecialchars($t['timestamp']) . "</span></td>";
            echo "<td><strong class='callsign-big'>" . htmlspecialchars($t['callsign']) . "</strong></td>";
            echo "<td><strong class='gateway-text'>" . htmlspecialchars($t['gateway']) . "</strong></td>";
            echo "<td><span class='" . $durClass . "' style='" . $durStyle . "'>" . htmlspecialchars($t['duration']) . "</span></td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='4' class='empty-row' style='text-align: center; color: var(--text-muted); padding: 20px;'>Nessun transito registrato oggi</td></tr>";
    }
    $transitsHtml = ob_get_clean();

    ob_start();
    if (!empty($activeNodes)) {
        foreach ($activeNodes as $callsign => $data) {
            $ip = explode(':', $data['ipport'])[0];
            $maskedIp = format_gateway_ip($ip);
            echo "<div class='gateway-item-card'>";
            echo "<div style='display: flex; align-items: center; gap: 8px;'><span class='node-led'></span><strong class='callsign-big'>" . htmlspecialchars($callsign) . "</strong></div>";
            echo "<div style='font-size: 12px; color: var(--text-muted); margin-top: 5px;'>IP: " . htmlspecialchars($maskedIp) . "</div>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: var(--text-muted); text-align: center; padding: 20px;'>Nessun gateway connesso</div>";
    }
    $gatewaysHtml = ob_get_clean();

    ob_start();
    ?>
    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
        <svg style="width:16px;height:16px;fill:var(--accent-color);" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
        Statistiche Traffico Gateway
    </div>
    <div style="margin-bottom: 10px;">
        <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
            <span style="color: #34d399;">XLX595 (<?php echo $countXlx; ?>)</span>
            <span style="color: #34d399;"><?php echo $pctXlx; ?>%</span>
        </div>
        <div style="background: rgba(15, 23, 42, 0.6); height: 8px; border-radius: 4px; overflow: hidden; border: 1px solid var(--border-color);">
            <div style="background: #34d399; width: <?php echo $pctXlx; ?>%; height: 100%; transition: width 0.4s ease;"></div>
        </div>
    </div>
    <div>
        <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
            <span style="color: #60a5fa;">Locale / Altri (<?php echo $countLocal; ?>)</span>
            <span style="color: #60a5fa;"><?php echo $pctLocal; ?>%</span>
        </div>
        <div style="background: rgba(15, 23, 42, 0.6); height: 8px; border-radius: 4px; overflow: hidden; border: 1px solid var(--border-color);">
            <div style="background: #60a5fa; width: <?php echo $pctLocal; ?>%; height: 100%; transition: width 0.4s ease;"></div>
        </div>
    </div>
    <?php
    $trafficChartHtml = ob_get_clean();

    ob_start();
    ?>
    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
        <svg style="width:16px;height:16px;fill:var(--accent-color);" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
        Informazioni di Sistema & VPS
    </div>
    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
            <span style="color: var(--text-muted);">Servizio Reflector:</span>
            <span style="color: var(--success-color); font-weight: 700;"><?php echo $isRunning ? 'Attivo (Running)' : 'Fermo'; ?></span>
        </div>
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
            <span style="color: var(--text-muted);">Memoria RAM:</span>
            <span style="font-weight: 600; font-family: monospace; color: #60a5fa;"><?php echo $ramUsage; ?></span>
        </div>
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
            <span style="color: var(--text-muted);">Spazio Disco (Root):</span>
            <span style="font-weight: 600; font-family: monospace;"><?php echo $diskUsage; ?></span>
        </div>
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
            <span style="color: var(--text-muted);">Uptime VPS:</span>
            <span style="font-weight: 600; font-size: 12px;"><?php echo $uptimeStr; ?></span>
        </div>
        <div style="display: flex; justify-content: space-between;">
            <span style="color: var(--text-muted);">Dashboard Ver:</span>
            <span style="font-weight: 600;"><?php echo htmlspecialchars(DASHBOARD_VERSION); ?></span>
        </div>
    </div>
    <?php
    $systemInfoHtml = ob_get_clean();

    ob_start();
    ?>
    <div style="text-align: center; color: var(--text-muted); font-size: 13px; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color);">
        <span><?php echo htmlspecialchars(HEADER_TITLE); ?> &bull; Dashboard Ver. <?php echo htmlspecialchars(DASHBOARD_VERSION); ?></span>
        <span>&bull; Contatto Admin: <a href="mailto:<?php echo htmlspecialchars(ADMIN_EMAIL); ?>" style="color: #60a5fa; text-decoration: none; font-weight: 600;"><?php echo htmlspecialchars(ADMIN_EMAIL); ?></a></span>
    </div>
    <?php
    $footerHtml = ob_get_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'gateway_online' => $gatewayOnlineCount,
        'distinct_callsigns' => $distinctCallsignsCount,
        'total_qso' => $totalQsoCount,
        'live_activity' => $liveActivityHtml,
        'recent_pills' => $recentPillsHtml,
        'transits' => $transitsHtml,
        'gateways_list' => $gatewaysHtml,
        'traffic_chart' => $trafficChartHtml,
        'system_info' => $systemInfoHtml,
        'footer_info' => $footerHtml
    ]);
    exit;
}

date_default_timezone_set('Europe/Rome');
$status_output = shell_exec('sudo systemctl is-active nxdnreflector');
$isRunning = (trim($status_output) === 'active');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(HEADER_SUBTITLE . ' - ' . HEADER_TITLE); ?></title>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-color: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --accent-color: #3b82f6;
            --success-color: #22c55e;
            --danger-color: #ef4444;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 30px; }
        .container { max-width: 1500px; margin: 0 auto; }
        header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 20px; flex-wrap: wrap; gap: 20px; }
        .header-content { display: flex; align-items: center; gap: 20px; }
        @keyframes globe-pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.92); }
            100% { opacity: 1; transform: scale(1); }
        }
        .globe-box { width: 70px; height: 70px; background-color: #172033; border: 1px solid var(--border-color); border-radius: 14px; display: flex; align-items: center; justify-content: center; color: var(--accent-color); animation: globe-pulse 2s infinite; }
        .globe-box svg { width: 34px; height: 34px; fill: currentColor; }
        .header-title { font-size: 32px; font-weight: 900; color: #ffffff; margin: 0 0 6px 0; letter-spacing: 0.02em; }
        .header-badges { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .badge-id { background-color: rgba(30, 41, 59, 0.9); border: 1px solid var(--border-color); color: #60a5fa; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; font-family: monospace; }
        .badge-xlx { background-color: rgba(6, 78, 59, 0.4); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .badge-dmr { background-color: rgba(120, 53, 15, 0.4); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .badge-ysf { background-color: rgba(153, 27, 27, 0.3); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .dot-sep { color: var(--text-muted); font-size: 10px; }
        
        .header-logo-area { display: flex; align-items: center; }
        .header-logo { height: 48px; width: auto; border-radius: 8px; border: 1px solid var(--border-color); object-fit: contain; background-color: rgba(15, 23, 42, 0.6); padding: 2px; }
        
        .main-layout { display: grid; grid-template-columns: 2.2fr 1fr; gap: 25px; align-items: start; }
        .card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 22px; margin-bottom: 25px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2); }
        .card-title { font-size: 14px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .card-title-left { display: flex; align-items: center; gap: 10px; }
        .card-title svg { width: 18px; height: 18px; fill: var(--accent-color); }
        
        .filters-wrapper { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-input-group { position: relative; display: flex; align-items: center; }
        .filter-input-group svg { position: absolute; left: 10px; width: 14px; height: 14px; fill: var(--text-muted); pointer-events: none; }
        .filter-input { background-color: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 12px; padding: 6px 10px 6px 30px; outline: none; transition: border-color 0.2s; width: 140px; }
        .filter-input:focus { border-color: var(--accent-color); }
        .filter-input::placeholder { color: var(--text-muted); }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
        .stat-card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px 18px; }
        .stat-label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
        .stat-label svg { width: 14px; height: 14px; fill: var(--accent-color); }
        .stat-value { font-size: 22px; font-weight: 800; color: var(--text-color); }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--border-color); }
        th { color: var(--text-muted); background-color: rgba(255, 255, 255, 0.02); font-weight: 600; }
        .callsign-big { font-size: 16px; font-weight: 800; }
        .gateway-text { color: #60a5fa; font-size: 15px; font-weight: 700; }
        .timestamp { color: var(--accent-color); font-family: monospace; }
        
        .gateways-scroll-container { max-height: 620px; overflow-y: auto; padding-right: 4px; }
        .gateways-scroll-container::-webkit-scrollbar { width: 6px; }
        .gateways-scroll-container::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.4); border-radius: 3px; }
        .gateways-scroll-container::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }

        .gateway-item-card { background-color: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; }
        .node-led { display: inline-block; width: 8px; height: 8px; background-color: var(--success-color); border-radius: 50%; box-shadow: 0 0 6px var(--success-color); }
        .recent-call-pill { background-color: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; }
        
        @keyframes blink { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
        .live-badge.txing-blink { background-color: rgba(239, 68, 68, 0.2); color: var(--danger-color); border: 1px solid var(--danger-color); animation: blink 1s infinite; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .live-badge.completed { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); border: 1px solid var(--success-color); padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .txing-blink { color: var(--danger-color); font-weight: 700; animation: blink 1s infinite; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <div class="header-content">
            <div class="globe-box">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
            </div>
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #60a5fa; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2px;"><?php echo htmlspecialchars(HEADER_SUBTITLE); ?></div>
                <div class="header-title"><?php echo htmlspecialchars(HEADER_TITLE); ?></div>
                <div class="header-badges">
                    <span class="badge-id"><?php echo htmlspecialchars(BADGE_TG); ?></span>
                    <span class="dot-sep">&bull;</span>
                    <span class="badge-xlx">
                        <svg style="width:13px;height:13px;fill:currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                        <?php echo htmlspecialchars(BADGE_XLX); ?>
                    </span>
                    <span class="dot-sep">&bull;</span>
                    <span class="badge-dmr">
                        <svg style="width:13px;height:13px;fill:currentColor;" viewBox="0 0 24 24"><path d="M20 15.5c-1.25 0-2.45-.2-3.57-.57-.35-.11-.74-.03-1.02.24l-2.2 2.2c-2.83-1.44-5.15-3.75-6.59-6.59l2.2-2.2c.27-.27.35-.67.24-1.02C8.7 6.45 8.5 5.25 8.5 4c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1 0 9.39 7.61 17 17 17 .55 0 1-.45 1-1v-3.5c0-.55-.45-1-1-1zM19 12h2c0-4.97-4.03-9-9-9v2c3.87 0 7 3.13 7 7zm-4 0h2c0-2.76-2.24-5-5-5v2c1.66 0 3 1.34 3 3z"/></svg>
                        <?php echo htmlspecialchars(BADGE_DMR); ?>
                    </span>
                    <span class="dot-sep">&bull;</span>
                    <span class="badge-ysf">
                        <svg style="width:13px;height:13px;fill:currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                        <?php echo htmlspecialchars(BADGE_YSF); ?>
                    </span>
                </div>
            </div>
        </div>
        <!-- Logo NXDN pulito nell'header -->
        <div class="header-logo-area">
            <img src="nxdn.jpg" alt="NXDN Logo" class="header-logo">
        </div>
    </header>

    <div class="main-layout">
        <div>
            <div class="card">
                <div class="card-title">
                    <div class="card-title-left">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                        Attivit&agrave; in Tempo Reale
                    </div>
                </div>
                <div id="live-activity-container" style="background: rgba(15, 23, 42, 0.5); padding: 15px; border-radius: 10px;">
                    <div style="color: var(--text-muted); font-style: italic; text-align: center;">In attesa di transiti...</div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Gateway Online
                    </div>
                    <div class="stat-value" id="stat-gateway-online">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        Callsign Distinti
                    </div>
                    <div class="stat-value" id="stat-distinct-callsigns">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
                        QSO Registrati
                    </div>
                    <div class="stat-value" id="stat-total-qso">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                        Aggiornamento
                    </div>
                    <div class="stat-value" id="stat-current-time" style="font-family: monospace; color: #fbbf24;">--:--:--</div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <div class="card-title-left">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        Nominativi transitati di recente
                    </div>
                </div>
                <div id="recent-pills-container">
                    <div style="color: var(--text-muted); font-size: 13px;">Nessun nominativo recente</div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <div class="card-title-left">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        Ultimi Transiti (Last Heard)
                    </div>
                    <!-- Filtri di Ricerca Callsign e Gateway con Icone -->
                    <div class="filters-wrapper">
                        <div class="filter-input-group">
                            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                            <input type="text" id="filter-callsign" class="filter-input" placeholder="Cerca Callsign..." onkeyup="filterTransits()">
                        </div>
                        <div class="filter-input-group">
                            <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                            <input type="text" id="filter-gateway" class="filter-input" placeholder="Cerca Gateway..." onkeyup="filterTransits()">
                        </div>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 160px;">Data / Ora</th>
                            <th>Callsign</th>
                            <th>Gateway</th>
                            <th style="width: 100px;">Durata</th>
                        </tr>
                    </thead>
                    <tbody id="transits-tbody">
                        <tr><td colspan="4" class="empty-row" style="text-align: center; color: var(--text-muted); padding: 20px;">Nessun transito registrato oggi</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-title">
                    <div class="card-title-left">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                        Gateway Connessi
                    </div>
                </div>
                <div class="gateways-scroll-container" id="gateways-container">
                    <div style="color: var(--text-muted); text-align: center; padding: 20px;">Nessun gateway connesso</div>
                </div>
            </div>

            <div class="card">
                <div id="traffic-chart-container">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <svg style="width:16px;height:16px;fill:var(--accent-color);" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
                        Statistiche Traffico Gateway
                    </div>
                    <div style="color: var(--text-muted); font-size: 13px; text-align: center; padding: 10px;">Caricamento dati...</div>
                </div>
            </div>

            <div class="card">
                <div id="system-info-container">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <svg style="width:16px;height:16px;fill:var(--accent-color);" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        Informazioni di Sistema & VPS
                    </div>
                    <div style="color: var(--text-muted); font-size: 13px; text-align: center; padding: 10px;">Caricamento info...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer dinamico con email -->
    <div id="footer-container">
        <div style="text-align: center; color: var(--text-muted); font-size: 13px; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color);">
            Caricamento footer...
        </div>
    </div>
</div>

<script>
function updateClock() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    document.getElementById('stat-current-time').innerText = `${hours}:${minutes}:${seconds}`;
}

// Funzione JavaScript per filtrare i transiti in tempo reale mantenendo i filtri attivi anche dopo l'aggiornamento AJAX
function filterTransits() {
    const callsignFilter = document.getElementById('filter-callsign').value.toLowerCase();
    const gatewayFilter = document.getElementById('filter-gateway').value.toLowerCase();
    const rows = document.querySelectorAll('#transits-tbody tr');

    rows.forEach(row => {
        if (row.classList.contains('empty-row')) return;
        const callsign = row.getAttribute('data-callsign') || '';
        const gateway = row.getAttribute('data-gateway') || '';

        const matchesCallsign = callsign.includes(callsignFilter);
        const matchesGateway = gateway.includes(gatewayFilter);

        if (matchesCallsign && matchesGateway) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function updateDashboard() {
    fetch('index.php?ajax=1')
        .then(response => response.json())
        .then(data => {
            document.getElementById('stat-gateway-online').innerText = data.gateway_online;
            document.getElementById('stat-distinct-callsigns').innerText = data.distinct_callsigns;
            document.getElementById('stat-total-qso').innerText = data.total_qso;
            
            document.getElementById('live-activity-container').innerHTML = data.live_activity;
            document.getElementById('recent-pills-container').innerHTML = data.recent_pills;
            document.getElementById('transits-tbody').innerHTML = data.transits;
            document.getElementById('gateways-container').innerHTML = data.gateways_list;
            document.getElementById('traffic-chart-container').innerHTML = data.traffic_chart;
            document.getElementById('system-info-container').innerHTML = data.system_info;
            document.getElementById('footer-container').innerHTML = data.footer_info;

            // Riesegue il filtro in modo che rimanga applicato anche ad ogni refresh automatico AJAX
            filterTransits();
        })
        .catch(error => console.error('Errore:', error));
}

setInterval(updateClock, 1000);
setInterval(updateDashboard, 1000);
updateClock();
updateDashboard();
</script>
</body>
</html>

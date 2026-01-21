<?php

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    error_reporting(0); 

    function cmd($c) { return trim(shell_exec($c . " 2>&1")); }

    // 1. 系统基础
    function getSys() {
        $os_str = cmd('cat /etc/os-release');
        preg_match('/PRETTY_NAME="([^"]+)"/', $os_str, $m);
        $os = isset($m[1]) ? $m[1] : 'Linux System';
        return [
            'os' => $os,
            'kernel' => cmd('uname -r'),
            'uptime' => str_replace('up ', '', cmd('uptime -p')),
            'hostname' => cmd('hostname'),
            'time' => date('H:i:s')
        ];
    }

    // 2. 传感器 (精准匹配你的 sensors 输出)
    function getSensors() {
        $raw = cmd('sensors');
        $lines = explode("\n", $raw);
        $data = [];
        $adapter = 'System';
        
        foreach($lines as $line) {
            $line = trim($line);
            if(empty($line)) continue;
            // 识别适配器
            if(!str_contains($line, ':')) {
                $adapter = $line;
                continue;
            }
            
            // 匹配规则 (根据你的debug数据定制)
            // 显卡: temp1
            if(str_contains($adapter, 'nouveau') && preg_match('/^temp1:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => '显卡 GPU', 'val' => $m[1], 'icon' => '🎮'];
            }
            // CPU 封装: Package id 0
            if(preg_match('/^Package id 0:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => 'CPU 封装', 'val' => $m[1], 'icon' => '🔥'];
            }
            // NVMe: Composite
            if(preg_match('/^Composite:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => 'NVMe 固态', 'val' => $m[1], 'icon' => '⚡'];
            }
            // 主板环境: acpitz temp1
            if(str_contains($adapter, 'acpitz') && preg_match('/^temp1:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => '主板环境', 'val' => $m[1], 'icon' => '🌡️'];
            }
            // 风扇
            if(preg_match('/^fan1:\s+([0-9]+)\s+RPM/', $line, $m)) {
                // 区分显卡风扇和系统风扇
                $name = str_contains($adapter, 'nouveau') ? '显卡风扇' : '系统风扇';
                $data[] = ['name' => $name, 'val' => $m[1], 'unit' => 'RPM', 'icon' => '🌪️'];
            }
        }
        return $data;
    }

    // 3. CPU 8核 + 频率
    function getCpu() {
        // 使用率
        $s1 = cmd('cat /proc/stat | grep "^cpu"');
        usleep(200000); // 0.2s
        $s2 = cmd('cat /proc/stat | grep "^cpu"');
        
        $cores = [];
        $total = 0;
        
        $l1 = explode("\n", $s1);
        $l2 = explode("\n", $s2);
        
        foreach($l1 as $i => $line) {
            if(!isset($l2[$i])) continue;
            $p1 = preg_split('/\s+/', trim($line));
            $p2 = preg_split('/\s+/', trim($l2[$i]));
            
            $t1 = array_sum(array_slice($p1, 1));
            $t2 = array_sum(array_slice($p2, 1));
            $idle1 = $p1[4]; $idle2 = $p2[4];
            
            $usage = 0;
            $diff = $t2 - $t1;
            if($diff > 0) $usage = round(($diff - ($idle2 - $idle1)) / $diff * 100, 1);
            
            if($p1[0] == 'cpu') $total = $usage;
            else $cores[] = $usage;
        }

        // 实时频率 (直接读 cpuinfo)
        $freq_raw = cmd("cat /proc/cpuinfo | grep 'MHz'");
        preg_match_all('/:\s+([0-9\.]+)/', $freq_raw, $fm);
        $freqs = isset($fm[1]) ? array_map('round', $fm[1]) : array_fill(0, 8, 0);

        return ['total' => $total, 'cores' => $cores, 'freqs' => $freqs, 'model' => 'Xeon E3-1246 v3'];
    }

    // 4. 内存
    function getMem() {
        $m = cmd('free -m');
        preg_match('/Mem:\s+(\d+)\s+(\d+)/', $m, $ma);
        preg_match('/Swap:\s+(\d+)\s+(\d+)/', $m, $sa);
        // 获取 Cache
        preg_match('/Cached:\s+(\d+)/', cmd('cat /proc/meminfo'), $c);
        
        $total = $ma[1];
        $used_sys = $ma[2]; 
        $cached = round($c[1]/1024);
        
        return [
            'total' => round($total/1024, 2),
            'used' => round($used_sys/1024, 2),
            'cached' => round($cached/1024, 2),
            'percent' => round($used_sys/$total*100, 1),
            'swap_used' => round($sa[2]/1024, 2),
            'swap_total' => round($sa[1]/1024, 2)
        ];
    }

    // 5. 硬盘 (物理+逻辑)
    function getDisk() {
        // 物理盘 (解析 lsblk)
        $raw = cmd('lsblk -dno NAME,SIZE,MODEL,TYPE | grep disk');
        $phy = [];
        foreach(explode("\n", $raw) as $l) {
            $p = preg_split('/\s\s+/', trim($l)); // 两个空格分割
            if(count($p) >= 3) {
                $phy[] = ['name' => $p[0], 'size' => $p[1], 'model' => $p[2]];
            }
        }
        // 逻辑分区 (根目录)
        $df = cmd('df -hT / | tail -1');
        $parts = preg_split('/\s+/', $df);
        return [
            'phy' => $phy,
            'root' => ['size'=>$parts[2], 'used'=>$parts[3], 'p'=>rtrim($parts[5],'%')]
        ];
    }

    // 6. 网络 (eth0)
    function getNet() {
        // 仅读取 eth0
        $rx = cmd("cat /proc/net/dev | grep eth0 | awk '{print $2}'");
        $tx = cmd("cat /proc/net/dev | grep eth0 | awk '{print $10}'");
        return ['rx' => $rx, 'tx' => $tx];
    }
    
    // 7. 进程 Top 5
    function getProc() {
        $out = cmd("ps -eo comm,%cpu,%mem --sort=-%cpu | head -n 6 | tail -n 5");
        $list = [];
        foreach(explode("\n", $out) as $l) {
            $p = preg_split('/\s+/', trim($l));
            if(count($p) >= 3) $list[] = ['name' => $p[0], 'cpu' => $p[1], 'mem' => $p[2]];
        }
        return $list;
    }

    echo json_encode([
        'sys' => getSys(),
        'cpu' => getCpu(),
        'mem' => getMem(),
        'disk' => getDisk(),
        'sensors' => getSensors(),
        'net' => getNet(),
        'proc' => getProc()
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfect Monitor</title>
    <style>
        :root { --bg: #111; --card: #1c1c1c; --text: #eee; --accent: #007bff; --green: #28a745; --yellow: #ffc107; --red: #dc3545; --border: #333; }
        body { background: var(--bg); color: var(--text); font-family: sans-serif; margin: 0; padding: 20px; font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 15px; max-width: 1400px; margin: 0 auto; }
        
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 15px; }
        .head { border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 10px; font-weight: bold; color: var(--accent); display: flex; justify-content: space-between; }
        
        /* 进度条 */
        .bar-bg { background: #333; height: 6px; border-radius: 3px; overflow: hidden; flex: 1; margin-left: 10px; }
        .bar-fg { height: 100%; transition: width 0.3s; }
        
        /* 传感器 */
        .sensor-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; border-bottom: 1px dashed #333; padding-bottom: 4px; }
        
        /* CPU 核心 */
        .core-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; }
        .core-item { background: #222; padding: 5px; text-align: center; border-radius: 4px; }
        .core-freq { font-size: 10px; color: #888; }
        
        /* 硬盘 */
        .disk-row { display: flex; align-items: center; margin-bottom: 10px; }
        .disk-icon { font-size: 20px; margin-right: 10px; }
        
        /* 进程表 */
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        td { padding: 4px 0; border-bottom: 1px solid #222; }
    </style>
</head>
<body>

<div style="max-width: 1400px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1 style="margin:0; font-size: 20px;">SYSTEM MONITOR</h1>
        <div style="font-size: 12px; color: #888;" id="sys-info">Loading...</div>
    </div>
    <div style="text-align: right;">
        <div style="font-size: 24px; font-weight: bold;" id="clock">00:00:00</div>
        <div style="font-size: 12px; color: #888;" id="uptime">...</div>
    </div>
</div>

<div class="grid">
    <div class="card">
        <div class="head">硬件温度 & 风扇</div>
        <div id="sensor-list"></div>
    </div>

    <div class="card">
        <div class="head"><span>CPU 处理器</span><span style="font-size:12px; color:#888" id="cpu-model"></span></div>
        <div style="display: flex; align-items: center; margin-bottom: 15px;">
            <span style="width: 40px;">Total</span>
            <div class="bar-bg"><div class="bar-fg" id="cpu-bar" style="width:0%; background:var(--accent)"></div></div>
            <span style="width: 40px; text-align: right;" id="cpu-val">0%</span>
        </div>
        <div class="core-grid" id="core-list"></div>
    </div>

    <div class="card">
        <div class="head">内存 RAM</div>
        <div style="text-align: center; margin-bottom: 10px;">
            <span style="font-size: 20px; font-weight: bold;" id="mem-used">0</span>
            <span style="color: #888;"> / <span id="mem-total">0</span> GB</span>
        </div>
        <div style="display: flex; align-items: center; margin-bottom: 5px;">
            <span style="width: 50px;">Used</span>
            <div class="bar-bg"><div class="bar-fg" id="mem-bar" style="width:0%; background:var(--green)"></div></div>
        </div>
        <div style="display: flex; align-items: center; margin-bottom: 15px;">
            <span style="width: 50px;">Cache</span>
            <div class="bar-bg"><div class="bar-fg" id="cache-bar" style="width:0%; background:var(--yellow)"></div></div>
        </div>
        <div style="font-size: 12px; color: #888; text-align: right;" id="swap-val">Swap: 0/0</div>
    </div>

    <div class="card">
        <div class="head">存储设备</div>
        <div id="disk-list"></div>
        <div style="margin-top: 15px; border-top: 1px solid var(--border); padding-top: 10px;">
            <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
                <span>系统分区 (/)</span>
                <span id="disk-root-val">0/0</span>
            </div>
            <div class="bar-bg"><div class="bar-fg" id="disk-root-bar" style="width:0%; background:var(--accent)"></div></div>
        </div>
    </div>

    <div class="card">
        <div class="head">网络 (eth0) & 负载</div>
        <div style="display: flex; justify-content: space-around; margin-bottom: 15px; text-align: center;">
            <div>
                <div style="font-size: 18px; color: var(--green); font-weight: bold;" id="net-down">0 KB/s</div>
                <div style="font-size: 12px; color: #888;">⬇️ 下载</div>
            </div>
            <div>
                <div style="font-size: 18px; color: var(--accent); font-weight: bold;" id="net-up">0 KB/s</div>
                <div style="font-size: 12px; color: #888;">⬆️ 上传</div>
            </div>
        </div>
        <table>
            <tbody id="proc-list"></tbody>
        </table>
    </div>
</div>

<script>
    let lastRx = 0, lastTx = 0, lastTime = 0;

    function fmtSpeed(bytes, sec) {
        if(sec <= 0) return '0 KB/s';
        let s = bytes / sec;
        return s > 1024*1024 ? (s/1024/1024).toFixed(1)+' MB/s' : (s/1024).toFixed(1)+' KB/s';
    }

    function update() {
        fetch('?api=1')
            .then(r => r.json())
            .then(d => {
                // Sys
                document.getElementById('sys-info').innerText = `${d.sys.os} | ${d.sys.kernel}`;
                document.getElementById('uptime').innerText = `Up: ${d.sys.uptime}`;
                document.getElementById('clock').innerText = d.sys.time;
                
                // Sensors
                let sHtml = '';
                d.sensors.forEach(s => {
                    let unit = s.unit ? s.unit : '°C';
                    let valStyle = (unit === '°C' && s.val > 70) ? 'color:var(--red)' : 'color:var(--green)';
                    sHtml += `
                    <div class="sensor-row">
                        <span>${s.icon} ${s.name}</span>
                        <span style="font-weight:bold; ${valStyle}">${s.val} ${unit}</span>
                    </div>`;
                });
                document.getElementById('sensor-list').innerHTML = sHtml;

                // CPU
                document.getElementById('cpu-model').innerText = d.cpu.model;
                document.getElementById('cpu-bar').style.width = d.cpu.total + '%';
                document.getElementById('cpu-val').innerText = d.cpu.total + '%';
                let cHtml = '';
                d.cpu.cores.forEach((usage, i) => {
                    let freq = d.cpu.freqs[i] || 0;
                    cHtml += `
                    <div class="core-item">
                        <div style="font-size:10px; color:#888">Core ${i}</div>
                        <div style="color:var(--accent); font-weight:bold">${usage}%</div>
                        <div class="core-freq">${freq} MHz</div>
                        <div class="bar-bg" style="height:3px; margin-top:3px"><div class="bar-fg" style="width:${usage}%; background:${usage>80?'var(--red)':'var(--green)'}"></div></div>
                    </div>`;
                });
                document.getElementById('core-list').innerHTML = cHtml;

                // Mem
                document.getElementById('mem-used').innerText = d.mem.used;
                document.getElementById('mem-total').innerText = d.mem.total;
                document.getElementById('mem-bar').style.width = d.mem.percent + '%';
                document.getElementById('cache-bar').style.width = (d.mem.cached / d.mem.total * 100) + '%';
                document.getElementById('swap-val').innerText = `Swap: ${d.mem.swap_used} / ${d.mem.swap_total} GB`;

                // Disk
                let dHtml = '';
                d.disk.phy.forEach(p => {
                    let icon = p.model.includes('NVMe') ? '⚡' : '💾';
                    dHtml += `
                    <div class="disk-row">
                        <div class="disk-icon">${icon}</div>
                        <div>
                            <div style="font-size:13px; font-weight:bold">${p.model}</div>
                            <div style="font-size:11px; color:#888">${p.name} (${p.size})</div>
                        </div>
                    </div>`;
                });
                document.getElementById('disk-list').innerHTML = dHtml;
                document.getElementById('disk-root-val').innerText = `${d.disk.root.used} / ${d.disk.root.size}`;
                document.getElementById('disk-root-bar').style.width = d.disk.root.p;

                // Net
                let now = Date.now()/1000;
                if(lastTime > 0) {
                    let diff = now - lastTime;
                    document.getElementById('net-down').innerText = fmtSpeed(d.net.rx - lastRx, diff);
                    document.getElementById('net-up').innerText = fmtSpeed(d.net.tx - lastTx, diff);
                }
                lastRx = d.net.rx; lastTx = d.net.tx; lastTime = now;

                // Proc
                let pHtml = '';
                d.proc.forEach(p => pHtml += `<tr><td>${p.name}</td><td style="color:var(--accent); text-align:right">${p.cpu}%</td><td style="color:var(--green); text-align:right">${p.mem}%</td></tr>`);
                document.getElementById('proc-list').innerHTML = pHtml;
            });
    }

    update();
    setInterval(update, 2000);
</script>
</body>
</html>

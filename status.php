<?php
// ==========================================
// 后端 API 逻辑
// ==========================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    // 开启错误报告以便调试，生产环境可关闭
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); 

    // 0. 核心环境检测
    $disabled_functions = explode(',', ini_get('disable_functions'));
    if (in_array('shell_exec', $disabled_functions)) {
        echo json_encode(['error' => '必须在宝塔 PHP 配置中删除 shell_exec 禁用函数，否则无法获取硬件信息']);
        exit;
    }

    // 辅助函数：执行命令
    function cmd($c) { 
        $res = shell_exec($c . " 2>&1");
        return $res ? trim($res) : ''; 
    }
    
    // 辅助函数：兼容 PHP7 的字符串包含检查
    function has($haystack, $needle) {
        return strpos($haystack, $needle) !== false;
    }

    // 1. 系统基础
    function getSys() {
        $os_str = cmd('cat /etc/os-release');
        preg_match('/PRETTY_NAME="([^"]+)"/', $os_str, $m);
        $os = isset($m[1]) ? $m[1] : 'Linux System';
        
        $uptime_raw = cmd('uptime -p');
        $uptime = $uptime_raw ? str_replace('up ', '', $uptime_raw) : 'Unknown';

        return [
            'os' => $os,
            'kernel' => cmd('uname -r'),
            'uptime' => $uptime,
            'hostname' => cmd('hostname'),
            'time' => date('H:i:s')
        ];
    }

    // 2. 传感器 (增强容错)
    function getSensors() {
        $raw = cmd('sensors');
        if (empty($raw)) return []; // 没装 sensors 或无权限

        $lines = explode("\n", $raw);
        $data = [];
        $adapter = 'System';
        
        foreach($lines as $line) {
            $line = trim($line);
            if(empty($line)) continue;
            // 识别适配器
            if(!has($line, ':')) {
                $adapter = $line;
                continue;
            }
            
            // 显卡
            if(has($adapter, 'nouveau') && preg_match('/^temp1:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => '显卡 GPU', 'val' => floatval($m[1]), 'icon' => '🎮'];
            }
            // CPU 封装
            if(preg_match('/^Package id 0:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => 'CPU 封装', 'val' => floatval($m[1]), 'icon' => '🔥'];
            }
            // NVMe
            if(preg_match('/^Composite:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => 'NVMe 固态', 'val' => floatval($m[1]), 'icon' => '⚡'];
            }
            // 主板环境
            if(has($adapter, 'acpitz') && preg_match('/^temp1:\s+\+([0-9\.]+)/', $line, $m)) {
                $data[] = ['name' => '主板环境', 'val' => floatval($m[1]), 'icon' => '🌡️'];
            }
            // 风扇
            if(preg_match('/^fan1:\s+([0-9]+)\s+RPM/', $line, $m)) {
                $name = has($adapter, 'nouveau') ? '显卡风扇' : '系统风扇';
                $data[] = ['name' => $name, 'val' => intval($m[1]), 'unit' => 'RPM', 'icon' => '🌪️'];
            }
        }
        return $data;
    }

    // 3. CPU (优化计算逻辑)
    function getCpu() {
        // 第一次采样
        $s1 = cmd('cat /proc/stat | grep "^cpu"');
        usleep(150000); // 150ms
        // 第二次采样
        $s2 = cmd('cat /proc/stat | grep "^cpu"');
        
        $cores = [];
        $total = 0;
        
        $l1 = explode("\n", $s1);
        $l2 = explode("\n", $s2);
        
        foreach($l1 as $i => $line) {
            if(!isset($l2[$i])) continue;
            $p1 = preg_split('/\s+/', trim($line));
            $p2 = preg_split('/\s+/', trim($l2[$i]));
            
            // 简单的 CPU 使用率计算算法
            // idle 是第 5 列 (index 4), total 是所有列之和
            $info1 = array_slice($p1, 1);
            $info2 = array_slice($p2, 1);
            $t1 = array_sum($info1);
            $t2 = array_sum($info2);
            $idle1 = $p1[4]; 
            $idle2 = $p2[4];
            
            $diff_total = $t2 - $t1;
            $diff_idle = $idle2 - $idle1;
            
            $usage = 0;
            if($diff_total > 0) {
                $usage = round((($diff_total - $diff_idle) / $diff_total) * 100, 1);
            }
            
            if($p1[0] == 'cpu') $total = $usage;
            else $cores[] = $usage;
        }

        // 频率
        $freq_raw = cmd("grep 'MHz' /proc/cpuinfo");
        preg_match_all('/:\s+([0-9\.]+)/', $freq_raw, $fm);
        $freqs = isset($fm[1]) ? array_map('round', $fm[1]) : array_fill(0, 8, 0);

        // 型号
        $model_raw = cmd("grep 'model name' /proc/cpuinfo | head -1");
        $model = explode(':', $model_raw)[1] ?? 'CPU';

        return ['total' => $total, 'cores' => $cores, 'freqs' => $freqs, 'model' => trim($model)];
    }

    // 4. 内存
    function getMem() {
        $m = cmd('free -m');
        if (empty($m)) return ['total'=>0, 'used'=>0, 'percent'=>0, 'cached'=>0, 'swap_used'=>0, 'swap_total'=>0];

        preg_match('/Mem:\s+(\d+)\s+(\d+)/', $m, $ma);
        preg_match('/Swap:\s+(\d+)\s+(\d+)/', $m, $sa);
        
        // 尝试读取 Cached
        $meminfo = cmd('cat /proc/meminfo');
        preg_match('/Cached:\s+(\d+)/', $meminfo, $c);
        
        $total = isset($ma[1]) ? $ma[1] : 0;
        $used_sys = isset($ma[2]) ? $ma[2] : 0;
        $cached = isset($c[1]) ? round($c[1]/1024) : 0;
        
        return [
            'total' => round($total/1024, 2),
            'used' => round($used_sys/1024, 2),
            'cached' => round($cached/1024, 2),
            'percent' => $total > 0 ? round($used_sys/$total*100, 1) : 0,
            'swap_used' => isset($sa[2]) ? round($sa[2]/1024, 2) : 0,
            'swap_total' => isset($sa[1]) ? round($sa[1]/1024, 2) : 0
        ];
    }

    // 5. 硬盘
    function getDisk() {
        $phy = [];
        $raw = cmd('lsblk -dno NAME,SIZE,MODEL,TYPE | grep disk');
        if ($raw) {
            foreach(explode("\n", $raw) as $l) {
                // 将多个空格替换为一个，方便分割
                $l = preg_replace('/\s+/', ' ', trim($l));
                $p = explode(' ', $l, 3); // 限制分割为3部分
                if(count($p) >= 2) {
                    $phy[] = ['name' => $p[0], 'size' => $p[1], 'model' => isset($p[2]) ? $p[2] : 'Disk'];
                }
            }
        }
        
        $df = cmd('df -hT / | tail -1');
        $parts = preg_split('/\s+/', $df);
        return [
            'phy' => $phy,
            'root' => [
                'size' => isset($parts[2]) ? $parts[2] : '0G',
                'used' => isset($parts[3]) ? $parts[3] : '0G',
                'p' => isset($parts[5]) ? rtrim($parts[5],'%') : 0
            ]
        ];
    }

    // 6. 网络
    function getNet() {
        $raw = cmd("cat /proc/net/dev");
        // 自动查找第一个非 lo 的网卡
        preg_match('/(eth0|ens\d+|enp\d+s\d+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $raw, $m);
        return [
            'iface' => isset($m[1]) ? $m[1] : 'eth0',
            'rx' => isset($m[2]) ? $m[2] : 0, 
            'tx' => isset($m[3]) ? $m[3] : 0
        ];
    }
    
    // 7. 进程
    function getProc() {
        // 防止 ps 命令不存在
        $out = cmd("ps -eo comm,%cpu,%mem --sort=-%cpu | head -n 6 | tail -n 5");
        $list = [];
        if ($out) {
            foreach(explode("\n", $out) as $l) {
                $p = preg_split('/\s+/', trim($l));
                if(count($p) >= 3) $list[] = ['name' => $p[0], 'cpu' => $p[1], 'mem' => $p[2]];
            }
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
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 15px; max-width: 1400px; margin: 0 auto; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 15px; }
        .head { border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 10px; font-weight: bold; color: var(--accent); display: flex; justify-content: space-between; }
        .bar-bg { background: #333; height: 6px; border-radius: 3px; overflow: hidden; flex: 1; margin-left: 10px; }
        .bar-fg { height: 100%; transition: width 0.3s; }
        .sensor-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; border-bottom: 1px dashed #333; padding-bottom: 4px; }
        .core-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; }
        .core-item { background: #222; padding: 5px; text-align: center; border-radius: 4px; }
        .core-freq { font-size: 10px; color: #888; }
        .disk-row { display: flex; align-items: center; margin-bottom: 10px; }
        .disk-icon { font-size: 20px; margin-right: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        td { padding: 4px 0; border-bottom: 1px solid #222; }
        
        /* 错误提示层 */
        #error-overlay { display:none; position:fixed; top:0; left:0; right:0; padding:20px; background:var(--red); color:#fff; text-align:center; z-index:999; }
    </style>
</head>
<body>

<div id="error-overlay"></div>

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
        <div id="sensor-list">
            <div style="color:#666; text-align:center; padding:20px">暂无数据 (lm-sensors 未安装?)</div>
        </div>
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
        <div class="head">网络 (<span id="net-iface">eth0</span>)</div>
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
        if(s < 0) return '0 KB/s'; // 防止负数
        return s > 1024*1024 ? (s/1024/1024).toFixed(1)+' MB/s' : (s/1024).toFixed(1)+' KB/s';
    }

    function update() {
        fetch('?api=1')
            .then(r => r.json())
            .then(d => {
                if(d.error) {
                    document.getElementById('error-overlay').style.display = 'block';
                    document.getElementById('error-overlay').innerText = d.error;
                    return;
                }

                // Sys
                document.getElementById('sys-info').innerText = `${d.sys.os} | ${d.sys.kernel}`;
                document.getElementById('uptime').innerText = `Up: ${d.sys.uptime}`;
                document.getElementById('clock').innerText = d.sys.time;
                
                // Sensors
                let sHtml = '';
                if(d.sensors.length > 0) {
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
                }

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
                // 修复 Cache 计算导致的布局问题
                let cacheP = d.mem.total > 0 ? (d.mem.cached / d.mem.total * 100) : 0;
                document.getElementById('cache-bar').style.width = cacheP + '%';
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
                document.getElementById('disk-root-bar').style.width = d.disk.root.p + '%';

                // Net
                document.getElementById('net-iface').innerText = d.net.iface;
                let now = Date.now()/1000;
                if(lastTime > 0) {
                    let diff = now - lastTime;
                    // 处理数据回绕或重置的情况
                    let diffRx = d.net.rx - lastRx;
                    let diffTx = d.net.tx - lastTx;
                    if(diffRx >= 0 && diffTx >= 0) {
                        document.getElementById('net-down').innerText = fmtSpeed(diffRx, diff);
                        document.getElementById('net-up').innerText = fmtSpeed(diffTx, diff);
                    }
                }
                lastRx = d.net.rx; lastTx = d.net.tx; lastTime = now;

                // Proc
                let pHtml = '';
                d.proc.forEach(p => pHtml += `<tr><td>${p.name}</td><td style="color:var(--accent); text-align:right">${p.cpu}%</td><td style="color:var(--green); text-align:right">${p.mem}%</td></tr>`);
                document.getElementById('proc-list').innerHTML = pHtml;
            })
            .catch(e => {
                console.log("Error:", e);
                // 暂时不弹出报错，以免刷新时闪烁
            });
    }

    update();
    setInterval(update, 2000);
</script>
</body>
</html>

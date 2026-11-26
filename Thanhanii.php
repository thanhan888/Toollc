<?php
/*
 * LC79 — THỐNG KÊ PHÂN TÍCH ĐA TẦNG
 * Bản an toàn: chỉ phân tích dữ liệu lịch sử, không đặt cược,
 * không gợi ý mức cược, không martingale và không tối ưu tỷ lệ thắng.
 *
 * Các nhóm thống kê lấy ý tưởng từ Ug_fixed_v3.txt / Ug_fixed.txt:
 * - chuỗi Tài/Xỉu, run/streak, nhịp đảo
 * - trung bình điểm các cửa sổ 3/6/12/20
 * - độ lệch quanh mốc cân bằng 10.5
 * - chênh lệch/acceleration giữa các cửa sổ
 * - mật độ Tài/Xỉu ở nhiều cửa sổ
 * - phân bố mặt xúc xắc và tổng điểm
 */

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normalize_side($v) {
    $v = strtoupper(trim((string)$v));
    return in_array($v, ['TAI','TÀI','T'], true) ? 'T' :
           (in_array($v, ['XIU','XỈU','X', 'XIU'], true) ? 'X' : null);
}

function parse_rows($raw) {
    $raw = trim($raw);
    if ($raw === '') return [];

    $json = json_decode($raw, true);
    if (is_array($json)) $rows = $json;
    else {
        $rows = [];
        foreach (preg_split('/\R+/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = preg_split('/[,\s;|]+/', $line);
            if (count($p) < 2) continue;
            $row = ['id' => $p[0], 'resultTruyenThong' => $p[1]];
            if (count($p) >= 5) $row['dices'] = [(int)$p[2], (int)$p[3], (int)$p[4]];
            $rows[] = $row;
        }
    }

    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $side = normalize_side($r['resultTruyenThong'] ?? ($r['result'] ?? $r['side'] ?? ''));
        if ($side === null) continue;
        $dice = $r['dices'] ?? [];
        if (!is_array($dice)) $dice = [];
        $dice = array_values(array_filter(array_map('intval', $dice), fn($x) => $x >= 1 && $x <= 6));
        $point = isset($r['point']) ? (int)$r['point'] : (count($dice) === 3 ? array_sum($dice) : null);
        $out[] = [
            'id' => $r['id'] ?? $r['session'] ?? count($out) + 1,
            'side' => $side,
            'point' => $point,
            'dices' => count($dice) === 3 ? $dice : []
        ];
    }

    // API thường trả mới nhất trước; giữ nguyên thứ tự người dùng đưa vào.
    return $out;
}

function avg_window($vals, $n) {
    $v = array_slice($vals, 0, $n);
    return count($v) ? array_sum($v) / count($v) : null;
}

function rate_side($sides, $side, $n) {
    $v = array_slice($sides, 0, $n);
    if (!$v) return null;
    return count(array_filter($v, fn($x) => $x === $side)) / count($v);
}

function runs($sides) {
    $out = [];
    if (!$sides) return $out;
    $cur = $sides[0]; $len = 1;
    for ($i=1; $i<count($sides); $i++) {
        if ($sides[$i] === $cur) $len++;
        else { $out[] = [$cur, $len]; $cur = $sides[$i]; $len = 1; }
    }
    $out[] = [$cur, $len];
    return $out;
}

function analyze($rows) {
    $sides = array_column($rows, 'side');
    $points = array_values(array_filter(array_column($rows, 'point'), fn($x) => $x !== null));
    $n = count($sides);

    $flip = 0;
    for ($i=1; $i<$n; $i++) if ($sides[$i] !== $sides[$i-1]) $flip++;
    $flipRate = $n > 1 ? $flip / ($n-1) : 0;

    $rs = runs($sides);
    $streak = $rs ? $rs[0][1] : 0;
    $current = $rs ? ($rs[0][0] === 'T' ? 'Tài' : 'Xỉu') : '—';

    $avg = [];
    foreach ([3,6,12,20] as $w) $avg[$w] = avg_window($points, $w);

    $density = [];
    foreach ([4,6,8,10,12,20] as $w) {
        $density[$w] = [
            'tai' => rate_side($sides, 'T', $w),
            'xiu' => rate_side($sides, 'X', $w),
        ];
    }

    $acc = [];
    if ($avg[3] !== null && $avg[6] !== null) $acc['3_vs_6'] = $avg[3] - $avg[6];
    if ($avg[6] !== null && $avg[12] !== null) $acc['6_vs_12'] = $avg[6] - $avg[12];
    if ($avg[12] !== null && $avg[20] !== null) $acc['12_vs_20'] = $avg[12] - $avg[20];

    $equilibrium = [];
    foreach ([3,6,12,20] as $w) {
        if ($avg[$w] !== null) $equilibrium[$w] = $avg[$w] - 10.5;
    }

    $diceFreq = array_fill(1, 6, 0);
    $diceTotals = [];
    foreach ($rows as $r) {
        foreach ($r['dices'] as $d) $diceFreq[$d]++;
        if ($r['point'] !== null) $diceTotals[] = $r['point'];
    }

    $last10 = array_slice($sides, 0, 10);
    $t10 = count(array_filter($last10, fn($x) => $x === 'T'));
    $x10 = count($last10) - $t10;

    return [
        'n' => $n, 'current' => $current, 'streak' => $streak,
        'flip_rate' => $flipRate, 'runs' => $rs, 'avg' => $avg,
        'density' => $density, 'acc' => $acc, 'equilibrium' => $equilibrium,
        'dice_freq' => $diceFreq, 't10' => $t10, 'x10' => $x10,
        'mean_all' => $points ? array_sum($points)/count($points) : null,
    ];
}

$raw = $_POST['data'] ?? '';
$rows = parse_rows($raw);
$A = $rows ? analyze($rows) : null;
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LC79 · Phân tích đa tầng</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#090909;color:#f5f5f5;font-family:Inter,Arial,sans-serif}
.wrap{max-width:980px;margin:auto;padding:22px}.hero,.card{background:linear-gradient(145deg,#171717,#0d0d0d);border:1px solid #343434;border-radius:20px;box-shadow:0 15px 45px #0008}
.hero{padding:24px;margin-bottom:16px}.brand{letter-spacing:2px;font-weight:900;font-size:24px}.sub{color:#aaa;margin-top:7px;font-size:13px}
textarea{width:100%;min-height:180px;background:#070707;color:#eee;border:1px solid #383838;border-radius:14px;padding:14px;font:13px monospace;outline:none}
button{border:0;border-radius:12px;padding:13px 18px;margin-top:10px;background:#d4af37;color:#080808;font-weight:900;cursor:pointer}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}.metric{padding:14px;border:1px solid #303030;border-radius:15px;background:#101010}.metric small{display:block;color:#999;font-size:10px}.metric b{display:block;margin-top:6px;font-size:20px}
.card{padding:18px;margin-top:16px}.title{font-weight:900;letter-spacing:.5px;margin-bottom:12px}.row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #292929}.muted{color:#999}.ok{color:#ddd}.bar{height:8px;background:#292929;border-radius:20px;overflow:hidden;margin-top:7px}.bar i{display:block;height:100%;background:#d4af37}
@media(max-width:700px){.grid{grid-template-columns:repeat(2,1fr)}.wrap{padding:12px}}
</style>
</head>
<body>
<div class="wrap">
<section class="hero">
  <div class="brand">THANHAN LC79 · ANALYTICS</div>
  <div class="sub">Phân tích thống kê lịch sử nhiều tầng — không phải công cụ đặt cược.</div>
</section>

<section class="card">
  <div class="title">DỮ LIỆU PHIÊN</div>
  <form method="post">
    <textarea name="data" placeholder='Dán JSON dạng [{"id":123,"resultTruyenThong":"TAI","point":12,"dices":[4,5,3]}, ...]
Hoặc mỗi dòng: PHIEN TAI/XIU XUC_XAC_1 XUC_XAC_2 XUC_XAC_3'><?=h($raw)?></textarea>
    <button>PHÂN TÍCH</button>
  </form>
</section>

<?php if ($A): ?>
<section class="grid">
  <div class="metric"><small>SỐ PHIÊN</small><b><?=number_format($A['n'])?></b></div>
  <div class="metric"><small>CHUỖI HIỆN TẠI</small><b><?=h($A['current'])?></b></div>
  <div class="metric"><small>ĐỘ DÀI CHUỖI</small><b><?=$A['streak']?></b></div>
  <div class="metric"><small>NHỊP ĐẢO</small><b><?=number_format($A['flip_rate']*100,1)?>%</b></div>
</section>

<section class="card">
<div class="title">TRUNG BÌNH ĐIỂM · MỐC CÂN BẰNG 10.5</div>
<?php foreach($A['avg'] as $w=>$v): ?>
<div class="row"><span>Cửa sổ <?=$w?> phiên</span><b><?= $v===null?'—':number_format($v,2) ?><?= $v===null?'':' · lệch '.number_format($A['equilibrium'][$w],2) ?></b></div>
<?php endforeach; ?>
</section>

<section class="card">
<div class="title">MẬT ĐỘ TÀI / XỈU</div>
<?php foreach($A['density'] as $w=>$d): if($d['tai']===null) continue; ?>
<div class="row"><span><?=$w?> phiên</span><b>TÀI <?=number_format($d['tai']*100,1)?>% · XỈU <?=number_format($d['xiu']*100,1)?>%</b></div>
<?php endforeach; ?>
</section>

<section class="card">
<div class="title">ACCELERATION · CHÊNH LỆCH CÁC CỬA SỔ</div>
<?php foreach($A['acc'] as $k=>$v): ?>
<div class="row"><span><?=h(str_replace('_',' → ',$k))?></span><b><?=number_format($v,2)?></b></div>
<?php endforeach; ?>
</section>

<section class="card">
<div class="title">MẶT XÚC XẮC</div>
<?php foreach($A['dice_freq'] as $face=>$cnt): ?>
<div class="row"><span>Mặt <?=$face?></span><b><?=$cnt?></b></div>
<?php endforeach; ?>
<div class="row"><span>Điểm trung bình toàn mẫu</span><b><?= $A['mean_all']===null?'—':number_format($A['mean_all'],2) ?></b></div>
</section>

<section class="card">
<div class="title">CHUỖI / RUN GẦN NHẤT</div>
<?php foreach(array_slice($A['runs'],0,12) as $r): ?>
<div class="row"><span><?= $r[0]==='T'?'TÀI':'XỈU' ?></span><b><?=$r[1]?> phiên</b></div>
<?php endforeach; ?>
</section>
<?php endif; ?>
</div>
</body>
</html>

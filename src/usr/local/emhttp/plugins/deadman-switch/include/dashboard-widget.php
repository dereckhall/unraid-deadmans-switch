<?php
// Dead Man's Switch - Dashboard Widget (Unraid tile format)

require_once __DIR__ . '/helpers.php';

$config = dms_load_config();
$state = dms_load_state();
$status = dms_get_status($config, $state);
$remaining = dms_time_remaining($config, $state);

$badge_colors = [
    'disarmed'       => '#6c757d',
    'armed_ok'       => '#4caf50',
    'armed_warning'  => '#ff9800',
    'armed_critical' => '#f44336',
    'grace_period'   => '#d32f2f',
    'triggered'      => '#b71c1c',
    'paused'         => '#2196f3',
];

$badge_labels = [
    'disarmed'       => 'DISARMED',
    'armed_ok'       => 'OK',
    'armed_warning'  => 'WARNING',
    'armed_critical' => 'CRITICAL',
    'grace_period'   => 'GRACE PERIOD',
    'triggered'      => 'TRIGGERED',
    'paused'         => 'PAUSED',
];

$color = $badge_colors[$status] ?? '#6c757d';
$label = $badge_labels[$status] ?? strtoupper($status);

$remaining_text = '';
if ($state['armed'] && $remaining !== null && !$state['paused']) {
    $remaining_text = dms_format_time_remaining($remaining);
}

$last_checkin_ago = '';
if ($state['last_checkin']) {
    $ago_seconds = time() - strtotime($state['last_checkin']);
    $ago_days = floor($ago_seconds / 86400);
    $ago_hours = floor(($ago_seconds % 86400) / 3600);
    if ($ago_days > 0) {
        $last_checkin_ago = "{$ago_days}d {$ago_hours}h ago";
    } else {
        $last_checkin_ago = "{$ago_hours}h ago";
    }
}

// Detect responsive WebGUI (7.2+)
$unraidVersion = @parse_ini_file('/etc/unraid-version')['version'] ?? '6.12.0';
$isResponsive = version_compare($unraidVersion, '7.2.0-beta', '>=');
?>

<style>
.dms-widget-badge { display:inline-block; padding:1px 8px; border-radius:3px; color:#fff; font-weight:bold; font-size:0.75em; letter-spacing:0.3px; }
.dms-widget-remaining { font-size:0.9em; font-weight:bold; font-family:monospace; }
.dms-widget-checkin-btn { padding:3px 14px; background:#4caf50; color:#fff; border:none; border-radius:3px; font-size:0.75em; font-weight:bold; cursor:pointer; transition:background 0.2s; letter-spacing:0.3px; }
.dms-widget-checkin-btn:hover { background:#45a049; }
</style>

<tbody title="deadman-switch">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <i class="fa fa-bomb f32"></i>
          <div class="section">
            <?php if ($isResponsive): ?>
              <h3 class="tile-header-main">Dead Man's Switch</h3>
              <span><span class="dms-widget-badge" style="background:<?=$color?>"><?=$label?></span><?php if ($last_checkin_ago): ?> <span style="color:#999;font-size:0.75em">checked in <?=$last_checkin_ago?></span><?php endif; ?></span>
            <?php else: ?>
              Dead Man's Switch<br>
              <span class="dms-widget-badge" style="background:<?=$color?>"><?=$label?></span><?php if ($last_checkin_ago): ?> <span style="color:#999;font-size:0.75em">checked in <?=$last_checkin_ago?></span><?php endif; ?>
            <?php endif; ?>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-ctrl">
            <?php if ($remaining_text): ?>
            <span class="dms-widget-remaining" style="color:<?=$color?>"><?=$remaining_text?></span>
            <?php endif; ?>
            <?php if ($state['armed'] && !$state['triggered']): ?>
            <button class="dms-widget-checkin-btn" id="dms-widget-checkin" onclick="dmsWidgetCheckIn(this)">CHECK IN</button>
            <?php endif; ?>
          </span>
          <span class="tile-header-right-controls">
            <a href="/Settings/deadman-switch"><i class="fa fa-cog control"></i></a>
          </span>
        </span>
      </span>
    </td>
  </tr>
</tbody>

<script>
function dmsWidgetCheckIn(btn) {
    btn.disabled = true;
    btn.textContent = 'Checking in...';
    $.post('/plugins/deadman-switch/include/api.php', {
        action: 'web_checkin',
        csrf_token: '<?=$var['csrf_token'] ?? ''?>'
    }, function(data) {
        if (data.success) {
            btn.textContent = 'Done!';
            btn.style.background = '#2e7d32';
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            btn.textContent = data.message || 'Error';
            btn.style.background = '#d32f2f';
            btn.disabled = false;
        }
    }, 'json').fail(function() {
        btn.textContent = 'Error';
        btn.style.background = '#d32f2f';
        btn.disabled = false;
    });
}
</script>

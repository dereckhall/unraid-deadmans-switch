<?php
// Dead Man's Switch - Dashboard Widget (Unraid tile format)

require_once __DIR__ . '/helpers.php';

$config = dms_load_config();
$state = dms_load_state();
$status = dms_get_status($config, $state);
$remaining = dms_time_remaining($config, $state);
$action_count = count($config['actions']['deletions']) + count($config['actions']['scripts']);

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
$is_urgent = in_array($status, ['armed_warning', 'armed_critical', 'grace_period']);

$remaining_text = 'N/A';
if ($state['armed'] && $remaining !== null) {
    $remaining_text = dms_format_time_remaining($remaining);
}

$last_checkin_text = 'Never';
$last_checkin_ago = '';
if ($state['last_checkin']) {
    $last_checkin_text = date('M j, g:i A', strtotime($state['last_checkin']));
    $ago_seconds = time() - strtotime($state['last_checkin']);
    $ago_days = floor($ago_seconds / 86400);
    $ago_hours = floor(($ago_seconds % 86400) / 3600);
    if ($ago_days > 0) {
        $last_checkin_ago = "({$ago_days}d {$ago_hours}h ago)";
    } else {
        $last_checkin_ago = "({$ago_hours}h ago)";
    }
}

// Detect responsive WebGUI (7.2+)
$unraidVersion = @parse_ini_file('/etc/unraid-version')['version'] ?? '6.12.0';
$isResponsive = version_compare($unraidVersion, '7.2.0-beta', '>=');
?>

<style>
.dms-widget-badge { display:inline-block; padding:3px 12px; border-radius:4px; color:#fff; font-weight:bold; font-size:0.85em; letter-spacing:0.5px; }
.dms-widget-remaining { font-size:1.2em; font-weight:bold; font-family:monospace; }
.dms-widget-detail { color:#999; font-size:0.85em; }
.dms-widget-checkin-btn { display:block; width:100%; padding:10px; margin-top:8px; background:#4caf50; color:#fff; border:none; border-radius:6px; font-size:1.1em; font-weight:bold; cursor:pointer; text-align:center; transition:background 0.2s; }
.dms-widget-checkin-btn:hover { background:#45a049; }
<?php if ($is_urgent): ?>
.dms-widget-urgent { border-left:4px solid <?=$color?>; padding-left:8px; }
<?php endif; ?>
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
              <span><span class="dms-widget-badge" style="background:<?=$color?>"><?=$label?></span></span>
            <?php else: ?>
              Dead Man's Switch<br>
              <span class="dms-widget-badge" style="background:<?=$color?>"><?=$label?></span><br>
            <?php endif; ?>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-ctrl">
            <?php if ($state['armed'] && $remaining !== null && !$state['paused']): ?>
            <span class="dms-widget-remaining" style="color:<?=$color?>"><?=$remaining_text?></span>
            <?php endif; ?>
          </span>
          <span class="tile-header-right-controls">
            <a href="/Settings/deadman-switch"><i class="fa fa-cog control"></i></a>
          </span>
        </span>
      </span>
    </td>
  </tr>
  <tr>
    <td>
      <div class="<?=$is_urgent ? 'dms-widget-urgent' : ''?>" style="padding:10px;">
        <table class="tablesorter">
          <tr>
            <td>Last check-in:</td>
            <td><?=$last_checkin_text?> <?=$last_checkin_ago?></td>
          </tr>
          <?php if ($state['paused'] && $state['pause_expires']): ?>
          <tr>
            <td>Paused until:</td>
            <td><?=date('M j, g:i A', strtotime($state['pause_expires']))?></td>
          </tr>
          <?php endif; ?>
          <?php if ($action_count > 0): ?>
          <tr>
            <td>Actions:</td>
            <td><?=$action_count?> configured<?=$config['dry_run'] ? ' (dry run)' : ''?></td>
          </tr>
          <?php endif; ?>
        </table>

        <?php if ($state['armed'] && !$state['triggered']): ?>
        <button class="dms-widget-checkin-btn" id="dms-widget-checkin" onclick="dmsWidgetCheckIn(this)">CHECK IN</button>
        <?php endif; ?>
      </div>
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
            btn.textContent = 'Checked In!';
            btn.style.background = '#2e7d32';
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            btn.textContent = data.message || 'Error';
            btn.style.background = '#d32f2f';
            btn.disabled = false;
        }
    }, 'json').fail(function() {
        btn.textContent = 'Error - Try Again';
        btn.style.background = '#d32f2f';
        btn.disabled = false;
    });
}
</script>

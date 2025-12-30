<?php
// admin/log.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/_layout.php';
require_admin();

$pdo = db();

$err = '';
$ok  = '';

/**
 * Helpers
 */
function qstr(string $k, string $def = ''): string {
  $v = (string)($_GET[$k] ?? $def);
  return trim($v);
}
function qint(string $k, int $def = 0): int {
  $v = $_GET[$k] ?? null;
  if ($v === null || $v === '') return $def;
  return (int)$v;
}
function qbool(string $k, bool $def = false): bool {
  $v = $_GET[$k] ?? null;
  if ($v === null || $v === '') return $def;
  return ((string)$v === '1' || (string)$v === 'true' || (string)$v === 'on');
}
function build_query_string(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') unset($q[$k]);
    else $q[$k] = $v;
  }
  return http_build_query($q);
}
function page_url(array $overrides = []): string {
  $qs = build_query_string($overrides);
  $base = url('admin/log.php');
  return $qs ? ($base . '?' . $qs) : $base;
}

function class_display_row(array $c): string {
  $label = trim((string)($c['label'] ?? ''));
  $grade = ($c['grade_level'] ?? null) !== null ? (int)$c['grade_level'] : null;
  $name  = trim((string)($c['name'] ?? ''));
  if ($grade !== null && $label !== '') return (string)$grade . $label;
  if ($name !== '') return $name;
  return '#' . (string)((int)($c['id'] ?? 0));
}

/**
 * Robust details_json decode (handles double-escaped dumps)
 */
function decode_details_json(?string $raw): ?array {
  $raw = (string)$raw;
  $raw = trim($raw);
  if ($raw === '' || strtoupper($raw) === 'NULL') return null;

  $data = json_decode($raw, true);
  if (is_array($data)) return $data;

  $un = stripcslashes($raw);
  $data = json_decode($un, true);
  if (is_array($data)) return $data;

  if ($un !== '' && $un[0] === '"' && substr($un, -1) === '"') {
    $un2 = substr($un, 1, -1);
    $un2 = stripcslashes($un2);
    $data = json_decode($un2, true);
    if (is_array($data)) return $data;
  }

  return null;
}

function is_list_array(array $a): bool {
  $i = 0;
  foreach ($a as $k => $_) {
    if ($k !== $i) return false;
    $i++;
  }
  return true;
}

function render_resolved_line(string $main, string $sub = ''): string {
  $html = '<div style="display:flex; flex-direction:column; gap:2px;">';
  $html .= '<div>' . h($main) . '</div>';
  if ($sub !== '') $html .= '<div class="muted">' . h($sub) . '</div>';
  $html .= '</div>';
  return $html;
}

function details_summary(array $d, array $maps): string {
  $parts = [];

  if (isset($d['action'])) $parts[] = 'action=' . (string)$d['action'];
  if (isset($d['mode']))   $parts[] = 'mode=' . (string)$d['mode'];
  if (isset($d['count']))  $parts[] = 'count=' . (string)$d['count'];

  if (isset($d['class_id'])) {
    $id = (int)$d['class_id'];
    $label = $maps['classes'][$id] ?? '';
    $parts[] = $label !== '' ? ('Klasse=' . $label) : ('class_id=#' . $id);
  }
  if (isset($d['report_instance_id'])) {
    $id = (int)$d['report_instance_id'];
    $label = $maps['report_instances'][$id] ?? '';
    $parts[] = $label !== '' ? ('Report=#' . $id) : ('report_instance_id=#' . $id);
  }
  if (isset($d['template_field_id'])) {
    $id = (int)$d['template_field_id'];
    $label = $maps['template_fields'][$id] ?? '';
    $parts[] = $label !== '' ? ('Feld=#' . $id) : ('template_field_id=#' . $id);
  }
  if (isset($d['list_id'])) {
    $id = (int)$d['list_id'];
    $label = $maps['option_lists'][$id] ?? '';
    $parts[] = $label !== '' ? ('Liste=' . $label) : ('list_id=#' . $id);
  }
  if (isset($d['user_id'])) {
    $id = (int)$d['user_id'];
    $label = $maps['users'][$id] ?? '';
    $parts[] = $label !== '' ? ('User=' . $label) : ('user_id=#' . $id);
  }

  if (!$parts) {
    $ks = array_keys($d);
    $ks = array_slice($ks, 0, 3);
    foreach ($ks as $k) $parts[] = (string)$k;
  }

  $s = implode(' · ', $parts);
  if (mb_strlen($s) > 160) $s = mb_substr($s, 0, 160) . ' …';
  return $s;
}

function render_details_kv(array $details, array $maps): string {
  $priority = [
    'action','mode','status','count','skipped','received','created','deleted','copied','imported','generated',
    'school_year','period_label','grade_level','label','name','file','path',
    'user_id','teacher_ids','student_id',
    'class_id','from_class_id','to_class_id',
    'template_id','report_instance_id','template_field_id',
    'list_id','icon_id',
    'deleted_ids','exclude_ids',
    'changed','values_deleted','reports_deleted','students_deleted','items_saved','template_fields_updated',
    'field_values_updated_value_text','field_values_updated_value_json',
    'email','role','is_active','tts_enabled','group_key'
  ];

  $keys = array_keys($details);
  usort($keys, function($a, $b) use ($priority) {
    $pa = array_search($a, $priority, true);
    $pb = array_search($b, $priority, true);
    $pa = ($pa === false) ? 9999 : $pa;
    $pb = ($pb === false) ? 9999 : $pb;
    if ($pa !== $pb) return $pa <=> $pb;
    return strcmp((string)$a, (string)$b);
  });

  $out = '<div class="kv" style="display:flex; flex-direction:column; gap:10px;">';

  foreach ($keys as $k) {
    $v = $details[$k];

    $label = (string)$k;
    $valHtml = '';

    $resolve_id = function(string $bucket, int $id) use ($maps): string {
      return (string)($maps[$bucket][$id] ?? '');
    };

    if (in_array($k, ['user_id','student_id','class_id','from_class_id','to_class_id','template_id','report_instance_id','template_field_id','list_id','icon_id'], true)) {
      $id = (int)$v;
      $sub = '';
      $main = $id > 0 ? ('#' . $id) : '—';

      if ($id > 0) {
        if ($k === 'user_id') {
          $name = $resolve_id('users', $id);
          if ($name !== '') $main = $name . ' (#' . $id . ')';
        } elseif ($k === 'student_id') {
          $name = $resolve_id('students', $id);
          if ($name !== '') $main = $name . ' (#' . $id . ')';
        } elseif (in_array($k, ['class_id','from_class_id','to_class_id'], true)) {
          $name = $resolve_id('classes', $id);
          if ($name !== '') $main = $name . ' (#' . $id . ')';
        } elseif ($k === 'template_id') {
          $name = $resolve_id('templates', $id);
          if ($name !== '') $main = $name . ' (#' . $id . ')';
        } elseif ($k === 'report_instance_id') {
          $sub = $resolve_id('report_instances', $id);
          if ($sub !== '') $main = 'Report #' . $id;
        } elseif ($k === 'template_field_id') {
          $sub = $resolve_id('template_fields', $id);
          if ($sub !== '') $main = 'Feld #' . $id;
        } elseif ($k === 'list_id') {
          $name = $resolve_id('option_lists', $id);
          if ($name !== '') $main = $name . ' (#' . $id . ')';
        } elseif ($k === 'icon_id') {
          $name = $resolve_id('icons', $id);
          if ($name !== '') $main = $name . ' (#' . $id . ')';
        }
      }

      $valHtml = render_resolved_line($main, $sub);
    }
    elseif ($k === 'teacher_ids' || (is_array($v) && is_list_array($v) && preg_match('/_ids$/', (string)$k))) {
      if (!is_array($v) || !$v) {
        $valHtml = '<span class="muted">—</span>';
      } else {
        $chips = [];
        foreach ($v as $idv) {
          $id = (int)$idv;
          $text = '#' . $id;
          if ($k === 'teacher_ids') {
            $name = (string)($maps['users'][$id] ?? '');
            if ($name !== '') $text = $name . ' (#' . $id . ')';
          }
          $chips[] = '<span style="display:inline-block; padding:4px 8px; border:1px solid var(--border); border-radius:999px; margin:2px 6px 2px 0; background:#fff;">'
                   . h($text) . '</span>';
        }
        $valHtml = '<div style="display:flex; flex-wrap:wrap;">' . implode('', $chips) . '</div>';
      }
    }
    elseif (is_bool($v)) {
      $valHtml = $v ? 'true' : 'false';
    }
    elseif ($v === null) {
      $valHtml = '<span class="muted">null</span>';
    }
    elseif (is_scalar($v)) {
      $valHtml = h((string)$v);
    }
    else {
      $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $valHtml = '<code>' . h((string)$j) . '</code>';
    }

    $out .= '<div style="display:flex; gap:12px; align-items:flex-start;">';
    $out .= '<div class="muted" style="min-width:190px; max-width:260px; word-break:break-word;">' . h($label) . '</div>';
    $out .= '<div style="flex:1; min-width:0; word-break:break-word;">' . $valHtml . '</div>';
    $out .= '</div>';
  }

  $out .= '</div>';
  return $out;
}

/**
 * Filters (GET)
 */
$fromDate    = qstr('from');
$toDate      = qstr('to');
$event       = qstr('event');
$userId      = qint('user_id', 0);
$q           = qstr('q');
$showIp      = qbool('show_ip', false);
$filtersOpen = qbool('filters_open', false);

$page    = max(1, qint('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

/**
 * Sorting (clickable headers)
 */
$sort = qstr('sort', 'created_at');
$dir  = strtolower(qstr('dir', 'desc'));
$dir  = ($dir === 'asc') ? 'asc' : 'desc';

/**
 * Allowed sort keys -> SQL expressions
 */
$sortMap = [
  'created_at' => 'al.created_at',
  'event'      => 'al.event_type',
  'user'       => 'u.display_name',
  'ip'         => 'INET6_NTOA(al.ip_address)',
];

if (!isset($sortMap[$sort])) $sort = 'created_at';
if ($sort === 'ip' && !$showIp) $sort = 'created_at';

$orderExpr = $sortMap[$sort] ?? 'al.created_at';

/**
 * WHERE + params
 */
$where = [];
$params = [];
$qCounter = 0;

if ($fromDate !== '') {
  $where[] = "al.created_at >= :from_dt";
  $params[':from_dt'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
  $where[] = "al.created_at <= :to_dt";
  $params[':to_dt'] = $toDate . ' 23:59:59';
}
if ($event !== '') {
  $where[] = "al.event_type = :event_type";
  $params[':event_type'] = $event;
}
if ($userId > 0) {
  $where[] = "al.user_id = :user_id";
  $params[':user_id'] = $userId;
}

if ($q !== '') {
  $qLike = '%' . $q . '%';
  $ph = function() use (&$qCounter, &$params, $qLike): string {
    $qCounter++;
    $name = ':q' . $qCounter;
    $params[$name] = $qLike;
    return $name;
  };

  $p1 = $ph(); // event_type
  $p2 = $ph(); // user display_name
  $p3 = $ph(); // user email
  $p4 = $ph(); // details json

  $where[] = "(
      al.event_type LIKE $p1
      OR u.display_name LIKE $p2
      OR u.email LIKE $p3
      OR CAST(al.details_json AS CHAR) LIKE $p4
    )";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$fromSql = "FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id";

/**
 * Filter dropdown data
 */
try {
  $eventTypes = [];
  $st = $pdo->query("SELECT DISTINCT event_type FROM audit_log ORDER BY event_type ASC");
  $eventTypes = $st->fetchAll(PDO::FETCH_COLUMN);

  $users = [];
  $st = $pdo->query("SELECT id, display_name, email FROM users WHERE deleted_at IS NULL ORDER BY display_name ASC, email ASC");
  $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $err = 'Konnte Filterdaten nicht laden: ' . $e->getMessage();
}

/**
 * Count for pagination
 */
$totalRows = 0;
$totalPages = 1;

try {
  $sqlCount = "SELECT COUNT(*) AS c $fromSql $whereSql";
  $st = $pdo->prepare($sqlCount);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->execute();

  $totalRows = (int)($st->fetchColumn() ?: 0);
  $totalPages = max(1, (int)ceil($totalRows / $perPage));

  if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
  }
} catch (Throwable $e) {
  $err = $err ?: ('Konnte Anzahl nicht bestimmen: ' . $e->getMessage());
}

/**
 * Load page data
 */
$rows = [];
try {
  $selectIp = $showIp ? ", INET6_NTOA(al.ip_address) AS ip_text" : "";

  $sql = "SELECT
            al.id,
            al.created_at,
            al.event_type,
            al.user_id,
            u.display_name AS user_name,
            u.email AS user_email,
            al.details_json
            $selectIp
          $fromSql
          $whereSql
          ORDER BY $orderExpr $dir, al.id $dir
          LIMIT :limit OFFSET :offset";

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);
  $st->execute();

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $err = $err ?: ('Konnte Logs nicht laden: ' . $e->getMessage());
}

/**
 * Batch-resolve IDs inside details_json
 */
$maps = [
  'users' => [],
  'students' => [],
  'classes' => [],
  'templates' => [],
  'report_instances' => [],
  'template_fields' => [],
  'option_lists' => [],
  'icons' => [],
];

try {
  $need = [
    'user' => [],
    'student' => [],
    'class' => [],
    'template' => [],
    'ri' => [],
    'tf' => [],
    'list' => [],
    'icon' => [],
  ];

  foreach ($rows as $r) {
    $d = decode_details_json((string)($r['details_json'] ?? ''));
    if (!is_array($d)) continue;

    foreach (['user_id','student_id','class_id','from_class_id','to_class_id','template_id','report_instance_id','template_field_id','list_id','icon_id'] as $k) {
      if (!isset($d[$k])) continue;
      $id = (int)$d[$k];
      if ($id <= 0) continue;

      if ($k === 'user_id') $need['user'][$id] = true;
      elseif ($k === 'student_id') $need['student'][$id] = true;
      elseif (in_array($k, ['class_id','from_class_id','to_class_id'], true)) $need['class'][$id] = true;
      elseif ($k === 'template_id') $need['template'][$id] = true;
      elseif ($k === 'report_instance_id') $need['ri'][$id] = true;
      elseif ($k === 'template_field_id') $need['tf'][$id] = true;
      elseif ($k === 'list_id') $need['list'][$id] = true;
      elseif ($k === 'icon_id') $need['icon'][$id] = true;
    }

    if (isset($d['teacher_ids']) && is_array($d['teacher_ids'])) {
      foreach ($d['teacher_ids'] as $idv) {
        $id = (int)$idv;
        if ($id > 0) $need['user'][$id] = true;
      }
    }
  }

  $ids = fn($bucket) => array_keys($need[$bucket]);

  if ($ids('user')) {
    $in = implode(',', array_fill(0, count($ids('user')), '?'));
    $st = $pdo->prepare("SELECT id, display_name, email FROM users WHERE id IN ($in)");
    foreach ($ids('user') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $urow) {
      $id = (int)$urow['id'];
      $dn = trim((string)($urow['display_name'] ?? ''));
      $em = trim((string)($urow['email'] ?? ''));
      $text = $dn !== '' ? $dn : $em;
      if ($dn !== '' && $em !== '') $text .= ' (' . $em . ')';
      $maps['users'][$id] = $text;
    }
  }

  if ($ids('student')) {
    $in = implode(',', array_fill(0, count($ids('student')), '?'));
    $st = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ($in)");
    foreach ($ids('student') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $srow) {
      $id = (int)$srow['id'];
      $maps['students'][$id] = trim((string)$srow['last_name']) . ', ' . trim((string)$srow['first_name']);
    }
  }

  if ($ids('class')) {
    $in = implode(',', array_fill(0, count($ids('class')), '?'));
    $st = $pdo->prepare("SELECT id, grade_level, label, name FROM classes WHERE id IN ($in)");
    foreach ($ids('class') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $crow) {
      $id = (int)$crow['id'];
      $maps['classes'][$id] = class_display_row($crow);
    }
  }

  if ($ids('template')) {
    $in = implode(',', array_fill(0, count($ids('template')), '?'));
    $st = $pdo->prepare("SELECT id, name, template_version FROM templates WHERE id IN ($in)");
    foreach ($ids('template') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $trow) {
      $id = (int)$trow['id'];
      $nm = trim((string)$trow['name']);
      $ver = (int)($trow['template_version'] ?? 0);
      $maps['templates'][$id] = $nm . ($ver > 0 ? (' v' . $ver) : '');
    }
  }

  if ($ids('ri')) {
    $in = implode(',', array_fill(0, count($ids('ri')), '?'));
    $sql = "SELECT
              ri.id, ri.school_year, ri.period_label, ri.status,
              s.first_name, s.last_name,
              t.name AS template_name, t.template_version
            FROM report_instances ri
            LEFT JOIN students s ON s.id = ri.student_id
            LEFT JOIN templates t ON t.id = ri.template_id
            WHERE ri.id IN ($in)";
    $st = $pdo->prepare($sql);
    foreach ($ids('ri') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
      $id = (int)$x['id'];
      $student = trim((string)$x['last_name']) !== '' ? (trim((string)$x['last_name']) . ', ' . trim((string)$x['first_name'])) : '';
      $tpl = trim((string)($x['template_name'] ?? ''));
      $ver = (int)($x['template_version'] ?? 0);
      $sy  = trim((string)($x['school_year'] ?? ''));
      $pl  = trim((string)($x['period_label'] ?? ''));
      $stt = trim((string)($x['status'] ?? ''));

      $parts = [];
      if ($student !== '') $parts[] = 'Schüler: ' . $student;
      if ($tpl !== '') $parts[] = 'Template: ' . $tpl . ($ver > 0 ? (' v' . $ver) : '');
      if ($sy !== '') $parts[] = 'Jahr: ' . $sy;
      if ($pl !== '') $parts[] = 'Periode: ' . $pl;
      if ($stt !== '') $parts[] = 'Status: ' . $stt;

      $maps['report_instances'][$id] = implode(' · ', $parts);
    }
  }

  if ($ids('tf')) {
    $in = implode(',', array_fill(0, count($ids('tf')), '?'));
    $sql = "SELECT
              tf.id, tf.field_name, tf.label, tf.template_id,
              t.name AS template_name, t.template_version
            FROM template_fields tf
            LEFT JOIN templates t ON t.id = tf.template_id
            WHERE tf.id IN ($in)";
    $st = $pdo->prepare($sql);
    foreach ($ids('tf') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
      $id = (int)$x['id'];
      $fname = trim((string)($x['field_name'] ?? ''));
      $label = trim((string)($x['label'] ?? ''));
      $tpl   = trim((string)($x['template_name'] ?? ''));
      $ver   = (int)($x['template_version'] ?? 0);

      $fieldText = $label !== '' ? $label : ($fname !== '' ? $fname : '');
      $parts = [];
      if ($fieldText !== '') $parts[] = 'Feld: ' . $fieldText;
      if ($fname !== '' && $fieldText !== $fname) $parts[] = 'Key: ' . $fname;
      if ($tpl !== '') $parts[] = 'Template: ' . $tpl . ($ver > 0 ? (' v' . $ver) : '');

      $maps['template_fields'][$id] = implode(' · ', $parts);
    }
  }

  if ($ids('list')) {
    $in = implode(',', array_fill(0, count($ids('list')), '?'));
    $st = $pdo->prepare("SELECT id, name FROM option_list_templates WHERE id IN ($in)");
    foreach ($ids('list') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
      $id = (int)$x['id'];
      $nm = trim((string)($x['name'] ?? ''));
      $maps['option_lists'][$id] = $nm !== '' ? ('Optionenliste: ' . $nm) : ('Optionenliste #' . $id);
    }
  }

  if ($ids('icon')) {
    $in = implode(',', array_fill(0, count($ids('icon')), '?'));
    $st = $pdo->prepare("SELECT id, filename, file_ext FROM icon_library WHERE id IN ($in)");
    foreach ($ids('icon') as $i => $val) $st->bindValue($i + 1, (int)$val, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
      $id = (int)$x['id'];
      $fn = trim((string)($x['filename'] ?? ''));
      $ext= trim((string)($x['file_ext'] ?? ''));
      $name = $fn !== '' ? $fn : ('icon_' . $id . ($ext ? ('.' . $ext) : ''));
      $maps['icons'][$id] = 'Icon: ' . $name;
    }
  }

} catch (Throwable $e) {
  // best-effort
}

/**
 * Compact "active filters" label
 */
$active = [];
if ($fromDate !== '') $active[] = 'von ' . $fromDate;
if ($toDate !== '') $active[] = 'bis ' . $toDate;
if ($event !== '') $active[] = 'event=' . $event;
if ($userId > 0)  $active[] = 'user_id=' . $userId;
if ($q !== '')    $active[] = 'q=' . $q;
if ($showIp)      $active[] = 'IP an';
$activeText = $active ? implode(' · ', $active) : 'keine';

/**
 * Sort header helper
 */
function sort_link(string $label, string $key, string $currentSort, string $currentDir): string {
  $dir = 'asc';
  $arrow = '';
  if ($currentSort === $key) {
    if ($currentDir === 'asc') { $dir = 'desc'; $arrow = ' ▲'; }
    else { $dir = 'asc'; $arrow = ' ▼'; }
  }
  $href = page_url(['sort' => $key, 'dir' => $dir, 'page' => 1]);
  return '<a href="' . h($href) . '" style="text-decoration:none; color:inherit;">' . h($label . $arrow) . '</a>';
}

render_admin_header('Admin – Audit-Log');
?>
<style>
  .filters-compact .label{font-size:12px; margin-bottom:4px}
  .filters-compact input, .filters-compact select{padding:8px 10px}
  .filters-compact .field{min-width:160px}
  .filters-compact .field.sm{min-width:140px}
  .filters-compact .field.lg{min-width:260px}
  .filters-compact .field.flex{flex:1; min-width:220px}
  .filters-head{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}
  .filters-head .hint{display:flex; gap:10px; align-items:center; flex-wrap:wrap}
  .chip{display:inline-block; padding:4px 10px; border:1px solid var(--border); border-radius:999px; background:#fff; font-size:12px}
  th a:hover{text-decoration:underline!important}

  .linklike{color:inherit; text-decoration:none}
  .linklike:hover{text-decoration:underline}
</style>

<div class="card">
  <div class="filters-head">
    <div>
      <h1>Audit-Log</h1>
      <div class="muted" style="margin-top:4px;">Alle Veränderungen in der Datenbank werden protokolliert. Das entsprechende Protokoll wird hier angezeigt.</div>
    </div>

    <div class="hint">
      <span class="chip">Filter: <?=h($activeText)?></span>
      <button class="btn secondary" type="button" id="btnToggleFilters">
        <?= $filtersOpen ? 'Filter einklappen' : 'Filter anzeigen' ?>
      </button>
    </div>
  </div>
</div>

<?php if ($err): ?><div class="alert danger"><strong><?=h($err)?></strong></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><strong><?=h($ok)?></strong></div><?php endif; ?>

<div class="card" id="filtersCard" style="<?= $filtersOpen ? '' : 'display:none;' ?>">
  <h2>Filter</h2>

  <form method="get" class="filters-compact" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:6px;">
    <div class="field sm">
      <label class="label">Von</label>
      <input type="date" name="from" value="<?=h($fromDate)?>">
    </div>

    <div class="field sm">
      <label class="label">Bis</label>
      <input type="date" name="to" value="<?=h($toDate)?>">
    </div>

    <div class="field">
      <label class="label">Event</label>
      <select name="event">
        <option value="">— alle —</option>
        <?php foreach (($eventTypes ?? []) as $et): ?>
          <option value="<?=h((string)$et)?>" <?=($event === (string)$et ? 'selected' : '')?>><?=h((string)$et)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field lg">
      <label class="label">Benutzer</label>
      <select name="user_id">
        <option value="0">— alle —</option>
        <?php foreach (($users ?? []) as $u): ?>
          <?php
            $uid = (int)($u['id'] ?? 0);
            $label = trim((string)($u['display_name'] ?? ''));
            $mail = trim((string)($u['email'] ?? ''));
            $text = $label !== '' ? $label : $mail;
            if ($mail !== '' && $label !== '' && stripos($label, $mail) === false) $text .= ' (' . $mail . ')';
          ?>
          <option value="<?=h((string)$uid)?>" <?=($userId === $uid ? 'selected' : '')?>><?=h($text)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field flex">
      <label class="label">Freitext (event/user/details)</label>
      <input type="text" name="q" value="<?=h($q)?>" placeholder="…">
    </div>

    <div class="field sm">
      <label style="display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--border); border-radius:10px; background:#fff; margin: 0;">
        <input type="checkbox" name="show_ip" value="1" <?= $showIp ? 'checked' : '' ?>>
        <span>IP anzeigen</span>
      </label>
    </div>

    <div class="actions" style="gap:8px; margin-left:auto;">
      <input type="hidden" name="page" value="1">
      <input type="hidden" name="sort" value="<?=h($sort)?>">
      <input type="hidden" name="dir" value="<?=h($dir)?>">
      <input type="hidden" name="filters_open" value="1" id="filtersOpenField">

      <button class="btn primary" type="submit">Filtern</button>
      <a class="btn secondary" href="<?=h(url('admin/log.php'))?>">Zurücksetzen</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="row-actions" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div class="muted">
      <?=h((string)$totalRows)?> Einträge · Seite <?=h((string)$page)?> / <?=h((string)$totalPages)?>
    </div>

    <div class="row-actions" style="gap:6px;">
      <?php
        $hasPrev = $page > 1;
        $hasNext = $page < $totalPages;

        $window = 2;
        $startP = max(1, $page - $window);
        $endP   = min($totalPages, $page + $window);
      ?>

      <a class="btn secondary <?=(!$hasPrev ? 'disabled' : '')?>"
         href="<?=h($hasPrev ? page_url(['page' => $page - 1]) : '#')?>"
         <?=(!$hasPrev ? 'aria-disabled="true" onclick="return false;"' : '')?>>←</a>

      <?php if ($startP > 1): ?>
        <a class="btn secondary" href="<?=h(page_url(['page' => 1]))?>">1</a>
        <?php if ($startP > 2): ?><span class="muted" style="padding:0 6px;">…</span><?php endif; ?>
      <?php endif; ?>

      <?php for ($p = $startP; $p <= $endP; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="btn primary" style="pointer-events:none; opacity:1;"><?=h((string)$p)?></span>
        <?php else: ?>
          <a class="btn secondary" href="<?=h(page_url(['page' => $p]))?>"><?=h((string)$p)?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($endP < $totalPages): ?>
        <?php if ($endP < $totalPages - 1): ?><span class="muted" style="padding:0 6px;">…</span><?php endif; ?>
        <a class="btn secondary" href="<?=h(page_url(['page' => $totalPages]))?>"><?=h((string)$totalPages)?></a>
      <?php endif; ?>

      <a class="btn secondary <?=(!$hasNext ? 'disabled' : '')?>"
         href="<?=h($hasNext ? page_url(['page' => $page + 1]) : '#')?>"
         <?=(!$hasNext ? 'aria-disabled="true" onclick="return false;"' : '')?>>→</a>
    </div>
  </div>

  <div style="overflow:auto; margin-top:12px;">
    <table class="table" style="min-width:<?= $showIp ? '1150px' : '1020px' ?>;">
      <thead>
        <tr>
          <th style="white-space:nowrap;"><?= sort_link('Zeit', 'created_at', $sort, $dir) ?></th>
          <th style="white-space:nowrap;"><?= sort_link('Event', 'event', $sort, $dir) ?></th>
          <th><?= sort_link('Benutzer', 'user', $sort, $dir) ?></th>
          <?php if ($showIp): ?>
            <th style="white-space:nowrap;"><?= sort_link('IP', 'ip', $sort, $dir) ?></th>
          <?php endif; ?>
          <th>Details (aufgelöst)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $showIp ? 5 : 4 ?>" class="muted">Keine Einträge gefunden.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $dt = (string)($r['created_at'] ?? '');
              $ev = (string)($r['event_type'] ?? '');

              $uid = (int)($r['user_id'] ?? 0);
              $uname = trim((string)($r['user_name'] ?? ''));
              $uemail = trim((string)($r['user_email'] ?? ''));
              $userLabel = $uname !== '' ? $uname : ($uemail !== '' ? $uemail : ($uid ? ('#' . $uid) : '—'));
              if ($uemail !== '' && $uname !== '' && stripos($uname, $uemail) === false) $userLabel .= ' (' . $uemail . ')';

              $detailsRaw = (string)($r['details_json'] ?? '');
              $details = decode_details_json($detailsRaw);
              $sum = is_array($details) ? details_summary($details, $maps) : '';

              $ip = $showIp ? (string)($r['ip_text'] ?? '') : '';
              if ($showIp && $ip === '') $ip = '—';

              // Click-to-filter URLs (keep other filters, but override the clicked one)
              $urlEvent = page_url(['event' => $ev, 'page' => 1, 'filters_open' => 0]);
              $urlUser  = ($uid > 0) ? page_url(['user_id' => $uid, 'page' => 1, 'filters_open' => 0]) : '';
            ?>
            <tr>
              <td style="white-space:nowrap;"><?=h($dt)?></td>

              <td style="white-space:nowrap;">
                <a class="linklike" href="<?=h($urlEvent)?>" title="Nach diesem Event filtern">
                  <code><?=h($ev)?></code>
                </a>
              </td>

              <td style="white-space:nowrap;">
                <?php if ($urlUser !== ''): ?>
                  <a class="linklike" href="<?=h($urlUser)?>" title="Nach diesem Benutzer filtern">
                    <?=h($userLabel)?>
                  </a>
                <?php else: ?>
                  <?=h($userLabel)?>
                <?php endif; ?>
              </td>

              <?php if ($showIp): ?>
                <td style="white-space:nowrap; font-variant-numeric: tabular-nums;"><?=h($ip)?></td>
              <?php endif; ?>

              <td style="min-width:640px; max-width:980px;">
                <?php if (!is_array($details)): ?>
                  <span class="muted">—</span>
                <?php else: ?>
                  <details>
                    <summary class="muted" style="cursor:pointer; user-select:none;">
                      <?=h($sum !== '' ? $sum : 'anzeigen')?>
                    </summary>
                    <div style="margin-top:10px; padding:12px; border:1px solid var(--border); border-radius:12px; background:#f8f9fb;">
                      <?=render_details_kv($details, $maps)?>
                    </div>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="row-actions" style="justify-content:flex-end; gap:6px; margin-top:12px;">
      <a class="btn secondary <?=($page<=1?'disabled':'')?>"
         href="<?=h($page>1 ? page_url(['page' => $page - 1]) : '#')?>"
         <?=($page<=1 ? 'aria-disabled="true" onclick="return false;"' : '')?>>Vorherige</a>
      <a class="btn secondary <?=($page>=$totalPages?'disabled':'')?>"
         href="<?=h($page<$totalPages ? page_url(['page' => $page + 1]) : '#')?>"
         <?=($page>=$totalPages ? 'aria-disabled="true" onclick="return false;"' : '')?>>Nächste</a>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const btn = document.getElementById('btnToggleFilters');
  const card = document.getElementById('filtersCard');
  const field = document.getElementById('filtersOpenField');

  function isOpen(){
    if (!card) return false;
    // display:none can come from inline style or script; computed style is more robust
    return window.getComputedStyle(card).display !== 'none';
  }

  function setOpen(open){
    if (!card) return;
    card.style.display = open ? '' : 'none';
    if (btn) btn.textContent = open ? 'Filter einklappen' : 'Filter anzeigen';
    if (field) field.value = open ? '1' : '';
    try { localStorage.setItem('admin_audit_filters_open', open ? '1' : '0'); } catch(e){}
  }

  // init from localStorage if URL doesn't specify filters_open
  const urlParams = new URLSearchParams(window.location.search);
  const hasExplicit = urlParams.has('filters_open');
  if (!hasExplicit) {
    try {
      const v = localStorage.getItem('admin_audit_filters_open');
      if (v === '1') setOpen(true);
      if (v === '0') setOpen(false);
    } catch(e){}
  }

  if (btn) {
    btn.addEventListener('click', function(){
      setOpen(!isOpen());
    });
  }
})();
</script>

<?php render_admin_footer(); ?>

<?php
declare(strict_types=1);
// parent/portal.php
require __DIR__ . '/../bootstrap.php';

$pdo = db();
$token = (string)($_GET['token'] ?? '');
$autoTranslate = ((int)($_POST['auto_translate'] ?? $_GET['auto_translate'] ?? 0) === 1);
$alerts = [];
$errors = [];

function parent_label_for_lang(?string $labelDe, ?string $labelEn, string $lang, string $fallback = ''): string {
  $labelDe = trim((string)($labelDe ?? ''));
  $labelEn = trim((string)($labelEn ?? ''));
  if ($lang === 'en' && $labelEn !== '') return $labelEn;
  if ($labelDe !== '') return $labelDe;
  return $fallback;
}

function parent_meta_read(?string $json): array {
  if (!$json) return [];
  $a = json_decode($json, true);
  return is_array($a) ? $a : [];
}

function parent_option_list_id(array $meta): int {
  $tid = $meta['option_list_template_id'] ?? null;
  if ($tid === null || $tid === '') return 0;
  return (int)$tid;
}

function parent_resolve_option_value(PDO $pdo, array $meta, ?string $valueJson, ?string $valueText): string {
  $listId = parent_option_list_id($meta);
  if ($listId <= 0) return (string)($valueText ?? '');

  $optId = 0;
  if ($valueJson) {
    $j = json_decode($valueJson, true);
    if (is_array($j) && isset($j['option_item_id'])) $optId = (int)$j['option_item_id'];
  }
  if ($optId > 0) {
    $st = $pdo->prepare("SELECT value FROM option_list_items WHERE id=? AND list_id=? LIMIT 1");
    $st->execute([$optId, $listId]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) return (string)$v;
  }

  return (string)($valueText ?? '');
}

function parent_portal_class_display(array $c): string {
  $label = (string)($c['label'] ?? '');
  $grade = $c['grade_level'] !== null ? (int)$c['grade_level'] : null;
  $name  = (string)($c['name'] ?? '');
  return ($grade !== null && $label !== '') ? ($grade . $label) : ($name !== '' ? $name : ('#' . (int)($c['id'] ?? 0)));
}

function parent_collect_preview_fields(PDO $pdo, int $reportId, string $lang, bool $autoTranslate): array {
  $st = $pdo->prepare(
    "SELECT tf.id, tf.field_name, tf.label, tf.label_en, tf.meta_json, tf.field_type, tf.sort_order,\n" .
    "       fv.value_text, fv.value_json, fv.source, fv.updated_at\n" .
    "FROM template_fields tf\n" .
    "LEFT JOIN field_values fv ON fv.template_field_id=tf.id AND fv.report_instance_id=?\n" .
    "WHERE tf.template_id=(SELECT template_id FROM report_instances WHERE id=? LIMIT 1)\n" .
    "ORDER BY tf.sort_order ASC, tf.id ASC"
  );
  $st->execute([$reportId, $reportId]);

  $priority = ['child' => 1, 'system' => 2, 'teacher' => 3];
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)($row['field_name'] ?? '');
    if ($key === '') continue;
    $src = (string)($row['source'] ?? 'teacher');
    $meta = parent_meta_read($row['meta_json'] ?? null);
    $label = parent_label_for_lang($row['label'] ?? null, $row['label_en'] ?? null, $lang, $key);
    $resolved = parent_resolve_option_value($pdo, $meta, $row['value_json'] ?? null, $row['value_text'] ?? '');

    $existing = $map[$key] ?? null;
    $curScore = $existing ? ($priority[$existing['source']] ?? 0) : -1;
    $newScore = $priority[$src] ?? 0;
    if ($newScore > $curScore || !$existing) {
      $map[$key] = [
        'label' => $label,
        'value' => $resolved,
        'source' => $src,
      ];
    }
  }

  $autoNote = $autoTranslate && $lang !== 'de' ? ' (' . t('parent.portal.auto_note', 'Automatische Übersetzung · mögliche Fehlübersetzungen') . ')' : '';
  return array_map(function($row) use ($autoNote) {
    $val = (string)($row['value'] ?? '');
    if ($val === '') $val = t('parent.portal.empty', '–');
    if ($autoNote !== '' && $val !== t('parent.portal.empty', '–')) {
      $val .= $autoNote;
    }
    return [
      'label' => (string)($row['label'] ?? ''),
      'value' => $val,
      'source' => (string)($row['source'] ?? ''),
    ];
  }, array_values($map));
}

if ($token === '') {
  http_response_code(400);
  echo 'Token fehlt.';
  exit;
}

$st = $pdo->prepare(
  "SELECT ppl.*, s.first_name, s.last_name, c.school_year, c.grade_level, c.label, c.name,\n" .
  "       ri.template_id, ri.period_label, ri.school_year AS report_school_year\n" .
  "FROM parent_portal_links ppl\n" .
  "JOIN students s ON s.id=ppl.student_id\n" .
  "JOIN report_instances ri ON ri.id=ppl.report_instance_id\n" .
  "JOIN classes c ON c.id=s.class_id\n" .
  "WHERE ppl.token=?\n" .
  "LIMIT 1"
);
$st->execute([$token]);
$link = $st->fetch(PDO::FETCH_ASSOC);

if (!$link) {
  http_response_code(404);
  echo 'Freigabe nicht gefunden.';
  exit;
}

if (!empty($link['preferred_lang'])) {
  ui_lang_set((string)$link['preferred_lang']);
}
$lang = ui_lang();

$expiresAt = $link['expires_at'] ?? null;
$isExpired = false;
if ($expiresAt) {
  $isExpired = (strtotime((string)$expiresAt) < time());
  if ($isExpired && ($link['status'] ?? '') !== 'expired') {
    $pdo->prepare("UPDATE parent_portal_links SET status='expired', updated_at=NOW() WHERE id=? LIMIT 1")->execute([(int)$link['id']]);
    $link['status'] = 'expired';
  }
}

$status = (string)($link['status'] ?? '');
$allowResponses = ($status === 'approved' && !$isExpired);
$canPreview = $allowResponses;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    csrf_verify();
    $action = (string)$_POST['action'];
    if (!$allowResponses) throw new RuntimeException('Rückmeldungen sind aktuell nicht möglich.');

    if ($action === 'send_ack' || $action === 'send_question') {
      $message = trim((string)($_POST['message'] ?? ''));
      if ($action === 'send_question' && $message === '') throw new RuntimeException('Bitte eine Rückfrage eingeben.');
      $type = $action === 'send_ack' ? 'ack' : 'question';
      $ins = $pdo->prepare(
        "INSERT INTO parent_feedback (link_id, feedback_type, message, language, auto_translated, created_at)\n" .
        "VALUES (?, ?, ?, ?, ?, NOW())"
      );
      $ins->execute([(int)$link['id'], $type, $message !== '' ? $message : null, $lang, $autoTranslate ? 1 : 0]);
      $alerts[] = $type === 'ack' ? t('parent.portal.ack_ok', 'Danke für die Bestätigung. Wir prüfen die Meldung zeitnah.') : t('parent.portal.question_ok', 'Rückfrage gesendet. Sie wird moderiert.');
    }
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

$fields = $canPreview ? parent_collect_preview_fields($pdo, (int)$link['report_instance_id'], $lang, $autoTranslate) : [];
$b = brand();
$org = $b['org_name'] ?? 'LEG Tool';
$logo = $b['logo_path'] ?? '';
$primary = $b['primary'] ?? '#0b57d0';
$secondary = $b['secondary'] ?? '#111111';
?>
<!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t('parent.portal.title', 'Elternmodus – Vorschau'))?></title>
  <?php render_favicons(); ?>
  <link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
  <style>:root{--primary:<?=h($primary)?>;--secondary:<?=h($secondary)?>;}</style>
</head>
<body class="page">
  <div class="topbar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?=h(url($logo))?>" alt="<?=h($org)?>"><?php endif; ?>
      <div>
        <div class="brand-title"><?=h($org)?></div>
        <div class="brand-subtitle"><?=h(t('parent.portal.subtitle', 'Elternmodus – nur Lesen'))?></div>
      </div>
      <div class="lang-switch" aria-label="Sprache wechseln">
        <a class="lang <?= $lang==='de' ? 'active' : '' ?>" href="<?=h(url_with_lang('de'))?>">🇩🇪</a>
        <a class="lang <?= $lang==='en' ? 'active' : '' ?>" href="<?=h(url_with_lang('en'))?>">🇬🇧</a>
      </div>
    </div>
  </div>

  <div class="container" style="max-width:960px;">
    <div class="card">
      <h1 style="margin-top:0;"><?=h(t('parent.portal.heading', 'Vorschau des Lernentwicklungsberichts'))?></h1>
      <p class="muted" style="max-width:820px;">
        <?=h(t('parent.portal.readonly_hint', 'Sie sehen eine schreibgeschützte Vorschau. Download ist deaktiviert. Rückmeldungen werden moderiert und sind zeitlich begrenzt.'))?>
      </p>
      <div class="pill"><?=h((string)$link['first_name'] . ' ' . (string)$link['last_name'])?></div>
      <div class="muted" style="margin-top:4px;">
        <?=h(t('parent.portal.class', 'Klasse'))?>: <?=h((string)$link['school_year'])?> · <?=h(parent_portal_class_display($link))?>
      </div>
      <div class="muted" style="margin-top:4px;">
        <?=h(t('parent.portal.valid_until', 'Gültig bis'))?>: <?=h($expiresAt ? $expiresAt : t('parent.portal.no_expiry', 'ohne Enddatum'))?>
      </div>
      <?php if ($status === 'requested'): ?>
        <div class="alert warn" style="margin-top:10px;"><?=h(t('parent.portal.waiting', 'Freigabe wird noch durch die Schule bestätigt.'))?></div>
      <?php elseif ($status === 'revoked'): ?>
        <div class="alert danger" style="margin-top:10px;"><?=h(t('parent.portal.revoked', 'Dieser Zugang wurde deaktiviert.'))?></div>
      <?php elseif ($isExpired): ?>
        <div class="alert danger" style="margin-top:10px;"><?=h(t('parent.portal.expired', 'Dieser Zugang ist abgelaufen.'))?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="row" style="justify-content:space-between; align-items:center; gap:10px;">
        <h2 style="margin:0;"><?=h(t('parent.portal.preview_title', 'PDF-Vorschau (Nur lesen)'))?></h2>
        <form method="get" style="margin:0; display:flex; gap:8px; align-items:center;">
          <input type="hidden" name="token" value="<?=h($token)?>">
          <label class="chk" style="margin:0;">
            <input type="checkbox" name="auto_translate" value="1" <?=$autoTranslate?'checked':''?>>
            <?=h(t('parent.portal.auto_translate', 'Automatische Übersetzung anzeigen'))?>
          </label>
          <button class="btn secondary" type="submit">OK</button>
        </form>
      </div>
      <p class="muted" style="margin-top:6px;">
        <?=h(t('parent.portal.auto_hint', 'Maschinelle Übersetzungen können ungenau sein. Originaltext bleibt maßgeblich.'))?>
      </p>
      <?php if (!$canPreview): ?>
        <p class="muted"><?=h(t('parent.portal.preview_blocked', 'Die Freigabe ist noch nicht aktiv oder bereits beendet.'))?></p>
      <?php elseif (!$fields): ?>
        <p class="muted"><?=h(t('parent.portal.no_fields', 'Noch keine Inhalte erfasst.'))?></p>
      <?php else: ?>
        <div class="card" style="background:#f8f9fb; border:1px solid var(--border);">
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
            <?php foreach ($fields as $f): ?>
              <div style="padding:10px; background:#fff; border:1px solid var(--border); border-radius:10px;">
                <div style="font-weight:600; margin-bottom:4px;"><?=h($f['label'])?></div>
                <div style="white-space:pre-wrap;"><?=h($f['value'])?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0;"><?=h(t('parent.portal.feedback_title', 'Rückmeldung'))?></h2>
      <p class="muted" style="margin-top:0;"><?=h(t('parent.portal.feedback_hint', 'Sie können eine Rückfrage stellen oder bestätigen, dass Sie die Inhalte gelesen haben. Alle Rückmeldungen werden moderiert.'))?></p>

      <?php if ($errors): ?>
        <div class="alert danger"><?php foreach ($errors as $e): ?><div><?=h($e)?></div><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($alerts): ?>
        <div class="alert success"><?php foreach ($alerts as $a): ?><div><?=h($a)?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <?php if (!$allowResponses): ?>
        <p class="muted"><?=h(t('parent.portal.responses_closed', 'Rückmeldungen sind derzeit nicht möglich.'))?></p>
      <?php else: ?>
        <div class="grid" style="grid-template-columns:1fr; gap:14px;">
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="send_question">
            <input type="hidden" name="auto_translate" value="<?= $autoTranslate ? '1' : '0' ?>">
            <label><?=h(t('parent.portal.question_label', 'Rückfrage stellen'))?></label>
            <textarea name="message" rows="4" placeholder="<?=h(t('parent.portal.question_placeholder', 'Ihre Frage ...'))?>"></textarea>
            <div class="muted" style="font-size:12px; margin-top:4px;">
              <?=h(t('parent.portal.question_hint', 'Die Schule prüft Rückfragen, bevor sie angezeigt werden.'))?>
            </div>
            <div class="actions" style="margin-top:8px;">
              <button class="btn primary" type="submit"><?=h(t('parent.portal.question_send', 'Rückfrage senden'))?></button>
            </div>
          </form>

          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="send_ack">
            <input type="hidden" name="auto_translate" value="<?= $autoTranslate ? '1' : '0' ?>">
            <label><?=h(t('parent.portal.ack_label', 'Bestätigung (gelesen)'))?></label>
            <textarea name="message" rows="2" placeholder="<?=h(t('parent.portal.ack_placeholder', 'Optionaler Kommentar'))?>"></textarea>
            <div class="actions" style="margin-top:8px;">
              <button class="btn secondary" type="submit"><?=h(t('parent.portal.ack_send', 'Als gelesen bestätigen'))?></button>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

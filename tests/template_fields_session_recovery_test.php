<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
  'editor' => 'admin/template_fields.php',
  'translations' => 'shared/translations.php',
  'handbook' => 'docs/leb_tool_benutzerhandbuch.tex',
];
$source = [];
foreach ($paths as $key => $path) {
  $source[$key] = file_get_contents($root . '/' . $path);
  if ($source[$key] === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
  }
}

function template_fields_recovery_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$editor = $source['editor'];

template_fields_recovery_assert(
  strpos($editor, 'template_fields_draft_v1:${templateId}:${currentUserId}') !== false,
  'drafts must be stored separately for each template and administrator'
);
template_fields_recovery_assert(
  strpos($editor, 'function persistDraftNow()') !== false
    && strpos($editor, 'localStorage.setItem(DRAFT_STORAGE_KEY') !== false
    && strpos($editor, 'scheduleDraftPersist();') !== false,
  'dirty field updates must be written to a local draft'
);
template_fields_recovery_assert(
  strpos($editor, 'function restoreLocalDraft()') !== false
    && strpos($editor, 'const restoredDraftCount = restoreLocalDraft();') !== false,
  'a locally stored draft must be restored after the server fields load'
);
template_fields_recovery_assert(
  strpos($editor, "window.addEventListener('beforeunload', (event)=>{\n  if (!dirty.size) return;\n  persistDraftNow();") !== false,
  'the latest changes must be persisted before the page unloads'
);

$saveStart = strpos($editor, 'async function save(){');
$saveEnd = $saveStart === false ? false : strpos($editor, '/* ---------- Options dialog ---------- */', $saveStart);
$saveSource = ($saveStart !== false && $saveEnd !== false) ? substr($editor, $saveStart, $saveEnd - $saveStart) : '';
template_fields_recovery_assert(
  strpos($saveSource, "await apiPost({ action:'save', updates });") !== false
    && strpos($saveSource, 'clearLocalDraft();') > strpos($saveSource, "await apiPost({ action:'save', updates });"),
  'the local draft must only be cleared after the server confirms the save'
);
template_fields_recovery_assert(
  strpos($editor, "url('ajax/session_status.php')") !== false
    && strpos($editor, 'function checkSessionNow(showFeedback = false)') !== false
    && strpos($editor, 'if (payload.csrf_token) csrf = String(payload.csrf_token);') !== false,
  'the editor must detect a restored session and adopt its fresh CSRF token'
);
template_fields_recovery_assert(
  strpos($editor, 'function ensureSessionOverlay()') !== false
    && strpos($editor, 'target="_blank"') !== false
    && strpos($editor, "overlay.querySelector('[data-session-save]')") !== false,
  'expired sessions must keep the editor open while offering re-login and save controls'
);
template_fields_recovery_assert(
  strpos($editor, 'let groupTitleEnChanged = false;') !== false
    && strpos($editor, 'if (!groupTitleEnChanged) return;') !== false
    && strpos($editor, 'let subgroupTitleEnChanged = false;') !== false
    && strpos($editor, 'if (!subgroupTitleEnChanged) return;') !== false,
  'leaving an unchanged empty English group field must not clear translations on sibling fields'
);
template_fields_recovery_assert(
  strpos($editor, 'function findExistingGroupTitleEn(group, excludeFieldId)') !== false
    && strpos($editor, 'function findExistingSubgroupTitleEn(group, subgroup, excludeFieldId)') !== false
    && strpos($editor, 'fields[idx].meta.group_title_en = existingGroupTitleEn;') !== false
    && strpos($editor, 'fields[idx].meta.subgroup_title_en = existingSubgroupTitleEn;') !== false,
  'assigning an existing group or subgroup must adopt its available English title'
);
template_fields_recovery_assert(
  substr_count($source['translations'], "'admin.template_fields.draft_restored'") === 2,
  'draft restoration feedback must be translated in German and English'
);
template_fields_recovery_assert(
  strpos($source['handbook'], 'lokaler Entwurf gesichert') !== false
    && strpos($source['handbook'], 'Erst ein erfolgreicher Speichervorgang entfernt diese Sicherung') !== false,
  'the handbook must document session-expiry recovery and draft lifetime'
);

fwrite(STDOUT, "template_fields_session_recovery_test: OK\n");

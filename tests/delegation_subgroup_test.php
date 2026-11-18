<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/group_keys.php';

function delegation_subgroup_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "delegation_subgroup_test: {$message}\n");
    exit(1);
  }
}

$meta = ['group' => 'Sozial-emotional', 'subgroup' => 'Zusammenarbeit'];
delegation_subgroup_assert(
  app_group_scope_key_from_meta($meta) === 'Sozial-emotional::Zusammenarbeit',
  'a subgroup must receive its own stable delegation key'
);
delegation_subgroup_assert(
  app_delegation_key_aliases_from_meta($meta) === [
    'Sozial-emotional::Zusammenarbeit',
    'Sozial::Zusammenarbeit',
    'Sozial-emotional',
    'Sozial',
  ],
  'field lookup must prefer subgroup rows and then fall back to historic main-group rows'
);
delegation_subgroup_assert(
  app_group_key_aliases_from_label('Sozial-emotional::Zusammenarbeit') === [
    'Sozial-emotional::Zusammenarbeit',
    'Sozial::Zusammenarbeit',
  ],
  'clearing a subgroup must never expand to and clear the main group'
);
delegation_subgroup_assert(
  app_delegation_key_aliases_from_meta(['group' => 'Mathematik']) === ['Mathematik'],
  'main-group behavior must stay backward compatible'
);

$root = dirname(__DIR__);
$entryApi = file_get_contents($root . '/teacher/ajax/entry_api.php');
$entryPage = file_get_contents($root . '/teacher/entry.php');
$inboxApi = file_get_contents($root . '/teacher/ajax/delegations_api.php');
$revoke = file_get_contents($root . '/shared/delegation_revoke.php');

delegation_subgroup_assert(
  is_string($entryApi)
    && str_contains($entryApi, 'delegated_users_for_field_meta')
    && str_contains($entryApi, "'delegation_scope_key'")
    && str_contains($entryApi, "'delegation' => delegation_from_map_for_meta"),
  'the entry API must resolve permissions and metadata per field/subgroup'
);
delegation_subgroup_assert(
  is_string($entryPage)
    && str_contains($entryPage, 'delegationScopeOptions')
    && str_contains($entryPage, 'delegationForScopeKey')
    && str_contains($entryPage, 'effectiveDelegation = f?.delegation || g?.delegation'),
  'the delegation dialogs and field map must operate on main groups and subgroups'
);
delegation_subgroup_assert(
  is_string($inboxApi) && str_contains($inboxApi, 'app_group_scope_parts_from_key'),
  'the delegation inbox must show readable subgroup labels'
);
delegation_subgroup_assert(
  is_string($revoke) && str_contains($revoke, 'app_delegation_key_aliases_from_meta'),
  'revoking a subgroup must annotate only fields within its effective scope'
);

fwrite(STDOUT, "delegation_subgroup_test: OK\n");

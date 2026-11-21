<?php
declare(strict_types=1);

function binding_condition_assert(bool $condition, string $message): void {
  if ($condition) return;
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

$root = dirname(__DIR__);
putenv('APP_CONFIG_FILE=' . $root . '/config.sample.php');
require_once $root . '/bootstrap.php';

function render_condition(string $template, array $values): string {
  return apply_binding_condition_blocks(
    $template,
    static fn(string $key): string => (string)($values[$key] ?? '')
  );
}

$separateNegative = '{{field:class_representative?}}Mia war Klassensprecherin.{{?}}'
  . '{{!field:class_representative?}}-{{?}}';
$inlineElse = '{{field:class_representative?}}Mia war Klassensprecherin.{{:}}-{{?}}';
$requestedText = '{{field:class_representative?}}{{student.first_name}} war in diesem Schulhalbjahr Klassensprecher(in). | '
  . '{{student.first_name}} was a class representative during this semester.{{:}}-{{?}}';

binding_condition_assert(
  render_condition($separateNegative, ['field:class_representative' => '1']) === 'Mia war Klassensprecherin.',
  'a separate negated condition must stay hidden when the value is present'
);
binding_condition_assert(
  render_condition($separateNegative, ['field:class_representative' => '']) === '-',
  'a separate negated condition must render when the value is empty'
);
binding_condition_assert(
  render_condition($inlineElse, ['field:class_representative' => 'yes']) === 'Mia war Klassensprecherin.',
  'the true branch of inline else syntax must render'
);
binding_condition_assert(
  render_condition($inlineElse, ['field:class_representative' => '']) === '-',
  'the false branch of inline else syntax must render'
);
binding_condition_assert(
  resolve_system_binding_template(
    $requestedText,
    ['first_name' => 'Mia'],
    [],
    [],
    null,
    ['class_representative' => 'yes']
  ) === 'Mia war in diesem Schulhalbjahr Klassensprecher(in). | Mia was a class representative during this semester.',
  'the requested bilingual template must resolve its condition and placeholders together'
);
binding_condition_assert(
  resolve_system_binding_template(
    $requestedText,
    ['first_name' => 'Mia'],
    [],
    [],
    null,
    ['class_representative' => '']
  ) === '-',
  'the requested bilingual template must render its fallback when the field is empty'
);

$comparisonCases = [
  ['field:value=2', ['field:value' => '2'], true],
  ['field:value==2', ['field:value' => '2.0'], true],
  ['field:value==="chosen"', ['field:value' => 'chosen'], true],
  ['field:value!=2', ['field:value' => '3'], true],
  ['field:value!==2', ['field:value' => '2'], true],
  ['field:value<>2', ['field:value' => '3'], true],
  ['field:value>2', ['field:value' => '3'], true],
  ['field:value>=2', ['field:value' => '2'], true],
  ['field:value<2', ['field:value' => '1'], true],
  ['field:value<=2', ['field:value' => '2'], true],
  ['field:value=="chosen"', ['field:value' => 'chosen'], true],
  ['field:value!="chosen"', ['field:value' => 'other'], true],
  ['field:value==true', ['field:value' => 'yes'], true],
  ['field:value==false', ['field:value' => 'off'], true],
  ['field:value==null', ['field:value' => ''], true],
  ['field:value!=empty', ['field:value' => 'content'], true],
  ['field:value=="wrong"', ['field:value' => 'chosen'], false],
  ['field:value==field:other', ['field:value' => 'same', 'field:other' => 'same'], true],
];
foreach ($comparisonCases as [$expression, $values, $expected]) {
  $actual = render_condition('{{' . $expression . '?}}yes{{:}}no{{?}}', $values);
  binding_condition_assert(
    $actual === ($expected ? 'yes' : 'no'),
    "comparison {$expression} must choose the expected branch"
  );
}

binding_condition_assert(
  render_condition(
    '{{(field:a == "yes" && field:score >= 2) || !field:fallback?}}yes{{:}}no{{?}}',
    ['field:a' => 'yes', 'field:score' => '3', 'field:fallback' => 'set']
  ) === 'yes',
  'parentheses, AND, OR and negation must work together'
);
binding_condition_assert(
  render_condition(
    '{{field:a and not field:b?}}yes{{:}}no{{?}}',
    ['field:a' => 'set', 'field:b' => '']
  ) === 'yes',
  'word operators and/not must be supported'
);
binding_condition_assert(
  render_condition(
    '{{field:a or field:b?}}yes{{:}}no{{?}}',
    ['field:a' => '', 'field:b' => 'set']
  ) === 'yes',
  'the word operator or must be supported'
);
binding_condition_assert(
  render_condition(
    '{{field:a xor field:b?}}yes{{:}}no{{?}}',
    ['field:a' => 'set', 'field:b' => '']
  ) === 'yes',
  'xor must be supported'
);
binding_condition_assert(
  render_condition(
    '{{field:outer?}}A{{field:inner?}}B{{:}}C{{?}}D{{:}}E{{?}}',
    ['field:outer' => 'set', 'field:inner' => '']
  ) === 'ACD',
  'nested conditions must preserve the correct else boundary'
);
binding_condition_assert(
  render_condition('{{field:value?}}yes{{:}}no', ['field:value' => 'set']) === '{{field:value?}}yes{{:}}no',
  'an incomplete block must remain visible instead of silently losing text'
);
binding_condition_assert(
  render_condition('{{field:value?}}yes{{:}}no{{?}}', ['field:value' => '0']) === 'yes',
  'legacy content checks must continue treating the non-empty string zero as present'
);

fwrite(STDOUT, "binding_condition_blocks_test: OK\n");

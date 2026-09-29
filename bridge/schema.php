<?php
declare(strict_types=1);

function mcpSchemaFieldType(string $name, mixed $value, ?string $referenceClass): array {
  if ($referenceClass !== null || $name === 'id' || preg_match('/Id$/D', $name)) {
    return array('type' => 'integer', 'format' => null);
  }
  if (preg_match('/DateTime$/D', $name)) {
    return array('type' => 'string', 'format' => 'date-time');
  }
  if (preg_match('/Date$/D', $name)) {
    return array('type' => 'string', 'format' => 'date');
  }
  if (preg_match('/^(is|has|idle$|done$|handled$|cancelled$|paused$|optional$|proportional$|fix)/i', $name)) {
    return array('type' => 'boolean', 'format' => 'zero-or-one');
  }
  if (preg_match('/(Work|Cost|Amount|Rate|Pct|Progress|Duration|Delay|Capacity)$/i', $name)) {
    return array('type' => 'number', 'format' => null);
  }
  if (is_int($value)) return array('type' => 'integer', 'format' => null);
  if (is_float($value)) return array('type' => 'number', 'format' => null);
  if (is_bool($value)) return array('type' => 'boolean', 'format' => null);
  return array('type' => 'string', 'format' => null);
}

function mcpSchemaReferenceClass(string $field): ?string {
  $overrides = array(
    'idResourceSelect' => 'Resource',
    'idAffectable' => 'Affectable',
    'idUser' => 'User'
  );
  if (array_key_exists($field, $overrides)) return $overrides[$field];
  if (!preg_match('/^id([A-Z][A-Za-z0-9_]*)$/D', $field, $matches)) return null;
  $candidate = $matches[1];
  return SqlElement::class_exists($candidate) ? $candidate : null;
}

function mcpCollectSchemaFields(object $object, array &$fields, array &$seen, int $depth = 0): void {
  if (method_exists($object, 'setAttributes')) $object->setAttributes();
  foreach (get_object_vars($object) as $name => $value) {
    if (is_object($value) && $depth < 2 && !str_starts_with($name, '_')) {
      mcpCollectSchemaFields($value, $fields, $seen, $depth + 1);
      continue;
    }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) ||
        $name === 'apiKey' || $name === 'password' || is_array($value) || is_object($value) ||
        array_key_exists($name, $seen)) {
      continue;
    }

    $attributeText = method_exists($object, 'getFieldAttributes') ? $object->getFieldAttributes($name) : '';
    $attributes = array_values(array_filter(array_map('trim', explode(',', (string)$attributeText))));
    $hidden = in_array('hidden', $attributes, true) || in_array('hiddenforce', $attributes, true);
    $readonly = in_array('readonly', $attributes, true) || in_array('calculated', $attributes, true) ||
      in_array('noImport', $attributes, true);
    $includedReadOnly = $depth > 0 && in_array($name, array(
      'id', 'refType', 'refId', 'refName', 'handled', 'done', 'idle', 'cancelled'
    ), true);
    $referenceClass = mcpSchemaReferenceClass($name);
    $type = mcpSchemaFieldType($name, $value, $referenceClass);
    $default = (is_scalar($value) || $value === null) ? $value : null;

    $fields[] = array(
      'name' => $name,
      'type' => $type['type'],
      'format' => $type['format'],
      'required' => in_array('required', $attributes, true),
      'readable' => !$hidden && !in_array('noExport', $attributes, true),
      'writable' => $name !== 'id' && !$hidden && !$readonly && !$includedReadOnly,
      'referenceClass' => $referenceClass,
      'default' => $default,
      'attributes' => $attributes,
      'sourceObject' => get_class($object)
    );
    $seen[$name] = true;
  }
}

function emitMcpSchema(string $class): never {
  $allowedClasses = array(
    'Action', 'Activity', 'ActivityType', 'Affectation', 'Assignment',
    'CalendarDefinition', 'Dependency', 'Document', 'Issue', 'Meeting',
    'Milestone', 'MilestoneType', 'Opportunity', 'PlanningMode', 'Profile',
    'Project', 'ProjectType', 'Requirement', 'Resource', 'Risk', 'Role',
    'Status', 'Team', 'TestCase', 'TestSession', 'Ticket', 'TicketType',
    'Version', 'Work'
  );
  if (!in_array($class, $allowedClasses, true) || !SqlElement::class_exists($class)) {
    denyMcpRequest(404, 'Unsupported object class');
  }

  Security::checkValidClass($class);
  $object = new $class();
  if (method_exists($object, 'setAttributes')) $object->setAttributes();

  $fields = array();
  $seen = array();
  mcpCollectSchemaFields($object, $fields, $seen);

  echo json_encode(array(
    'schemaVersion' => 1,
    'objectClass' => $class,
    'fields' => $fields
  ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

<?php
declare(strict_types=1);
namespace App\Mcp;

/**
 * Tiny JSON Schema subset used to declare MCP tool inputs (Tools::catalog) and to validate the
 * arguments of tools/call before any handler runs (Server::call).
 * Supported keywords: type (string or list), enum, minLength/maxLength, minimum/maximum,
 * properties/required/minProperties, items/minItems/maxItems. Unknown object properties are always
 * rejected, whatever additionalProperties says. Other keywords (uniqueItems, description, ...) are
 * published to clients but NOT enforced here; handlers and models must not rely on them.
 */
final class Schema
{
    /** Schema for a closed object: only the listed properties, $required ones mandatory. */
    public static function object(array $properties = [], array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
    /**
     * Validate the exact supported JSON Schema keywords and normalize objects once.
     * Input comes from json_decode without assoc mode (objects are stdClass); validated objects are
     * returned as associative arrays, so handlers receive plain PHP arrays. Throws
     * InvalidArgumentException with the failing path (e.g. "arguments.fields.title").
     */
    public static function validate(mixed $value, array $schema, string $path = 'arguments'): mixed
    {
        $types = (array) ($schema['type'] ?? []);
        // JSON has a single number type: accept 5.0 as the integer 5 when the schema expects an integer.
        if (
            in_array('integer', $types, true) &&
            is_float($value) &&
            is_finite($value) &&
            floor($value) === $value &&
            $value >= (float) PHP_INT_MIN &&
            $value < (float) PHP_INT_MAX
        ) {
            $value = (int) $value;
        }
        $valid = false;
        foreach ($types as $type) {
            $valid =
                $valid ||
                match ($type) {
                    'object' => $value instanceof \stdClass,
                    'array' => is_array($value),
                    'string' => is_string($value),
                    'integer' => is_int($value),
                    'number' => is_int($value) || is_float($value),
                    'boolean' => is_bool($value),
                    'null' => $value === null,
                    default => false,
                };
        }
        if (!$valid) {
            throw new \InvalidArgumentException($path . ' : type invalide.');
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new \InvalidArgumentException($path . ' : valeur non autorisée.');
        }
        if (
            is_string($value) &&
            ((isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) ||
                (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']))
        ) {
            throw new \InvalidArgumentException($path . ' : longueur invalide.');
        }
        if (
            (is_int($value) || is_float($value)) &&
            ((isset($schema['minimum']) && $value < $schema['minimum']) ||
                (isset($schema['maximum']) && $value > $schema['maximum']))
        ) {
            throw new \InvalidArgumentException($path . ' : nombre hors limites.');
        }
        if ($value instanceof \stdClass) {
            $values = (array) $value;
            $properties = (array) ($schema['properties'] ?? []);
            if (isset($schema['minProperties']) && count($values) < $schema['minProperties']) {
                throw new \InvalidArgumentException($path . ' : objet vide.');
            }
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $values)) {
                    throw new \InvalidArgumentException($path . '.' . $key . ' est requis.');
                }
            }
            foreach ($values as $key => &$v) {
                if (!isset($properties[$key])) {
                    throw new \InvalidArgumentException($path . '.' . $key . ' : champ inconnu.');
                }
                $v = self::validate($v, $properties[$key], $path . '.' . $key);
            }
            unset($v);
            return $values;
        }
        if (is_array($value)) {
            if (
                count($value) > ($schema['maxItems'] ?? PHP_INT_MAX) ||
                count($value) < ($schema['minItems'] ?? 0)
            ) {
                throw new \InvalidArgumentException($path . ' : nombre d’éléments invalide.');
            }
            foreach ($value as $index => &$item) {
                $item = self::validate($item, $schema['items'], $path . '[' . $index . ']');
            }
            unset($item);
        }
        return $value;
    }
}

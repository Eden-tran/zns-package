<?php

namespace CPP\ZNSPackage\Validators;

use CPP\ZNSPackage\Exceptions\ZNSException;

class ZnsTemplateValidator
{
    /**
     * Validate template data against template parameters
     *
     * @param array $templateParams Template parameters from database
     * @param array $data Data to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $templateParams, array $data): array
    {
        $errors = [];

        foreach ($templateParams as $param) {
            $name = $param['name'] ?? null;
            $type = $param['type'] ?? 'string';
            $required = $param['required'] ?? false;
            $minLength = $param['min_length'] ?? 0;
            $maxLength = $param['max_length'] ?? null;
            $acceptNull = $param['accept_null'] ?? false;

            if (!$name) {
                continue;
            }

            $value = $data[$name] ?? null;

            // Check if required
            if ($required && ($value === null || $value === '')) {
                if (!$acceptNull) {
                    $errors[$name][] = "Parameter '{$name}' is required";
                    continue;
                }
            }

            // Skip validation if value is null and accept_null is true
            if ($value === null && $acceptNull) {
                continue;
            }

            // Skip validation if value is empty and not required
            if (!$required && ($value === null || $value === '')) {
                continue;
            }

            // Validate by type
            switch ($type) {
                case 'string':
                    $typeErrors = self::validateString($name, $value, $minLength, $maxLength);
                    break;

                case 'number':
                    $typeErrors = self::validateNumber($name, $value, $minLength, $maxLength);
                    break;

                case 'date':
                    $typeErrors = self::validateDate($name, $value);
                    break;

                case 'datetime':
                    $typeErrors = self::validateDateTime($name, $value);
                    break;

                default:
                    $typeErrors = [];
            }

            if (!empty($typeErrors)) {
                $errors[$name] = array_merge($errors[$name] ?? [], $typeErrors);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate and throw exception if invalid
     *
     * @param array $templateParams
     * @param array $data
     * @throws ZNSException
     */
    public static function validateOrFail(array $templateParams, array $data): void
    {
        $result = self::validate($templateParams, $data);

        if (!$result['valid']) {
            $errorMessages = [];
            foreach ($result['errors'] as $field => $fieldErrors) {
                $errorMessages[] = "{$field}: " . implode(', ', $fieldErrors);
            }

            throw new ZNSException('Template data validation failed: ' . implode('; ', $errorMessages));
        }
    }

    /**
     * Validate string type
     */
    protected static function validateString(string $name, $value, int $minLength, ?int $maxLength): array
    {
        $errors = [];

        if (!is_string($value) && !is_numeric($value)) {
            $errors[] = "'{$name}' must be a string";
            return $errors;
        }

        $value = (string) $value;
        $length = mb_strlen($value, 'UTF-8');

        if ($minLength > 0 && $length < $minLength) {
            $errors[] = "'{$name}' must be at least {$minLength} characters (got {$length})";
        }

        if ($maxLength !== null && $length > $maxLength) {
            $errors[] = "'{$name}' must not exceed {$maxLength} characters (got {$length})";
        }

        return $errors;
    }

    /**
     * Validate number type
     */
    protected static function validateNumber(string $name, $value, int $minLength, ?int $maxLength): array
    {
        $errors = [];

        if (!is_numeric($value)) {
            $errors[] = "'{$name}' must be a number";
            return $errors;
        }

        // Convert to string to check length
        $valueStr = (string) $value;
        $length = strlen($valueStr);

        if ($minLength > 0 && $length < $minLength) {
            $errors[] = "'{$name}' must be at least {$minLength} digits (got {$length})";
        }

        if ($maxLength !== null && $length > $maxLength) {
            $errors[] = "'{$name}' must not exceed {$maxLength} digits (got {$length})";
        }

        return $errors;
    }

    /**
     * Validate date type (format: DD/MM/YYYY)
     */
    protected static function validateDate(string $name, $value): array
    {
        $errors = [];

        if (!is_string($value)) {
            $errors[] = "'{$name}' must be a string in DD/MM/YYYY format";
            return $errors;
        }

        // Try multiple date formats
        $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y'];
        $isValid = false;

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            $errors[] = "'{$name}' must be a valid date (DD/MM/YYYY or YYYY-MM-DD format)";
        }

        return $errors;
    }

    /**
     * Validate datetime type (format: DD/MM/YYYY HH:mm:ss)
     */
    protected static function validateDateTime(string $name, $value): array
    {
        $errors = [];

        if (!is_string($value)) {
            $errors[] = "'{$name}' must be a string in datetime format";
            return $errors;
        }

        // Try multiple datetime formats
        $formats = ['d/m/Y H:i:s', 'Y-m-d H:i:s', 'd-m-Y H:i:s'];
        $isValid = false;

        foreach ($formats as $format) {
            $datetime = \DateTime::createFromFormat($format, $value);
            if ($datetime && $datetime->format($format) === $value) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            $errors[] = "'{$name}' must be a valid datetime (DD/MM/YYYY HH:mm:ss or YYYY-MM-DD HH:mm:ss format)";
        }

        return $errors;
    }

    /**
     * Format data according to template requirements (convert types, trim strings, etc.)
     *
     * @param array $templateParams
     * @param array $data
     * @return array Formatted data
     */
    public static function formatData(array $templateParams, array $data): array
    {
        $formatted = [];

        foreach ($templateParams as $param) {
            $name = $param['name'] ?? null;
            $type = $param['type'] ?? 'string';
            $maxLength = $param['max_length'] ?? null;

            if (!$name || !isset($data[$name])) {
                continue;
            }

            $value = $data[$name];

            switch ($type) {
                case 'string':
                    $value = (string) $value;
                    // Trim to max length if specified
                    if ($maxLength && mb_strlen($value, 'UTF-8') > $maxLength) {
                        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
                    }
                    $formatted[$name] = trim($value);
                    break;

                case 'number':
                    $value = (string) $value;
                    // Trim to max length if specified
                    if ($maxLength && strlen($value) > $maxLength) {
                        $value = substr($value, 0, $maxLength);
                    }
                    $formatted[$name] = $value;
                    break;

                case 'date':
                case 'datetime':
                    $formatted[$name] = (string) $value;
                    break;

                default:
                    $formatted[$name] = $value;
            }
        }

        return $formatted;
    }
}

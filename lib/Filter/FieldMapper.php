<?php

namespace Gnc\Othet\Filter;

final class FieldMapper
{
    public static function toUiFilterField(array $meta, array $context): array
    {
        $warnings = [];
        $fieldId = (string)($context['fieldId'] ?? ($meta['code'] ?? ''));
        $filterId = (string)($context['filterId'] ?? 'GNC_FILTER_TEST');
        $code = (string)($meta['code'] ?? $fieldId);
        $title = (string)($meta['title'] ?? $code);
        $type = self::detectType($meta);

        $entry = [
            'id' => $fieldId,
            'name' => $title,
            'type' => $type,
            'default' => true,
        ];

        if ($type === 'list')
        {
            $items = (array)($meta['items'] ?? []);
            if (strtolower((string)($meta['userTypeId'] ?? '')) === 'boolean' && empty($items))
            {
                $items = ['1' => 'Да', '0' => 'Нет'];
            }
            if (empty($items))
            {
                $warnings[] = 'List has no items, fallback values applied';
                $items = ['A' => 'Вариант A', 'B' => 'Вариант B'];
            }
            $entry['items'] = $items;
            $entry['params'] = ['multiple' => !empty($meta['isMultiple']) ? 'Y' : 'N'];
        }
        elseif ($type === 'dest_selector')
        {
            $isCrmContact = self::isContactFilterCode($code)
                || strtolower((string)($meta['crmType'] ?? '')) === 'crm_contact';

            if ($isCrmContact)
            {
                $entry['params'] = [
                    'multiple' => !empty($meta['isMultiple']) ? 'Y' : 'N',
                    'context' => 'CRM_ENTITIES',
                    'contextCode' => 'CRM',
                    'apiVersion' => 3,
                    'enableAll' => 'N',
                    'enableSonetgroups' => 'N',
                    'allowEmailInvitation' => 'N',
                    'allowSearchEmailUsers' => 'N',
                    'departmentSelectDisable' => 'Y',
                    'isNumeric' => 'Y',
                    'enableUsers' => 'N',
                    'enableDepartments' => 'N',
                    'enableCrm' => 'Y',
                    'enableCrmContacts' => 'Y',
                    'prefix' => 'CRMCONTACT',
                ];
            }
            else
            {
                $entry['params'] = [
                    'apiVersion' => 3,
                    'context' => self::buildContext($filterId, $code),
                    'multiple' => !empty($meta['isMultiple']) ? 'Y' : 'N',
                    'enableUsers' => 'Y',
                    'enableDepartments' => 'Y',
                    'enableSonetgroups' => 'N',
                    'allowAddUser' => 'N',
                ];
            }
        }
        elseif ($type === 'entity_selector')
        {
            $entityTypeIds = self::resolveEntityTypeIds($meta);
            if (!empty($meta['forceEntityTypeId']))
            {
                $entityTypeIds = [(int)$meta['forceEntityTypeId']];
            }

            $entityTypeIds = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds), static function (int $id): bool {
                return $id > 0;
            })));

            if (empty($entityTypeIds))
            {
                $warnings[] = 'Entity type is unresolved, CONTACT(3) fallback used';
                $entityTypeIds = [3];
            }

            $entities = [];
            foreach ($entityTypeIds as $entityTypeId)
            {
                $entities[] = [
                    'id' => 'crm',
                    'options' => ['entityTypeId' => $entityTypeId],
                    'searchable' => true,
                    'dynamicLoad' => true,
                    'dynamicSearch' => true,
                ];
            }

            $entry['params'] = [
                'multiple' => !empty($meta['isMultiple']) ? 'Y' : 'N',
                'dialogOptions' => [
                    'id' => self::sanitize($fieldId).'_'.self::sanitize($filterId),
                    'context' => self::buildContext($filterId, $code),
                    'multiple' => !empty($meta['isMultiple']),
                    'dropdownMode' => true,
                    'recentItemsLimit' => 20,
                    'clearUnavailableItems' => true,
                    'entities' => $entities,
                ],
            ];
        }

        if (!in_array($entry['type'], ['string', 'textarea', 'number', 'date', 'list', 'dest_selector', 'entity_selector'], true))
        {
            $warnings[] = 'Unknown type, string fallback applied';
            $entry['type'] = 'string';
        }

        if (!empty($warnings))
        {
            $entry['_warnings'] = $warnings;
        }

        return $entry;
    }

    public static function detectType(array $meta): string
    {
        $userType = strtolower((string)($meta['userTypeId'] ?? ''));
        $crmType = strtolower((string)($meta['crmType'] ?? ''));
        $isUf = !empty($meta['isUf']);

        if ($isUf && $userType !== '')
        {
            switch ($userType)
            {
                case 'date':
                case 'datetime':
                    return 'date';
                case 'integer':
                case 'double':
                    return 'number';
                case 'enumeration':
                case 'crm_status':
                case 'boolean':
                    return 'list';
                case 'crm':
                    if (self::isContactFilterCode((string)($meta['code'] ?? '')))
                    {
                        return 'dest_selector';
                    }
                    return 'entity_selector';
                case 'employee':
                case 'user':
                    return 'dest_selector';
                case 'string':
                case 'url':
                case 'address':
                    return 'string';
                case 'text':
                    return 'textarea';
                default:
                    return 'string';
            }
        }

        if (in_array($crmType, ['date', 'datetime'], true))
        {
            return 'date';
        }
        if (in_array($crmType, ['integer', 'int', 'double', 'float', 'number', 'money'], true))
        {
            return 'number';
        }
        if (in_array($crmType, ['string', 'text'], true))
        {
            return 'string';
        }
        if (in_array($crmType, ['user', 'employee'], true))
        {
            return 'dest_selector';
        }
        if (in_array($crmType, ['enumeration', 'list'], true) || !empty($meta['items']))
        {
            return 'list';
        }
        if ($crmType === 'crm_entity' || strpos($crmType, 'crm_') === 0)
        {
            if ($crmType === 'crm_contact')
            {
                return 'dest_selector';
            }
            return 'entity_selector';
        }

        return 'string';
    }

    public static function resolveEntityTypeIds(array $meta): array
    {
        $code = strtoupper((string)($meta['code'] ?? ''));

        if ($code === 'CONTACT_ID' || preg_match('/_CONTACT_ID$/', $code))
        {
            return [3];
        }
        if ($code === 'COMPANY_ID' || preg_match('/_COMPANY_ID$/', $code))
        {
            return [4];
        }
        if ($code === 'DEAL_ID' || preg_match('/_DEAL_ID$/', $code))
        {
            return [2];
        }
        if (preg_match('/^PARENT_ID_(\d+)$/', $code, $m))
        {
            return [(int)$m[1]];
        }

        $crmType = strtoupper((string)($meta['crmType'] ?? ''));
        if ($crmType === 'CRM_CONTACT')
        {
            return [3];
        }
        if ($crmType === 'CRM_COMPANY')
        {
            return [4];
        }
        if ($crmType === 'CRM_DEAL')
        {
            return [2];
        }

        $settings = (array)($meta['settings'] ?? []);
        $ids = [];

        foreach ($settings as $k => $v)
        {
            $key = strtoupper((string)$k);
            $isEnabled = !is_scalar($v) || strtoupper((string)$v) === 'Y' || (string)$v === '1' || $v === true;
            if (preg_match('/^DYNAMIC_(\d+)$/', $key, $m) && $isEnabled)
            {
                $ids[] = (int)$m[1];
                continue;
            }

            if (!$isEnabled)
            {
                continue;
            }

            if (in_array($key, ['CONTACT', 'CRM_CONTACT'], true)) { $ids[] = 3; }
            if (in_array($key, ['COMPANY', 'CRM_COMPANY'], true)) { $ids[] = 4; }
            if (in_array($key, ['DEAL', 'CRM_DEAL'], true)) { $ids[] = 2; }
            if (in_array($key, ['LEAD', 'CRM_LEAD'], true)) { $ids[] = 1; }
        }

        foreach (self::flattenTokens($settings) as $token)
        {
            $token = strtoupper($token);
            if (preg_match('/^DYNAMIC_(\d+)$/', $token, $m)) { $ids[] = (int)$m[1]; }
            if (in_array($token, ['CONTACT', 'CRM_CONTACT'], true)) { $ids[] = 3; }
            if (in_array($token, ['COMPANY', 'CRM_COMPANY'], true)) { $ids[] = 4; }
            if (in_array($token, ['DEAL', 'CRM_DEAL'], true)) { $ids[] = 2; }
            if (in_array($token, ['LEAD', 'CRM_LEAD'], true)) { $ids[] = 1; }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    private static function buildContext(string $filterId, string $code): string
    {
        return self::sanitize($filterId).'_'.self::sanitize($code);
    }

    private static function sanitize(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        return trim((string)$value, '_');
    }

    private static function flattenTokens($value): array
    {
        $result = [];
        if (is_array($value))
        {
            foreach ($value as $k => $v)
            {
                $result = array_merge($result, self::flattenTokens($k), self::flattenTokens($v));
            }
            return array_values(array_unique($result));
        }

        if (is_scalar($value))
        {
            $str = trim((string)$value);
            if ($str !== '')
            {
                foreach (preg_split('/[\s,;|]+/', $str) ?: [] as $part)
                {
                    $part = trim($part);
                    if ($part !== '')
                    {
                        $result[] = $part;
                    }
                }
            }
        }

        return array_values(array_unique($result));
    }

    private static function isContactFilterCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '')
        {
            return false;
        }

        return $code === 'CONTACT_ID' || (bool)preg_match('/_CONTACT_ID$/', $code);
    }
}

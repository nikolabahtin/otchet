<?php
/**
 * /local/tools/gnc_dump_all_fields_json.php
 *
 * Dump all fields (standard + UF) for:
 * - ALL Smart Processes (dynamic types)
 * - CRM Contact
 *
 * Output: single JSON object (pretty printed)
 *
 * Usage:
 *   /local/tools/gnc_dump_all_fields_json.php
 *
 * Optional params:
 *   ?includeRaw=0   (default 0)  - include huge RAW blocks or not
 *   ?limitTypes=50  (default 0)  - limit number of smart process types (0 = all)
 */

use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\UserFieldTable;
use Bitrix\Main\UserFieldLangTable;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

global $USER;
if (!$USER || !$USER->IsAdmin())
{
    http_response_code(403);
    die("Access denied (admin only)");
}

if (!Loader::includeModule('crm') || !Loader::includeModule('main'))
{
    http_response_code(500);
    die("Modules crm/main not installed");
}

@set_time_limit(300);

$includeRaw = isset($_GET['includeRaw']) ? (int)$_GET['includeRaw'] : 0;
$limitTypes = isset($_GET['limitTypes']) ? (int)$_GET['limitTypes'] : 0;

header('Content-Type: application/json; charset=UTF-8');

/**
 * Heuristic: map field meta -> recommended filter type (for your comparison with main.ui.filter)
 * Return one of: string|number|date|list|dest_selector|entity_selector
 */
function guessFilterType(array $field): string
{
    $type = strtolower((string)($field['TYPE'] ?? $field['type'] ?? ''));
    $settings = $field['SETTINGS'] ?? $field['settings'] ?? [];

    // user/employee
    if (in_array($type, ['user','employee'], true)) return 'dest_selector';

    // date/datetime
    if (in_array($type, ['date','datetime'], true)) return 'date';

    // numeric
    if (in_array($type, ['integer','int','double','float','number'], true)) return 'number';

    // enumeration/list (FieldsInfo sometimes contains ITEMS)
    if ($type === 'enumeration' || $type === 'list' || isset($field['ITEMS']) || isset($field['items'])) return 'list';

    // CRM link types that your mapper likely fails on:
    // examples from your snapshots: "crm_contact", "crm_company", "crm_deal", "crm_entity"
    if (str_starts_with($type, 'crm_') || $type === 'crm_entity')
    {
        return 'entity_selector'; // or number in MVP, but we recommend entity_selector
    }

    // string fallback
    return 'string';
}

/**
 * Load UF fields by ENTITY_ID from main userfield tables
 */
function loadUserFieldsFull(string $entityId): array
{
    $ufRows = UserFieldTable::getList([
        'filter' => ['=ENTITY_ID' => $entityId],
        'select' => [
            'ID','ENTITY_ID','FIELD_NAME','USER_TYPE_ID','MULTIPLE','MANDATORY','SETTINGS','SORT'
        ],
        'order' => ['SORT' => 'ASC', 'ID' => 'ASC']
    ])->fetchAll();

    if (!$ufRows) return [];

    $ids = array_map(static fn($r) => (int)$r['ID'], $ufRows);

    // RU labels
    $langs = [];
    $langRows = UserFieldLangTable::getList([
        'filter' => ['=USER_FIELD_ID' => $ids, '=LANGUAGE_ID' => 'ru'],
        'select' => ['USER_FIELD_ID','EDIT_FORM_LABEL','LIST_COLUMN_LABEL','LIST_FILTER_LABEL']
    ])->fetchAll();
    foreach ($langRows as $lr) {
        $langs[(int)$lr['USER_FIELD_ID']] = $lr;
    }

    // Enum values for list-type
    $enumsByField = [];

    // New core (if available)
    if (class_exists('Bitrix\\Main\\UserFieldEnumTable'))
    {
        $enumRows = \Bitrix\Main\UserFieldEnumTable::getList([
            'filter' => ['=USER_FIELD_ID' => $ids],
            'select' => ['USER_FIELD_ID','ID','VALUE','XML_ID','SORT','DEF'],
            'order' => ['USER_FIELD_ID'=>'ASC','SORT'=>'ASC','ID'=>'ASC']
        ])->fetchAll();

        foreach ($enumRows as $er)
        {
            $fid = (int)$er['USER_FIELD_ID'];
            if (!isset($enumsByField[$fid])) { $enumsByField[$fid] = []; }
            $enumsByField[$fid][] = $er;
        }
    }
    else
    {
        // Legacy API fallback
        if (class_exists('CUserFieldEnum'))
        {
            $rsEnum = \CUserFieldEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['USER_FIELD_ID' => $ids]);
            while ($er = $rsEnum->GetNext())
            {
                $fid = (int)$er['USER_FIELD_ID'];
                if (!isset($enumsByField[$fid])) { $enumsByField[$fid] = []; }
                $enumsByField[$fid][] = [
                    'USER_FIELD_ID' => $fid,
                    'ID' => (int)$er['ID'],
                    'VALUE' => (string)$er['VALUE'],
                    'XML_ID' => (string)$er['XML_ID'],
                    'SORT' => (int)$er['SORT'],
                    'DEF' => (string)($er['DEF'] ?? 'N'),
                ];
            }
        }
    }

    
    $out = [];
    foreach ($ufRows as $r)
    {
        $id = (int)$r['ID'];
        $out[] = [
            'id' => $id,
            'entityId' => $r['ENTITY_ID'],
            'fieldName' => $r['FIELD_NAME'],
            'userTypeId' => $r['USER_TYPE_ID'],
            'multiple' => $r['MULTIPLE'],
            'mandatory' => $r['MANDATORY'],
            'sort' => (int)$r['SORT'],
            'labels' => [
                'ru' => [
                    'edit' => $langs[$id]['EDIT_FORM_LABEL'] ?? null,
                    'list' => $langs[$id]['LIST_COLUMN_LABEL'] ?? null,
                    'filter' => $langs[$id]['LIST_FILTER_LABEL'] ?? null,
                ],
            ],
            'settings' => $r['SETTINGS'],
            'enum' => isset($enumsByField[$id]) ? $enumsByField[$id] : [],
        ];
    }
    return $out;
}

/**
 * CRM UF ENTITY_ID resolver:
 * - Contact: CRM_CONTACT
 * - Smart process: most often CRM_SMART_DOCUMENT_{entityTypeId}
 */
function ufEntityIdForContact(): string
{
    return 'CRM_CONTACT';
}
function ufEntityIdForSmartProcess(int $entityTypeId): string
{
    return "CRM_SMART_DOCUMENT_{$entityTypeId}";
}

/**
 * Dump fieldsInfo to normalized array
 */
function normalizeFieldsInfo(array $fieldsInfo, bool $includeRaw): array
{
    $out = [];
    foreach ($fieldsInfo as $code => $info)
    {
        $type = $info['TYPE'] ?? ($info['type'] ?? null);
        $title = $info['TITLE'] ?? ($info['title'] ?? $code);
        $isMultiple = (bool)($info['isMultiple'] ?? ($info['IS_MULTIPLE'] ?? false));
        $items = $info['ITEMS'] ?? ($info['items'] ?? null);
        $settings = $info['SETTINGS'] ?? ($info['settings'] ?? null);

        $row = [
            'code' => (string)$code,
            'title' => (string)$title,
            'type' => $type,
            'isMultiple' => $isMultiple,
            'isRequired' => (bool)($info['isRequired'] ?? ($info['IS_REQUIRED'] ?? false)),
            'isReadOnly' => (bool)($info['isReadOnly'] ?? ($info['IS_READ_ONLY'] ?? false)),
            'filterTypeGuess' => guessFilterType($info),
            'items' => is_array($items) ? $items : null,
            'settings' => is_array($settings) ? $settings : $settings, // settings may be array or scalar
        ];

        if ($includeRaw) $row['raw'] = $info;

        $out[] = $row;
    }
    return $out;
}

/**
 * Dump smart processes types list
 */
$container = Container::getInstance();
$typesClass = $container->getDynamicTypeDataClass();

$types = $typesClass::getList([
    'select' => ['ID','TITLE','ENTITY_TYPE_ID'],
    'order' => ['ENTITY_TYPE_ID' => 'ASC'],
])->fetchAll();

if ($limitTypes > 0)
{
    $types = array_slice($types, 0, $limitTypes);
}

$result = [
    'generatedAt' => date('c'),
    'bitrix' => [
        'product' => 'bitrix24',
    ],
    'smartProcesses' => [],
    'contact' => null,
];

foreach ($types as $t)
{
    $entityTypeId = (int)$t['ENTITY_TYPE_ID'];
    $title = (string)$t['TITLE'];

    $factory = $container->getFactory($entityTypeId);
    if (!$factory)
    {
        $result['smartProcesses'][] = [
            'entityTypeId' => $entityTypeId,
            'title' => $title,
            'error' => 'factory_not_found',
        ];
        continue;
    }

    $fieldsInfo = $factory->getFieldsInfo();

    $ufEntityId = ufEntityIdForSmartProcess($entityTypeId);
    $uf = loadUserFieldsFull($ufEntityId);

    $result['smartProcesses'][] = [
        'entityTypeId' => $entityTypeId,
        'title' => $title,
        'ufEntityId' => $ufEntityId,
        'fieldsInfo' => normalizeFieldsInfo($fieldsInfo, (bool)$includeRaw),
        'userFields' => $uf,
    ];
}

/**
 * Contact
 */
$contactFactory = $container->getFactory(\CCrmOwnerType::Contact);
$contactFieldsInfo = $contactFactory ? $contactFactory->getFieldsInfo() : [];
$contactUfEntityId = ufEntityIdForContact();
$contactUf = loadUserFieldsFull($contactUfEntityId);

$result['contact'] = [
    'entityTypeId' => \CCrmOwnerType::Contact,
    'title' => 'CRM Contact',
    'ufEntityId' => $contactUfEntityId,
    'fieldsInfo' => normalizeFieldsInfo($contactFieldsInfo, (bool)$includeRaw),
    'userFields' => $contactUf,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
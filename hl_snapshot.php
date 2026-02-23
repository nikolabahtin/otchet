<?php
/**
 * HL snapshot + updater utility.
 *
 * Usage examples:
 * /local/otchet/hl_snapshot.php
 * /local/otchet/hl_snapshot.php?table=gnc_report_presets
 * /local/otchet/hl_snapshot.php?action=update
 * /local/otchet/hl_snapshot.php?action=update&table=gnc_report_presets
 */

declare(strict_types=1);

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Highloadblock as HL;

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=utf-8');

global $USER;
if (!$USER || !$USER->IsAdmin())
{
    echo Json::encode([
        'status' => 'error',
        'errors' => [['message' => 'Access denied. Admin only.']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

if (!Loader::includeModule('highloadblock') || !Loader::includeModule('main'))
{
    echo Json::encode([
        'status' => 'error',
        'errors' => [['message' => 'Modules highloadblock/main are required']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

$request = Context::getCurrent()->getRequest();
$action = trim((string)$request->getQuery('action'));
$id = (int)$request->getQuery('id');
$table = trim((string)$request->getQuery('table'));
$name = trim((string)$request->getQuery('name'));

$filter = [];
if ($id > 0)
{
    $filter['=ID'] = $id;
}
if ($table !== '')
{
    $filter['=TABLE_NAME'] = $table;
}
if ($name !== '')
{
    $filter['=NAME'] = $name;
}

$changes = [];
if ($action === 'update')
{
    $changes = applyGncReportPresetUpdate($filter);
}

$items = getSnapshot($filter);

echo Json::encode([
    'status' => 'success',
    'mode' => $action === 'update' ? 'update+snapshot' : 'snapshot',
    'generatedAt' => date('c'),
    'filter' => [
        'id' => $id > 0 ? $id : null,
        'table' => $table !== '' ? $table : null,
        'name' => $name !== '' ? $name : null,
    ],
    'changes' => $changes,
    'count' => count($items),
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

die();

function getSnapshot(array $filter): array
{
    $blocks = HL\HighloadBlockTable::getList([
        'select' => ['ID', 'NAME', 'TABLE_NAME'],
        'filter' => $filter,
        'order' => ['ID' => 'ASC'],
    ])->fetchAll();

    $result = [];
    foreach ($blocks as $block)
    {
        $blockId = (int)$block['ID'];
        $entityId = 'HLBLOCK_'.$blockId;

        $fields = [];
        $res = CUserTypeEntity::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['ENTITY_ID' => $entityId]
        );

        while ($row = $res->Fetch())
        {
            $settings = normalizeSettings($row['SETTINGS'] ?? null);
            $fields[] = [
                'id' => (int)($row['ID'] ?? 0),
                'fieldName' => (string)($row['FIELD_NAME'] ?? ''),
                'userTypeId' => (string)($row['USER_TYPE_ID'] ?? ''),
                'multiple' => (string)($row['MULTIPLE'] ?? ''),
                'mandatory' => (string)($row['MANDATORY'] ?? ''),
                'sort' => (int)($row['SORT'] ?? 0),
                'xmlId' => (string)($row['XML_ID'] ?? ''),
                'editFormLabel' => $row['EDIT_FORM_LABEL'] ?? null,
                'listColumnLabel' => $row['LIST_COLUMN_LABEL'] ?? null,
                'listFilterLabel' => $row['LIST_FILTER_LABEL'] ?? null,
                'settings' => $settings,
            ];
        }

        $result[] = [
            'id' => $blockId,
            'name' => (string)$block['NAME'],
            'table' => (string)$block['TABLE_NAME'],
            'entityId' => $entityId,
            'fieldsCount' => count($fields),
            'fields' => $fields,
        ];
    }

    return $result;
}

function applyGncReportPresetUpdate(array $incomingFilter): array
{
    $filter = $incomingFilter;
    if (empty($filter))
    {
        $filter['=TABLE_NAME'] = 'gnc_report_presets';
    }

    $blocks = HL\HighloadBlockTable::getList([
        'select' => ['ID', 'NAME', 'TABLE_NAME'],
        'filter' => $filter,
        'order' => ['ID' => 'ASC'],
    ])->fetchAll();

    $report = [];
    if (!$blocks)
    {
        return [['status' => 'skip', 'message' => 'No HL blocks found for update filter']];
    }

    $ufEntity = new CUserTypeEntity();
    foreach ($blocks as $block)
    {
        $entityId = 'HLBLOCK_'.(int)$block['ID'];
        $changes = [];

        $fieldDefs = [
            [
                'FIELD_NAME' => 'UF_CONTACT_FIELD',
                'USER_TYPE_ID' => 'string',
                'MANDATORY' => 'N',
                'SORT' => 140,
                'SETTINGS' => ['SIZE' => 64, 'ROWS' => 1, 'MIN_LENGTH' => 0, 'MAX_LENGTH' => 0, 'DEFAULT_VALUE' => ''],
                'EDIT_FORM_LABEL' => ['ru' => 'Поле связи (legacy)', 'en' => 'Legacy contact field'],
                'LIST_COLUMN_LABEL' => ['ru' => 'Legacy field', 'en' => 'Legacy field'],
                'LIST_FILTER_LABEL' => ['ru' => 'Legacy field', 'en' => 'Legacy field'],
            ],
            [
                'FIELD_NAME' => 'UF_ROOT_ENTITY_CODE',
                'USER_TYPE_ID' => 'string',
                'MANDATORY' => 'N',
                'SORT' => 145,
                'SETTINGS' => ['SIZE' => 64, 'ROWS' => 1, 'MIN_LENGTH' => 0, 'MAX_LENGTH' => 0, 'DEFAULT_VALUE' => ''],
                'EDIT_FORM_LABEL' => ['ru' => 'Код основной сущности', 'en' => 'Root entity code'],
                'LIST_COLUMN_LABEL' => ['ru' => 'Root entity', 'en' => 'Root entity'],
                'LIST_FILTER_LABEL' => ['ru' => 'Root entity', 'en' => 'Root entity'],
            ],
            [
                'FIELD_NAME' => 'UF_PERIOD_FIELD_CODE',
                'USER_TYPE_ID' => 'string',
                'MANDATORY' => 'N',
                'SORT' => 146,
                'SETTINGS' => ['SIZE' => 64, 'ROWS' => 1, 'MIN_LENGTH' => 0, 'MAX_LENGTH' => 0, 'DEFAULT_VALUE' => ''],
                'EDIT_FORM_LABEL' => ['ru' => 'Код поля периода', 'en' => 'Period field code'],
                'LIST_COLUMN_LABEL' => ['ru' => 'Period field', 'en' => 'Period field'],
                'LIST_FILTER_LABEL' => ['ru' => 'Period field', 'en' => 'Period field'],
            ],
        ];

        foreach ($fieldDefs as $def)
        {
            $result = upsertUserField($entityId, $def, $ufEntity);
            $changes[] = $result;
        }

        $report[] = [
            'blockId' => (int)$block['ID'],
            'blockName' => (string)$block['NAME'],
            'table' => (string)$block['TABLE_NAME'],
            'changes' => $changes,
        ];
    }

    return $report;
}

function upsertUserField(string $entityId, array $fieldDef, CUserTypeEntity $ufEntity): array
{
    $fieldName = (string)$fieldDef['FIELD_NAME'];

    $existing = CUserTypeEntity::GetList([], [
        'ENTITY_ID' => $entityId,
        'FIELD_NAME' => $fieldName,
    ])->Fetch();

    $payload = array_merge(['ENTITY_ID' => $entityId], $fieldDef);

    if ($existing)
    {
        $fieldId = (int)$existing['ID'];
        $ok = $ufEntity->Update($fieldId, $payload);
        if (!$ok)
        {
            global $APPLICATION;
            $err = $APPLICATION && $APPLICATION->GetException() ? $APPLICATION->GetException()->GetString() : 'UF update error';
            return [
                'field' => $fieldName,
                'action' => 'update',
                'status' => 'error',
                'message' => $err,
            ];
        }

        return [
            'field' => $fieldName,
            'action' => 'update',
            'status' => 'ok',
            'fieldId' => $fieldId,
        ];
    }

    $newId = $ufEntity->Add($payload);
    if (!$newId)
    {
        global $APPLICATION;
        $err = $APPLICATION && $APPLICATION->GetException() ? $APPLICATION->GetException()->GetString() : 'UF add error';
        return [
            'field' => $fieldName,
            'action' => 'add',
            'status' => 'error',
            'message' => $err,
        ];
    }

    return [
        'field' => $fieldName,
        'action' => 'add',
        'status' => 'ok',
        'fieldId' => (int)$newId,
    ];
}

function normalizeSettings($settings)
{
    if (is_array($settings))
    {
        return $settings;
    }

    if (is_string($settings))
    {
        $unserialized = @unserialize($settings, ['allowed_classes' => false]);
        if (is_array($unserialized))
        {
            return $unserialized;
        }
    }

    return $settings;
}

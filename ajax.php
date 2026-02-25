<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Highloadblock as HL;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Model\Dynamic\TypeTable;

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

global $USER;

header('Content-Type: application/json; charset=utf-8');

if (!$USER || !$USER->IsAuthorized())
{
    echo Json::encode(['status' => 'error', 'errors' => [['message' => 'Unauthorized']]]);
    die();
}

if (!check_bitrix_sessid())
{
    echo Json::encode(['status' => 'error', 'errors' => [['message' => 'Invalid sessid']]]);
    die();
}

if (!Loader::includeModule('main') || !Loader::includeModule('crm'))
{
    echo Json::encode(['status' => 'error', 'errors' => [['message' => 'main/crm modules required']]]);
    die();
}

$request = Context::getCurrent()->getRequest();
$action = (string)$request->getPost('action');
$userId = (int)$USER->GetID();
$storageMode = resolveStorageMode($request);

try
{
    switch ($action)
    {
        case 'listTemplates':
            $payload = ['items' => listTemplates($userId, $storageMode)];
            break;

        case 'getTemplate':
            $id = (string)$request->getPost('id');
            $payload = ['item' => getTemplate($userId, $id, $storageMode)];
            break;

        case 'saveTemplate':
            $id = (string)$request->getPost('id');
            $name = trim((string)$request->getPost('name'));
            $configRaw = (string)$request->getPost('config');
            $config = $configRaw !== '' ? Json::decode($configRaw) : [];

            if ($name === '')
            {
                throw new RuntimeException('Template name is required');
            }

            $saved = saveTemplate($userId, $id, $name, is_array($config) ? $config : [], $storageMode);
            $payload = ['item' => $saved];
            break;

        case 'deleteTemplate':
            $id = (string)$request->getPost('id');
            deleteTemplate($userId, $id, $storageMode);
            $payload = ['ok' => true];
            break;

        case 'getRootEntities':
            $payload = ['items' => getRootEntities()];
            break;

        case 'getEntityMeta':
            $entityCode = (string)$request->getPost('entityCode');
            $payload = getEntityMeta($entityCode);
            break;

        case 'searchEntityItems':
            $entityCode = (string)$request->getPost('entityCode');
            $query = trim((string)$request->getPost('query'));
            $limit = (int)$request->getPost('limit');
            $payload = ['items' => searchEntityItems($entityCode, $query, $limit > 0 ? $limit : 20)];
            break;

        case 'debugEntitySelector':
            $rawIds = $request->getPost('entityTypeIds');
            if (!is_array($rawIds) || empty($rawIds))
            {
                $single = (int)$request->getPost('entityTypeId');
                $rawIds = $single > 0 ? [$single] : [1038, 1050];
            }
            $payload = ['items' => debugEntitySelector($rawIds, $userId)];
            break;

        case 'preview':
            $templateId = (string)$request->getPost('templateId');
            $filterValuesRaw = (string)$request->getPost('filterValues');
            $filterValues = $filterValuesRaw !== '' ? Json::decode($filterValuesRaw) : [];
            $page = max(1, (int)$request->getPost('page'));
            $pageSize = (int)$request->getPost('pageSize');
            if ($pageSize <= 0)
            {
                $pageSize = 5000;
            }
            $pageSize = max(1, min(5000, $pageSize));

            $sortRaw = (string)$request->getPost('sort');
            $sort = $sortRaw !== '' ? Json::decode($sortRaw) : [];
            $payload = buildTemplatePreview(
                $userId,
                $templateId,
                is_array($filterValues) ? $filterValues : [],
                $page,
                $pageSize,
                is_array($sort) ? $sort : []
            );
            break;

        default:
            throw new RuntimeException('Unknown action');
    }

    echo Json::encode(['status' => 'success', 'data' => $payload]);
}
catch (Throwable $e)
{
    echo Json::encode([
        'status' => 'error',
        'errors' => [['message' => $e->getMessage()]],
    ]);
}

die();

function resolveStorageMode(\Bitrix\Main\HttpRequest $request): string
{
    $query = strtolower(trim((string)$request->getQuery('storage')));
    $post = strtolower(trim((string)$request->getPost('storage')));
    $mode = $post !== '' ? $post : $query;
    return $mode === 'file' ? 'file' : 'hl';
}

function getStorageDir(): string
{
    $dir = $_SERVER['DOCUMENT_ROOT'].'/local/otchet/storage/templates';
    if (!is_dir($dir))
    {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function listTemplates(int $userId, string $storageMode = 'hl'): array
{
    if ($storageMode === 'file')
    {
        return listTemplatesFile($userId);
    }

    try
    {
        return listTemplatesHl($userId);
    }
    catch (Throwable $e)
    {
        return listTemplatesFile($userId);
    }
}

function getTemplate(int $userId, string $id, string $storageMode = 'hl'): ?array
{
    if ($storageMode === 'file')
    {
        return getTemplateFile($userId, $id);
    }

    try
    {
        return getTemplateHl($userId, $id);
    }
    catch (Throwable $e)
    {
        return getTemplateFile($userId, $id);
    }
}

function saveTemplate(int $userId, string $id, string $name, array $config, string $storageMode = 'hl'): array
{
    if ($storageMode === 'file')
    {
        return saveTemplateFile($userId, $id, $name, $config);
    }

    try
    {
        return saveTemplateHl($userId, $id, $name, $config);
    }
    catch (Throwable $e)
    {
        return saveTemplateFile($userId, $id, $name, $config);
    }
}

function deleteTemplate(int $userId, string $id, string $storageMode = 'hl'): void
{
    if ($storageMode === 'file')
    {
        deleteTemplateFile($userId, $id);
        return;
    }

    try
    {
        deleteTemplateHl($userId, $id);
    }
    catch (Throwable $e)
    {
        deleteTemplateFile($userId, $id);
    }
}

function listTemplatesFile(int $userId): array
{
    $dir = getStorageDir();
    $pattern = $dir.'/u'.$userId.'_*.json';
    $files = glob($pattern) ?: [];
    $items = [];

    foreach ($files as $file)
    {
        $json = file_get_contents($file);
        if ($json === false)
        {
            continue;
        }

        $data = Json::decode($json);
        if (!is_array($data))
        {
            continue;
        }

        $items[] = [
            'id' => (string)($data['id'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'updatedAt' => (string)($data['updatedAt'] ?? ''),
        ];
    }

    usort($items, static function ($a, $b) {
        return strcmp((string)$b['updatedAt'], (string)$a['updatedAt']);
    });

    return $items;
}

function getTemplateFile(int $userId, string $id): ?array
{
    if ($id === '')
    {
        return null;
    }

    $file = getStorageDir().'/u'.$userId.'_'.$id.'.json';
    if (!file_exists($file))
    {
        return null;
    }

    $json = file_get_contents($file);
    if ($json === false)
    {
        return null;
    }

    $data = Json::decode($json);
    return is_array($data) ? $data : null;
}

function saveTemplateFile(int $userId, string $id, string $name, array $config): array
{
    $id = $id !== '' ? $id : uniqid('tpl_', true);
    $now = (new DateTime())->toString();

    $payload = [
        'id' => $id,
        'userId' => $userId,
        'name' => $name,
        'updatedAt' => $now,
        'config' => $config,
    ];

    $file = getStorageDir().'/u'.$userId.'_'.$id.'.json';
    file_put_contents($file, Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $payload;
}

function deleteTemplateFile(int $userId, string $id): void
{
    if ($id === '')
    {
        throw new RuntimeException('Template id is required');
    }

    $file = getStorageDir().'/u'.$userId.'_'.$id.'.json';
    if (file_exists($file))
    {
        unlink($file);
    }
}

function getPresetDataClass(): string
{
    static $dataClass = null;
    if ($dataClass !== null)
    {
        return $dataClass;
    }

    if (!Loader::includeModule('highloadblock'))
    {
        throw new RuntimeException('highloadblock module is required');
    }

    $block = HL\HighloadBlockTable::getList([
        'select' => ['ID', 'NAME', 'TABLE_NAME'],
        'filter' => ['=TABLE_NAME' => 'gnc_report_presets'],
        'limit' => 1,
    ])->fetch();

    if (!$block)
    {
        throw new RuntimeException('HL block gnc_report_presets not found');
    }

    $entity = HL\HighloadBlockTable::compileEntity($block);
    $dataClass = $entity->getDataClass();

    return $dataClass;
}

function listTemplatesHl(int $userId): array
{
    $dataClass = getPresetDataClass();
    $rows = $dataClass::getList([
        'filter' => ['=UF_USER_ID' => $userId],
        'order' => ['UF_UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
        'select' => ['ID', 'UF_NAME', 'UF_UPDATED_AT'],
    ])->fetchAll();

    $result = [];
    foreach ($rows as $row)
    {
        $updatedAt = $row['UF_UPDATED_AT'];
        $result[] = [
            'id' => (string)$row['ID'],
            'name' => (string)($row['UF_NAME'] ?? ''),
            'updatedAt' => $updatedAt instanceof DateTime ? $updatedAt->toString() : (string)$updatedAt,
        ];
    }

    return $result;
}

function getTemplateHl(int $userId, string $id): ?array
{
    $templateId = (int)$id;
    if ($templateId <= 0)
    {
        return null;
    }

    $dataClass = getPresetDataClass();
    $row = $dataClass::getById($templateId)->fetch();
    if (!$row || (int)$row['UF_USER_ID'] !== $userId)
    {
        return null;
    }

    $config = [];
    if (!empty($row['UF_CONFIG_JSON']))
    {
        $decoded = Json::decode((string)$row['UF_CONFIG_JSON']);
        $config = is_array($decoded) ? $decoded : [];
    }
    $storedContactField = (string)($row['UF_CONTACT_FIELD'] ?? '');
    if ($storedContactField !== '' && empty($config['contactFieldCode']))
    {
        $config['contactFieldCode'] = $storedContactField;
    }

    $updatedAt = $row['UF_UPDATED_AT'];
    return [
        'id' => (string)$row['ID'],
        'name' => (string)($row['UF_NAME'] ?? ''),
        'updatedAt' => $updatedAt instanceof DateTime ? $updatedAt->toString() : (string)$updatedAt,
        'contactFieldCode' => $storedContactField,
        'config' => $config,
    ];
}

function saveTemplateHl(int $userId, string $id, string $name, array $config): array
{
    $dataClass = getPresetDataClass();
    $templateId = (int)$id;
    $now = new DateTime();

    $rootEntityCode = (string)($config['rootEntity'] ?? '');
    $entityTypeId = extractEntityTypeIdFromCode($rootEntityCode);
    $contactField = (string)($config['contactFieldCode'] ?? '');

    $payload = [
        'UF_NAME' => $name,
        'UF_USER_ID' => $userId,
        'UF_ACTIVE' => 1,
        'UF_ENTITY_TYPE_ID' => $entityTypeId,
        'UF_CONTACT_FIELD' => $contactField,
        'UF_CONFIG_JSON' => Json::encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'UF_UPDATED_AT' => $now,
    ];

    if ($templateId > 0)
    {
        $existing = $dataClass::getById($templateId)->fetch();
        if (!$existing || (int)$existing['UF_USER_ID'] !== $userId)
        {
            throw new RuntimeException('Template not found');
        }

        $result = $dataClass::update($templateId, $payload);
        if (!$result->isSuccess())
        {
            throw new RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }
    else
    {
        $payload['UF_CREATED_AT'] = $now;
        $payload['UF_IS_DEFAULT'] = 0;
        $result = $dataClass::add($payload);
        if (!$result->isSuccess())
        {
            throw new RuntimeException(implode('; ', $result->getErrorMessages()));
        }
        $templateId = (int)$result->getId();
    }

    return [
        'id' => (string)$templateId,
        'userId' => $userId,
        'name' => $name,
        'updatedAt' => $now->toString(),
        'config' => $config,
    ];
}

function deleteTemplateHl(int $userId, string $id): void
{
    $templateId = (int)$id;
    if ($templateId <= 0)
    {
        throw new RuntimeException('Template id is required');
    }

    $dataClass = getPresetDataClass();
    $existing = $dataClass::getById($templateId)->fetch();
    if (!$existing || (int)$existing['UF_USER_ID'] !== $userId)
    {
        throw new RuntimeException('Template not found');
    }

    $result = $dataClass::delete($templateId);
    if (!$result->isSuccess())
    {
        throw new RuntimeException(implode('; ', $result->getErrorMessages()));
    }
}

function extractEntityTypeIdFromCode(string $entityCode): int
{
    $entityCode = strtoupper(trim($entityCode));
    if ($entityCode === 'CONTACT')
    {
        return (int)\CCrmOwnerType::Contact;
    }
    if ($entityCode === 'DEAL')
    {
        return (int)\CCrmOwnerType::Deal;
    }
    if ($entityCode === 'COMPANY')
    {
        return (int)\CCrmOwnerType::Company;
    }
    if ($entityCode === 'LEAD')
    {
        return (int)\CCrmOwnerType::Lead;
    }
    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        return (int)substr($entityCode, 8);
    }

    return 0;
}

function getRootEntities(): array
{
    $items = [
        ['code' => 'CONTACT', 'title' => 'Contact'],
    ];

    $types = TypeTable::getList([
        'select' => ['ENTITY_TYPE_ID', 'TITLE'],
        'order' => ['TITLE' => 'ASC'],
    ])->fetchAll();

    foreach ($types as $type)
    {
        $entityTypeId = (int)$type['ENTITY_TYPE_ID'];
        $items[] = [
            'code' => 'DYNAMIC_'.$entityTypeId,
            'title' => (string)$type['TITLE'],
        ];
    }

    return $items;
}

function getEntityMeta(string $entityCode): array
{
    $factory = getFactoryByCode($entityCode);
    if (!$factory)
    {
        throw new RuntimeException('Factory not found for '.$entityCode);
    }

    $fields = [];
    $links = [];
    $knownCodes = [];

    $entityId = resolveUserFieldEntityId($entityCode);
    $userMetaMap = $entityId !== '' ? getUserFieldMetaMap($entityId) : [];

    $collection = $factory->getFieldsCollection();
    foreach ($collection as $field)
    {
        $code = method_exists($field, 'getName') ? (string)$field->getName() : (string)$field->getFieldName();
        if ($code === '')
        {
            continue;
        }
        $knownCodes[] = $code;

        $title = method_exists($field, 'getTitle') ? trim((string)$field->getTitle()) : '';
        $baseType = method_exists($field, 'getTypeId') ? (string)$field->getTypeId() : (method_exists($field, 'getType') ? (string)$field->getType() : 'string');
        $isMultiple = false;
        $isRequired = false;
        if (method_exists($field, 'isMultiple'))
        {
            $isMultiple = (bool)$field->isMultiple();
        }
        elseif (method_exists($field, 'isMulti'))
        {
            $isMultiple = (bool)$field->isMulti();
        }
        if (method_exists($field, 'isRequired'))
        {
            $isRequired = (bool)$field->isRequired();
        }

        $settings = method_exists($field, 'getSettings') ? normalizeFieldSettings($field->getSettings()) : [];
        $userField = method_exists($field, 'getUserField') ? (array)$field->getUserField() : [];
        if (empty($userField) && !empty($userMetaMap[$code]))
        {
            $userField = $userMetaMap[$code];
        }
        $userFieldSettings = normalizeFieldSettings($userField['SETTINGS'] ?? []);
        $settings = mergeFieldSettings($settings, $userFieldSettings);
        if (isset($userField['MANDATORY']))
        {
            $isRequired = (string)$userField['MANDATORY'] === 'Y';
        }
        if (isset($userField['MULTIPLE']))
        {
            $isMultiple = (string)$userField['MULTIPLE'] === 'Y';
        }
        $type = normalizeMetaFieldType($code, (string)($userField['USER_TYPE_ID'] ?? $baseType));

        if ($title === '' && !empty($userField))
        {
            $title = pickUserFieldLabel($userField);
        }
        if ($title === '')
        {
            $title = prettifyCodeAsTitle($code);
        }

        $info = [
            'TYPE' => $type,
            'SETTINGS' => $settings,
            'USER_TYPE_ID' => (string)($userField['USER_TYPE_ID'] ?? $type),
            'TITLE' => $title,
        ];
        $fieldLinks = detectLinksFromField($code, $info);
        $isLinkField = isLinkedField($code, $info, $fieldLinks);
        $isCrmLink = isCrmBindingField($code, $info);
        foreach ($fieldLinks as $linkCode)
        {
            $links[$linkCode] = true;
        }

        $fields[] = [
            'code' => $code,
            'title' => $title,
            'type' => $type,
            'userTypeId' => (string)($userField['USER_TYPE_ID'] ?? $type),
            'typeTitle' => resolveFieldTypeTitle($type),
            'isDate' => isDateLikeField($code, $title, $type, resolveFieldTypeTitle($type)),
            'isMultiple' => $isMultiple,
            'isRequired' => $isRequired,
            'isLink' => $isLinkField,
            'isCrmLink' => $isCrmLink,
            'linkTargets' => $fieldLinks,
            'settings' => $settings,
            'enumItems' => extractEnumItemsFromField($field),
        ];
        if (empty($fields[count($fields) - 1]['enumItems']) && !empty($userField['ID']))
        {
            $fields[count($fields) - 1]['enumItems'] = getUserFieldEnumItems((int)$userField['ID'], (string)($userField['USER_TYPE_ID'] ?? ''));
        }
    }

    // Fallback: если какие-то UF не попали в коллекцию фабрики, добираем их отдельно.
    $userFields = getUserFieldsForEntity($entityCode, $knownCodes, $userMetaMap);
    foreach ($userFields as $userField)
    {
        foreach ((array)($userField['linkTargets'] ?? []) as $linkCode)
        {
            $links[$linkCode] = true;
        }
        $fields[] = $userField;
    }

    if (strtoupper($entityCode) === 'CONTACT')
    {
        $fields = appendContactAddressFieldsToMeta($fields);
    }

    foreach (getConfiguredEntityRelations($entityCode) as $linkedEntityCode)
    {
        $links[$linkedEntityCode] = true;
    }

    $linkItems = [];
    foreach (array_keys($links) as $code)
    {
        $title = mapEntityTitle($code);
        if ($title !== '')
        {
            $linkItems[] = ['code' => $code, 'title' => $title];
        }
    }

    usort($linkItems, static function ($a, $b) {
        return strcmp($a['title'], $b['title']);
    });

    return [
        'entity' => ['code' => $entityCode, 'title' => mapEntityTitle($entityCode)],
        'fields' => $fields,
        'links' => $linkItems,
    ];
}

function appendContactAddressFieldsToMeta(array $fields): array
{
    $existing = [];
    foreach ($fields as $field)
    {
        $code = strtoupper((string)($field['code'] ?? ''));
        if ($code !== '')
        {
            $existing[$code] = true;
        }
    }

    $addressMap = [
        'ADDRESS' => 'Адрес (строка 1)',
        'ADDRESS_2' => 'Адрес (строка 2)',
        'ADDRESS_CITY' => 'Город',
        'ADDRESS_POSTAL_CODE' => 'Почтовый индекс',
        'ADDRESS_REGION' => 'Район',
        'ADDRESS_PROVINCE' => 'Область',
        'ADDRESS_COUNTRY' => 'Страна',
        'ADDRESS_COUNTRY_CODE' => 'Код страны',
        'REG_ADDRESS' => 'Адрес регистрации (строка 1)',
        'REG_ADDRESS_2' => 'Адрес регистрации (строка 2)',
        'REG_ADDRESS_CITY' => 'Город регистрации',
        'REG_ADDRESS_POSTAL_CODE' => 'Индекс регистрации',
        'REG_ADDRESS_REGION' => 'Район регистрации',
        'REG_ADDRESS_PROVINCE' => 'Область регистрации',
        'REG_ADDRESS_COUNTRY' => 'Страна регистрации',
        'REG_ADDRESS_COUNTRY_CODE' => 'Код страны регистрации',
    ];

    foreach ($addressMap as $code => $title)
    {
        if (!empty($existing[$code]))
        {
            continue;
        }

        $fields[] = [
            'code' => $code,
            'title' => $title,
            'type' => 'string',
            'userTypeId' => 'string',
            'typeTitle' => resolveFieldTypeTitle('string'),
            'isDate' => false,
            'isMultiple' => false,
            'isRequired' => false,
            'isLink' => false,
            'isCrmLink' => false,
            'linkTargets' => [],
            'settings' => [],
            'enumItems' => [],
        ];
    }

    return $fields;
}

function getUserFieldsForEntity(string $entityCode, array $knownCodes, array $userMetaMap = []): array
{
    $entityId = resolveUserFieldEntityId($entityCode);
    if ($entityId === '')
    {
        return [];
    }

    if (empty($userMetaMap))
    {
        $userMetaMap = getUserFieldMetaMap($entityId);
    }

    $rows = \Bitrix\Main\UserFieldTable::getList([
        'select' => ['ID', 'FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE', 'SETTINGS'],
        'filter' => ['=ENTITY_ID' => $entityId],
        'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
    ])->fetchAll();

    $result = [];
    foreach ($rows as $row)
    {
        $code = (string)($row['FIELD_NAME'] ?? '');
        if ($code === '' || in_array($code, $knownCodes, true))
        {
            continue;
        }

        $settings = normalizeFieldSettings($row['SETTINGS'] ?? []);
        $realUserTypeId = (string)($row['USER_TYPE_ID'] ?? ($userMetaMap[$code]['USER_TYPE_ID'] ?? 'string'));
        $fieldType = normalizeMetaFieldType($code, $realUserTypeId);
        $info = [
            'TYPE' => $fieldType,
            'SETTINGS' => $settings,
            'USER_TYPE_ID' => $realUserTypeId,
        ];
        $links = detectLinksFromField($code, $info);
        $isLinkField = isLinkedField($code, $info, $links);
        $isCrmLink = isCrmBindingField($code, $info);

        $result[] = [
            'code' => $code,
            'title' => getUserFieldDisplayTitle($code, $userMetaMap),
            'type' => $fieldType,
            'userTypeId' => $realUserTypeId,
            'typeTitle' => getUserFieldTypeTitle($code, $userMetaMap, $realUserTypeId),
            'isDate' => isDateLikeField(
                $code,
                getUserFieldDisplayTitle($code, $userMetaMap),
                $realUserTypeId,
                getUserFieldTypeTitle($code, $userMetaMap, $realUserTypeId)
            ),
            'isMultiple' => (string)($row['MULTIPLE'] ?? ($userMetaMap[$code]['MULTIPLE'] ?? 'N')) === 'Y',
            'isRequired' => (string)($userMetaMap[$code]['MANDATORY'] ?? 'N') === 'Y',
            'isLink' => $isLinkField,
            'isCrmLink' => $isCrmLink,
            'linkTargets' => $links,
            'settings' => $settings,
            'enumItems' => getUserFieldEnumItems((int)($row['ID'] ?? ($userMetaMap[$code]['ID'] ?? 0)), $realUserTypeId),
        ];
    }

    return $result;
}

function getUserFieldMetaMap(string $entityId): array
{
    $result = [];
    global $USER_FIELD_MANAGER;
    if (!$USER_FIELD_MANAGER)
    {
        return $result;
    }

    $lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
    $userFields = $USER_FIELD_MANAGER->GetUserFields($entityId, 0, $lang);
    if (!is_array($userFields))
    {
        return $result;
    }

    foreach ($userFields as $code => $row)
    {
        $code = (string)$code;
        if ($code === '')
        {
            continue;
        }

        $result[$code] = [
            'title' => pickUserFieldLabel($row),
            'typeTitle' => (string)($row['USER_TYPE']['DESCRIPTION'] ?? ''),
            'USER_TYPE_ID' => (string)($row['USER_TYPE_ID'] ?? ''),
            'SETTINGS' => normalizeFieldSettings($row['SETTINGS'] ?? []),
            'ID' => (int)($row['ID'] ?? 0),
            'MULTIPLE' => (string)($row['MULTIPLE'] ?? 'N'),
            'MANDATORY' => (string)($row['MANDATORY'] ?? 'N'),
        ];
    }

    return $result;
}

function pickUserFieldLabel(array $row): string
{
    $lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
    $candidates = [
        'EDIT_FORM_LABEL',
        'LIST_COLUMN_LABEL',
        'LIST_FILTER_LABEL',
    ];

    foreach ($candidates as $key)
    {
        $value = $row[$key] ?? null;
        $label = normalizeUserFieldLabelValue($value, $lang);
        if ($label !== '')
        {
            return $label;
        }
    }

    return '';
}

function normalizeUserFieldLabelValue($value, string $lang): string
{
    if (is_array($value))
    {
        if (!empty($value[$lang]))
        {
            return trim((string)$value[$lang]);
        }
        foreach (['ru', 'en'] as $fallbackLang)
        {
            if (!empty($value[$fallbackLang]))
            {
                return trim((string)$value[$fallbackLang]);
            }
        }
        $first = reset($value);
        return is_string($first) ? trim($first) : '';
    }

    if (is_string($value) && $value !== '')
    {
        $trim = trim($value);
        if ($trim !== '')
        {
            return $trim;
        }
    }

    return '';
}

function getUserFieldDisplayTitle(string $code, array $metaMap): string
{
    if (!empty($metaMap[$code]['title']))
    {
        return (string)$metaMap[$code]['title'];
    }

    return prettifyCodeAsTitle($code);
}

function getUserFieldTypeTitle(string $code, array $metaMap, string $fallbackType): string
{
    if (!empty($metaMap[$code]['typeTitle']))
    {
        return (string)$metaMap[$code]['typeTitle'];
    }

    return resolveFieldTypeTitle($fallbackType);
}

function resolveFieldTitle(array $info, string $fallbackCode): string
{
    $candidates = [
        $info['TITLE'] ?? null,
        $info['title'] ?? null,
        $info['CAPTION'] ?? null,
        $info['caption'] ?? null,
        $info['NAME'] ?? null,
        $info['name'] ?? null,
    ];

    foreach ($candidates as $candidate)
    {
        if (is_string($candidate) && trim($candidate) !== '')
        {
            return trim($candidate);
        }
    }

    return prettifyCodeAsTitle($fallbackCode);
}

function prettifyCodeAsTitle(string $code): string
{
    $pretty = str_replace('_', ' ', trim($code));
    $pretty = preg_replace('/\\s+/', ' ', (string)$pretty);
    if ($pretty === '')
    {
        return 'Поле';
    }

    return ucfirst($pretty);
}

function resolveFieldTypeTitle(string $type): string
{
    $type = strtolower(trim($type));
    $map = [
        'string' => 'Строка',
        'text' => 'Текст',
        'integer' => 'Число',
        'double' => 'Число',
        'float' => 'Число',
        'money' => 'Деньги',
        'boolean' => 'Да/Нет',
        'date' => 'Дата',
        'datetime' => 'Дата и время',
        'enumeration' => 'Список',
        'crm' => 'Привязка к CRM',
        'crm_entity' => 'Привязка к CRM',
        'file' => 'Файл',
        'url' => 'Ссылка',
        'employee' => 'Пользователь',
    ];

    return $map[$type] ?? ($type !== '' ? $type : 'Поле');
}

function extractEnumItemsFromField($field): array
{
    $result = [];

    if (method_exists($field, 'getUserField'))
    {
        $uf = $field->getUserField();
        if (is_array($uf))
        {
            $result = getUserFieldEnumItems((int)($uf['ID'] ?? 0), (string)($uf['USER_TYPE_ID'] ?? ''));
            if (!empty($result))
            {
                return $result;
            }
        }
    }

    if (method_exists($field, 'getSettings'))
    {
        $settings = (array)$field->getSettings();
        if (!empty($settings['ENUM']) && is_array($settings['ENUM']))
        {
            foreach ($settings['ENUM'] as $id => $title)
            {
                $result[] = ['id' => (string)$id, 'title' => (string)$title];
            }
        }
    }

    return $result;
}

function getUserFieldEnumItems(int $userFieldId, string $userTypeId): array
{
    if ($userFieldId <= 0 || strtolower($userTypeId) !== 'enumeration')
    {
        return [];
    }

    $result = [];
    $enum = new \CUserFieldEnum();
    $res = $enum->GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['USER_FIELD_ID' => $userFieldId]);
    while ($row = $res->Fetch())
    {
        $result[] = [
            'id' => (string)($row['ID'] ?? ''),
            'title' => (string)($row['VALUE'] ?? ''),
        ];
    }

    return $result;
}

function isDateLikeField(string $code, string $title, string $type, string $typeTitle): bool
{
    $type = strtolower(trim($type));
    $typeTitle = strtolower(trim($typeTitle));
    $code = strtoupper(trim($code));
    $title = strtolower(trim($title));

    if (strpos($type, 'date') !== false || strpos($type, 'time') !== false)
    {
        return true;
    }
    if (strpos($typeTitle, 'дата') !== false || strpos($typeTitle, 'время') !== false)
    {
        return true;
    }
    if (preg_match('/(_DATE|_TIME|DATE_|TIME_|CREATED|UPDATED|BIRTH|DEADLINE|CLOSE)/', $code))
    {
        return true;
    }
    if (strpos($title, 'дата') !== false || strpos($title, 'время') !== false)
    {
        return true;
    }

    return false;
}

function normalizeMetaFieldType(string $fieldCode, string $rawType): string
{
    $type = strtolower(trim($rawType));
    $code = strtoupper(trim($fieldCode));

    if ($type === 'datetime' || $type === 'date')
    {
        return $type;
    }

    if (preg_match('/(^|_)(CREATED_TIME|UPDATED_TIME|MOVED_TIME|DATE_CREATE|DATE_MODIFY|LAST_ACTIVITY_TIME)$/', $code))
    {
        return 'datetime';
    }

    if (preg_match('/(^|_)(BEGINDATE|CLOSEDATE|BIRTHDATE|DATE)$/', $code) || preg_match('/(^|_)UF_.*_DATE$/', $code))
    {
        return 'date';
    }

    if (preg_match('/(^|_)(CREATED_BY|UPDATED_BY|MOVED_BY|ASSIGNED_BY_ID|CREATED_BY_ID|UPDATED_BY_ID|MODIFY_BY_ID)$/', $code))
    {
        return 'user';
    }

    return $type !== '' ? $type : 'string';
}

function resolveUserFieldEntityId(string $entityCode): string
{
    if ($entityCode === 'CONTACT')
    {
        return 'CRM_CONTACT';
    }

    if ($entityCode === 'DEAL')
    {
        return 'CRM_DEAL';
    }

    if ($entityCode === 'COMPANY')
    {
        return 'CRM_COMPANY';
    }

    if ($entityCode === 'LEAD')
    {
        return 'CRM_LEAD';
    }

    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        $typeId = (int)substr($entityCode, 8);
        if ($typeId > 0)
        {
            return 'CRM_'.$typeId;
        }
    }

    return '';
}

function normalizeFieldSettings($settings): array
{
    if (is_array($settings))
    {
        return $settings;
    }

    if (is_string($settings) && $settings !== '')
    {
        $decoded = @unserialize($settings, ['allowed_classes' => false]);
        if (is_array($decoded))
        {
            return $decoded;
        }

        $json = Json::decode($settings);
        if (is_array($json))
        {
            return $json;
        }
    }

    return [];
}

function mergeFieldSettings(array $baseSettings, array $userFieldSettings): array
{
    if (empty($baseSettings))
    {
        return $userFieldSettings;
    }
    if (empty($userFieldSettings))
    {
        return $baseSettings;
    }

    // User field settings should override base field settings.
    return array_replace_recursive($baseSettings, $userFieldSettings);
}

function getConfiguredEntityRelations(string $entityCode): array
{
    if (strpos($entityCode, 'DYNAMIC_') !== 0)
    {
        return [];
    }

    $typeId = (int)substr($entityCode, 8);
    if ($typeId <= 0)
    {
        return [];
    }

    $result = [];
    $relationClass = 'Bitrix\\Crm\\Model\\Dynamic\\TypeRelationTable';
    if (!class_exists($relationClass))
    {
        return [];
    }

    try
    {
        $rows = $relationClass::getList([
            'select' => ['PARENT_ENTITY_TYPE_ID', 'CHILD_ENTITY_TYPE_ID'],
            'filter' => [
                [
                    'LOGIC' => 'OR',
                    '=PARENT_ENTITY_TYPE_ID' => $typeId,
                    '=CHILD_ENTITY_TYPE_ID' => $typeId,
                ],
            ],
        ])->fetchAll();
    }
    catch (Throwable $e)
    {
        return [];
    }

    foreach ($rows as $row)
    {
        $parentId = (int)($row['PARENT_ENTITY_TYPE_ID'] ?? 0);
        $childId = (int)($row['CHILD_ENTITY_TYPE_ID'] ?? 0);

        if ($parentId > 0 && $parentId !== $typeId)
        {
            $mapped = mapEntityTypeIdToCode($parentId);
            if ($mapped !== '')
            {
                $result[] = $mapped;
            }
        }

        if ($childId > 0 && $childId !== $typeId)
        {
            $mapped = mapEntityTypeIdToCode($childId);
            if ($mapped !== '')
            {
                $result[] = $mapped;
            }
        }
    }

    return array_values(array_unique($result));
}

function mapEntityTypeIdToCode(int $entityTypeId): string
{
    if ($entityTypeId === \CCrmOwnerType::Contact)
    {
        return 'CONTACT';
    }

    if ($entityTypeId === \CCrmOwnerType::Deal)
    {
        return 'DEAL';
    }

    if ($entityTypeId === \CCrmOwnerType::Company)
    {
        return 'COMPANY';
    }

    if ($entityTypeId === \CCrmOwnerType::Lead)
    {
        return 'LEAD';
    }

    if ($entityTypeId >= \CCrmOwnerType::DynamicTypeStart)
    {
        return 'DYNAMIC_'.$entityTypeId;
    }

    return '';
}

function getFactoryByCode(string $entityCode)
{
    $container = Container::getInstance();

    if ($entityCode === 'CONTACT')
    {
        return $container->getFactory(CCrmOwnerType::Contact);
    }

    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        $id = (int)substr($entityCode, 8);
        if ($id > 0)
        {
            return $container->getFactory($id);
        }
    }

    if ($entityCode === 'DEAL')
    {
        return $container->getFactory(\CCrmOwnerType::Deal);
    }

    if ($entityCode === 'COMPANY')
    {
        return $container->getFactory(\CCrmOwnerType::Company);
    }

    if ($entityCode === 'LEAD')
    {
        return $container->getFactory(\CCrmOwnerType::Lead);
    }

    return null;
}

function detectLinksFromField(string $fieldCode, array $info): array
{
    $result = [];
    $upperCode = strtoupper(trim($fieldCode));

    if (preg_match('/^PARENT_ID_(\d+)$/', $upperCode, $matches))
    {
        $result[] = 'DYNAMIC_'.(int)$matches[1];
    }

    $directMap = [
        'CONTACT_ID' => 'CONTACT',
        'CONTACT_IDS' => 'CONTACT',
        'COMPANY_ID' => 'COMPANY',
        'COMPANY_IDS' => 'COMPANY',
        'DEAL_ID' => 'DEAL',
        'DEAL_IDS' => 'DEAL',
        'LEAD_ID' => 'LEAD',
        'LEAD_IDS' => 'LEAD',
    ];
    if (isset($directMap[$upperCode]))
    {
        $result[] = $directMap[$upperCode];
    }

    $settings = [];
    if (!empty($info['SETTINGS']) && is_array($info['SETTINGS']))
    {
        $settings = $info['SETTINGS'];
    }

    if (isCrmBindingField($fieldCode, $info))
    {
        $targets = extractCrmTargetsFromSettings($settings);
        foreach ($targets as $target)
        {
            $result[] = $target;
        }
    }

    foreach (findSettingValuesByKey($settings, 'CRM_ENTITY_TYPE') as $entityName)
    {
        if (is_array($entityName))
        {
            foreach ($entityName as $nestedEntityName)
            {
                $mapped = mapCrmEntityNameToCode((string)$nestedEntityName);
                if ($mapped !== '')
                {
                    $result[] = $mapped;
                }
            }
            continue;
        }

        $mapped = mapCrmEntityNameToCode((string)$entityName);
        if ($mapped !== '')
        {
            $result[] = $mapped;
        }
    }

    foreach (findSettingValuesByKey($settings, 'CRM_ENTITY_TYPE_LIST') as $entityList)
    {
        if (!is_array($entityList))
        {
            continue;
        }
        foreach ($entityList as $entityName)
        {
            $mapped = mapCrmEntityNameToCode((string)$entityName);
            if ($mapped !== '')
            {
                $result[] = $mapped;
            }
        }
    }

    return array_values(array_unique(array_filter($result)));
}

function isCrmBindingField(string $fieldCode, array $info): bool
{
    $crmType = strtoupper((string)($info['TYPE'] ?? $info['type'] ?? ''));
    $userTypeId = strtoupper((string)($info['USER_TYPE_ID'] ?? $info['userTypeId'] ?? ''));
    $upperCode = strtoupper(trim($fieldCode));

    if (isDictionaryUserType($userTypeId))
    {
        return false;
    }

    $crmTypes = ['CRM', 'CRM_ENTITY', 'CRM_ENTITY_TYPE', 'CRM_ENTITY_TYPE_LIST'];
    if (in_array($crmType, $crmTypes, true) || in_array($userTypeId, $crmTypes, true))
    {
        return true;
    }
    if (strpos($crmType, 'CRM') !== false || strpos($userTypeId, 'CRM') !== false)
    {
        return true;
    }

    return (bool)preg_match('/^UF_CRM_\\d+_/', $upperCode);
}

function isDictionaryUserType(string $userTypeId): bool
{
    $value = strtoupper(trim($userTypeId));
    return in_array($value, ['ENUMERATION', 'HLBLOCK', 'IBLOCK_ELEMENT', 'CRMSTATUS', 'CRM_STATUS', 'DATE', 'DATETIME'], true);
}

function isLinkedField(string $fieldCode, array $info, array $fieldLinks): bool
{
    if (!empty($fieldLinks))
    {
        return true;
    }

    if (isCrmBindingField($fieldCode, $info))
    {
        return true;
    }

    $upperCode = strtoupper($fieldCode);
    if (preg_match('/^PARENT_ID_(\\d+)$/', $upperCode))
    {
        return true;
    }

    return false;
}

function extractCrmTargetsFromSettings(array $settings): array
{
    $result = [];
    foreach (['CRM_ENTITY_TYPE', 'CRM_ENTITY_TYPE_LIST'] as $path)
    {
        foreach (findSettingValuesByKey($settings, $path) as $candidate)
        {
            if (is_array($candidate))
            {
                foreach ($candidate as $entry)
                {
                    $mapped = mapCrmEntityNameToCode((string)$entry);
                    if ($mapped !== '')
                    {
                        $result[] = $mapped;
                    }
                }
            }
            else
            {
                $mapped = mapCrmEntityNameToCode((string)$candidate);
                if ($mapped !== '')
                {
                    $result[] = $mapped;
                }
            }
        }
    }

    $flat = flattenSettingsTokens($settings);
    foreach ($flat as $token)
    {
        $mapped = mapCrmEntityNameToCode($token);
        if ($mapped !== '')
        {
            $result[] = $mapped;
        }
    }

    return array_values(array_unique($result));
}

function findSettingValuesByKey(array $settings, string $wantedKey): array
{
    $result = [];
    $wantedKey = strtoupper(trim($wantedKey));

    foreach ($settings as $key => $value)
    {
        if (strtoupper((string)$key) === $wantedKey)
        {
            $result[] = $value;
        }

        if (is_array($value))
        {
            $result = array_merge($result, findSettingValuesByKey($value, $wantedKey));
        }
    }

    return $result;
}

function flattenSettingsTokens($value): array
{
    $result = [];
    if (is_array($value))
    {
        foreach ($value as $k => $v)
        {
            // In CRM settings keys can carry entity codes (e.g. DYNAMIC_123 => Y),
            // but only include the key when value explicitly enables the link.
            if (isEnabledSettingValue($v))
            {
                $result = array_merge($result, flattenSettingsTokens((string)$k));
            }
            $result = array_merge($result, flattenSettingsTokens($v));
        }
        return $result;
    }

    if (is_scalar($value))
    {
        $str = trim((string)$value);
        if ($str === '')
        {
            return [];
        }

        $parts = preg_split('/[\\s,;|]+/', $str) ?: [];
        foreach ($parts as $part)
        {
            $part = trim($part);
            if ($part !== '')
            {
                $result[] = $part;
            }
        }
    }

    return $result;
}

function isEnabledSettingValue($value): bool
{
    if (is_bool($value))
    {
        return $value;
    }

    if (is_int($value) || is_float($value))
    {
        return (float)$value > 0;
    }

    if (is_string($value))
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '' || $normalized === 'N' || $normalized === 'NO' || $normalized === '0' || $normalized === 'FALSE' || $normalized === '-')
        {
            return false;
        }
        if ($normalized === 'Y' || $normalized === 'YES' || $normalized === '1' || $normalized === 'TRUE')
        {
            return true;
        }
    }

    // For nested arrays/objects we cannot decide by scalar truthiness here.
    return !empty($value);
}

function mapCrmEntityNameToCode(string $name): string
{
    $name = strtoupper(trim($name));
    if ($name === '')
    {
        return '';
    }

    if ($name === 'CONTACT' || $name === 'CRM_CONTACT')
    {
        return 'CONTACT';
    }

    if ($name === 'COMPANY' || $name === 'CRM_COMPANY')
    {
        return 'COMPANY';
    }

    if ($name === 'DEAL' || $name === 'CRM_DEAL')
    {
        return 'DEAL';
    }

    if ($name === 'LEAD' || $name === 'CRM_LEAD')
    {
        return 'LEAD';
    }

    if (strpos($name, 'DYNAMIC_') === 0)
    {
        return $name;
    }

    if (preg_match('/^CRM_(\\d+)$/', $name, $matches))
    {
        return mapEntityTypeIdToCode((int)$matches[1]);
    }

    if (preg_match('/^DYNAMIC[-_:]?(\\d+)$/', $name, $matches))
    {
        return 'DYNAMIC_'.(int)$matches[1];
    }

    if (preg_match('/^DYNAMICS[-_:]?(\\d+)$/', $name, $matches))
    {
        return 'DYNAMIC_'.(int)$matches[1];
    }

    if (preg_match('/^CRMDYNAMIC[-_:]?(\\d+)$/', $name, $matches))
    {
        return 'DYNAMIC_'.(int)$matches[1];
    }

    if (preg_match('/^T(\d+)$/', $name, $matches))
    {
        return 'DYNAMIC_'.(int)$matches[1];
    }

    if (ctype_digit($name))
    {
        return mapEntityTypeIdToCode((int)$name);
    }

    return '';
}

function mapEntityTitle(string $code): string
{
    if ($code === 'CONTACT')
    {
        return 'Contact';
    }

    if (strpos($code, 'DYNAMIC_') === 0)
    {
        $id = (int)substr($code, 8);
        if ($id <= 0)
        {
            return '';
        }

        $row = TypeTable::getList([
            'select' => ['TITLE'],
            'filter' => ['=ENTITY_TYPE_ID' => $id],
            'limit' => 1,
        ])->fetch();

        return $row ? (string)$row['TITLE'] : '';
    }

    if ($code === 'DEAL')
    {
        return 'Deal';
    }

    if ($code === 'COMPANY')
    {
        return 'Company';
    }

    if ($code === 'LEAD')
    {
        return 'Lead';
    }

    return '';
}

function searchEntityItems(string $entityCode, string $query, int $limit = 20): array
{
    $factory = getFactoryByCode($entityCode);
    if (!$factory)
    {
        return [];
    }

    $limit = max(1, min(100, $limit));

    $select = ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'COMPANY_TITLE'];
    $filter = [];
    if ($query !== '')
    {
        $filter = [
            'LOGIC' => 'OR',
            '%TITLE' => $query,
            '%NAME' => $query,
            '%LAST_NAME' => $query,
            '%SECOND_NAME' => $query,
            '%COMPANY_TITLE' => $query,
        ];
    }

    $items = $factory->getItems([
        'select' => $select,
        'filter' => $filter,
        'order' => ['ID' => 'DESC'],
        'limit' => $limit,
    ]);

    $result = [];
    foreach ($items as $item)
    {
        $result[] = [
            'id' => (string)$item->getId(),
            'title' => buildEntityItemTitle($entityCode, $item),
        ];
    }

    return $result;
}

function buildEntityItemTitle(string $entityCode, $item): string
{
    if ($entityCode === 'CONTACT')
    {
        $parts = [
            trim((string)$item->get('LAST_NAME')),
            trim((string)$item->get('NAME')),
            trim((string)$item->get('SECOND_NAME')),
        ];
        $name = trim(implode(' ', array_filter($parts)));
        if ($name !== '')
        {
            return $name;
        }
    }

    $title = trim((string)$item->get('TITLE'));
    if ($title !== '')
    {
        return $title;
    }

    $companyTitle = trim((string)$item->get('COMPANY_TITLE'));
    if ($companyTitle !== '')
    {
        return $companyTitle;
    }

    return '#'.$item->getId();
}

function debugEntitySelector(array $entityTypeIds, int $userId): array
{
    $result = [];
    $entityTypeIds = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds), static function ($id) {
        return $id > 0;
    })));

    foreach ($entityTypeIds as $entityTypeId)
    {
        $code = mapEntityTypeIdToCode($entityTypeId);
        $factory = $code !== '' ? getFactoryByCode($code) : null;
        if (!$factory)
        {
            $result[] = [
                'entityTypeId' => $entityTypeId,
                'entityCode' => $code,
                'factory' => false,
                'error' => 'Factory not found',
            ];
            continue;
        }

        $title = mapEntityTitle($code);
        $diag = [
            'entityTypeId' => $entityTypeId,
            'entityCode' => $code,
            'entityTitle' => $title,
            'factory' => true,
            'counts' => [
                'raw' => null,
                'permissionFiltered' => null,
                'searchBy1' => null,
                'searchByA' => null,
            ],
            'samples' => [
                'raw' => [],
                'permissionFiltered' => [],
            ],
            'permissions' => [
                'userId' => $userId,
                'canReadType' => null,
                'notes' => [],
            ],
        ];

        $selectFields = buildFactorySelectFields($factory, ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'COMPANY_TITLE']);

        try
        {
            $rawItems = (array)$factory->getItems([
                'select' => $selectFields,
                'order' => ['ID' => 'DESC'],
                'limit' => 5,
            ]);
            $diag['samples']['raw'] = normalizeFactoryItems($code, $rawItems);
        }
        catch (\Throwable $e)
        {
            $diag['permissions']['notes'][] = 'raw sample failed: '.$e->getMessage();
        }

        try
        {
            $rawCount = getFactoryItemsCountSafe($factory, []);
            $diag['counts']['raw'] = $rawCount;
        }
        catch (\Throwable $e)
        {
            $diag['permissions']['notes'][] = 'raw count failed: '.$e->getMessage();
        }

        try
        {
            $permissionItems = getPermissionFilteredItemsSafe($factory, [
                'select' => $selectFields,
                'order' => ['ID' => 'DESC'],
                'limit' => 5,
            ], $userId);
            $diag['samples']['permissionFiltered'] = normalizeFactoryItems($code, $permissionItems);
            $diag['counts']['permissionFiltered'] = getPermissionFilteredCountSafe($factory, [], $userId);
        }
        catch (\Throwable $e)
        {
            $diag['permissions']['notes'][] = 'permission filtered failed: '.$e->getMessage();
        }

        try
        {
            $diag['counts']['searchBy1'] = getPermissionFilteredCountSafe($factory, [
                'LOGIC' => 'OR',
                '%TITLE' => '1',
                '%NAME' => '1',
                '%LAST_NAME' => '1',
                '%COMPANY_TITLE' => '1',
            ], $userId);
        }
        catch (\Throwable $e)
        {
            $diag['permissions']['notes'][] = 'searchBy1 failed: '.$e->getMessage();
        }

        try
        {
            $diag['counts']['searchByA'] = getPermissionFilteredCountSafe($factory, [
                'LOGIC' => 'OR',
                '%TITLE' => 'а',
                '%NAME' => 'а',
                '%LAST_NAME' => 'а',
                '%COMPANY_TITLE' => 'а',
            ], $userId);
        }
        catch (\Throwable $e)
        {
            $diag['permissions']['notes'][] = 'searchByA failed: '.$e->getMessage();
        }

        try
        {
            if (method_exists($factory, 'getUserPermissions'))
            {
                $permissions = $factory->getUserPermissions($userId);
                if ($permissions && method_exists($permissions, 'checkReadPermissions'))
                {
                    $diag['permissions']['canReadType'] = (bool)$permissions->checkReadPermissions();
                }
                else
                {
                    $diag['permissions']['notes'][] = 'checkReadPermissions method unavailable';
                }
            }
            else
            {
                $diag['permissions']['notes'][] = 'getUserPermissions method unavailable';
            }
        }
        catch (\Throwable $e)
        {
            $diag['permissions']['notes'][] = 'permission object failed: '.$e->getMessage();
        }

        $result[] = $diag;
    }

    return $result;
}

function normalizeFactoryItems(string $entityCode, array $items): array
{
    $result = [];
    foreach ($items as $item)
    {
        if (!is_object($item) || !method_exists($item, 'getId'))
        {
            continue;
        }
        $result[] = [
            'id' => (int)$item->getId(),
            'title' => buildEntityItemTitle($entityCode, $item),
        ];
    }
    return $result;
}

function getFactoryItemsCountSafe($factory, array $filter): ?int
{
    if (method_exists($factory, 'getItemsCount'))
    {
        try
        {
            return (int)$factory->getItemsCount($filter);
        }
        catch (\Throwable $e)
        {
            return (int)$factory->getItemsCount(['filter' => $filter]);
        }
    }

    $items = (array)$factory->getItems([
        'select' => ['ID'],
        'filter' => $filter,
        'limit' => 5000,
    ]);
    return count($items);
}

function getPermissionFilteredItemsSafe($factory, array $parameters, int $userId): array
{
    if (method_exists($factory, 'getItemsFilteredByPermissions'))
    {
        return (array)$factory->getItemsFilteredByPermissions($parameters, $userId);
    }

    return (array)$factory->getItems($parameters);
}

function getPermissionFilteredCountSafe($factory, array $filter, int $userId): ?int
{
    if (method_exists($factory, 'getItemsCountFilteredByPermissions'))
    {
        try
        {
            return (int)$factory->getItemsCountFilteredByPermissions($filter, $userId);
        }
        catch (\Throwable $e)
        {
            return (int)$factory->getItemsCountFilteredByPermissions(['filter' => $filter], $userId);
        }
    }

    if (method_exists($factory, 'getItemsFilteredByPermissions'))
    {
        $items = (array)$factory->getItemsFilteredByPermissions([
            'select' => ['ID'],
            'filter' => $filter,
            'limit' => 5000,
        ], $userId);
        return count($items);
    }

    return getFactoryItemsCountSafe($factory, $filter);
}

function buildFactorySelectFields($factory, array $candidates): array
{
    $available = [];
    if (method_exists($factory, 'getFieldsCollection'))
    {
        try
        {
            $collection = $factory->getFieldsCollection();
            foreach ($collection as $field)
            {
                $name = method_exists($field, 'getName') ? (string)$field->getName() : (string)$field->getFieldName();
                if ($name !== '')
                {
                    $available[strtoupper($name)] = true;
                }
            }
        }
        catch (\Throwable $e)
        {
        }
    }

    $result = [];
    foreach ($candidates as $field)
    {
        if (empty($available) || isset($available[strtoupper($field)]))
        {
            $result[] = $field;
        }
    }
    if (empty($result))
    {
        $result = ['ID'];
        if (isset($available['TITLE']))
        {
            $result[] = 'TITLE';
        }
    }

    return array_values(array_unique($result));
}

function buildTemplatePreview(int $userId, string $templateId, array $filterValues, int $page, int $pageSize, array $sort = []): array
{
    $template = getTemplate($userId, $templateId, 'hl');
    if (!$template)
    {
        throw new RuntimeException('Template not found');
    }

    $config = is_array($template['config'] ?? null) ? $template['config'] : [];
    $rootEntityCode = (string)($config['rootEntity'] ?? '');
    if ($rootEntityCode === '')
    {
        throw new RuntimeException('Template root entity is empty');
    }

    $rootFactory = getFactoryByCode($rootEntityCode);
    if (!$rootFactory)
    {
        throw new RuntimeException('Factory not found for root entity');
    }

    $nodes = indexTemplateNodes($config);
    $filterItems = collectTemplateFilterItemsForPreview($config, $nodes);
    $filterIndex = [];
    foreach ($filterItems as $item)
    {
        $logicalId = buildPreviewFilterFieldId($item, $rootEntityCode);
        if ($logicalId !== '')
        {
            $filterIndex[$logicalId] = $item;
        }
        $legacyId = makeReportFilterFieldId((string)($item['key'] ?? ''));
        if ($legacyId !== '')
        {
            $filterIndex[$legacyId] = $item;
        }
    }

    $contactFieldCode = (string)($config['contactFieldCode'] ?? '');
    if ($contactFieldCode === '')
    {
        $contactFieldCode = (string)($template['contactFieldCode'] ?? '');
    }
    $rootMeta = getEntityMeta($rootEntityCode);
    $rootMetaByCode = buildMetaMapForPreview($rootMeta);
    $contactMetaByCode = buildMetaMapForPreview(getEntityMeta('CONTACT'));

    $rootBaseFilter = buildRootOrmFilterForPreview($filterValues, $filterIndex, $rootEntityCode, $rootMetaByCode);
    $rootBaseFilter = sanitizeOrmFilterForPreview($rootBaseFilter);
    $rootFilter = $rootBaseFilter;
    $rootPeriodRule = buildRootPeriodRuleForPreview($filterValues, $filterIndex, $rootEntityCode, $rootMetaByCode);
    if ($rootPeriodRule !== null)
    {
        applyRootPeriodRuleToOrmFilterForPreview($rootFilter, $rootPeriodRule);
        $rootFilter = sanitizeOrmFilterForPreview($rootFilter);
    }
    $rootSelect = buildRootSelectForPreview($config, $filterItems, $contactFieldCode);

    $needRootPeriodInMemoryFilter = false;
    try
    {
        $rootItems = getPermissionFilteredItemsSafe($rootFactory, [
            'select' => $rootSelect,
            'filter' => $rootFilter,
            'order' => ['ID' => 'DESC'],
            'limit' => 5000,
        ], $userId);
    }
    catch (\Throwable $e)
    {
        $isDateValueError = mb_stripos($e->getMessage(), 'Incorrect DATE value') !== false;
        if (!$isDateValueError || $rootPeriodRule === null)
        {
            throw $e;
        }

        // Fallback for corrupted date data: load by base filter and apply period in PHP.
        $rootItems = getPermissionFilteredItemsSafe($rootFactory, [
            'select' => $rootSelect,
            'filter' => $rootBaseFilter,
            'order' => ['ID' => 'DESC'],
            'limit' => 5000,
        ], $userId);
        $needRootPeriodInMemoryFilter = true;
    }

    if ($needRootPeriodInMemoryFilter && $rootPeriodRule !== null)
    {
        $rootItems = array_values(array_filter($rootItems, static function ($item) use ($rootPeriodRule): bool {
            return matchRootPeriodRuleForPreview($item, $rootPeriodRule);
        }));
    }

    $contactsById = loadContactsForRootItems($rootItems, $contactFieldCode, $userId);
    $contactFilterRules = buildContactFilterRulesForPreview($filterValues, $filterIndex, $contactMetaByCode);
    applyPeriodContactRuleForPreview($contactFilterRules, $filterValues, $filterIndex, $rootEntityCode, $contactMetaByCode);

    $rowsWithContext = [];
    foreach ($rootItems as $rootItem)
    {
        if (!is_object($rootItem) || !method_exists($rootItem, 'getId'))
        {
            continue;
        }

        $contactId = extractFirstContactIdFromItem($rootItem, $contactFieldCode);
        $contactItem = $contactId > 0 ? ($contactsById[$contactId] ?? null) : null;
        if (!matchContactRulesForPreview($contactItem, $contactFilterRules))
        {
            continue;
        }

        $rowsWithContext[] = [
            'rootItem' => $rootItem,
            'contactItem' => $contactItem,
        ];
    }

    enrichRowsWithNodeItemsForPreview($rowsWithContext, $nodes, $userId);

    $columns = buildPreviewColumns($config, $nodes);
    $rows = buildPreviewRows($rowsWithContext, $columns, $contactFieldCode);

    if (!empty($sort['columnKey']))
    {
        $rows = sortPreviewRows($rows, (string)$sort['columnKey'], (string)($sort['direction'] ?? 'asc'));
    }

    $total = count($rows);
    $offset = ($page - 1) * $pageSize;
    $pagedRows = array_slice($rows, $offset, $pageSize);

    return [
        'templateId' => (string)$template['id'],
        'contactFieldCode' => $contactFieldCode,
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'columns' => $columns,
        'rows' => $pagedRows,
    ];
}

function sanitizeOrmFilterForPreview(array $filter): array
{
    foreach ($filter as $key => $value)
    {
        if (is_array($value))
        {
            $filter[$key] = sanitizeOrmFilterForPreview($value);
            if ($filter[$key] === [])
            {
                unset($filter[$key]);
            }
            continue;
        }

        if (!is_scalar($value))
        {
            continue;
        }

        if (trim((string)$value) === '')
        {
            unset($filter[$key]);
        }
    }

    return $filter;
}

function applyPeriodFilterForPreview(array &$rootFilter, array $filterValues, array $filterIndex, string $rootEntityCode, array $rootMetaByCode): void
{
    $rule = buildRootPeriodRuleForPreview($filterValues, $filterIndex, $rootEntityCode, $rootMetaByCode);
    if ($rule === null)
    {
        return;
    }

    applyRootPeriodRuleToOrmFilterForPreview($rootFilter, $rule);
}

function buildRootPeriodRuleForPreview(array $filterValues, array $filterIndex, string $rootEntityCode, array $rootMetaByCode): ?array
{
    $periodFieldId = trim((string)getFilterValueByFieldId($filterValues, 'PERIOD_FIELD'));
    if ($periodFieldId === '' || !isset($filterIndex[$periodFieldId]))
    {
        return null;
    }

    $item = (array)$filterIndex[$periodFieldId];
    $entityCode = strtoupper((string)($item['entityCode'] ?? ''));
    if ($entityCode !== strtoupper($rootEntityCode))
    {
        return null;
    }

    $fieldCode = (string)($item['fieldCode'] ?? '');
    if ($fieldCode === '')
    {
        return null;
    }

    $meta = $rootMetaByCode[$fieldCode] ?? [];
    $isDateTime = isDateTimeMetaFieldForPreview($meta, $fieldCode);
    $range = resolveDateRangeFromFilterForPreview($filterValues, 'PERIOD', $isDateTime);
    if ($range['from'] !== '' && !isValidDateBoundaryForPreview($range['from'], $isDateTime))
    {
        $range['from'] = '';
    }
    if ($range['to'] !== '' && !isValidDateBoundaryForPreview($range['to'], $isDateTime))
    {
        $range['to'] = '';
    }
    if ($range['from'] === '' && $range['to'] === '')
    {
        return null;
    }

    return [
        'fieldCode' => $fieldCode,
        'from' => $range['from'],
        'to' => $range['to'],
        'isDateTime' => $isDateTime,
    ];
}

function applyRootPeriodRuleToOrmFilterForPreview(array &$rootFilter, array $rule): void
{
    $fieldCode = (string)($rule['fieldCode'] ?? '');
    if ($fieldCode === '')
    {
        return;
    }

    $from = trim((string)($rule['from'] ?? ''));
    $to = trim((string)($rule['to'] ?? ''));
    $isDateTime = (bool)($rule['isDateTime'] ?? false);

    if ($from !== '' && isValidDateBoundaryForPreview($from, $isDateTime))
    {
        $rootFilter['>='.$fieldCode] = $from;
    }
    if ($to !== '' && isValidDateBoundaryForPreview($to, $isDateTime))
    {
        $rootFilter['<='.$fieldCode] = $to;
    }
}

function matchRootPeriodRuleForPreview($item, array $rule): bool
{
    if (!is_object($item) || !method_exists($item, 'get'))
    {
        return false;
    }

    $fieldCode = (string)($rule['fieldCode'] ?? '');
    if ($fieldCode === '')
    {
        return true;
    }

    $valueTs = parseDateValueToTimestampForPreview($item->get($fieldCode));
    if ($valueTs === null)
    {
        return false;
    }

    $fromTs = parseDateValueToTimestampForPreview((string)($rule['from'] ?? ''));
    $toTs = parseDateValueToTimestampForPreview((string)($rule['to'] ?? ''));

    if ($fromTs !== null && $valueTs < $fromTs)
    {
        return false;
    }
    if ($toTs !== null && $valueTs > $toTs)
    {
        return false;
    }

    return true;
}

function parseDateValueToTimestampForPreview($value): ?int
{
    if ($value instanceof \Bitrix\Main\Type\DateTime || $value instanceof \Bitrix\Main\Type\Date)
    {
        $value = (string)$value;
    }
    elseif (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return parseDateValueToTimestampForPreview($value->getValue());
        }
        if (method_exists($value, '__toString'))
        {
            $value = (string)$value;
        }
        else
        {
            return null;
        }
    }
    elseif (is_array($value))
    {
        foreach ($value as $part)
        {
            $ts = parseDateValueToTimestampForPreview($part);
            if ($ts !== null)
            {
                return $ts;
            }
        }
        return null;
    }

    $text = trim((string)$value);
    if ($text === '')
    {
        return null;
    }

    $ts = strtotime($text);
    return $ts === false ? null : (int)$ts;
}

function applyPeriodContactRuleForPreview(array &$contactFilterRules, array $filterValues, array $filterIndex, string $rootEntityCode, array $contactMetaByCode): void
{
    $periodFieldId = trim((string)getFilterValueByFieldId($filterValues, 'PERIOD_FIELD'));
    if ($periodFieldId === '' || !isset($filterIndex[$periodFieldId]))
    {
        return;
    }

    $item = (array)$filterIndex[$periodFieldId];
    $entityCode = strtoupper((string)($item['entityCode'] ?? ''));
    if ($entityCode === '' || $entityCode === strtoupper($rootEntityCode) || $entityCode !== 'CONTACT')
    {
        return;
    }

    $fieldCode = (string)($item['fieldCode'] ?? '');
    if ($fieldCode === '')
    {
        return;
    }

    $meta = $contactMetaByCode[$fieldCode] ?? [];
    $isDateTime = isDateTimeMetaFieldForPreview($meta, $fieldCode);
    $range = resolveDateRangeFromFilterForPreview($filterValues, 'PERIOD', $isDateTime);
    if ($range['from'] !== '' && !isValidDateBoundaryForPreview($range['from'], $isDateTime))
    {
        $range['from'] = '';
    }
    if ($range['to'] !== '' && !isValidDateBoundaryForPreview($range['to'], $isDateTime))
    {
        $range['to'] = '';
    }
    if ($range['from'] === '' && $range['to'] === '')
    {
        return;
    }

    $contactFilterRules[] = [
        'fieldCode' => $fieldCode,
        'type' => 'range',
        'from' => $range['from'],
        'to' => $range['to'],
    ];
}

function makeReportFilterFieldId(string $key): string
{
    return (string)preg_replace('/[^A-Za-z0-9_]/', '_', str_replace(['::', '.'], ['__', '_'], $key));
}

function buildPreviewFilterFieldId(array $item, string $rootEntityCode): string
{
    $entityCode = strtoupper((string)($item['entityCode'] ?? ''));
    $fieldCode = (string)($item['fieldCode'] ?? '');
    $key = (string)($item['key'] ?? '');
    if ($fieldCode === '')
    {
        return '';
    }

    if ($entityCode === 'CONTACT')
    {
        return 'contact__'.preg_replace('/[^A-Za-z0-9_]/', '_', $fieldCode);
    }

    if ($entityCode !== '' && $entityCode === strtoupper($rootEntityCode))
    {
        if ($key !== '')
        {
            return makeReportFilterFieldId($key);
        }
        return preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($entityCode).'__'.$fieldCode);
    }

    $entityToken = preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($entityCode));
    return 'rel_'.$entityToken.'__'.preg_replace('/[^A-Za-z0-9_]/', '_', $fieldCode);
}

function indexTemplateNodes(array $config): array
{
    $nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
    $result = [];
    foreach ($nodes as $node)
    {
        $id = (string)($node['id'] ?? '');
        if ($id === '')
        {
            continue;
        }
        $result[$id] = $node;
    }
    return $result;
}

function collectTemplateFilterItemsForPreview(array $config, array $nodes): array
{
    $filterItems = is_array($config['filterFields'] ?? null) ? $config['filterFields'] : [];
    if (!empty($filterItems))
    {
        return $filterItems;
    }

    $result = [];
    foreach ($nodes as $nodeId => $node)
    {
        $entityCode = (string)($node['entityCode'] ?? '');
        $entityTitle = (string)($node['entityTitle'] ?? $entityCode);
        $selectedFields = is_array($node['selectedFields'] ?? null) ? $node['selectedFields'] : [];
        foreach ($selectedFields as $fieldCode)
        {
            $fieldCode = (string)$fieldCode;
            if ($fieldCode === '' || $entityCode === '')
            {
                continue;
            }
            $result[] = [
                'key' => $nodeId.'::'.$fieldCode,
                'nodeId' => $nodeId,
                'entityCode' => $entityCode,
                'entityTitle' => $entityTitle,
                'fieldCode' => $fieldCode,
                'fieldTitle' => $fieldCode,
            ];
        }
    }
    return $result;
}

function buildMetaMapForPreview(array $meta): array
{
    $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
    $result = [];
    foreach ($fields as $field)
    {
        $code = (string)($field['code'] ?? '');
        if ($code === '')
        {
            continue;
        }
        $result[$code] = $field;
    }
    return $result;
}

function buildRootOrmFilterForPreview(array $filterValues, array $filterIndex, string $rootEntityCode, array $rootMetaByCode): array
{
    $rootFilter = [];

    foreach ($filterIndex as $fieldId => $item)
    {
        $entityCode = strtoupper((string)($item['entityCode'] ?? ''));
        if ($entityCode !== strtoupper($rootEntityCode))
        {
            continue;
        }
        $fieldCode = (string)($item['fieldCode'] ?? '');
        if ($fieldCode === '')
        {
            continue;
        }
        $meta = $rootMetaByCode[$fieldCode] ?? [];
        appendOrmFilterConditionForPreview($rootFilter, $fieldCode, $meta, $filterValues, $fieldId);
    }

    return $rootFilter;
}

function appendOrmFilterConditionForPreview(array &$ormFilter, string $fieldCode, array $meta, array $filterValues, string $fieldId): void
{
    $isDateTime = isDateTimeMetaFieldForPreview($meta, $fieldCode);
    $dateRange = resolveDateRangeFromFilterForPreview($filterValues, $fieldId, $isDateTime);
    $from = $dateRange['from'];
    $to = $dateRange['to'];
    if ($from !== '' && isValidDateBoundaryForPreview($from, $isDateTime))
    {
        $ormFilter['>='.$fieldCode] = $from;
    }
    if ($to !== '' && isValidDateBoundaryForPreview($to, $isDateTime))
    {
        $ormFilter['<='.$fieldCode] = $to;
    }
    if (($from !== '' && isValidDateBoundaryForPreview($from, $isDateTime))
        || ($to !== '' && isValidDateBoundaryForPreview($to, $isDateTime)))
    {
        return;
    }

    $valueFromFilter = getFilterValueByFieldId($filterValues, $fieldId, false);
    if ($valueFromFilter === null)
    {
        return;
    }

    if (isContactFilterFieldCodeForPreview($fieldCode))
    {
        $contactIds = normalizeContactSelectorValuesForPreview($valueFromFilter);
        if (empty($contactIds))
        {
            return;
        }
        if (count($contactIds) === 1)
        {
            $ormFilter['='.$fieldCode] = $contactIds[0];
        }
        else
        {
            $ormFilter['@'.$fieldCode] = $contactIds;
        }
        return;
    }

    $userTypeId = strtolower((string)($meta['userTypeId'] ?? ''));
    if ($userTypeId === 'crm')
    {
        $crmValues = normalizeCrmUfFilterValuesForPreview($valueFromFilter, $meta);
        if (!empty($crmValues))
        {
            $ormFilter['@'.$fieldCode] = $crmValues;
        }
        return;
    }

    $value = $valueFromFilter;
    if (is_array($value))
    {
        $list = array_values(array_filter(array_map('strval', $value), static function ($v) {
            return $v !== '';
        }));
        if (!empty($list))
        {
            $ormFilter['@'.$fieldCode] = $list;
        }
        return;
    }

    $value = trim((string)$value);
    if ($value === '')
    {
        return;
    }

    if (!empty($meta['isDate']))
    {
        $ormFilter['='.$fieldCode] = $value;
        return;
    }

    $metaType = strtolower((string)($meta['type'] ?? ''));
    if (in_array($metaType, ['integer', 'double', 'float', 'money', 'boolean'], true))
    {
        $ormFilter['='.$fieldCode] = $value;
        return;
    }

    $ormFilter['%'.$fieldCode] = $value;
}

function normalizeCrmUfFilterValuesForPreview($raw, array $meta): array
{
    $values = is_array($raw) ? $raw : [$raw];
    $ids = [];
    $typed = [];

    foreach ($values as $value)
    {
        if (is_int($value) || is_float($value))
        {
            $id = (int)$value;
            if ($id > 0)
            {
                $ids[] = $id;
            }
            continue;
        }

        $text = trim((string)$value);
        if ($text === '')
        {
            continue;
        }

        if ($text[0] === '{' || $text[0] === '[')
        {
            try
            {
                $decoded = Json::decode($text);
            }
            catch (\Throwable $e)
            {
                $decoded = null;
            }

            if (is_array($decoded))
            {
                foreach ($decoded as $entityCode => $items)
                {
                    if (!is_array($items))
                    {
                        continue;
                    }
                    foreach ($items as $id)
                    {
                        $intId = (int)$id;
                        if ($intId <= 0)
                        {
                            continue;
                        }
                        $ids[] = $intId;
                        $typed[] = strtoupper((string)$entityCode).'_'.$intId;
                    }
                }
                continue;
            }
        }

        if (preg_match('/(?:^|_)(\d+)$/', $text, $m))
        {
            $id = (int)$m[1];
            if ($id > 0)
            {
                $ids[] = $id;
            }
        }
        if (preg_match('/^(DYNAMIC_\d+)_(\d+)$/i', $text, $m))
        {
            $typed[] = strtoupper($m[1]).'_'.(int)$m[2];
        }
    }

    $targets = extractCrmTargetsFromSettings((array)($meta['settings'] ?? []));
    $fieldCode = strtoupper((string)($meta['fieldCode'] ?? ''));
    if (isContactFilterFieldCodeForPreview($fieldCode))
    {
        $targets[] = 'CONTACT';
    }
    if (preg_match('/^PARENT_ID_(\d+)$/', $fieldCode, $m))
    {
        $targets[] = 'DYNAMIC_'.(int)$m[1];
    }
    $targets = array_values(array_unique(array_filter($targets)));
    foreach ($ids as $id)
    {
        foreach ($targets as $target)
        {
            if (preg_match('/^DYNAMIC_(\d+)$/', strtoupper($target)))
            {
                $typed[] = strtoupper($target).'_'.$id;
            }
        }
    }

    $result = [];
    foreach (array_merge($typed, array_map('strval', $ids)) as $value)
    {
        $value = trim((string)$value);
        if ($value !== '')
        {
            $result[] = $value;
        }
    }

    return array_values(array_unique($result));
}

function buildRootSelectForPreview(array $config, array $filterItems, string $contactFieldCode): array
{
    $select = ['ID', 'TITLE', 'CREATED_TIME', 'UPDATED_TIME'];
    $rootEntityCode = (string)($config['rootEntity'] ?? '');
    if ($contactFieldCode !== '')
    {
        $select[] = $contactFieldCode;
    }

    $nodes = indexTemplateNodes($config);
    foreach ($nodes as $node)
    {
        if (!empty($node['parentId']))
        {
            continue;
        }
        $selectedFields = is_array($node['selectedFields'] ?? null) ? $node['selectedFields'] : [];
        foreach ($selectedFields as $fieldCode)
        {
            $fieldCode = (string)$fieldCode;
            if ($fieldCode !== '')
            {
                $select[] = $fieldCode;
            }
        }
    }

    foreach ($filterItems as $item)
    {
        if (strtoupper((string)($item['entityCode'] ?? '')) !== strtoupper($rootEntityCode))
        {
            continue;
        }
        $fieldCode = (string)($item['fieldCode'] ?? '');
        if ($fieldCode !== '')
        {
            $select[] = $fieldCode;
        }
    }

    return array_values(array_unique($select));
}

function loadContactsForRootItems(array $rootItems, string $contactFieldCode, int $userId): array
{
    $result = [];
    if ($contactFieldCode === '')
    {
        return $result;
    }

    $contactIds = [];
    foreach ($rootItems as $rootItem)
    {
        $contactId = extractFirstContactIdFromItem($rootItem, $contactFieldCode);
        if ($contactId > 0)
        {
            $contactIds[] = $contactId;
        }
    }
    $contactIds = array_values(array_unique($contactIds));
    if (empty($contactIds))
    {
        return $result;
    }

    $contactFactory = getFactoryByCode('CONTACT');
    if (!$contactFactory)
    {
        return $result;
    }

    $contactSelect = buildFactorySelectFields($contactFactory, [
        'ID',
        'TITLE',
        'NAME',
        'LAST_NAME',
        'SECOND_NAME',
        'COMPANY_TITLE',
        'CREATED_TIME',
        'UPDATED_TIME',
    ]);

    $contacts = getPermissionFilteredItemsSafe($contactFactory, [
        'select' => $contactSelect,
        'filter' => ['@ID' => $contactIds],
        'limit' => count($contactIds),
    ], $userId);

    foreach ($contacts as $contactItem)
    {
        if (!is_object($contactItem) || !method_exists($contactItem, 'getId'))
        {
            continue;
        }
        $result[(int)$contactItem->getId()] = $contactItem;
    }

    return $result;
}

function extractFirstContactIdFromItem($item, string $contactFieldCode): int
{
    if (!is_object($item) || !method_exists($item, 'get') || $contactFieldCode === '')
    {
        return 0;
    }

    $value = $item->get($contactFieldCode);
    return extractFirstContactIdFromValue($value);
}

function extractFirstContactIdFromValue($value): int
{
    if (is_array($value))
    {
        foreach ($value as $part)
        {
            $id = extractFirstContactIdFromValue($part);
            if ($id > 0)
            {
                return $id;
            }
        }
        return 0;
    }

    if (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return extractFirstContactIdFromValue($value->getValue());
        }
        if (method_exists($value, '__toString'))
        {
            return extractFirstContactIdFromValue((string)$value);
        }
        return 0;
    }

    $str = trim((string)$value);
    if ($str === '')
    {
        return 0;
    }
    if (ctype_digit($str))
    {
        return (int)$str;
    }
    if (preg_match('/(?:^|_)(\d+)$/', $str, $matches))
    {
        return (int)$matches[1];
    }
    return 0;
}

function enrichRowsWithNodeItemsForPreview(array &$rowsWithContext, array $nodes, int $userId): void
{
    if (empty($rowsWithContext) || empty($nodes))
    {
        return;
    }

    $rootNodeId = '';
    foreach ($nodes as $nodeId => $node)
    {
        if (empty($node['parentId']))
        {
            $rootNodeId = (string)$nodeId;
            break;
        }
    }
    if ($rootNodeId === '')
    {
        return;
    }

    foreach ($rowsWithContext as &$rowContext)
    {
        $rowContext['nodeItems'] = [];
        $rootItem = $rowContext['rootItem'] ?? null;
        if ($rootItem && is_object($rootItem))
        {
            $rowContext['nodeItems'][$rootNodeId] = $rootItem;
        }
    }
    unset($rowContext);

    $childrenByParent = [];
    foreach ($nodes as $nodeId => $node)
    {
        $parentId = (string)($node['parentId'] ?? '');
        if ($parentId === '')
        {
            continue;
        }
        if (!isset($childrenByParent[$parentId]))
        {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = (string)$nodeId;
    }

    $queue = [$rootNodeId];
    while (!empty($queue))
    {
        $parentId = array_shift($queue);
        $childNodeIds = (array)($childrenByParent[$parentId] ?? []);
        foreach ($childNodeIds as $childNodeId)
        {
            $childNode = (array)($nodes[$childNodeId] ?? []);
            $entityCode = (string)($childNode['entityCode'] ?? '');
            $parentFieldCode = (string)($childNode['parentFieldCode'] ?? '');
            if ($entityCode === '' || $parentFieldCode === '')
            {
                $queue[] = $childNodeId;
                continue;
            }

            $factory = getFactoryByCode($entityCode);
            if (!$factory)
            {
                $queue[] = $childNodeId;
                continue;
            }

            $idsByRowIndex = [];
            $allIds = [];
            foreach ($rowsWithContext as $rowIndex => $rowContext)
            {
                $parentItem = $rowContext['nodeItems'][$parentId] ?? null;
                if (!$parentItem || !is_object($parentItem) || !method_exists($parentItem, 'get'))
                {
                    $idsByRowIndex[$rowIndex] = [];
                    continue;
                }

                $ids = extractEntityIdsFromRelationValueForPreview($parentItem->get($parentFieldCode));
                $idsByRowIndex[$rowIndex] = $ids;
                if (!empty($ids))
                {
                    $allIds = array_merge($allIds, $ids);
                }
            }

            $allIds = array_values(array_unique(array_filter(array_map('intval', $allIds), static function (int $id): bool {
                return $id > 0;
            })));
            if (empty($allIds))
            {
                $queue[] = $childNodeId;
                continue;
            }

            $selectCandidates = ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'COMPANY_TITLE', 'CREATED_TIME', 'UPDATED_TIME'];
            $selectedFields = is_array($childNode['selectedFields'] ?? null) ? $childNode['selectedFields'] : [];
            foreach ($selectedFields as $fieldCode)
            {
                $fieldCode = trim((string)$fieldCode);
                if ($fieldCode !== '')
                {
                    $selectCandidates[] = $fieldCode;
                }
            }
            $nextChildren = (array)($childrenByParent[$childNodeId] ?? []);
            foreach ($nextChildren as $nextChildId)
            {
                $nextFieldCode = trim((string)($nodes[$nextChildId]['parentFieldCode'] ?? ''));
                if ($nextFieldCode !== '')
                {
                    $selectCandidates[] = $nextFieldCode;
                }
            }
            $select = buildFactorySelectFields($factory, array_values(array_unique($selectCandidates)));

            $items = getPermissionFilteredItemsSafe($factory, [
                'select' => $select,
                'filter' => ['@ID' => $allIds],
                'limit' => count($allIds),
            ], $userId);

            $itemsById = [];
            foreach ($items as $item)
            {
                if (!is_object($item) || !method_exists($item, 'getId'))
                {
                    continue;
                }
                $itemsById[(int)$item->getId()] = $item;
            }

            foreach ($rowsWithContext as $rowIndex => &$rowContext)
            {
                $picked = null;
                foreach ((array)($idsByRowIndex[$rowIndex] ?? []) as $id)
                {
                    $id = (int)$id;
                    if ($id > 0 && isset($itemsById[$id]))
                    {
                        $picked = $itemsById[$id];
                        break;
                    }
                }
                if ($picked !== null)
                {
                    if (!isset($rowContext['nodeItems']) || !is_array($rowContext['nodeItems']))
                    {
                        $rowContext['nodeItems'] = [];
                    }
                    $rowContext['nodeItems'][$childNodeId] = $picked;
                }
            }
            unset($rowContext);

            $queue[] = $childNodeId;
        }
    }
}

function extractEntityIdsFromRelationValueForPreview($value): array
{
    if ($value === null)
    {
        return [];
    }

    if (is_array($value))
    {
        $ids = [];
        foreach ($value as $part)
        {
            $ids = array_merge($ids, extractEntityIdsFromRelationValueForPreview($part));
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    if (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return extractEntityIdsFromRelationValueForPreview($value->getValue());
        }
        if (method_exists($value, '__toString'))
        {
            return extractEntityIdsFromRelationValueForPreview((string)$value);
        }
        return [];
    }

    $text = trim((string)$value);
    if ($text === '')
    {
        return [];
    }

    if (ctype_digit($text))
    {
        $id = (int)$text;
        return $id > 0 ? [$id] : [];
    }

    if ($text[0] === '{' || $text[0] === '[')
    {
        try
        {
            $decoded = Json::decode($text);
        }
        catch (\Throwable $e)
        {
            $decoded = null;
        }
        if ($decoded !== null)
        {
            return extractEntityIdsFromRelationValueForPreview($decoded);
        }
    }

    if (preg_match_all('/(?:^|_)(\d+)(?:$|[^0-9])/', $text, $matches) && !empty($matches[1]))
    {
        return array_values(array_unique(array_filter(array_map('intval', $matches[1]), static function (int $id): bool {
            return $id > 0;
        })));
    }

    return [];
}

function normalizeContactSelectorValuesForPreview($raw): array
{
    $values = is_array($raw) ? $raw : [$raw];
    $ids = [];
    foreach ($values as $value)
    {
        if (is_array($value))
        {
            $ids = array_merge($ids, normalizeContactSelectorValuesForPreview($value));
            continue;
        }

        if (is_int($value) || is_float($value))
        {
            $id = (int)$value;
            if ($id > 0)
            {
                $ids[] = $id;
            }
            continue;
        }

        $text = trim((string)$value);
        if ($text === '')
        {
            continue;
        }

        if ($text[0] === '{' || $text[0] === '[')
        {
            try
            {
                $decoded = Json::decode($text);
            }
            catch (\Throwable $e)
            {
                $decoded = null;
            }

            if ($decoded !== null)
            {
                $ids = array_merge($ids, normalizeContactSelectorValuesForPreview($decoded));
                continue;
            }
        }

        $id = extractFirstContactIdFromValue($text);
        if ($id > 0)
        {
            $ids[] = $id;
        }
    }

    return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
}

function buildContactFilterRulesForPreview(array $filterValues, array $filterIndex, array $contactMetaByCode): array
{
    $rules = [];
    foreach ($filterIndex as $fieldId => $item)
    {
        $entityCode = strtoupper((string)($item['entityCode'] ?? ''));
        if ($entityCode !== 'CONTACT')
        {
            continue;
        }
        $fieldCode = (string)($item['fieldCode'] ?? '');
        if ($fieldCode === '')
        {
            continue;
        }
        $meta = $contactMetaByCode[$fieldCode] ?? [];
        $rule = buildFilterRuleForPreview($fieldCode, $meta, $filterValues, $fieldId);
        if ($rule !== null)
        {
            $rules[] = $rule;
        }
    }
    return $rules;
}

function buildFilterRuleForPreview(string $fieldCode, array $meta, array $filterValues, string $fieldId): ?array
{
    $isDateTime = isDateTimeMetaFieldForPreview($meta, $fieldCode);
    $dateRange = resolveDateRangeFromFilterForPreview($filterValues, $fieldId, $isDateTime);
    $from = $dateRange['from'];
    $to = $dateRange['to'];
    if ($from !== '' || $to !== '')
    {
        return [
            'fieldCode' => $fieldCode,
            'type' => 'range',
            'from' => $from,
            'to' => $to,
        ];
    }

    $raw = getFilterValueByFieldId($filterValues, $fieldId, false);
    if ($raw === null)
    {
        return null;
    }

    if (isContactFilterFieldCodeForPreview($fieldCode))
    {
        $contactIds = normalizeContactSelectorValuesForPreview($raw);
        if (empty($contactIds))
        {
            return null;
        }

        if (count($contactIds) === 1)
        {
            return [
                'fieldCode' => $fieldCode,
                'type' => 'eq',
                'value' => (string)$contactIds[0],
            ];
        }

        return [
            'fieldCode' => $fieldCode,
            'type' => 'in',
            'values' => array_map('strval', $contactIds),
        ];
    }

    $userTypeId = strtolower((string)($meta['userTypeId'] ?? ''));
    if ($userTypeId === 'crm')
    {
        $crmValues = normalizeCrmUfFilterValuesForPreview($raw, $meta);
        if (empty($crmValues))
        {
            return null;
        }
        return [
            'fieldCode' => $fieldCode,
            'type' => 'in',
            'values' => $crmValues,
        ];
    }
    if (is_array($raw))
    {
        $vals = array_values(array_filter(array_map('strval', $raw), static function ($v) {
            return $v !== '';
        }));
        if (empty($vals))
        {
            return null;
        }
        return [
            'fieldCode' => $fieldCode,
            'type' => 'in',
            'values' => $vals,
        ];
    }

    $value = trim((string)$raw);
    if ($value === '')
    {
        return null;
    }

    $metaType = strtolower((string)($meta['type'] ?? ''));
    if (in_array($metaType, ['integer', 'double', 'float', 'money', 'boolean'], true))
    {
        return [
            'fieldCode' => $fieldCode,
            'type' => 'eq',
            'value' => $value,
        ];
    }

    return [
        'fieldCode' => $fieldCode,
        'type' => 'contains',
        'value' => mb_strtolower($value),
    ];
}

function matchContactRulesForPreview($contactItem, array $rules): bool
{
    if (empty($rules))
    {
        return true;
    }
    if (!$contactItem || !is_object($contactItem) || !method_exists($contactItem, 'get'))
    {
        return false;
    }

    foreach ($rules as $rule)
    {
        $fieldCode = (string)($rule['fieldCode'] ?? '');
        $fieldValue = $contactItem->get($fieldCode);
        if (!matchSingleRuleForPreview($fieldValue, $rule))
        {
            return false;
        }
    }
    return true;
}

function isContactFilterFieldCodeForPreview(string $fieldCode): bool
{
    $fieldCode = strtoupper(trim($fieldCode));
    if ($fieldCode === '')
    {
        return false;
    }

    return $fieldCode === 'CONTACT_ID' || (bool)preg_match('/_CONTACT_ID$/', $fieldCode);
}

function matchSingleRuleForPreview($fieldValue, array $rule): bool
{
    $type = (string)($rule['type'] ?? '');
    if ($type === 'range')
    {
        $from = (string)($rule['from'] ?? '');
        $to = (string)($rule['to'] ?? '');
        $fromTs = $from !== '' ? parseDateValueToTimestampForPreview($from) : null;
        $toTs = $to !== '' ? parseDateValueToTimestampForPreview($to) : null;

        $values = normalizeFieldValuesForComparisonForPreview($fieldValue);
        if (empty($values))
        {
            return false;
        }

        foreach ($values as $value)
        {
            $valueTs = parseDateValueToTimestampForPreview($value);
            if ($valueTs === null)
            {
                // Fallback for non-date strings.
                if ($from !== '' && $value < $from)
                {
                    continue;
                }
                if ($to !== '' && $value > $to)
                {
                    continue;
                }
                return true;
            }

            if ($fromTs !== null && $valueTs < $fromTs)
            {
                continue;
            }
            if ($toTs !== null && $valueTs > $toTs)
            {
                continue;
            }

            return true;
        }

        return false;
    }

    if ($type === 'in')
    {
        $needles = array_values(array_filter(array_map('strval', (array)($rule['values'] ?? [])), static function (string $v): bool {
            return trim($v) !== '';
        }));
        if (empty($needles))
        {
            return false;
        }

        $haystack = normalizeFieldValuesForComparisonForPreview($fieldValue);
        if (empty($haystack))
        {
            return false;
        }

        foreach ($haystack as $value)
        {
            if (in_array($value, $needles, true))
            {
                return true;
            }
        }

        return false;
    }

    if ($type === 'eq')
    {
        $expected = trim((string)($rule['value'] ?? ''));
        if ($expected === '')
        {
            return false;
        }

        $values = normalizeFieldValuesForComparisonForPreview($fieldValue);
        if (empty($values))
        {
            return false;
        }

        return in_array($expected, $values, true);
    }

    if ($type === 'contains')
    {
        $needle = (string)($rule['value'] ?? '');
        if ($needle === '')
        {
            return false;
        }

        $values = normalizeFieldValuesForComparisonForPreview($fieldValue);
        foreach ($values as $value)
        {
            if (mb_stripos($value, $needle) !== false)
            {
                return true;
            }
        }

        return false;
    }

    return true;
}

function normalizeFieldValuesForComparisonForPreview($value): array
{
    if ($value === null)
    {
        return [];
    }

    if (is_array($value))
    {
        $result = [];
        foreach ($value as $part)
        {
            $result = array_merge($result, normalizeFieldValuesForComparisonForPreview($part));
        }
        return array_values(array_unique(array_filter($result, static function (string $v): bool {
            return $v !== '';
        })));
    }

    if (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return normalizeFieldValuesForComparisonForPreview($value->getValue());
        }
        if (method_exists($value, '__toString'))
        {
            return normalizeFieldValuesForComparisonForPreview((string)$value);
        }
        return [];
    }

    $text = trim((string)$value);
    if ($text === '')
    {
        return [];
    }

    return [$text];
}

function buildPreviewColumns(array $config, array $nodes): array
{
    $rows = [];
    $metaByEntity = [];
    $levelByNode = [];

    foreach ($nodes as $nodeId => $_node)
    {
        $levelByNode[$nodeId] = resolveNodeLevelForPreview($nodes, $nodeId);
    }

    foreach ($nodes as $nodeId => $node)
    {
        $entityCode = (string)($node['entityCode'] ?? '');
        $entityTitle = (string)($node['entityTitle'] ?? $entityCode);
        if ($entityCode === '')
        {
            continue;
        }

        if (!isset($metaByEntity[$entityCode]))
        {
            $metaByEntity[$entityCode] = buildMetaMapForPreview(getEntityMeta($entityCode));
        }

        $selectedFields = is_array($node['selectedFields'] ?? null) ? $node['selectedFields'] : [];
        foreach ($selectedFields as $fieldCode)
        {
            $fieldCode = (string)$fieldCode;
            if ($fieldCode === '')
            {
                continue;
            }

            $meta = (array)($metaByEntity[$entityCode][$fieldCode] ?? []);
            $fieldTitle = trim((string)($meta['title'] ?? ''));
            if ($fieldTitle === '')
            {
                $fieldTitle = $fieldCode;
            }

            $level = (int)($levelByNode[$nodeId] ?? 1);
            $source = $entityTitle.' / Уровень '.$level;
            $rows[] = [
                'key' => $nodeId.'::'.$fieldCode,
                'nodeId' => $nodeId,
                'entityCode' => $entityCode,
                'fieldCode' => $fieldCode,
                'title' => $fieldTitle,
                'source' => $source,
            ];
        }
    }

    $order = is_array($config['columnOrder'] ?? null) ? $config['columnOrder'] : [];
    if (!empty($order))
    {
        $byKey = [];
        foreach ($rows as $row)
        {
            $byKey[(string)$row['key']] = $row;
        }

        $ordered = [];
        foreach ($order as $key)
        {
            $key = (string)$key;
            if (isset($byKey[$key]))
            {
                $ordered[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }
        foreach ($byKey as $row)
        {
            $ordered[] = $row;
        }
        $rows = $ordered;
    }

    // If titles repeat (e.g. "Название"), append source for clarity.
    $titleCounts = [];
    foreach ($rows as $row)
    {
        $title = (string)($row['title'] ?? '');
        $titleCounts[$title] = (int)($titleCounts[$title] ?? 0) + 1;
    }
    foreach ($rows as &$row)
    {
        $title = (string)($row['title'] ?? '');
        if ($title !== '' && (int)($titleCounts[$title] ?? 0) > 1)
        {
            $row['title'] = $title.' ('.$row['source'].')';
        }
    }
    unset($row);

    return $rows;
}

function resolveNodeLevelForPreview(array $nodes, string $nodeId): int
{
    $level = 1;
    $currentId = $nodeId;
    $guard = 0;
    while (isset($nodes[$currentId]) && $guard < 100)
    {
        $parentId = (string)($nodes[$currentId]['parentId'] ?? '');
        if ($parentId === '' || !isset($nodes[$parentId]))
        {
            break;
        }
        $level++;
        $currentId = $parentId;
        $guard++;
    }

    return $level;
}

function buildPreviewRows(array $rowsWithContext, array $columns, string $contactFieldCode): array
{
    $result = [];
    foreach ($rowsWithContext as $rowContext)
    {
        $rootItem = $rowContext['rootItem'] ?? null;
        if (!$rootItem || !is_object($rootItem) || !method_exists($rootItem, 'getId'))
        {
            continue;
        }

        $nodeItems = is_array($rowContext['nodeItems'] ?? null) ? $rowContext['nodeItems'] : [];
        $cells = [];
        foreach ($columns as $column)
        {
            $key = (string)($column['key'] ?? '');
            $fieldCode = (string)($column['fieldCode'] ?? '');
            $nodeId = (string)($column['nodeId'] ?? '');
            if ($key === '' || $fieldCode === '' || $nodeId === '')
            {
                continue;
            }

            $nodeItem = $nodeItems[$nodeId] ?? null;
            if (!$nodeItem || !is_object($nodeItem) || !method_exists($nodeItem, 'get'))
            {
                $cells[$key] = '';
                continue;
            }

            $cells[$key] = stringifyPreviewValue($nodeItem->get($fieldCode));
        }

        $result[] = [
            'id' => (int)$rootItem->getId(),
            'contactId' => extractFirstContactIdFromItem($rootItem, $contactFieldCode),
            'cells' => $cells,
        ];
    }

    return $result;
}

function stringifyPreviewValue($value): string
{
    if (is_array($value))
    {
        $parts = [];
        foreach ($value as $part)
        {
            $parts[] = stringifyPreviewValue($part);
        }
        $parts = array_values(array_filter($parts, static function ($v) {
            return $v !== '';
        }));
        return implode(', ', $parts);
    }
    if (is_object($value))
    {
        if (method_exists($value, '__toString'))
        {
            return trim((string)$value);
        }
        if (method_exists($value, 'getValue'))
        {
            return stringifyPreviewValue($value->getValue());
        }
        return '';
    }
    return trim((string)$value);
}

function sortPreviewRows(array $rows, string $columnKey, string $direction = 'asc'): array
{
    $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
    usort($rows, static function (array $a, array $b) use ($columnKey, $direction) {
        $av = (string)($a['cells'][$columnKey] ?? '');
        $bv = (string)($b['cells'][$columnKey] ?? '');
        if ($av === $bv)
        {
            return 0;
        }
        if ($direction === 'asc')
        {
            return $av <=> $bv;
        }
        return $bv <=> $av;
    });
    return $rows;
}

function getFilterValueByFieldId(array $filterValues, string $fieldId, bool $emptyDefault = true)
{
    if (array_key_exists($fieldId, $filterValues))
    {
        return $filterValues[$fieldId];
    }

    foreach ($filterValues as $key => $value)
    {
        $key = (string)$key;
        if (preg_match('/^'.preg_quote($fieldId, '/').'_GNC_OTCHET_FILTER_\\d+$/', $key))
        {
            return $value;
        }
    }

    return $emptyDefault ? '' : null;
}

function isDateTimeMetaFieldForPreview(array $meta, string $fieldCode): bool
{
    $type = strtolower((string)($meta['type'] ?? ''));
    if ($type === 'datetime')
    {
        return true;
    }

    $upper = strtoupper(trim($fieldCode));
    if (strpos($upper, 'TIME') !== false)
    {
        return true;
    }

    return false;
}

function normalizePeriodBoundaryValue(string $value, bool $isFrom, bool $isDateTime): string
{
    $value = trim($value);
    if ($value === '' || !$isDateTime)
    {
        return $value;
    }

    if (preg_match('/^\\d{2}\\.\\d{2}\\.\\d{4}$/', $value))
    {
        return $value.' '.($isFrom ? '00:00:00' : '23:59:59');
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value))
    {
        return $value.' '.($isFrom ? '00:00:00' : '23:59:59');
    }

    return $value;
}

function resolveDateRangeFromFilterForPreview(array $filterValues, string $fieldId, bool $isDateTime): array
{
    $fromRaw = trim((string)getFilterValueByFieldId($filterValues, $fieldId.'_from'));
    $toRaw = trim((string)getFilterValueByFieldId($filterValues, $fieldId.'_to'));
    $dateSel = strtoupper(trim((string)getFilterValueByFieldId($filterValues, $fieldId.'_datesel')));

    $from = $fromRaw;
    $to = $toRaw;

    // Explicit range/values have priority.
    if ($from !== '' || $to !== '')
    {
        return [
            'from' => normalizePeriodBoundaryValue($from, true, $isDateTime),
            'to' => normalizePeriodBoundaryValue($to, false, $isDateTime),
        ];
    }

    $today = new \DateTimeImmutable('today');
    $start = null;
    $end = null;

    switch ($dateSel)
    {
        case 'YESTERDAY':
            $start = $today->modify('-1 day');
            $end = $start;
            break;
        case 'CURRENT_DAY':
            $start = $today;
            $end = $today;
            break;
        case 'TOMORROW':
            $start = $today->modify('+1 day');
            $end = $start;
            break;
        case 'CURRENT_WEEK':
            $start = $today->modify('monday this week');
            $end = $today->modify('sunday this week');
            break;
        case 'LAST_WEEK':
            $start = $today->modify('monday last week');
            $end = $today->modify('sunday last week');
            break;
        case 'NEXT_WEEK':
            $start = $today->modify('monday next week');
            $end = $today->modify('sunday next week');
            break;
        case 'CURRENT_MONTH':
            $start = $today->modify('first day of this month');
            $end = $today->modify('last day of this month');
            break;
        case 'LAST_MONTH':
            $start = $today->modify('first day of last month');
            $end = $today->modify('last day of last month');
            break;
        case 'NEXT_MONTH':
            $start = $today->modify('first day of next month');
            $end = $today->modify('last day of next month');
            break;
        case 'CURRENT_QUARTER':
            $month = (int)$today->format('n');
            $quarterStartMonth = (int)(floor(($month - 1) / 3) * 3) + 1;
            $start = $today->setDate((int)$today->format('Y'), $quarterStartMonth, 1);
            $end = $start->modify('+2 month')->modify('last day of this month');
            break;
        case 'LAST_7_DAYS':
        case 'LAST_30_DAYS':
        case 'LAST_60_DAYS':
        case 'LAST_90_DAYS':
            $days = (int)str_replace(['LAST_', '_DAYS'], '', $dateSel);
            if ($days > 0)
            {
                $start = $today->modify('-'.($days - 1).' day');
                $end = $today;
            }
            break;
        case 'PREV_DAYS':
            $days = (int)trim((string)getFilterValueByFieldId($filterValues, $fieldId.'_days'));
            if ($days > 0)
            {
                $start = $today->modify('-'.($days - 1).' day');
                $end = $today;
            }
            break;
        case 'NEXT_DAYS':
            $days = (int)trim((string)getFilterValueByFieldId($filterValues, $fieldId.'_days'));
            if ($days > 0)
            {
                $start = $today;
                $end = $today->modify('+'.($days - 1).' day');
            }
            break;
        case 'EXACT':
            $exact = trim((string)getFilterValueByFieldId($filterValues, $fieldId));
            if ($exact !== '')
            {
                $from = $exact;
                $to = $exact;
            }
            break;
        default:
            break;
    }

    if ($start instanceof \DateTimeInterface)
    {
        $from = $start->format('Y-m-d');
    }
    if ($end instanceof \DateTimeInterface)
    {
        $to = $end->format('Y-m-d');
    }

    return [
        'from' => normalizePeriodBoundaryValue($from, true, $isDateTime),
        'to' => normalizePeriodBoundaryValue($to, false, $isDateTime),
    ];
}

function isValidDateBoundaryForPreview(string $value, bool $isDateTime): bool
{
    $value = trim($value);
    if ($value === '')
    {
        return false;
    }

    if ($isDateTime)
    {
        return (bool)preg_match('/^\\d{4}-\\d{2}-\\d{2}\\s\\d{2}:\\d{2}:\\d{2}$/', $value)
            || (bool)preg_match('/^\\d{2}\\.\\d{2}\\.\\d{4}\\s\\d{2}:\\d{2}:\\d{2}$/', $value);
    }

    return (bool)preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)
        || (bool)preg_match('/^\\d{2}\\.\\d{2}\\.\\d{4}$/', $value);
}

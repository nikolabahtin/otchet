<?php
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

global $USER, $USER_FIELD_MANAGER, $APPLICATION;

if (!$USER || !$USER->IsAuthorized())
{
    echo '<h2>Please login</h2>';
    die();
}

if (!Loader::includeModule('main'))
{
    echo 'Module "main" is required';
    die();
}

if (!Loader::includeModule('crm'))
{
    echo 'Module "crm" is required';
    die();
}

$extensionLoadLog = [];
\CJSCore::Init(['ajax', 'main.ui.selector', 'ui.entity-selector']);
foreach ([
    'main.ui.filter',
    'ui.buttons',
    'ui.forms',
    'ui.notification',
    'ui.entity-selector',
    'crm.entity-selector',
    'main.ui.selector',
] as $extension)
{
    try
    {
        Extension::load([$extension]);
        $extensionLoadLog[] = ['extension' => $extension, 'loaded' => true];
    }
    catch (\Throwable $e)
    {
        $extensionLoadLog[] = ['extension' => $extension, 'loaded' => false, 'error' => $e->getMessage()];
    }
}

$dynamic = pickDynamicType();
$dynamicEntityCode = $dynamic['entityCode'];
$dynamicMeta = collectEntityMeta($dynamicEntityCode);
$contactMeta = collectEntityMeta('CONTACT');

$selected = pickTestFields($dynamic, $dynamicMeta, $contactMeta);
$filterFields = buildTestFilterFields($dynamic, $selected);

$filterId = 'GNC_FILTER_PUBLIC_TEST';
$gridId = 'GNC_FILTER_PUBLIC_TEST_GRID';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>GNC Public Filter Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 12px; }
        .title { font-size: 24px; margin-bottom: 10px; }
        .meta { margin-bottom: 10px; color: #3b4b5b; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0; }
        .actions input { min-width: 220px; padding: 6px 8px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .panel { border: 1px solid #d6dde5; border-radius: 8px; padding: 8px; background: #fff; }
        .panel h3 { margin: 0 0 6px; font-size: 14px; }
        pre { margin: 0; max-height: 320px; overflow: auto; white-space: pre-wrap; font-size: 12px; background: #f7fbff; border: 1px solid #dce5ee; border-radius: 6px; padding: 8px; }
        @media (max-width: 1000px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="title">GNC Public Filter Test</div>
<div class="meta">
    dynamicTypeId: <b><?=htmlspecialcharsbx((string)$dynamic['entityTypeId'])?></b>,
    title: <b><?=htmlspecialcharsbx((string)$dynamic['title'])?></b>,
    entityCode: <b><?=htmlspecialcharsbx((string)$dynamic['entityCode'])?></b>
</div>

<?php
$APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', [
    'FILTER_ID' => $filterId,
    'GRID_ID' => $gridId,
    'FILTER' => $filterFields,
    'ENABLE_LABEL' => true,
    'ENABLE_LIVE_SEARCH' => true,
    'DISABLE_SEARCH' => false,
    'ENABLE_FIELDS_SEARCH' => true,
    'VALUE_REQUIRED_MODE' => false,
], false);
?>

<div style="display:none;">
    <?php
    $APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
        'GRID_ID' => $gridId,
        'COLUMNS' => [['id' => 'ID', 'name' => 'ID', 'default' => true]],
        'ROWS' => [],
        'SHOW_ROW_CHECKBOXES' => false,
        'SHOW_CHECK_ALL_CHECKBOXES' => false,
        'SHOW_ROW_ACTIONS_MENU' => false,
        'SHOW_GRID_SETTINGS_MENU' => false,
        'SHOW_NAVIGATION_PANEL' => false,
        'SHOW_PAGINATION' => false,
        'SHOW_SELECTED_COUNTER' => false,
        'SHOW_TOTAL_COUNTER' => false,
        'SHOW_PAGESIZE' => false,
        'SHOW_ACTION_PANEL' => false,
        'ALLOW_COLUMNS_SORT' => false,
        'ALLOW_SORT' => false,
        'AJAX_MODE' => 'Y',
        'AJAX_OPTION_HISTORY' => 'N',
        'AJAX_OPTION_STYLE' => 'N',
    ], false);
    ?>
</div>

<div class="actions">
    <button type="button" class="ui-btn ui-btn-primary" id="showValuesBtn">Показать значения фильтра</button>
    <button type="button" class="ui-btn ui-btn-light-border" id="resetBtn">Сброс</button>
    <input type="text" id="fallbackQuery" value="ни" placeholder="Запрос для fallback поиска">
    <button type="button" class="ui-btn ui-btn-light-border" id="fallbackBtn">Проверить backend поиск (fallback)</button>
</div>

<div class="grid">
    <div class="panel"><h3>Логи</h3><pre id="logBox"></pre></div>
    <div class="panel"><h3>Текущие значения filter.getFilterFieldsValues()</h3><pre id="valuesBox">Нажмите кнопку...</pre></div>
    <div class="panel"><h3>selectedFields (автоподбор)</h3><pre><?=htmlspecialcharsbx(Json::encode($selected, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre></div>
    <div class="panel"><h3>filterFields (передан в компонент)</h3><pre><?=htmlspecialcharsbx(Json::encode($filterFields, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre></div>
    <div class="panel" style="grid-column: 1 / -1;"><h3>Расширения</h3><pre><?=htmlspecialcharsbx(Json::encode($extensionLoadLog, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre></div>
</div>

<script>
(function () {
    var filterId = '<?=$filterId?>';
    var dynamicTypeId = <?= (int)$dynamic['entityTypeId'] ?>;
    var dynamicEntityCode = '<?=CUtil::JSEscape((string)$dynamic['entityCode'])?>';
    var logBox = document.getElementById('logBox');
    var valuesBox = document.getElementById('valuesBox');

    function now() { return new Date().toISOString(); }
    function stringify(v){ try { return JSON.stringify(v, null, 2); } catch (e) { return String(v); } }
    function preview(v){ var s = typeof v === 'string' ? v : stringify(v); return s.length > 1000 ? s.slice(0,1000)+' ...' : s; }
    function log(level, message, data) {
        var line = '['+now()+'] ['+level+'] '+message;
        if (typeof data !== 'undefined') { line += '\n' + stringify(data); }
        logBox.textContent += (logBox.textContent ? '\n' : '') + line + '\n';
        logBox.scrollTop = logBox.scrollHeight;
        console.log(line, data);
    }

    function getFilter() {
        return window.BX && BX.Main && BX.Main.filterManager ? BX.Main.filterManager.getById(filterId) : null;
    }

    function getValues() {
        var f = getFilter();
        if (!f) { return null; }
        if (typeof f.getFilterFieldsValues === 'function') { return f.getFilterFieldsValues(); }
        if (f.getApi && f.getApi() && typeof f.getApi().getFilterFieldsValues === 'function') { return f.getApi().getFilterFieldsValues(); }
        return null;
    }

    if (window.BX && BX.ajax && !BX.__GNC_PUBLIC_FILTER_AJAX_PATCHED__) {
        BX.__GNC_PUBLIC_FILTER_AJAX_PATCHED__ = true;
        var oldAjax = BX.ajax;
        BX.ajax = function (config) {
            var url = config && config.url ? String(config.url) : '';
            var watch = url.indexOf('action=ui.entityselector.load') >= 0
                || url.indexOf('action=ui.entityselector.doSearch') >= 0
                || url.indexOf('c=bitrix%3Amain.ui.selector') >= 0
                || url.indexOf('c=bitrix:main.ui.selector') >= 0;
            if (!watch) { return oldAjax.apply(BX, arguments); }

            log('XHR_REQ', url, { method: String(config.method || 'POST'), bodyPreview: preview(config.data || {}) });
            var onSuccess = config.onsuccess;
            var onFailure = config.onfailure;
            config.onsuccess = function (res) {
                log('XHR_RES', url, { bodyPreview: preview(res) });
                if (typeof onSuccess === 'function') { return onSuccess.apply(this, arguments); }
            };
            config.onfailure = function (err) {
                log('XHR_ERR', url, { error: String(err && err.message ? err.message : err) });
                if (typeof onFailure === 'function') { return onFailure.apply(this, arguments); }
            };
            return oldAjax.call(BX, config);
        };
    }

    (function hookEntitySelector() {
        var attempts = 0;
        var timer = setInterval(function () {
            attempts++;
            var proto = window.BX && BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.Dialog && BX.UI.EntitySelector.Dialog.prototype;
            if (!proto) {
                if (attempts > 40) { clearInterval(timer); log('WARN', 'EntitySelector prototype not found'); }
                return;
            }
            clearInterval(timer);
            if (proto.__GNC_PUBLIC_FILTER_HOOKED__) { return; }
            proto.__GNC_PUBLIC_FILTER_HOOKED__ = true;
            if (typeof proto.show === 'function') {
                var oldShow = proto.show;
                proto.show = function () {
                    log('ENTITY_SELECTOR_SHOW', 'Dialog show', { id: this.getId ? this.getId() : '', context: this.getContext ? this.getContext() : '' });
                    return oldShow.apply(this, arguments);
                };
            }
            if (typeof proto.search === 'function') {
                var oldSearch = proto.search;
                proto.search = function (query) {
                    log('ENTITY_SELECTOR_SEARCH', 'Dialog search', { id: this.getId ? this.getId() : '', context: this.getContext ? this.getContext() : '', query: String(query || '') });
                    return oldSearch.apply(this, arguments);
                };
            }
            log('INFO', 'EntitySelector hooks installed');
        }, 150);
    })();

    document.getElementById('showValuesBtn').addEventListener('click', function () {
        var values = getValues();
        valuesBox.textContent = stringify(values || {});
        log('INFO', 'Current filter values', values || {});
    });

    document.getElementById('resetBtn').addEventListener('click', function () {
        var f = getFilter();
        if (!f) { log('WARN', 'Filter instance not ready'); return; }
        if (f.getApi && f.getApi() && typeof f.getApi().reset === 'function') {
            f.getApi().reset();
        } else if (typeof f.reset === 'function') {
            f.reset();
        }
        log('INFO', 'Reset triggered');
    });

    function fallbackSearch(entityCode, query) {
        return new Promise(function (resolve, reject) {
            BX.ajax({
                url: '/local/otchet/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    sessid: BX.bitrix_sessid(),
                    action: 'searchEntityItems',
                    entityCode: entityCode,
                    query: query,
                    limit: 20
                },
                onsuccess: resolve,
                onfailure: reject
            });
        });
    }

    document.getElementById('fallbackBtn').addEventListener('click', function () {
        var query = (document.getElementById('fallbackQuery').value || 'ни').trim() || 'ни';
        log('INFO', 'Fallback search started', { query: query, dynamicTypeId: dynamicTypeId, dynamicEntityCode: dynamicEntityCode });
        Promise.all([
            fallbackSearch('CONTACT', query),
            fallbackSearch(dynamicEntityCode, query)
        ]).then(function (responses) {
            log('INFO', 'Fallback CONTACT response', responses[0]);
            log('INFO', 'Fallback DYNAMIC response', responses[1]);
        }).catch(function (err) {
            log('ERROR', 'Fallback search failed', { error: String(err && err.message ? err.message : err) });
        });
    });

    if (window.BX && typeof BX.addCustomEvent === 'function') {
        BX.addCustomEvent(window, 'BX.Main.Filter:apply', function (id) {
            if (String(id || '') !== filterId) { return; }
            log('INFO', 'Filter apply', getValues() || {});
        });
        BX.addCustomEvent(window, 'BX.Main.Filter:reset', function (id) {
            if (String(id || '') !== filterId) { return; }
            log('INFO', 'Filter reset', getValues() || {});
        });
    }

    log('INFO', 'Page loaded', {
        templateId: 'public_test',
        filterId: filterId,
        gridId: '<?=$gridId?>',
        filterFieldsCount: <?=count($filterFields)?>,
        filterFieldIds: <?=Json::encode(array_map(static function ($f) { return (string)($f['id'] ?? ''); }, $filterFields))?>,
        configuredFieldTypes: <?=Json::encode(array_map(static function ($f) {
            return ['id' => (string)($f['id'] ?? ''), 'type' => strtoupper((string)($f['type'] ?? ''))];
        }, $filterFields))?>
    });
})();
</script>
</body>
</html>
<?php

function pickDynamicType(): array
{
    $types = TypeTable::getList([
        'select' => ['ENTITY_TYPE_ID', 'TITLE'],
        'order' => ['ENTITY_TYPE_ID' => 'ASC'],
    ])->fetchAll();

    foreach ($types as $type)
    {
        $entityTypeId = (int)($type['ENTITY_TYPE_ID'] ?? 0);
        if ($entityTypeId <= 0)
        {
            continue;
        }
        $factory = Container::getInstance()->getFactory($entityTypeId);
        if (!$factory)
        {
            continue;
        }
        $fieldsInfo = (array)$factory->getFieldsInfo();
        if (empty($fieldsInfo))
        {
            continue;
        }
        return [
            'entityTypeId' => $entityTypeId,
            'title' => (string)($type['TITLE'] ?? ('Dynamic '.$entityTypeId)),
            'entityCode' => 'DYNAMIC_'.$entityTypeId,
        ];
    }

    return [
        'entityTypeId' => 1038,
        'title' => 'Fallback Dynamic 1038',
        'entityCode' => 'DYNAMIC_1038',
    ];
}

function collectEntityMeta(string $entityCode): array
{
    global $USER_FIELD_MANAGER;

    $factory = getFactoryByCode($entityCode);
    $fieldsInfo = $factory ? (array)$factory->getFieldsInfo() : [];
    $entityId = resolveUserFieldEntityId($entityCode);
    $ufMap = [];
    if ($entityId !== '' && $USER_FIELD_MANAGER)
    {
        $ufMap = (array)$USER_FIELD_MANAGER->GetUserFields($entityId, 0, LANGUAGE_ID);
    }

    $result = [];
    foreach ($fieldsInfo as $code => $info)
    {
        $code = (string)$code;
        if ($code === '') { continue; }
        $info = is_array($info) ? $info : [];
        $uf = is_array($ufMap[$code] ?? null) ? $ufMap[$code] : [];

        $type = strtolower((string)($info['TYPE'] ?? 'string'));
        $userTypeId = strtolower((string)($uf['USER_TYPE_ID'] ?? ''));
        $isMultiple = detectIsMultiple($info, $uf);
        $items = extractItems($info, $uf);
        $title = pickFieldTitle($code, $info, $uf);

        $entityTypeId = resolveLinkedEntityTypeId($code, $info, $uf);

        $result[$code] = [
            'code' => $code,
            'title' => $title,
            'type' => $type,
            'userTypeId' => $userTypeId,
            'isMultiple' => $isMultiple,
            'items' => $items,
            'entityTypeId' => $entityTypeId,
            'rawInfo' => $info,
            'rawUf' => $uf,
        ];
    }

    foreach ($ufMap as $code => $uf)
    {
        $code = (string)$code;
        if ($code === '' || isset($result[$code])) { continue; }
        $uf = is_array($uf) ? $uf : [];
        $userTypeId = strtolower((string)($uf['USER_TYPE_ID'] ?? 'string'));
        $items = extractItems([], $uf);
        $result[$code] = [
            'code' => $code,
            'title' => pickFieldTitle($code, [], $uf),
            'type' => $userTypeId,
            'userTypeId' => $userTypeId,
            'isMultiple' => detectIsMultiple([], $uf),
            'items' => $items,
            'entityTypeId' => resolveLinkedEntityTypeId($code, [], $uf),
            'rawInfo' => [],
            'rawUf' => $uf,
        ];
    }

    return $result;
}

function getFactoryByCode(string $entityCode)
{
    $entityCode = strtoupper(trim($entityCode));
    $container = Container::getInstance();
    if ($entityCode === 'CONTACT') { return $container->getFactory(\CCrmOwnerType::Contact); }
    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        $id = (int)substr($entityCode, 8);
        if ($id > 0) { return $container->getFactory($id); }
    }
    return null;
}

function resolveUserFieldEntityId(string $entityCode): string
{
    $entityCode = strtoupper(trim($entityCode));
    if ($entityCode === 'CONTACT') { return 'CRM_CONTACT'; }
    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        $typeId = (int)substr($entityCode, 8);
        if ($typeId > 0) { return 'CRM_'.$typeId; }
    }
    return '';
}

function detectIsMultiple(array $info, array $uf): bool
{
    if (array_key_exists('MULTIPLE', $uf))
    {
        return (string)$uf['MULTIPLE'] === 'Y';
    }
    if (array_key_exists('isMultiple', $info))
    {
        return (bool)$info['isMultiple'];
    }
    if (array_key_exists('MULTIPLE', $info))
    {
        return (string)$info['MULTIPLE'] === 'Y';
    }
    return false;
}

function extractItems(array $info, array $uf): array
{
    $items = [];
    if (!empty($info['ITEMS']) && is_array($info['ITEMS']))
    {
        foreach ($info['ITEMS'] as $k => $v)
        {
            if (is_array($v))
            {
                $value = (string)($v['VALUE'] ?? $v['ID'] ?? $k);
                $name = (string)($v['NAME'] ?? $v['TITLE'] ?? $value);
                $items[$value] = $name;
            }
            else
            {
                $items[(string)$k] = (string)$v;
            }
        }
    }

    if (!empty($items))
    {
        return $items;
    }

    if (!empty($uf['USER_TYPE_ID']) && in_array(strtolower((string)$uf['USER_TYPE_ID']), ['enumeration', 'crm_status'], true))
    {
        $userFieldId = (int)($uf['ID'] ?? 0);
        if ($userFieldId > 0)
        {
            $enum = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $userFieldId]);
            while ($row = $enum->Fetch())
            {
                $items[(string)$row['ID']] = (string)$row['VALUE'];
            }
        }
    }

    return $items;
}

function pickFieldTitle(string $code, array $info, array $uf): string
{
    $title = trim((string)($info['TITLE'] ?? ''));
    if ($title !== '') { return $title; }
    $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
    foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $key)
    {
        $v = $uf[$key] ?? null;
        if (is_array($v) && !empty($v[$lang])) { return trim((string)$v[$lang]); }
        if (is_string($v) && trim($v) !== '') { return trim($v); }
    }
    return $code;
}

function resolveLinkedEntityTypeId(string $code, array $info, array $uf): int
{
    $upperCode = strtoupper(trim($code));
    if ($upperCode === 'CONTACT_ID' || preg_match('/_CONTACT_ID$/', $upperCode))
    {
        return 3;
    }
    if (preg_match('/^PARENT_ID_(\d+)$/', $upperCode, $m))
    {
        return (int)$m[1];
    }

    $tokens = [];
    $settings = [];
    if (is_array($info['SETTINGS'] ?? null)) { $settings[] = $info['SETTINGS']; }
    if (is_array($uf['SETTINGS'] ?? null)) { $settings[] = $uf['SETTINGS']; }

    foreach ($settings as $set)
    {
        foreach (flattenTokens($set) as $token)
        {
            $tokens[] = strtoupper($token);
        }
    }

    foreach ($tokens as $token)
    {
        if (preg_match('/^DYNAMIC_(\d+)$/', $token, $m)) { return (int)$m[1]; }
        if ($token === 'CONTACT' || $token === 'CRM_CONTACT' || $token === 'CRMCONTACT') { return 3; }
        if ($token === 'COMPANY' || $token === 'CRM_COMPANY' || $token === 'CRMCOMPANY') { return 4; }
        if ($token === 'DEAL' || $token === 'CRM_DEAL' || $token === 'CRMDEAL') { return 2; }
        if ($token === 'LEAD' || $token === 'CRM_LEAD' || $token === 'CRMLEAD') { return 1; }
        if (preg_match('/^CRM_(\d+)$/', $token, $m)) { return (int)$m[1]; }
    }

    $type = strtoupper((string)($info['TYPE'] ?? ''));
    if ($type === 'CRM_CONTACT') { return 3; }
    if ($type === 'CRM_COMPANY') { return 4; }
    if ($type === 'CRM_DEAL') { return 2; }

    return 0;
}

function flattenTokens($value): array
{
    $out = [];
    if (is_array($value))
    {
        foreach ($value as $k => $v)
        {
            $out = array_merge($out, flattenTokens($k), flattenTokens($v));
        }
        return $out;
    }
    if (is_scalar($value))
    {
        $str = trim((string)$value);
        if ($str === '') { return []; }
        foreach (preg_split('/[\s,;|]+/', $str) ?: [] as $part)
        {
            $part = trim($part);
            if ($part !== '') { $out[] = $part; }
        }
    }
    return array_values(array_unique($out));
}

function pickTestFields(array $dynamic, array $dynamicMeta, array $contactMeta): array
{
    $selected = [];

    $selected['string'] = pickField($dynamicMeta, static function ($f) {
        return isStringField($f) && strtoupper($f['code']) === 'TITLE';
    }) ?: pickField($dynamicMeta, 'isStringField') ?: fallbackField('string', $dynamic, 'TITLE', 'Название (fallback)');

    $selected['number'] = pickField($dynamicMeta, static function ($f) {
        return isNumberField($f) && strtoupper($f['code']) === 'OPPORTUNITY';
    }) ?: pickField($dynamicMeta, 'isNumberField') ?: fallbackField('number', $dynamic, 'OPPORTUNITY', 'Сумма (fallback)');

    $selected['date'] = pickField($dynamicMeta, static function ($f) {
        return isDateField($f) && in_array(strtoupper($f['code']), ['CREATED_TIME', 'UPDATED_TIME'], true);
    }) ?: pickField($dynamicMeta, 'isDateField') ?: fallbackField('date', $dynamic, 'CREATED_TIME', 'Дата (fallback)');

    $selected['select'] = pickField($dynamicMeta, static function ($f) {
        return isListField($f) && empty($f['isMultiple']);
    }) ?: fallbackField('select', $dynamic, 'TEST_SELECT', 'SELECT fallback', ['A' => 'Вариант A', 'B' => 'Вариант B']);

    $selected['multi'] = pickField($dynamicMeta, static function ($f) {
        return isListField($f) && !empty($f['isMultiple']);
    }) ?: fallbackField('multi', $dynamic, 'TEST_MULTI', 'MULTI fallback', ['1' => 'Один', '2' => 'Два', '3' => 'Три'], true);

    $selected['dest'] = pickField($dynamicMeta, static function ($f) {
        return isDestField($f) && in_array(strtoupper($f['code']), ['CREATED_BY', 'ASSIGNED_BY_ID'], true);
    }) ?: pickField($dynamicMeta, 'isDestField') ?: fallbackField('dest', $dynamic, 'CREATED_BY', 'Пользователь (fallback)');

    $selected['entity_contact'] = pickField($dynamicMeta, static function ($f) {
        return isEntityField($f) && (int)($f['entityTypeId'] ?? 0) === 3;
    }) ?: fallbackField('entity_contact', $dynamic, 'CONTACT_ID', 'Контакт (fallback)', [], false, 3);

    $selected['entity_dynamic'] = pickField($dynamicMeta, static function ($f) use ($dynamic) {
        $entityTypeId = (int)($f['entityTypeId'] ?? 0);
        return isEntityField($f) && $entityTypeId > 0 && $entityTypeId !== 3 && $entityTypeId !== (int)$dynamic['entityTypeId'];
    }) ?: fallbackField('entity_dynamic', $dynamic, 'PARENT_ID_'.$dynamic['entityTypeId'], 'Динамик (fallback)', [], false, (int)$dynamic['entityTypeId']);

    $selected['contact_string'] = pickField($contactMeta, static function ($f) {
        return isStringField($f) && in_array(strtoupper($f['code']), ['NAME', 'LAST_NAME', 'TITLE'], true);
    }) ?: fallbackField('contact_string', ['entityCode' => 'CONTACT', 'title' => 'Contact'], 'TYPE_ID', 'Contact TYPE_ID fallback');
    // normalize source labels
    foreach ($selected as $key => $field)
    {
        if (!isset($selected[$key]['source']))
        {
            $selected[$key]['source'] = 'fallback';
        }
    }

    return $selected;
}

function pickField(array $meta, callable $predicate): ?array
{
    foreach ($meta as $field)
    {
        if ($predicate($field))
        {
            $field['source'] = $field['source'] ?? 'auto';
            return $field;
        }
    }
    return null;
}

function fallbackField(string $kind, array $entity, string $code, string $title, array $items = [], bool $multiple = false, int $entityTypeId = 0): array
{
    return [
        'code' => $code,
        'title' => $title,
        'type' => $kind,
        'userTypeId' => $kind,
        'isMultiple' => $multiple,
        'items' => $items,
        'entityTypeId' => $entityTypeId,
        'source' => 'fallback',
        'entityCode' => (string)($entity['entityCode'] ?? ''),
        'entityTitle' => (string)($entity['title'] ?? ''),
    ];
}

function isStringField(array $f): bool
{
    $u = strtolower((string)($f['userTypeId'] ?? ''));
    $t = strtolower((string)($f['type'] ?? ''));
    return in_array($u, ['string', 'url', 'address'], true) || in_array($t, ['string', 'text', 'char', 'varchar'], true);
}

function isNumberField(array $f): bool
{
    $u = strtolower((string)($f['userTypeId'] ?? ''));
    $t = strtolower((string)($f['type'] ?? ''));
    return in_array($u, ['integer', 'double'], true) || in_array($t, ['integer', 'int', 'double', 'float', 'number', 'money'], true);
}

function isDateField(array $f): bool
{
    $u = strtolower((string)($f['userTypeId'] ?? ''));
    $t = strtolower((string)($f['type'] ?? ''));
    return in_array($u, ['date', 'datetime'], true) || in_array($t, ['date', 'datetime'], true) || strpos(strtoupper((string)$f['code']), 'TIME') !== false;
}

function isListField(array $f): bool
{
    $u = strtolower((string)($f['userTypeId'] ?? ''));
    $t = strtolower((string)($f['type'] ?? ''));
    $items = (array)($f['items'] ?? []);
    return in_array($u, ['enumeration', 'crm_status'], true) || in_array($t, ['enumeration', 'list'], true) || !empty($items);
}

function isDestField(array $f): bool
{
    $u = strtolower((string)($f['userTypeId'] ?? ''));
    $t = strtolower((string)($f['type'] ?? ''));
    $code = strtoupper((string)($f['code'] ?? ''));
    return in_array($u, ['employee', 'user'], true)
        || in_array($t, ['user', 'employee'], true)
        || (bool)preg_match('/(^|_)(CREATED_BY|UPDATED_BY|ASSIGNED_BY_ID)$/', $code);
}

function isEntityField(array $f): bool
{
    $u = strtolower((string)($f['userTypeId'] ?? ''));
    $t = strtolower((string)($f['type'] ?? ''));
    return $u === 'crm' || $t === 'crm_entity' || strpos($t, 'crm_') === 0 || (int)($f['entityTypeId'] ?? 0) > 0;
}

function buildTestFilterFields(array $dynamic, array $selected): array
{
    $rootPrefix = 'root_'.$dynamic['entityCode'].'__';

    $periodItems = [];
    $periodItems[$rootPrefix.'CREATED_TIME'] = 'Когда создан';
    $periodItems[$rootPrefix.'UPDATED_TIME'] = 'Когда обновлён';
    if (!empty($selected['date']['code']))
    {
        $periodItems[$rootPrefix.$selected['date']['code']] = (string)$selected['date']['title'];
    }

    $fields = [
        [
            'id' => 'PERIOD_FIELD',
            'name' => 'Поле периода',
            'type' => 'list',
            'items' => $periodItems,
            'params' => ['multiple' => 'N'],
            'default' => true,
        ],
        [
            'id' => 'PERIOD',
            'name' => 'Период',
            'type' => 'date',
            'default' => true,
        ],
    ];

    $fields[] = [
        'id' => $rootPrefix.$selected['string']['code'],
        'name' => 'STRING: '.$selected['string']['title'],
        'type' => 'string',
        'default' => true,
    ];

    $fields[] = [
        'id' => $rootPrefix.$selected['number']['code'],
        'name' => 'NUMBER: '.$selected['number']['title'],
        'type' => 'number',
        'default' => true,
    ];

    $selectItems = !empty($selected['select']['items']) ? (array)$selected['select']['items'] : ['A' => 'Вариант A', 'B' => 'Вариант B'];
    $fields[] = [
        'id' => $rootPrefix.$selected['select']['code'],
        'name' => 'SELECT: '.$selected['select']['title'],
        'type' => 'list',
        'items' => $selectItems,
        'params' => ['multiple' => 'N'],
        'default' => true,
    ];

    $multiItems = !empty($selected['multi']['items']) ? (array)$selected['multi']['items'] : ['1' => 'Один', '2' => 'Два', '3' => 'Три'];
    $fields[] = [
        'id' => $rootPrefix.$selected['multi']['code'],
        'name' => 'MULTI_SELECT: '.$selected['multi']['title'],
        'type' => 'list',
        'items' => $multiItems,
        'params' => ['multiple' => 'Y'],
        'default' => true,
    ];

    $fields[] = [
        'id' => $rootPrefix.$selected['dest']['code'],
        'name' => 'DEST_SELECTOR: '.$selected['dest']['title'],
        'type' => 'dest_selector',
        'params' => [
            'multiple' => 'N',
            'apiVersion' => 3,
            'context' => 'GNC_FILTER_PUBLIC_TEST_USER',
            'enableAll' => 'N',
            'enableUsers' => 'Y',
            'enableSonetgroups' => 'N',
            'enableDepartments' => 'Y',
            'allowEmailInvitation' => 'N',
            'enableCrm' => 'N',
        ],
        'default' => true,
    ];

    $contactEntityTypeId = max(1, (int)($selected['entity_contact']['entityTypeId'] ?? 3));
    $fields[] = [
        'id' => $rootPrefix.$selected['entity_contact']['code'],
        'name' => 'ENTITY_SELECTOR(contact): '.$selected['entity_contact']['title'],
        'type' => 'entity_selector',
        'params' => [
            'multiple' => 'N',
            'dialogOptions' => [
                'id' => $rootPrefix.$selected['entity_contact']['code'],
                'context' => 'GNC_FILTER_PUBLIC_TEST_'.preg_replace('/[^A-Za-z0-9_]/', '_', $rootPrefix.$selected['entity_contact']['code']),
                'entities' => [
                    ['id' => 'crm', 'dynamicLoad' => true, 'dynamicSearch' => true, 'options' => ['entityTypeId' => $contactEntityTypeId]],
                ],
            ],
        ],
        'default' => true,
    ];

    $dynamicEntityTypeId = max(1, (int)($selected['entity_dynamic']['entityTypeId'] ?? $dynamic['entityTypeId']));
    $fields[] = [
        'id' => $rootPrefix.$selected['entity_dynamic']['code'],
        'name' => 'ENTITY_SELECTOR(dynamic): '.$selected['entity_dynamic']['title'],
        'type' => 'entity_selector',
        'params' => [
            'multiple' => 'N',
            'dialogOptions' => [
                'id' => $rootPrefix.$selected['entity_dynamic']['code'],
                'context' => 'GNC_FILTER_PUBLIC_TEST_'.preg_replace('/[^A-Za-z0-9_]/', '_', $rootPrefix.$selected['entity_dynamic']['code']),
                'entities' => [
                    ['id' => 'crm', 'dynamicLoad' => true, 'dynamicSearch' => true, 'options' => ['entityTypeId' => $dynamicEntityTypeId]],
                ],
            ],
        ],
        'default' => true,
    ];

    $fields[] = [
        'id' => $rootPrefix.$selected['date']['code'],
        'name' => 'DATE: '.$selected['date']['title'],
        'type' => 'date',
        'default' => true,
    ];

    $fields[] = [
        'id' => 'contact__'.$selected['contact_string']['code'],
        'name' => 'CONTACT STRING: '.$selected['contact_string']['title'],
        'type' => 'string',
        'default' => true,
    ];

    return $fields;
}

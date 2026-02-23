<?php
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Gnc\Othet\Filter\FieldMapper;

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__.'/../lib/Filter/FieldMapper.php';

global $USER, $APPLICATION, $USER_FIELD_MANAGER;

if (!$USER || !$USER->IsAuthorized())
{
    LocalRedirect('/auth/');
}

if (!Loader::includeModule('main'))
{
    echo 'Module "main" is required';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';
    die();
}

if (!Loader::includeModule('crm'))
{
    echo 'Module "crm" is required';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';
    die();
}

\CJSCore::Init(['ajax', 'ui.entity-selector', 'main.ui.selector']);
$extensionLoad = [];
foreach (['main.ui.filter', 'ui.buttons', 'ui.forms', 'ui.notification', 'ui.entity-selector', 'crm.entity-selector', 'main.ui.selector'] as $ext)
{
    try
    {
        Extension::load([$ext]);
        $extensionLoad[] = ['extension' => $ext, 'loaded' => true];
    }
    catch (\Throwable $e)
    {
        $extensionLoad[] = ['extension' => $ext, 'loaded' => false, 'error' => $e->getMessage()];
    }
}

$dynamicTypeId = max(1, (int)($_GET['dynamicTypeId'] ?? 1060));
$filterId = 'GNC_FILTER_TEST';
$gridId = 'GNC_FILTER_TEST_GRID';

$dynamicMetaMap = buildMetaMapForDynamic($dynamicTypeId);
$contactMetaMap = buildMetaMapForContact();
$mapperWarnings = [];
$selected = pickRepresentativeFields($dynamicMetaMap, $contactMetaMap, $dynamicTypeId, $mapperWarnings);
$filterFields = buildFilterFieldsFromMeta($selected, $dynamicTypeId, $filterId, $mapperWarnings);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_after.php';
?>
<style>
    .gnc-filter-test-wrap { padding: 16px; max-width: 1480px; }
    .gnc-filter-test-title { margin: 0 0 12px; font-size: 24px; }
    .gnc-filter-test-meta { margin-bottom: 8px; color: #405468; }
    .gnc-filter-test-actions { margin: 12px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .gnc-filter-test-actions input { min-width: 220px; padding: 6px 8px; }
    .gnc-filter-test-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .gnc-filter-test-panel { border: 1px solid #dde3ea; border-radius: 8px; padding: 10px; background: #fff; }
    .gnc-filter-test-panel h3 { margin: 0 0 8px; font-size: 14px; }
    .gnc-filter-test-pre { margin: 0; max-height: 360px; overflow: auto; background: #f8fbff; border: 1px solid #d7e0ea; border-radius: 6px; padding: 8px; white-space: pre-wrap; font-size: 12px; }
    @media (max-width: 1100px) { .gnc-filter-test-panels { grid-template-columns: 1fr; } }
</style>

<div class="gnc-filter-test-wrap">
    <h1 class="gnc-filter-test-title">GNC Public Filter Test</h1>
    <div class="gnc-filter-test-meta">dynamicTypeId: <b><?=htmlspecialcharsbx((string)$dynamicTypeId)?></b></div>

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

    <div class="gnc-filter-test-actions">
        <button type="button" class="ui-btn ui-btn-primary" id="showValuesBtn">Показать значения фильтра</button>
        <button type="button" class="ui-btn ui-btn-light-border" id="resetBtn">Сброс</button>
        <input type="text" id="fallbackQuery" value="ни" placeholder="Строка поиска для fallback AJAX">
        <button type="button" class="ui-btn ui-btn-light-border" id="fallbackBtn">Проверить backend поиск (fallback)</button>
    </div>

    <div class="gnc-filter-test-panels">
        <div class="gnc-filter-test-panel"><h3>Логи</h3><pre id="logBox" class="gnc-filter-test-pre"></pre></div>
        <div class="gnc-filter-test-panel"><h3>Текущие значения фильтра</h3><pre id="valuesBox" class="gnc-filter-test-pre">Нажмите кнопку</pre></div>
        <div class="gnc-filter-test-panel"><h3>selectedFields</h3><pre class="gnc-filter-test-pre"><?=htmlspecialcharsbx(Json::encode($selected, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre></div>
        <div class="gnc-filter-test-panel"><h3>filterFields</h3><pre class="gnc-filter-test-pre"><?=htmlspecialcharsbx(Json::encode($filterFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre></div>
        <div class="gnc-filter-test-panel" style="grid-column: 1 / -1;"><h3>Mapper warnings / extension load</h3><pre class="gnc-filter-test-pre"><?=htmlspecialcharsbx(Json::encode(['mapperWarnings' => $mapperWarnings, 'extensions' => $extensionLoad], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre></div>
    </div>
</div>

<script>
(function () {
    var filterId = '<?=$filterId?>';
    var gridId = '<?=$gridId?>';
    var dynamicTypeId = <?= (int)$dynamicTypeId ?>;
    var fieldIds = <?=Json::encode(array_map(static function ($f) { return (string)($f['id'] ?? ''); }, $filterFields))?>;
    var fieldTypes = <?=Json::encode(array_map(static function ($f) { return ['id' => (string)($f['id'] ?? ''), 'type' => strtoupper((string)($f['type'] ?? ''))]; }, $filterFields))?>;

    var logBox = document.getElementById('logBox');
    var valuesBox = document.getElementById('valuesBox');
    var showValuesBtn = document.getElementById('showValuesBtn');
    var resetBtn = document.getElementById('resetBtn');
    var fallbackBtn = document.getElementById('fallbackBtn');
    var fallbackQuery = document.getElementById('fallbackQuery');

    function ts() { return new Date().toISOString(); }
    function safeJson(v) { try { return JSON.stringify(v, null, 2); } catch (e) { return String(v); } }
    function preview(v) { var s = typeof v === 'string' ? v : safeJson(v); return s.length > 1000 ? s.slice(0, 1000) + ' ...' : s; }
    function log(level, message, data) {
        var line = '[' + ts() + '] [' + level + '] ' + message;
        if (typeof data !== 'undefined') { line += '\n' + safeJson(data); }
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

    function requestLooksInteresting(url, payloadText) {
        return String(url || '').indexOf('ui.entityselector.load') >= 0
            || String(url || '').indexOf('ui.entityselector.doSearch') >= 0
            || String(url || '').indexOf('main.ui.selector') >= 0
            || String(payloadText || '').indexOf('ui.entityselector.load') >= 0
            || String(payloadText || '').indexOf('ui.entityselector.doSearch') >= 0
            || String(payloadText || '').indexOf('action":"ui.entityselector.load') >= 0
            || String(payloadText || '').indexOf('action":"ui.entityselector.doSearch') >= 0
            || String(payloadText || '').indexOf('action=ui.entityselector.load') >= 0
            || String(payloadText || '').indexOf('action=ui.entityselector.doSearch') >= 0;
    }

    if (window.BX && BX.ajax && !BX.__GNC_FILTER_TEST_PATCHED__) {
        BX.__GNC_FILTER_TEST_PATCHED__ = true;
        var oldAjax = BX.ajax;
        BX.ajax = function (config) {
            var url = config && config.url ? String(config.url) : '';
            var payload = '';
            try {
                if (typeof config.data === 'string') { payload = config.data; }
                else if (config.data) { payload = JSON.stringify(config.data); }
                else if (typeof config.preparePost === 'string') { payload = config.preparePost; }
                else if (config.preparePost) { payload = JSON.stringify(config.preparePost); }
            } catch (e) {}

            var watch = requestLooksInteresting(url, payload);
            if (!watch) { return oldAjax.apply(BX, arguments); }

            log('XHR_REQ', url, { method: String(config.method || 'POST'), bodyPreview: preview(payload) });
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

    if (window.fetch && !window.__GNC_FILTER_TEST_FETCH_PATCHED__) {
        window.__GNC_FILTER_TEST_FETCH_PATCHED__ = true;
        var oldFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
            var body = init && typeof init.body === 'string' ? init.body : '';
            var watch = requestLooksInteresting(url, body);
            if (watch) { log('FETCH_REQ', String(url || ''), { method: (init && init.method) || 'GET', bodyPreview: preview(body) }); }
            return oldFetch.apply(this, arguments).then(function (resp) {
                if (!watch) { return resp; }
                var clone = resp.clone();
                return clone.text().then(function (txt) {
                    log('FETCH_RES', String(url || ''), { status: resp.status, bodyPreview: preview(txt) });
                    return resp;
                }).catch(function () {
                    log('FETCH_RES', String(url || ''), { status: resp.status });
                    return resp;
                });
            }).catch(function (err) {
                if (watch) { log('FETCH_ERR', String(url || ''), { error: String(err && err.message ? err.message : err) }); }
                throw err;
            });
        };
    }

    if (window.XMLHttpRequest && !window.__GNC_FILTER_TEST_XHR_PATCHED__) {
        window.__GNC_FILTER_TEST_XHR_PATCHED__ = true;
        var xOpen = XMLHttpRequest.prototype.open;
        var xSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__gncMethod = method;
            this.__gncUrl = url;
            return xOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function (body) {
            var payload = typeof body === 'string' ? body : '';
            var watch = requestLooksInteresting(this.__gncUrl, payload);
            if (watch) {
                log('XHR_NATIVE_REQ', String(this.__gncUrl || ''), { method: String(this.__gncMethod || 'GET'), bodyPreview: preview(payload) });
                this.addEventListener('load', function () {
                    log('XHR_NATIVE_RES', String(this.__gncUrl || ''), { status: this.status, bodyPreview: preview(this.responseText || '') });
                });
                this.addEventListener('error', function () {
                    log('XHR_NATIVE_ERR', String(this.__gncUrl || ''), { status: this.status });
                });
            }
            return xSend.apply(this, arguments);
        };
    }

    (function hookEntitySelector() {
        var attempts = 0;
        var t = setInterval(function () {
            attempts++;
            var proto = window.BX && BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.Dialog && BX.UI.EntitySelector.Dialog.prototype;
            if (!proto) {
                if (attempts > 40) { clearInterval(t); log('WARN', 'EntitySelector prototype not found'); }
                return;
            }
            clearInterval(t);
            if (proto.__GNC_FILTER_TEST_HOOKED__) { return; }
            proto.__GNC_FILTER_TEST_HOOKED__ = true;
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

    if (window.BX && typeof BX.addCustomEvent === 'function') {
        BX.addCustomEvent(window, 'BX.Main.Filter:apply', function (id) {
            if (String(id || '') !== filterId) { return; }
            var vals = getValues();
            log('INFO', 'Filter apply', vals || {});
            valuesBox.textContent = safeJson(vals || {});
        });
        BX.addCustomEvent(window, 'BX.Main.Filter:reset', function (id) {
            if (String(id || '') !== filterId) { return; }
            var vals = getValues();
            log('INFO', 'Filter reset', vals || {});
            valuesBox.textContent = safeJson(vals || {});
        });
    }

    showValuesBtn.addEventListener('click', function () {
        var vals = getValues();
        valuesBox.textContent = safeJson(vals || {});
        log('INFO', 'Current filter values', vals || {});
    });

    resetBtn.addEventListener('click', function () {
        var f = getFilter();
        if (!f) { log('WARN', 'Filter instance not ready'); return; }
        if (f.getApi && f.getApi() && typeof f.getApi().reset === 'function') { f.getApi().reset(); }
        else if (typeof f.reset === 'function') { f.reset(); }
        log('INFO', 'Reset clicked');
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

    fallbackBtn.addEventListener('click', function () {
        var q = (fallbackQuery.value || 'ни').trim() || 'ни';
        log('INFO', 'Fallback search started', { query: q, dynamicTypeId: dynamicTypeId });
        Promise.all([fallbackSearch('CONTACT', q), fallbackSearch('DYNAMIC_' + dynamicTypeId, q)]).then(function (res) {
            log('INFO', 'Fallback CONTACT response', res[0]);
            log('INFO', 'Fallback DYNAMIC response', res[1]);
        }).catch(function (err) {
            log('ERROR', 'Fallback search failed', { error: String(err && err.message ? err.message : err) });
        });
    });

    log('INFO', 'Страница теста загружена', {
        templateId: 'public_test',
        filterId: filterId,
        gridId: gridId,
        filterFieldsCount: fieldIds.length,
        filterFieldIds: fieldIds,
        configuredFieldTypes: fieldTypes
    });
})();
</script>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';

function buildMetaMapForDynamic(int $dynamicTypeId): array
{
    $factory = Container::getInstance()->getFactory($dynamicTypeId);
    if (!$factory) { return []; }

    return buildMetaMapFromFactory($factory, [
        'CRM_DYNAMIC_'.$dynamicTypeId,
        'CRM_SMART_DOCUMENT_'.$dynamicTypeId,
        'CRM_'.$dynamicTypeId,
    ], 'dynamic');
}

function buildMetaMapForContact(): array
{
    $contactFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Contact);
    if (!$contactFactory) { return []; }

    return buildMetaMapFromFactory($contactFactory, [
        'CRM_CONTACT',
    ], 'contact');
}

function buildMetaMapFromFactory($factory, array $entityIds, string $source): array
{
    global $USER_FIELD_MANAGER;

    $fieldsInfo = (array)$factory->getFieldsInfo();
    $ufMap = [];
    if ($USER_FIELD_MANAGER)
    {
        foreach ($entityIds as $entityId)
        {
            $rows = (array)$USER_FIELD_MANAGER->GetUserFields($entityId, 0, LANGUAGE_ID);
            if (!empty($rows))
            {
                $ufMap = array_replace($ufMap, $rows);
            }
        }
    }

    $metaMap = [];
    foreach ($fieldsInfo as $code => $info)
    {
        $code = (string)$code;
        if ($code === '') { continue; }
        $info = is_array($info) ? $info : [];
        $uf = is_array($ufMap[$code] ?? null) ? $ufMap[$code] : [];

        $metaMap[$code] = [
            'source' => $source,
            'code' => $code,
            'title' => pickFieldTitle($code, $info, $uf),
            'crmType' => strtolower((string)($info['TYPE'] ?? '')),
            'userTypeId' => strtolower((string)($uf['USER_TYPE_ID'] ?? '')),
            'isMultiple' => detectMultiple($info, $uf),
            'settings' => normalizeSettings($info['SETTINGS'] ?? [], $uf['SETTINGS'] ?? []),
            'items' => getFieldItems($info, $uf),
            'isUf' => strpos(strtoupper($code), 'UF_') === 0,
        ];
    }

    foreach ($ufMap as $code => $uf)
    {
        $code = (string)$code;
        if ($code === '' || isset($metaMap[$code])) { continue; }
        $uf = is_array($uf) ? $uf : [];
        $metaMap[$code] = [
            'source' => $source,
            'code' => $code,
            'title' => pickFieldTitle($code, [], $uf),
            'crmType' => '',
            'userTypeId' => strtolower((string)($uf['USER_TYPE_ID'] ?? 'string')),
            'isMultiple' => detectMultiple([], $uf),
            'settings' => normalizeSettings([], $uf['SETTINGS'] ?? []),
            'items' => getFieldItems([], $uf),
            'isUf' => true,
        ];
    }

    return $metaMap;
}

function pickRepresentativeFields(array $dynamicMeta, array $contactMeta, int $dynamicTypeId, array &$warnings): array
{
    $all = array_values(array_merge(array_values($dynamicMeta), array_values($contactMeta)));
    $picked = [];

    $picked['string'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'string' && strtoupper((string)$m['code']) === 'TITLE';
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'string';
    });

    $picked['number'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'number' && strtoupper((string)$m['code']) === 'OPPORTUNITY';
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'number';
    });

    $picked['date'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'date'
            && in_array(strtoupper((string)$m['code']), ['CREATED_TIME', 'UPDATED_TIME'], true);
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'date';
    });

    $picked['select'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'list' && empty($m['isMultiple']);
    });

    $picked['multiselect'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'list' && !empty($m['isMultiple']);
    });

    $picked['dest_selector'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'dest_selector'
            && in_array(strtoupper((string)$m['code']), ['CREATED_BY', 'ASSIGNED_BY_ID'], true);
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'dest_selector';
    });

    $picked['entity_contact'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'entity_selector'
            && in_array(3, FieldMapper::resolveEntityTypeIds($m), true);
    });

    $picked['entity_dynamic'] = findField($all, static function ($m): bool {
        if (FieldMapper::detectType($m) !== 'entity_selector') { return false; }
        foreach (FieldMapper::resolveEntityTypeIds($m) as $id)
        {
            if ((int)$id > 0 && (int)$id !== 3) { return true; }
        }
        return false;
    });

    if (empty($picked['select']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'SELECT fallback field used'];
        $picked['select'] = [
            'source' => 'fallback',
            'code' => 'FALLBACK_SELECT',
            'title' => 'Fallback Select',
            'crmType' => 'list',
            'userTypeId' => '',
            'isMultiple' => false,
            'settings' => [],
            'items' => ['A' => 'Вариант A', 'B' => 'Вариант B'],
            'isUf' => false,
        ];
    }

    if (empty($picked['multiselect']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'MULTI_SELECT fallback field used'];
        $picked['multiselect'] = [
            'source' => 'fallback',
            'code' => 'FALLBACK_MULTI',
            'title' => 'Fallback Multi',
            'crmType' => 'list',
            'userTypeId' => '',
            'isMultiple' => true,
            'settings' => [],
            'items' => ['1' => 'Один', '2' => 'Два', '3' => 'Три'],
            'isUf' => false,
        ];
    }

    if (empty($picked['dest_selector']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'DEST_SELECTOR fallback field used'];
        $picked['dest_selector'] = [
            'source' => 'fallback',
            'code' => 'CREATED_BY',
            'title' => 'Создал',
            'crmType' => 'user',
            'userTypeId' => '',
            'isMultiple' => false,
            'settings' => [],
            'items' => [],
            'isUf' => false,
        ];
    }

    if (empty($picked['entity_contact']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'ENTITY_SELECTOR CONTACT fallback field used'];
        $picked['entity_contact'] = [
            'source' => 'fallback',
            'code' => 'CONTACT_ID',
            'title' => 'Контакт',
            'crmType' => 'crm_contact',
            'userTypeId' => '',
            'isMultiple' => false,
            'settings' => [],
            'items' => [],
            'isUf' => false,
            'forceEntityTypeId' => 3,
        ];
    }

    if (empty($picked['entity_dynamic']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'ENTITY_SELECTOR DYNAMIC fallback field used'];
        $picked['entity_dynamic'] = [
            'source' => 'fallback',
            'code' => 'PARENT_ID_'.$dynamicTypeId,
            'title' => 'Связанный СП '.$dynamicTypeId,
            'crmType' => 'crm_entity',
            'userTypeId' => '',
            'isMultiple' => false,
            'settings' => ['DYNAMIC_'.$dynamicTypeId => 'Y'],
            'items' => [],
            'isUf' => false,
            'forceEntityTypeId' => $dynamicTypeId,
        ];
    }

    return array_filter($picked);
}

function buildFilterFieldsFromMeta(array $selected, int $dynamicTypeId, string $filterId, array &$warnings): array
{
    $fields = [
        [
            'id' => 'PERIOD_FIELD',
            'name' => 'Поле периода',
            'type' => 'list',
            'items' => [
                'root_DYNAMIC_'.$dynamicTypeId.'__CREATED_TIME' => 'Когда создан',
                'root_DYNAMIC_'.$dynamicTypeId.'__UPDATED_TIME' => 'Когда обновлён',
            ],
            'params' => ['multiple' => 'N'],
            'default' => true,
        ],
        ['id' => 'PERIOD', 'name' => 'Период', 'type' => 'date', 'default' => true],
    ];

    $needed = ['string', 'number', 'date', 'select', 'multiselect', 'dest_selector', 'entity_contact', 'entity_dynamic'];
    foreach ($needed as $slot)
    {
        $meta = $selected[$slot] ?? null;
        if (!$meta)
        {
            $warnings[] = ['level' => 'WARN', 'message' => 'No field for slot '.$slot];
            continue;
        }

        $fieldId = ($meta['source'] ?? '') === 'contact'
            ? 'contact__'.$meta['code']
            : 'root_DYNAMIC_'.$dynamicTypeId.'__'.$meta['code'];
        $uiField = FieldMapper::toUiFilterField($meta, [
            'fieldId' => $fieldId,
            'filterId' => $filterId,
            'entityTypeId' => $dynamicTypeId,
        ]);
        if (!empty($uiField['_warnings']))
        {
            foreach ((array)$uiField['_warnings'] as $w)
            {
                $warnings[] = ['level' => 'WARN', 'message' => $w, 'field' => $meta['code']];
            }
        }
        unset($uiField['_warnings']);
        $fields[] = $uiField;
    }

    return $fields;
}

function findField(array $metaMap, callable $predicate): ?array
{
    foreach ($metaMap as $meta)
    {
        if ($predicate($meta))
        {
            return $meta;
        }
    }
    return null;
}

function pickFieldTitle(string $code, array $info, array $uf): string
{
    $title = trim((string)($info['TITLE'] ?? ''));
    if ($title !== '') { return $title; }
    $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
    foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $k)
    {
        $v = $uf[$k] ?? null;
        if (is_array($v) && !empty($v[$lang])) { return trim((string)$v[$lang]); }
        if (is_string($v) && trim($v) !== '') { return trim($v); }
    }
    return $code;
}

function detectMultiple(array $info, array $uf): bool
{
    if (isset($uf['MULTIPLE'])) { return (string)$uf['MULTIPLE'] === 'Y'; }
    if (isset($info['isMultiple'])) { return (bool)$info['isMultiple']; }
    if (isset($info['MULTIPLE'])) { return (string)$info['MULTIPLE'] === 'Y'; }
    return false;
}

function normalizeSettings($infoSettings, $ufSettings): array
{
    $settings = [];
    if (is_array($infoSettings)) { $settings = array_replace_recursive($settings, $infoSettings); }
    if (is_array($ufSettings)) { $settings = array_replace_recursive($settings, $ufSettings); }
    return $settings;
}

function getFieldItems(array $info, array $uf): array
{
    $userTypeId = strtolower((string)($uf['USER_TYPE_ID'] ?? ''));
    if ($userTypeId === 'boolean')
    {
        return ['1' => 'Да', '0' => 'Нет'];
    }

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

    if (!empty($items)) { return $items; }

    if (in_array($userTypeId, ['enumeration', 'crm_status'], true))
    {
        $fieldId = (int)($uf['ID'] ?? 0);
        if ($fieldId > 0)
        {
            $enum = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $fieldId]);
            while ($row = $enum->Fetch())
            {
                $items[(string)$row['ID']] = (string)$row['VALUE'];
            }
        }
    }
    return $items;
}

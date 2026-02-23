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

if (!Loader::includeModule('main') || !Loader::includeModule('crm'))
{
    echo 'Required modules are not loaded (main, crm).';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';
    die();
}

\CJSCore::Init(['main.core', 'main.core.ajax', 'ajax', 'ui.entity-selector', 'main.ui.selector']);
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

// === CONFIG (you edit only this) ===
// Entity type (dynamic type id, contact/company ids also possible if Factory exists)
$entityTypeId = max(1, (int)($_GET['entityTypeId'] ?? 1060));
$klubEntityTypeId = max(0, (int)($_GET['klubEntityTypeId'] ?? 0));

// Only symbolic codes of fields you want to show in the filter
$onlyCodes = [
    'UF_CRM_9_1C',
    'UF_CRM_9_KLUB',
    'UF_CRM_9_TEMA',
    'UF_CRM_9_DATE_OS',
    'ASSIGNED_BY_ID',
];
// === /CONFIG ===

$filterId = 'GNC_FILTER_TEST';
$gridId = 'GNC_FILTER_TEST_GRID';

$mapperWarnings = [];


// Read field definitions from Bitrix (Factory + UF settings) and build UI filter config automatically
$factoryDump = dumpFieldsFromFactory($entityTypeId);
$byCode = $factoryDump['byCode'] ?? [];

$selected = [];
foreach ($onlyCodes as $code)
{
    $code = (string)$code;
    if ($code === '') { continue; }

    if (isset($byCode[$code]))
    {
        $selected[$code] = $byCode[$code];
    }
    else
    {
        $mapperWarnings[] = ['level' => 'WARN', 'message' => 'Factory field not found: '.$code];
    }
}

$filterFields = buildFilterFieldsAuto($entityTypeId, $filterId, $selected, $mapperWarnings, $klubEntityTypeId);
$klubResolvedEntityTypeId = resolveCrmEntityTypeIdFromMeta($selected['UF_CRM_9_KLUB'] ?? [], $klubEntityTypeId);
$transportMarkers = [];
foreach ($filterFields as $ff)
{
    if (($ff['id'] ?? '') === 'root_DYNAMIC_'.$entityTypeId.'__UF_CRM_9_KLUB' && ($ff['type'] ?? '') === 'dest_selector')
    {
        $transportMarkers[] = 'crm-uf via main.ui.selector';
    }
}

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_after.php';
?>
<style>
    .gnc-filter-test-wrap { padding: 16px; max-width: 1480px; }
    .gnc-filter-test-title { margin: 0 0 12px; font-size: 24px; }
    .gnc-filter-test-meta { margin-bottom: 8px; color: #405468; }
    .gnc-filter-test-actions { margin: 12px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .gnc-filter-test-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .gnc-filter-test-panel { border: 1px solid #dde3ea; border-radius: 8px; padding: 10px; background: #fff; }
    .gnc-filter-test-panel h3 { margin: 0 0 8px; font-size: 14px; }
    .gnc-filter-test-pre { margin: 0; max-height: 380px; overflow: auto; background: #f8fbff; border: 1px solid #d7e0ea; border-radius: 6px; padding: 8px; white-space: pre-wrap; font-size: 12px; }
    @media (max-width: 1100px) { .gnc-filter-test-panels { grid-template-columns: 1fr; } }
</style>

<div class="gnc-filter-test-wrap">
    <h1 class="gnc-filter-test-title">GNC Public Filter Test</h1>
    <div class="gnc-filter-test-meta">entityTypeId: <b><?=htmlspecialcharsbx((string)$entityTypeId)?></b></div>

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
        <button type="button" class="ui-btn ui-btn-light" id="probeProvidersBtn">Проверить провайдеры EntitySelector</button>
    </div>

    <div class="gnc-filter-test-panels">
        <div class="gnc-filter-test-panel"><h3>Логи</h3><pre id="logBox" class="gnc-filter-test-pre"></pre></div>
        <div class="gnc-filter-test-panel"><h3>Текущие значения фильтра</h3><pre id="valuesBox" class="gnc-filter-test-pre">Нажмите кнопку</pre></div>
        <div class="gnc-filter-test-panel"><h3>selectedFields (by code)</h3><pre class="gnc-filter-test-pre"><?=htmlspecialcharsbx(Json::encode($selected, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre></div>
        <div class="gnc-filter-test-panel"><h3>Конфиг filterFields</h3><pre class="gnc-filter-test-pre"><?=htmlspecialcharsbx(Json::encode($filterFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre></div>
        <div class="gnc-filter-test-panel" style="grid-column:1 / -1;"><h3>Mapper warnings / extension load</h3><pre class="gnc-filter-test-pre"><?=htmlspecialcharsbx(Json::encode(['mapperWarnings' => $mapperWarnings, 'extensions' => $extensionLoad], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre></div>
    </div>
</div>

<script>
(function () {
    var filterId = '<?=$filterId?>';
    var gridId = '<?=$gridId?>';
    var klubFieldCode = 'UF_CRM_9_KLUB';
    var klubFieldId = 'root_DYNAMIC_' + <?= (int)$entityTypeId ?> + '__' + klubFieldCode;
    var klubDialogId = klubFieldId + '_' + filterId;
    var klubContext = filterId + '_' + klubFieldCode;
    var klubEntityTypeId = <?= (int)$klubResolvedEntityTypeId ?>;
    var fieldIds = <?=Json::encode(array_map(static function ($f) { return (string)($f['id'] ?? ''); }, $filterFields))?>;
    var fieldTypes = <?=Json::encode(array_map(static function ($f) { return ['id' => (string)($f['id'] ?? ''), 'type' => strtoupper((string)($f['type'] ?? ''))]; }, $filterFields))?>;
    var transportMarkers = <?=Json::encode($transportMarkers)?>;

    var logBox = document.getElementById('logBox');
    var valuesBox = document.getElementById('valuesBox');

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
            || String(payloadText || '').indexOf('action=ui.entityselector.doSearch') >= 0
            || String(payloadText || '').toLowerCase().indexOf('action=ui.entityselector.load') >= 0
            || String(payloadText || '').toLowerCase().indexOf('action=ui.entityselector.dosearch') >= 0;
    }

    if (window.BX && BX.ajax && !BX.__GNC_FILTER_PUBLIC_TEST_PATCHED__) {
        BX.__GNC_FILTER_PUBLIC_TEST_PATCHED__ = true;
        var oldAjax = BX.ajax;
        var wrappedAjax = function (config) {
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

        // preserve BX.ajax.* methods like runAction/runComponentAction (часто non-enumerable)
        try {
            var props = [];
            try {
                props = Object.getOwnPropertyNames(oldAjax);
            } catch (e) {
                props = [];
                for (var k in oldAjax) { props.push(k); }
            }
            props.forEach(function (p) {
                try {
                    var d = Object.getOwnPropertyDescriptor(oldAjax, p);
                    if (d) {
                        Object.defineProperty(wrappedAjax, p, d);
                    } else {
                        wrappedAjax[p] = oldAjax[p];
                    }
                } catch (e) {
                    try { wrappedAjax[p] = oldAjax[p]; } catch (e2) {}
                }
            });

            // на всякий случай — явные методы
            ['runAction','runComponentAction','xhr','promise','prepareData','preparePost','loadScript','loadCSS']
                .forEach(function (m) {
                    try {
                        if (typeof oldAjax[m] !== 'undefined' && typeof wrappedAjax[m] === 'undefined') {
                            wrappedAjax[m] = oldAjax[m];
                        }
                    } catch (e) {}
                });
        } catch (e) {}
        BX.ajax = wrappedAjax;
    }

    // === PROBE START REMOVED ===
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
            if (proto.__GNC_FILTER_PUBLIC_TEST_HOOKED__) { return; }
            proto.__GNC_FILTER_PUBLIC_TEST_HOOKED__ = true;

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

    document.getElementById('showValuesBtn').addEventListener('click', function () {
        var vals = getValues();
        valuesBox.textContent = safeJson(vals || {});
        log('INFO', 'Current filter values', vals || {});
    });

    document.getElementById('resetBtn').addEventListener('click', function () {
        var f = getFilter();
        if (!f) { log('WARN', 'Filter instance not ready'); return; }
        if (f.getApi && f.getApi() && typeof f.getApi().reset === 'function') { f.getApi().reset(); }
        else if (typeof f.reset === 'function') { f.reset(); }
        log('INFO', 'Reset clicked');
    });

    function runProbe(providerId, providerOptions) {
        return new Promise(function (resolve) {
            var context = 'PROBE_' + providerId;
            var url = '/bitrix/services/main/ajax.php?context=' + encodeURIComponent(context) + '&action=ui.entityselector.load';

            // IMPORTANT:
            // ui.entityselector.* actions expect POST body in the same format as real EntitySelector requests.
            // In practice this works reliably when we send JSON string + sessid.
            var sessid = (window.BX && typeof BX.bitrix_sessid === 'function')
                ? BX.bitrix_sessid()
                : (window.BX && BX.message ? BX.message('bitrix_sessid') : '');

            var dialog = {
                id: 'PROBE_DIALOG_' + providerId,
                context: context,
                entities: [
                    {
                        id: providerId,
                        options: providerOptions || {},
                        searchable: true,
                        dynamicLoad: true,
                        dynamicSearch: true,
                        filters: [],
                        substituteEntityId: null
                    }
                ],
                preselectedItems: [],
                recentItemsLimit: 20,
                clearUnavailableItems: true
            };

            var payload = JSON.stringify({
                sessid: sessid,
                dialog: dialog
            });

            BX.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: payload,
                onsuccess: function (res) {
                    // If server returns status=error, treat it as failure for the probe
                    if (res && res.status && String(res.status).toLowerCase() === 'error') {
                        resolve({ ok: false, providerId: providerId, options: providerOptions || {}, raw: res, error: (res.errors || []) });
                        return;
                    }

                    var d = (res && res.data && res.data.dialog) ? res.data.dialog : null;
                    resolve({ ok: true, providerId: providerId, options: providerOptions || {}, raw: res, dialog: d });
                },
                onfailure: function (err) {
                    resolve({ ok: false, providerId: providerId, options: providerOptions || {}, error: err });
                }
            });
        });
    }

    function runContextProbe(context, dialogId, entityTypeId, query) {
        return new Promise(function (resolve) {
            var sessid = (window.BX && typeof BX.bitrix_sessid === 'function')
                ? BX.bitrix_sessid()
                : (window.BX && BX.message ? BX.message('bitrix_sessid') : '');
            var q = String(query || '12');
            var entity = {
                id: 'crm',
                options: {
                    entityTypeId: Number(entityTypeId || 3),
                    entityTypeIds: [Number(entityTypeId || 3)]
                },
                searchable: true,
                dynamicLoad: true,
                dynamicSearch: true,
                filters: [],
                substituteEntityId: null
            };
            var dialog = {
                id: dialogId,
                context: context,
                entities: [entity],
                preselectedItems: [],
                recentItemsLimit: 20,
                clearUnavailableItems: true
            };
            BX.ajax({
                url: '/bitrix/services/main/ajax.php?context=' + encodeURIComponent(context) + '&action=ui.entityselector.load',
                method: 'POST',
                dataType: 'json',
                data: JSON.stringify({ sessid: sessid, dialog: dialog }),
                onsuccess: function (loadRes) {
                    BX.ajax({
                        url: '/bitrix/services/main/ajax.php?context=' + encodeURIComponent(context) + '&action=ui.entityselector.doSearch',
                        method: 'POST',
                        dataType: 'json',
                        data: JSON.stringify({
                            sessid: sessid,
                            dialog: dialog,
                            searchQuery: {
                                queryWords: [q],
                                query: q,
                                dynamicSearchEntities: []
                            }
                        }),
                        onsuccess: function (searchRes) {
                            resolve({ ok: true, context: context, entityTypeId: entityTypeId, load: loadRes, search: searchRes });
                        },
                        onfailure: function (err) {
                            resolve({ ok: false, context: context, entityTypeId: entityTypeId, load: loadRes, error: err });
                        }
                    });
                },
                onfailure: function (err) {
                    resolve({ ok: false, context: context, entityTypeId: entityTypeId, error: err });
                }
            });
        });
    }

    async function probeProviders() {
        if (!window.BX || !BX.ajax) {
            log('ERROR', 'BX.ajax not available');
            return;
        }

        log('INFO', '=== PROBE START ===');

        // Candidate provider IDs for dynamic type items across Bitrix versions
        var candidates = [
            { id: 'crm-dynamic-type-items', opt: { dynamicTypeId: 1038 } },
            { id: 'crm_dynamic_type_items', opt: { dynamicTypeId: 1038 } },
            { id: 'crm-dynamic-items', opt: { dynamicTypeId: 1038 } },
            { id: 'crm_dynamic_items', opt: { dynamicTypeId: 1038 } },
            // Some installs expose dynamic items via CRM provider
            { id: 'crm', opt: { entityTypeId: 1038 } },
            { id: 'crm', opt: { entityTypeName: 'DYNAMIC_1038' } },
            { id: 'crm', opt: { entityTypeId: 1038, entityTypeName: 'DYNAMIC_1038' } },
            // sanity checks (these SHOULD return entities/tabs on any CRM install)
            { id: 'crm', opt: { entityTypeId: 3 } }, // Contact
            { id: 'crm', opt: { entityTypeId: 4 } }, // Company
            { id: 'user', opt: {} }
        ];

        for (var i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            log('INFO', 'Probe provider', { providerId: c.id, options: c.opt });
            var r = await runProbe(c.id, c.opt);

            if (!r.ok) {
                var firstErr = null;
                if (Array.isArray(r.error) && r.error.length) { firstErr = r.error[0]; }
                log('ERROR', 'Probe failed', {
                    providerId: c.id,
                    options: c.opt,
                    status: r.raw && r.raw.status ? r.raw.status : null,
                    firstError: firstErr,
                    error: r.error
                });
                continue;
            }

            var d = r.dialog || {};
            log('INFO', 'Probe result', {
                providerId: c.id,
                options: c.opt,
                hasDialog: !!r.dialog,
                itemsCount: Array.isArray(d.items) ? d.items.length : null,
                tabsCount: Array.isArray(d.tabs) ? d.tabs.length : null,
                entitiesCount: Array.isArray(d.entities) ? d.entities.length : null,
                entities: d.entities || null,
                tabs: d.tabs || null
            });

            // Probe doSearch as well (same payload format)
            try {
                var sessid = (window.BX && typeof BX.bitrix_sessid === 'function')
                    ? BX.bitrix_sessid()
                    : (window.BX && BX.message ? BX.message('bitrix_sessid') : '');

                var searchDialog = {
                    id: 'PROBE_DIALOG_' + c.id,
                    context: 'PROBE_' + c.id,
                    entities: [
                        {
                            id: c.id,
                            options: c.opt || {},
                            searchable: true,
                            dynamicLoad: true,
                            dynamicSearch: true,
                            filters: [],
                            substituteEntityId: null
                        }
                    ],
                    preselectedItems: [],
                    recentItemsLimit: 20,
                    clearUnavailableItems: true
                };

                var q = '12';
                var doSearchPayload = JSON.stringify({
                    sessid: sessid,
                    dialog: searchDialog,
                    searchQuery: {
                        queryWords: [q],
                        query: q,
                        dynamicSearchEntities: []
                    }
                });

                await new Promise(function (resolveSearch) {
                    BX.ajax({
                        url: '/bitrix/services/main/ajax.php?context=' + encodeURIComponent('PROBE_' + c.id) + '&action=ui.entityselector.doSearch',
                        method: 'POST',
                        dataType: 'json',
                        data: doSearchPayload,
                        onsuccess: function (res2) {
                            if (res2 && res2.status && String(res2.status).toLowerCase() === 'error') {
                                log('ERROR', 'Probe doSearch failed', { providerId: c.id, options: c.opt, status: res2.status, errors: res2.errors || [] });
                                resolveSearch();
                                return;
                            }
                            var d2 = (res2 && res2.data && res2.data.dialog) ? res2.data.dialog : null;
                            log('INFO', 'Probe doSearch result', {
                                providerId: c.id,
                                options: c.opt,
                                itemsCount: d2 && Array.isArray(d2.items) ? d2.items.length : null,
                                tabsCount: d2 && Array.isArray(d2.tabs) ? d2.tabs.length : null,
                                entitiesCount: d2 && Array.isArray(d2.entities) ? d2.entities.length : null
                            });
                            resolveSearch();
                        },
                        onfailure: function (err2) {
                            log('ERROR', 'Probe doSearch XHR failed', { providerId: c.id, options: c.opt, error: String(err2 && err2.message ? err2.message : err2) });
                            resolveSearch();
                        }
                    });
                });
            } catch (e) {
                log('ERROR', 'Probe doSearch exception', { providerId: c.id, error: String(e && e.message ? e.message : e) });
            }
        }

        var contextProbeCurrent = await runContextProbe(klubContext, klubDialogId, klubEntityTypeId || 3, '12');
        log('INFO', 'Context probe (current)', {
            context: klubContext,
            dialogId: klubDialogId,
            entityTypeId: klubEntityTypeId || 3,
            loadStatus: contextProbeCurrent && contextProbeCurrent.load ? contextProbeCurrent.load.status : null,
            loadDialog: contextProbeCurrent && contextProbeCurrent.load && contextProbeCurrent.load.data ? contextProbeCurrent.load.data.dialog : null,
            searchStatus: contextProbeCurrent && contextProbeCurrent.search ? contextProbeCurrent.search.status : null,
            searchDialog: contextProbeCurrent && contextProbeCurrent.search && contextProbeCurrent.search.data ? contextProbeCurrent.search.data.dialog : null
        });

        var contextProbeCrm = await runContextProbe('CRM', 'PROBE_CRM_' + filterId, klubEntityTypeId || 3, '12');
        log('INFO', 'Context probe (CRM)', {
            context: 'CRM',
            dialogId: 'PROBE_CRM_' + filterId,
            entityTypeId: klubEntityTypeId || 3,
            loadStatus: contextProbeCrm && contextProbeCrm.load ? contextProbeCrm.load.status : null,
            loadDialog: contextProbeCrm && contextProbeCrm.load && contextProbeCrm.load.data ? contextProbeCrm.load.data.dialog : null,
            searchStatus: contextProbeCrm && contextProbeCrm.search ? contextProbeCrm.search.status : null,
            searchDialog: contextProbeCrm && contextProbeCrm.search && contextProbeCrm.search.data ? contextProbeCrm.search.data.dialog : null
        });

        log('INFO', '=== PROBE END ===');
    }

    document.getElementById('probeProvidersBtn').addEventListener('click', function () {
        probeProviders();
    });

    log('INFO', 'Страница теста загружена', {
        templateId: 'public_test',
        filterId: filterId,
        gridId: gridId,
        filterFieldsCount: fieldIds.length,
        filterFieldIds: fieldIds,
        configuredFieldTypes: fieldTypes
    });
    if (transportMarkers.length > 0) {
        log('INFO', 'Transport markers', transportMarkers);
    }
})();
</script>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';

function buildFilterFieldsAuto(int $entityTypeId, string $filterId, array $selectedByCode, array &$warnings, int $klubEntityTypeId = 0): array
{
    $out = [];

    $makeId = static function (string $code) use ($entityTypeId): string {
        return 'root_DYNAMIC_'.$entityTypeId.'__'.$code;
    };

    foreach ($selectedByCode as $code => $meta)
    {
        $code = (string)$code;
        $meta = is_array($meta) ? $meta : [];
        if ($code === '') { continue; }

        $uc = strtoupper($code);
        $title = (string)($meta['title'] ?? $code);
        $typeId = strtolower((string)($meta['typeId'] ?? ''));
        $uf = is_array($meta['userField'] ?? null) ? (array)$meta['userField'] : [];
        $userTypeId = strtolower((string)($uf['USER_TYPE_ID'] ?? ''));
        $isMultiple = !empty($meta['isMultiple']);
        $settings = is_array($meta['settings'] ?? null) ? (array)$meta['settings'] : [];

        // Standard CRM responsible — render via dest_selector (works reliably)
        if ($uc === 'ASSIGNED_BY_ID')
        {
            $out[] = [
                'id' => $makeId($code),
                'name' => $title,
                'type' => 'dest_selector',
                'default' => true,
                'params' => [
                    'multiple' => 'N',
                    'context' => 'CRM',
                    'contextCode' => 'U',
                    'enableAll' => 'N',
                    'enableUsers' => 'Y',
                    'enableUserSearch' => 'Y',
                    'isNumeric' => 'Y',
                ],
            ];
            continue;
        }

        // Boolean
        if ($userTypeId === 'boolean' || $typeId === 'boolean')
        {
            $out[] = [
                'id' => $makeId($code),
                'name' => $title,
                'type' => 'checkbox',
                'default' => true,
            ];
            continue;
        }

        // Date / DateTime
        if (in_array($userTypeId, ['date', 'datetime'], true) || in_array($typeId, ['date', 'datetime'], true))
        {
            $out[] = [
                'id' => $makeId($code),
                'name' => $title,
                'type' => 'date',
                'default' => true,
            ];
            continue;
        }

        // Enumeration (UF list)
        if ($userTypeId === 'enumeration' || $typeId === 'enumeration')
        {
            $items = (array)($meta['enumItems'] ?? []);
            if (empty($items))
            {
                $warnings[] = ['level' => 'WARN', 'field' => $code, 'message' => 'Enum items are empty'];
            }

            $field = [
                'id' => $makeId($code),
                'name' => $title,
                'type' => 'list',
                'default' => true,
                'items' => $items,
            ];
            if ($isMultiple)
            {
                $field['params'] = ['multiple' => 'Y'];
            }
            $out[] = $field;
            continue;
        }

        // CRM element binding (UF "crm") — in this portal/version works via DEST_SELECTOR (main.ui.selector)
        if ($userTypeId === 'crm')
        {
            $refDynamicIds = [];
            foreach ($settings as $k => $v)
            {
                if ((string)$v !== 'Y') { continue; }
                if (preg_match('~^DYNAMIC_(\d+)$~', (string)$k, $m))
                {
                    $refDynamicIds[] = (int)$m[1];
                }
            }
            $refDynamicIds = array_values(array_unique(array_filter($refDynamicIds)));

            if ($uc === 'UF_CRM_9_KLUB' && $klubEntityTypeId > 0)
            {
                $refDynamicIds = [$klubEntityTypeId];
            }

            $entityTypeIds = !empty($refDynamicIds)
                ? $refDynamicIds
                : FieldMapper::resolveEntityTypeIds([
                    'code' => $code,
                    'crmType' => 'crm_entity',
                    'settings' => $settings,
                ]);
            $entityTypeIds = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds))));
            if (empty($entityTypeIds)) { $entityTypeIds = [3]; }
            $dynamicTitleKey = 'DYNAMICS_'.$entityTypeIds[0];

            $out[] = [
                'id' => $makeId($code),
                'name' => $title,
                'type' => 'dest_selector',
                'default' => true,
                'params' => [
                    'multiple' => $isMultiple ? 'Y' : 'N',
                    'context' => 'CRM_UF_FILTER_ENTITY',
                    'contextCode' => 'CRM',
                    'apiVersion' => 3,
                    'enableUsers' => 'N',
                    'enableDepartments' => 'N',
                    'enableCrm' => 'Y',
                    'convertJson' => 'Y',
                    'useClientDatabase' => 'N',
                    'enableCrmDynamics' => [(string)$entityTypeIds[0] => 'Y'],
                    'addTabCrmDynamics' => [(string)$entityTypeIds[0] => 'N'],
                    'addTabCrmContacts' => 'N',
                    'addTabCrmCompanies' => 'N',
                    'addTabCrmLeads' => 'N',
                    'addTabCrmDeals' => 'N',
                    'crmDynamicTitles' => [$dynamicTitleKey => $title],
                ],
            ];
            $warnings[] = ['level' => 'INFO', 'field' => $code, 'message' => 'crm-uf via main.ui.selector'];
            continue;
        }

        // Number
        if (in_array($typeId, ['integer', 'int', 'double', 'number'], true))
        {
            $out[] = [
                'id' => $makeId($code),
                'name' => $title,
                'type' => 'number',
                'default' => true,
            ];
            continue;
        }

        // Default string
        $out[] = [
            'id' => $makeId($code),
            'name' => $title,
            'type' => 'string',
            'default' => true,
        ];
    }

    // Keep only valid entries
    $out = array_values(array_filter($out, static function ($f) {
        return is_array($f) && !empty($f['id']) && !empty($f['type']);
    }));

    return $out;
}

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

    return buildMetaMapFromFactory($contactFactory, ['CRM_CONTACT'], 'contact');
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
        return FieldMapper::detectType($m) === 'number' && strtoupper((string)$m['code']) === 'ID';
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'number';
    });

    $picked['date'] = findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'date' && in_array(strtoupper((string)$m['code']), ['CREATED_TIME', 'UPDATED_TIME'], true);
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'date';
    });

    $picked['select'] = findField($contactMeta, static function ($m): bool {
        return FieldMapper::detectType($m) === 'list' && empty($m['isMultiple']);
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'list' && empty($m['isMultiple']);
    });

    $picked['multiselect'] = findField($contactMeta, static function ($m): bool {
        return FieldMapper::detectType($m) === 'list' && !empty($m['isMultiple']);
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'list' && !empty($m['isMultiple']);
    });

    $picked['dest_selector'] = findField($dynamicMeta, static function ($m): bool {
        return FieldMapper::detectType($m) === 'dest_selector' && in_array(strtoupper((string)$m['code']), ['CREATED_BY', 'ASSIGNED_BY_ID'], true);
    }) ?: findField($all, static function ($m): bool {
        return FieldMapper::detectType($m) === 'dest_selector';
    });

    $picked['entity_contact'] = findField($dynamicMeta, static function ($m): bool {
        return FieldMapper::detectType($m) === 'entity_selector' && in_array(3, FieldMapper::resolveEntityTypeIds($m), true);
    });

    $picked['entity_company'] = findField($dynamicMeta, static function ($m): bool {
        return FieldMapper::detectType($m) === 'entity_selector' && in_array(4, FieldMapper::resolveEntityTypeIds($m), true);
    });

    if (empty($picked['select']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'SELECT fallback field used'];
        $picked['select'] = ['source' => 'fallback', 'code' => 'FALLBACK_SELECT', 'title' => 'Fallback Select', 'crmType' => 'list', 'userTypeId' => '', 'isMultiple' => false, 'settings' => [], 'items' => ['A' => 'Вариант A', 'B' => 'Вариант B'], 'isUf' => false];
    }

    if (empty($picked['multiselect']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'MULTI_SELECT fallback field used'];
        $picked['multiselect'] = ['source' => 'fallback', 'code' => 'FALLBACK_MULTI', 'title' => 'Fallback Multi', 'crmType' => 'list', 'userTypeId' => '', 'isMultiple' => true, 'settings' => [], 'items' => ['1' => 'Один', '2' => 'Два', '3' => 'Три'], 'isUf' => false];
    }

    if (empty($picked['dest_selector']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'DEST_SELECTOR fallback field used'];
        $picked['dest_selector'] = ['source' => 'fallback', 'code' => 'CREATED_BY', 'title' => 'Создал', 'crmType' => 'user', 'userTypeId' => '', 'isMultiple' => false, 'settings' => [], 'items' => [], 'isUf' => false];
    }

    if (empty($picked['entity_contact']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'ENTITY_SELECTOR CONTACT fallback field used'];
        $picked['entity_contact'] = ['source' => 'fallback', 'code' => 'CONTACT_ID', 'title' => 'Контакт', 'crmType' => 'crm_contact', 'userTypeId' => '', 'isMultiple' => false, 'settings' => [], 'items' => [], 'isUf' => false, 'forceEntityTypeId' => 3];
    }

    if (empty($picked['entity_company']))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'ENTITY_SELECTOR COMPANY fallback field used'];
        $picked['entity_company'] = ['source' => 'fallback', 'code' => 'COMPANY_ID', 'title' => 'Компания', 'crmType' => 'crm_company', 'userTypeId' => '', 'isMultiple' => false, 'settings' => [], 'items' => [], 'isUf' => false, 'forceEntityTypeId' => 4];
    }

    return array_filter($picked);
}

function buildFilterFieldsFromMeta(array $selected, int $dynamicTypeId, string $filterId, array &$warnings): array
{
    $fields = [];
    $needed = ['string', 'number', 'date', 'select', 'multiselect', 'dest_selector', 'entity_contact', 'entity_company'];

    foreach ($needed as $slot)
    {
        $meta = $selected[$slot] ?? null;
        if (!$meta)
        {
            $warnings[] = ['level' => 'WARN', 'message' => 'No field for slot '.$slot];
            continue;
        }

        $fieldId = ($meta['source'] ?? '') === 'contact' ? 'contact__'.$meta['code'] : 'root_DYNAMIC_'.$dynamicTypeId.'__'.$meta['code'];
        $uiField = FieldMapper::toUiFilterField($meta, [
            'fieldId' => $fieldId,
            'filterId' => $filterId,
            'entityTypeId' => $dynamicTypeId,
        ]);

        if (!empty($uiField['_warnings']))
        {
            foreach ((array)$uiField['_warnings'] as $w)
            {
                $warnings[] = ['level' => 'WARN', 'field' => $meta['code'], 'message' => $w];
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
        if ($predicate($meta)) { return $meta; }
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
    if ($userTypeId === 'boolean') { return ['1' => 'Да', '0' => 'Нет']; }

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

function resolveCrmEntityTypeIdFromMeta(array $meta, int $override = 0): int
{
    if ($override > 0)
    {
        return $override;
    }

    $settings = is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
    foreach ($settings as $k => $v)
    {
        if ((string)$v !== 'Y')
        {
            continue;
        }
        if (preg_match('~^DYNAMIC_(\d+)$~', (string)$k, $m))
        {
            return (int)$m[1];
        }
    }

    $resolved = FieldMapper::resolveEntityTypeIds([
        'code' => (string)($meta['code'] ?? ''),
        'crmType' => (string)($meta['typeId'] ?? ''),
        'settings' => $settings,
    ]);
    $resolved = array_values(array_filter(array_map('intval', $resolved), static function ($id) {
        return $id > 0;
    }));

    return !empty($resolved) ? (int)$resolved[0] : 3;
}

function pickFixedFields(array $dynamicMeta, array $codes, array &$warnings): array
{
    $picked = [];
    foreach ($codes as $code)
    {
        $code = (string)$code;
        if ($code === '') { continue; }

        $meta = $dynamicMeta[$code] ?? null;
        if (!$meta)
        {
            $warnings[] = ['level' => 'WARN', 'message' => 'Dynamic field not found: '.$code];
            continue;
        }
        $picked[$code] = $meta;
    }
    return $picked;
}

function buildFilterFieldsFromFixed(array $selectedByCode, int $dynamicTypeId, string $filterId, array &$warnings): array
{
    $fields = [];

    foreach ($selectedByCode as $code => $meta)
    {
        $fieldId = 'root_DYNAMIC_'.$dynamicTypeId.'__'.$meta['code'];

        // For this fixed test we additionally force correct UI types where Bitrix expects special types
        $uiField = FieldMapper::toUiFilterField($meta, [
            'fieldId' => $fieldId,
            'filterId' => $filterId,
            'entityTypeId' => $dynamicTypeId,
        ]);

        // Enforce exact mapping for the 4 requested fields
        $uc = strtoupper((string)$meta['code']);
        if ($uc === 'UF_CRM_9_1C')
        {
            // boolean -> checkbox
            $uiField['type'] = 'checkbox';
            unset($uiField['items'], $uiField['params']);
        }
        elseif ($uc === 'UF_CRM_9_TEMA')
        {
            // enumeration multiple -> list + multiple=Y
            $uiField['type'] = 'list';
            $uiField['params'] = ['multiple' => 'Y'];
            $uiField['items'] = (array)($meta['items'] ?? []);
        }
        elseif ($uc === 'UF_CRM_9_KLUB')
        {
            // crm link to dynamic 1038, multiple -> entity_selector + multiple=Y
            $uiField['type'] = 'entity_selector';
            $uiField['params'] = $uiField['params'] ?? [];
            $uiField['params']['multiple'] = 'Y';

            // dialogOptions must exist for public page
            $ctx = 'GNC_FILTER_'.$filterId.'_'.$uc;
            $uiField['params']['dialogOptions'] = [
                'id' => $fieldId.'_'.$filterId,
                'context' => $ctx,
                'multiple' => true,
                'dropdownMode' => true,
                'recentItemsLimit' => 20,
                'clearUnavailableItems' => true,
                'entities' => [
                    [
                        'id' => 'crm',
                        'options' => ['entityTypeId' => 1038],
                        'searchable' => true,
                        'dynamicLoad' => true,
                        'dynamicSearch' => true,
                    ],
                ],
            ];
        }
        elseif ($uc === 'UF_CRM_9_DATE_OS')
        {
            // date -> date
            $uiField['type'] = 'date';
        }

        if (!empty($uiField['_warnings']))
        {
            foreach ((array)$uiField['_warnings'] as $w)
            {
                $warnings[] = ['level' => 'WARN', 'field' => $meta['code'], 'message' => $w];
            }
        }
        unset($uiField['_warnings']);

        $fields[] = $uiField;
    }

    return $fields;
}


function dumpFieldsFromFactory(int $entityTypeId): array
{
    $factory = Container::getInstance()->getFactory($entityTypeId);
    if (!$factory)
    {
        return ['byCode' => [], 'error' => 'Factory not found'];
    }

    $byCode = [];
    $collection = $factory->getFieldsCollection();
    foreach ($collection as $field)
    {
        $code = method_exists($field, 'getName') ? (string)$field->getName() : (string)($field->getFieldName() ?? '');
        if ($code === '') { continue; }

        $title = '';
        if (method_exists($field, 'getTitle')) { $title = (string)$field->getTitle(); }
        if ($title === '') { $title = $code; }

        $typeId = '';
        if (method_exists($field, 'getTypeId')) { $typeId = (string)$field->getTypeId(); }
        elseif (method_exists($field, 'getType')) { $typeId = (string)$field->getType(); }

        $isMultiple = false;
        if (method_exists($field, 'isMultiple')) { $isMultiple = (bool)$field->isMultiple(); }
        elseif (method_exists($field, 'isMulti')) { $isMultiple = (bool)$field->isMulti(); }

        $settings = [];
        if (method_exists($field, 'getSettings')) { $settings = (array)$field->getSettings(); }

        $userField = [];
        if (method_exists($field, 'getUserField'))
        {
            $uf = $field->getUserField();
            if (is_array($uf)) { $userField = $uf; }
        }

        $enumItems = [];
        $userTypeId = strtolower((string)($userField['USER_TYPE_ID'] ?? ''));
        if ($userTypeId === 'enumeration' && !empty($userField['ID']))
        {
            $rs = (new \CUserFieldEnum())->GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['USER_FIELD_ID' => (int)$userField['ID']]);
            while ($row = $rs->Fetch())
            {
                $enumItems[(string)$row['ID']] = (string)$row['VALUE'];
            }
        }

        
        $byCode[$code] = [
            'code' => $code,
            'title' => $title,
            'typeId' => strtolower($typeId),
            'isMultiple' => $isMultiple,
            'settings' => $settings,
            'userField' => $userField,
            'enumItems' => $enumItems,
        ];
    }

    return ['byCode' => $byCode];
}

function buildFilterFieldsDirect(int $dynamicTypeId, string $filterId, array $selectedByCode, array &$warnings): array
{
    $out = [];

    $makeId = static function (string $code) use ($dynamicTypeId): string {
        return 'root_DYNAMIC_'.$dynamicTypeId.'__'.$code;
    };

    // UF_CRM_9_1C (boolean) -> checkbox
    $meta = $selectedByCode['UF_CRM_9_1C'] ?? [];
    $out[] = [
        'id' => $makeId('UF_CRM_9_1C'),
        'name' => (string)($meta['title'] ?? 'С 1С сверено'),
        'type' => 'checkbox',
        'default' => true,
    ];

    // UF_CRM_9_KLUB (crm link to DYNAMIC_1038, multiple) -> list (dropdown)
    $meta = $selectedByCode['UF_CRM_9_KLUB'] ?? [];
    $fieldId = $makeId('UF_CRM_9_KLUB');

    // load items from CRM dynamic type 1038 (clubs)
    $clubItems = loadCrmDynamicItemsForFilter(1038, 500);
    if (empty($clubItems))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'UF_CRM_9_KLUB: dynamic 1038 items empty (check rights / items count)'];
    }

    $out[] = [
        'id' => $fieldId,
        'name' => (string)($meta['title'] ?? 'Клуб'),
        'type' => 'list',
        'default' => true,
        'items' => $clubItems,
        'params' => [
            'multiple' => 'Y',
        ],
    ];


    // UF_CRM_9_TEMA (enumeration, multiple) -> list + multiple=Y + items
    $meta = $selectedByCode['UF_CRM_9_TEMA'] ?? [];
    $items = (array)($meta['enumItems'] ?? []);
    if (empty($items))
    {
        $warnings[] = ['level' => 'WARN', 'message' => 'UF_CRM_9_TEMA enumItems empty (using fallback)'];
        $items = [
            '402' => 'Молитвенная просьба',
            '403' => 'Свидетельство',
            '404' => 'Благодарность',
            '405' => 'Отзыв',
            '406' => 'Другое',
            '650' => 'Возобновление подписки на ТL',
            '651' => 'Подписка на TL',
            '655' => 'Задать вопрос',
        ];
    }

    $out[] = [
        'id' => $makeId('UF_CRM_9_TEMA'),
        'name' => (string)($meta['title'] ?? 'Тема обращения'),
        'type' => 'list',
        'default' => true,
        'items' => $items,
        'params' => ['multiple' => 'Y'],
    ];

    // UF_CRM_9_DATE_OS (date) -> date
    $meta = $selectedByCode['UF_CRM_9_DATE_OS'] ?? [];
    $out[] = [
        'id' => $makeId('UF_CRM_9_DATE_OS'),
        'name' => (string)($meta['title'] ?? 'Дата ответа'),
        'type' => 'date',
        'default' => true,
    ];

    // ASSIGNED_BY_ID (responsible) -> dest_selector (standard Bitrix)
    $meta = $selectedByCode['ASSIGNED_BY_ID'] ?? [];

    
    $out[] = [
        'id' => $makeId('ASSIGNED_BY_ID'),
        'name' => (string)($meta['title'] ?? 'Ответственный'),
        'type' => 'dest_selector',
        'default' => true,
        'params' => [
            // Standard user selector
            'multiple' => 'N',
            'context' => 'CRM',
            'contextCode' => 'U',
            'enableAll' => 'N',
            'enableUsers' => 'Y',
            'enableUserSearch' => 'Y',
            'isNumeric' => 'Y',
        ],
    ];

    // Keep only valid entries
    $out = array_values(array_filter($out, static function ($f) {
        return !empty($f['id']) && !empty($f['type']);
    }));

    return $out;
}


function loadUsersForFilter(int $limit = 200): array
{
    // Kept for possible fallback/debug (older versions used dropdown list for responsible)
    $items = [];

    $limit = max(1, (int)$limit);

    // Prefer active users
    $rs = \CUser::GetList(
        $by = 'last_name',
        $order = 'asc',
        ['ACTIVE' => 'Y'],
        ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'], 'NAV_PARAMS' => ['nTopCount' => $limit]]
    );

    while ($u = $rs->Fetch())
    {
        $id = (string)($u['ID'] ?? '');
        if ($id === '') { continue; }

        $name = trim(
            (string)($u['LAST_NAME'] ?? '').' '.
            (string)($u['NAME'] ?? '').' '.
            (string)($u['SECOND_NAME'] ?? '')
        );
        if ($name === '')
        {
            $name = (string)($u['LOGIN'] ?? $id);
        }

        $items[$id] = $name;
    }

    return $items;
}

function loadCrmDynamicItemsForFilter(int $dynamicTypeId, int $limit = 200): array
{
    $items = [];
    $dynamicTypeId = max(1, (int)$dynamicTypeId);
    $limit = max(1, (int)$limit);

    $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($dynamicTypeId);
    if (!$factory)
    {
        return $items;
    }

    // Try to fetch a reasonable amount of items for a dropdown
    try
    {
        $titleField = null;
        if (method_exists($factory, 'getTitleFieldName'))
        {
            $titleField = (string)$factory->getTitleFieldName();
            $titleField = $titleField !== '' ? $titleField : null;
        }

        $select = ['ID'];
        if ($titleField) { $select[] = $titleField; }
        // also try common fallbacks
        $select[] = 'TITLE';
        $select[] = 'NAME';

        $itemsCollection = $factory->getItems([
            'select' => array_values(array_unique($select)),
            'order' => ['ID' => 'ASC'],
            'limit' => $limit,
        ]);

        foreach ($itemsCollection as $item)
        {
            $id = (string)$item->getId();
            if ($id === '') { continue; }

            $title = '';

            // Smart-process items: title can be exposed in different ways depending on edition/version
            if (method_exists($item, 'getTitle'))
            {
                $title = (string)$item->getTitle();
            }
            if ($title === '' && method_exists($item, 'getHeading'))
            {
                $title = (string)$item->getHeading();
            }
            if ($title === '' && method_exists($item, 'get'))
            {
                if ($title === '' && $titleField)
                {
                    $v = $item->get($titleField);
                    if (is_string($v))
                    {
                        $title = (string)$v;
                    }
                    elseif (is_array($v) && isset($v['VALUE']))
                    {
                        $title = (string)$v['VALUE'];
                    }
                }
                $v = $item->get('TITLE');
                if (is_string($v))
                {
                    $title = (string)$v;
                }
                elseif (is_array($v) && isset($v['VALUE']))
                {
                    $title = (string)$v['VALUE'];
                }

                if ($title === '')
                {
                    $v = $item->get('NAME');
                    if (is_string($v))
                    {
                        $title = (string)$v;
                    }
                    elseif (is_array($v) && isset($v['VALUE']))
                    {
                        $title = (string)$v['VALUE'];
                    }
                }
            }

            $title = trim($title);
            if ($title === '')
            {
                $title = 'ID '.$id;
            }

            $items[$id] = $title;
        }
    }
    catch (\Throwable $e)
    {
        // keep silent, caller will warn based on emptiness
    }

    return $items;
}

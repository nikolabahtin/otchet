<?php
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\Json;
use Bitrix\Main\UI\Extension;
use Bitrix\Highloadblock as HL;
use Bitrix\Crm\Service\Container;

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';

global $APPLICATION, $USER, $USER_FIELD_MANAGER;

$templateId = (string)($_GET['id'] ?? '');
$debugEnabled = strtoupper((string)($_GET['debug'] ?? 'N')) === 'Y';
$userId = (int)($USER ? $USER->GetID() : 0);

if ($userId <= 0)
{
    echo '<div class="gnc-slider-page"><div class="gnc-config-card"><div class="gnc-empty">Пользователь не авторизован.</div></div></div>';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    return;
}

if (!Loader::includeModule('crm'))
{
    echo '<div class="gnc-slider-page"><div class="gnc-config-card"><div class="gnc-empty">Модуль CRM не подключен.</div></div></div>';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    return;
}

\CJSCore::Init(['ajax', 'popup', 'fx', 'date', 'finder', 'crm', 'sidepanel', 'ui.design-tokens', 'ui.fonts.opensans', 'ui.entity-selector']);
safeLoadUiExtensions([
    'main.core',
    'main.core.ajax',
    'main.ui.filter',
    'main.ui.filter.fields',
    'main.ui.buttons',
    'ui',
    'ui.entity-selector',
    'crm.entity-selector',
    'crm.selector',
    'crm.entity-editor',
    'main.popup',
    'ui.forms',
]);

$template = loadTemplateData($templateId, $userId, true);
if (!$template)
{
    echo '<div class="gnc-slider-page"><div class="gnc-config-card"><div class="gnc-empty">Шаблон не найден. ID: '.htmlspecialcharsbx($templateId).'</div></div></div>';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    return;
}

$config = is_array($template['config'] ?? null) ? $template['config'] : [];
$nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];

$entityCodes = [];
foreach ($nodes as $node)
{
    $code = (string)($node['entityCode'] ?? '');
    if ($code !== '')
    {
        $entityCodes[$code] = true;
    }
}
$filterItemsFromConfig = is_array($config['filterFields'] ?? null) ? $config['filterFields'] : [];
foreach ($filterItemsFromConfig as $filterItem)
{
    $code = (string)($filterItem['entityCode'] ?? '');
    if ($code !== '')
    {
        $entityCodes[$code] = true;
    }
}

$metaByEntity = [];
foreach (array_keys($entityCodes) as $entityCode)
{
    $metaByEntity[$entityCode] = getEntityFieldMetaMap($entityCode);
}

$filterId = 'GNC_OTCHET_FILTER_'.preg_replace('/[^A-Za-z0-9_]/', '_', $template['id']);
$gridId = 'GNC_OTCHET_GRID_'.preg_replace('/[^A-Za-z0-9_]/', '_', $template['id']);

$columns = buildTemplateColumns($config, $metaByEntity);
$filterFields = buildFilterFields($config, $metaByEntity, $filterId, $gridId);
$filterFieldsForUi = array_map(static function (array $field): array {
    unset($field['_source']);
    unset($field['_debugTargets']);
    return $field;
}, $filterFields);
$moduleVersions = [
    'main' => getModuleVersionSafe('main'),
    'crm' => getModuleVersionSafe('crm'),
    'ui' => getModuleVersionSafe('ui'),
];
$filterDebugPayload = $debugEnabled ? buildFilterDebugPayload($config, $metaByEntity, $filterFields, $moduleVersions) : [];
$assetVersion = '20260223-12';
?>
<div class="gnc-slider-page">
    <section class="gnc-config-card">
        <div class="gnc-slider-page-head">
            <h2>Формирование отчета: <?=htmlspecialcharsbx((string)$template['name'])?></h2>
            <a href="/local/otchet/index.php" class="ui-btn ui-btn-light-border">Назад к шаблонам</a>
        </div>
        <p class="gnc-node-meta">Используется фильтр ядра Битрикс.</p>

        <?php
        $APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', [
            'FILTER_ID' => $filterId,
            'GRID_ID' => $gridId,
            'FILTER' => $filterFieldsForUi,
            'ENABLE_LABEL' => true,
            'ENABLE_LIVE_SEARCH' => true,
            'RESET_TO_DEFAULT_MODE' => false,
            'DISABLE_SEARCH' => false,
            'ENABLE_FIELDS_SEARCH' => true,
            'VALUE_REQUIRED_MODE' => false,
        ], false);
        ?>
        <?php if ($debugEnabled): ?>
            <details style="margin-top:10px;">
                <summary style="cursor:pointer;">Debug: метаданные фильтра</summary>
                <pre style="margin-top:8px;max-height:320px;overflow:auto;white-space:pre-wrap;font-size:12px;color:#263845;background:#f8fbff;border:1px solid #d9e1e8;border-radius:8px;padding:10px;"><?=htmlspecialcharsbx(Json::encode($filterDebugPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre>
            </details>
        <?php endif; ?>

        <div style="display:none;">
            <?php
            $APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
                'GRID_ID' => $gridId,
                'COLUMNS' => [
                    ['id' => 'ID', 'name' => 'ID', 'sort' => 'ID', 'default' => true],
                ],
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
        <div class="gnc-actions gnc-build-actions">
            <button type="button" class="ui-btn ui-btn-primary" id="buildReportBtn">Выгрузить в эксель</button>
        </div>
    </section>
    <section class="gnc-config-card">
        <div class="gnc-slider-page-head">
            <h3 style="margin:0;">DBG Логи</h3>
            <div class="gnc-actions" style="margin:0;">
                <button type="button" class="ui-btn ui-btn-light-border" id="dbgRuntimeBtn">Снять runtime</button>
                <button type="button" class="ui-btn ui-btn-light-border" id="dbgCopyBtn">Копировать</button>
                <button type="button" class="ui-btn ui-btn-light-border" id="dbgClearBtn">Очистить</button>
            </div>
        </div>
        <pre id="dbgLogBox" style="margin-top:10px;max-height:280px;overflow:auto;white-space:pre-wrap;font-size:12px;color:#263845;background:#f8fbff;border:1px solid #d9e1e8;border-radius:8px;padding:10px;">Логгер инициализируется...</pre>
    </section>
    <section class="gnc-config-card">
        <h3>Шаблон таблицы</h3>
        <p>Колонки по сохраненному шаблону.</p>
        <div class="gnc-table-wrap">
            <table class="gnc-table">
                <thead>
                <tr>
                    <?php if (empty($columns)): ?>
                        <th class="gnc-empty">В шаблоне нет выбранных колонок.</th>
                    <?php else: ?>
                        <?php foreach ($columns as $col): ?>
                            <th><?=htmlspecialcharsbx((string)$col['title'])?></th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
                </thead>
                <?php if (!empty($columns)): ?>
                    <tbody>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td class="gnc-col-source"><?=htmlspecialcharsbx((string)$col['source'])?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td>...</td>
                        <?php endforeach; ?>
                    </tr>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>
    </section>
    <section class="gnc-config-card">
        <h3>Результат preview</h3>
        <p id="previewMetaText">Нажмите «Выгрузить в эксель», чтобы загрузить данные с учетом фильтра.</p>
        <div class="gnc-table-wrap">
            <table class="gnc-table" id="previewTable">
                <thead>
                <tr id="previewHeadRow">
                    <th class="gnc-empty">Нет данных</th>
                </tr>
                </thead>
                <tbody id="previewBody">
                <tr>
                    <td class="gnc-empty">Результат появится после запроса preview.</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<link rel="stylesheet" href="/local/otchet/assets/style.css?v=<?=$assetVersion?>">
<script>
(function () {
    var btn = document.getElementById('buildReportBtn');
    var dbgBox = document.getElementById('dbgLogBox');
    var dbgCopyBtn = document.getElementById('dbgCopyBtn');
    var dbgClearBtn = document.getElementById('dbgClearBtn');
    var dbgRuntimeBtn = document.getElementById('dbgRuntimeBtn');
    var previewMetaText = document.getElementById('previewMetaText');
    var previewHeadRow = document.getElementById('previewHeadRow');
    var previewBody = document.getElementById('previewBody');
    if (!btn) {
        return;
    }

    var ajaxUrl = '/local/otchet/ajax.php';
    var templateId = '<?=CUtil::JSEscape((string)$template['id'])?>';
    var initPayload = {
        templateId: templateId,
        filterId: '<?=$filterId?>',
        gridId: '<?=$gridId?>',
        filterFieldsCount: <?=count($filterFields)?>,
        filterFieldIds: <?=Json::encode(array_map(static function ($f) { return (string)($f['id'] ?? ''); }, $filterFields))?>
    };

    function nowTs() {
        var d = new Date();
        return d.toISOString();
    }

    function safeStringify(data) {
        try {
            return JSON.stringify(data, null, 2);
        } catch (e) {
            return String(data);
        }
    }

    function dbg(type, message, data) {
        if (!dbgBox) {
            return;
        }
        var line = '[' + nowTs() + '] [' + type + '] ' + message;
        if (typeof data !== 'undefined') {
            line += '\n' + safeStringify(data);
        }
        dbgBox.textContent += '\n' + line + '\n';
        dbgBox.scrollTop = dbgBox.scrollHeight;
    }

    if (dbgClearBtn) {
        dbgClearBtn.addEventListener('click', function () {
            dbgBox.textContent = '';
            dbg('INFO', 'Логи очищены');
        });
    }
    if (dbgCopyBtn) {
        dbgCopyBtn.addEventListener('click', function () {
            var text = dbgBox ? dbgBox.textContent : '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    dbg('INFO', 'Логи скопированы в буфер');
                }).catch(function (err) {
                    dbg('ERROR', 'Не удалось скопировать логи', { message: err && err.message ? err.message : String(err) });
                });
            }
        });
    }
    if (dbgRuntimeBtn) {
        dbgRuntimeBtn.addEventListener('click', function () {
            var managerDump = [];
            try {
                var mgr = (window.BX && BX.Main && BX.Main.filterManager) ? BX.Main.filterManager : null;
                var list = mgr && typeof mgr.getList === 'function' ? mgr.getList() : [];
                if (Array.isArray(list)) {
                    managerDump = list.map(function (item) {
                        var id = '';
                        if (item && typeof item.getId === 'function') {
                            id = String(item.getId() || '');
                        } else if (item && item.id) {
                            id = String(item.id);
                        } else if (item && item.params && item.params.FILTER_ID) {
                            id = String(item.params.FILTER_ID);
                        }
                        var paramsCount = 0;
                        try {
                            var p = item && item.params ? item.params : {};
                            if (Array.isArray(p.FILTER)) {
                                paramsCount = p.FILTER.length;
                            } else if (Array.isArray(p.FIELDS)) {
                                paramsCount = p.FIELDS.length;
                            }
                        } catch (e0) {}
                        return { id: id, paramsFilterCount: paramsCount };
                    });
                }
            } catch (e1) {}

            var f = getFilterInstance();
            if (!f) {
                dbg('INFO', 'Runtime snapshot: filter instance not ready', { managerDump: managerDump });
                return;
            }
            var configured = [];
            try {
                var rawFields = (f.params && Array.isArray(f.params.FILTER) && f.params.FILTER.length)
                    ? f.params.FILTER
                    : ((f.params && Array.isArray(f.params.FIELDS)) ? f.params.FIELDS : []);
                configured = rawFields.map(function (x) {
                    return {
                        id: x && (x.id || x.ID) ? normalizeRuntimeFieldId(String(x.id || x.ID)) : '',
                        type: x && (x.type || x.TYPE) ? String(x.type || x.TYPE) : '',
                        params: x && (x.params || x.PARAMS || x.DIALOG_OPTIONS) ? (x.params || x.PARAMS || { DIALOG_OPTIONS: x.DIALOG_OPTIONS }) : {},
                        itemsCount: x && (x.items || x.ITEMS) && typeof (x.items || x.ITEMS) === 'object' ? Object.keys(x.items || x.ITEMS).length : 0
                    };
                });
            } catch (e) {}
            var runtime = [];
            try {
                runtime = Array.isArray(f.fields) ? f.fields.map(function (x) {
                    return {
                        id: x && x.id ? normalizeRuntimeFieldId(String(x.id)) : '',
                        type: x && x.type ? String(x.type) : '',
                        params: x && x.params ? x.params : {}
                    };
                }) : [];
            } catch (e2) {}
            dbg('INFO', 'Runtime filter snapshot', {
                filterId: f.getId ? f.getId() : '',
                requestedFilterId: '<?=$filterId?>',
                managerDump: managerDump,
                paramsKeys: f && f.params ? Object.keys(f.params) : [],
                paramsRaw: f && f.params ? f.params : {},
                configuredFields: configured,
                runtimeFields: runtime
            });
        });
    }

    dbgBox.textContent = '';
    dbg('INFO', 'Страница отчета загружена', initPayload);

    window.addEventListener('error', function (event) {
        dbg('JS_ERROR', event.message || 'Unknown window error', {
            file: event.filename || '',
            line: event.lineno || 0,
            col: event.colno || 0
        });
    });
    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason && event.reason.message ? event.reason.message : String(event.reason || 'Unknown promise rejection');
        dbg('PROMISE_REJECT', reason);
    });

    if (window.fetch) {
        var originalFetch = window.fetch.bind(window);
        window.fetch = function () {
            var args = Array.prototype.slice.call(arguments);
            var url = '';
            if (args[0] && typeof args[0] === 'string') {
                url = args[0];
            } else if (args[0] && args[0].url) {
                url = args[0].url;
            }

            dbg('FETCH_REQ', url, args[1] || {});
            return originalFetch.apply(window, args).then(function (resp) {
                var cloned = resp.clone();
                cloned.text().then(function (bodyText) {
                    dbg('FETCH_RES', url + ' [' + resp.status + ']', {
                        status: resp.status,
                        ok: resp.ok,
                        bodyPreview: (bodyText || '').slice(0, 1200)
                    });
                }).catch(function () {
                    dbg('FETCH_RES', url + ' [' + resp.status + ']', { status: resp.status, ok: resp.ok });
                });
                return resp;
            }).catch(function (err) {
                dbg('FETCH_ERR', url, { message: err && err.message ? err.message : String(err) });
                throw err;
            });
        };
    }

    if (window.XMLHttpRequest && !window.__GNC_OTCHET_XHR_DEBUG_PATCHED__) {
        window.__GNC_OTCHET_XHR_DEBUG_PATCHED__ = true;
        var xhrOpen = XMLHttpRequest.prototype.open;
        var xhrSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this.__gncDbgMethod = method;
            this.__gncDbgUrl = url;
            return xhrOpen.apply(this, arguments);
        };

        function stripSelectorIdSuffix(id) {
            return String(id || '').replace(/_GNC_OTCHET_FILTER_\d+$/i, '');
        }

        function normalizeEntitySelectorPayload(url, body) {
            if (typeof body !== 'string') {
                return body;
            }
            var targetUrl = String(url || '');
            if (targetUrl.indexOf('action=ui.entityselector.load') < 0 && targetUrl.indexOf('action=ui.entityselector.doSearch') < 0) {
                return body;
            }
            try {
                var payload = JSON.parse(body);
                if (payload && payload.dialog && typeof payload.dialog.id === 'string') {
                    payload.dialog.id = stripSelectorIdSuffix(payload.dialog.id);
                }
                return JSON.stringify(payload);
            } catch (e) {
                return body;
            }
        }

        XMLHttpRequest.prototype.send = function (body) {
            var self = this;
            body = normalizeEntitySelectorPayload(this.__gncDbgUrl, body);
            try {
                this.addEventListener('load', function () {
                    var text = '';
                    try { text = String(self.responseText || ''); } catch (e0) {}
                    dbg('XHR_RES', String(self.__gncDbgUrl || ''), {
                        method: String(self.__gncDbgMethod || ''),
                        status: self.status,
                        bodyPreview: text.slice(0, 1200)
                    });
                });
                this.addEventListener('error', function () {
                    dbg('XHR_ERR', String(self.__gncDbgUrl || ''), {
                        method: String(self.__gncDbgMethod || '')
                    });
                });
            } catch (e1) {}

            dbg('XHR_REQ', String(this.__gncDbgUrl || ''), {
                method: String(this.__gncDbgMethod || ''),
                bodyPreview: typeof body === 'string' ? body.slice(0, 1200) : ''
            });

            return xhrSend.apply(this, arguments);
        };
    }

    // Важно: не переопределяем BX.ajax, иначе теряются методы (например runComponentAction).
    dbg('INFO', 'BX.ajax monkey patch отключен (безопасный режим логирования)');

    (function installEntitySelectorDebug() {
        var attempts = 0;
        var timer = setInterval(function () {
            attempts++;
            var proto = window.BX
                && BX.UI
                && BX.UI.EntitySelector
                && BX.UI.EntitySelector.Dialog
                && BX.UI.EntitySelector.Dialog.prototype;

            if (!proto) {
                if (attempts > 30) {
                    clearInterval(timer);
                    dbg('INFO', 'EntitySelector debug hook: Dialog prototype not found');
                }
                return;
            }

            clearInterval(timer);
            if (proto.__gncDbgPatched) {
                return;
            }
            proto.__gncDbgPatched = true;

            if (typeof proto.show === 'function') {
                var oldShow = proto.show;
                proto.show = function () {
                    try {
                        dbg('ENTITY_SELECTOR_SHOW', 'Dialog show', {
                            context: this && this.getContext ? this.getContext() : '',
                            id: this && this.getId ? normalizeRuntimeFieldId(this.getId()) : ''
                        });
                    } catch (e0) {}
                    return oldShow.apply(this, arguments);
                };
            }

            if (typeof proto.search === 'function') {
                var oldSearch = proto.search;
                proto.search = function (query) {
                    try {
                        dbg('ENTITY_SELECTOR_SEARCH', 'Dialog search', {
                            context: this && this.getContext ? this.getContext() : '',
                            query: String(query || '')
                        });
                    } catch (e1) {}
                    return oldSearch.apply(this, arguments);
                };
            }

            dbg('INFO', 'EntitySelector debug hook installed');
        }, 200);
    })();

    function getFilterInstance() {
        if (!(window.BX && BX.Main && BX.Main.filterManager)) {
            return null;
        }
        var manager = BX.Main.filterManager;
        var direct = manager.getById('<?=$filterId?>');
        if (direct) {
            return direct;
        }
        try {
            var list = manager.getList ? manager.getList() : [];
            if (Array.isArray(list)) {
                for (var i = 0; i < list.length; i++) {
                    var item = list[i];
                    var id = '';
                    if (item && typeof item.getId === 'function') {
                        id = String(item.getId() || '');
                    } else if (item && item.id) {
                        id = String(item.id);
                    } else if (item && item.params && item.params.FILTER_ID) {
                        id = String(item.params.FILTER_ID);
                    }
                    if (id === '<?=$filterId?>') {
                        return item;
                    }
                }
            }
        } catch (e) {}
        return null;
    }

    function waitForFilter(filterId, timeoutMs, intervalMs) {
        timeoutMs = typeof timeoutMs === 'number' ? timeoutMs : 3000;
        intervalMs = typeof intervalMs === 'number' ? intervalMs : 100;
        var startedAt = Date.now();

        return new Promise(function (resolve, reject) {
            (function poll() {
                var instance = getFilterInstance();
                if (instance) {
                    resolve(instance);
                    return;
                }
                if (Date.now() - startedAt >= timeoutMs) {
                    reject(new Error('Filter instance not ready: ' + filterId));
                    return;
                }
                setTimeout(poll, intervalMs);
            })();
        });
    }

    function getFilterValues(filter) {
        if (!filter) {
            return {};
        }
        if (typeof filter.getFilterFieldsValues === 'function') {
            return filter.getFilterFieldsValues();
        }
        if (typeof filter.getApi === 'function') {
            var api = filter.getApi();
            if (api && typeof api.getFilterFieldsValues === 'function') {
                return api.getFilterFieldsValues();
            }
        }
        return {};
    }

    function logFilterState(tag) {
        var filter = getFilterInstance();
        dbg('INFO', tag, {
            bx: !!window.BX,
            bxMain: !!(window.BX && BX.Main),
            filterManager: !!(window.BX && BX.Main && BX.Main.filterManager),
            hasFilterInstance: !!filter
        });
        return filter;
    }

    function normalizeRuntimeFieldId(rawId) {
        var id = String(rawId || '');
        return id.replace(/_GNC_OTCHET_FILTER_\d+$/i, '');
    }

    function logFilterInternals(filter, tag) {
        if (!filter) {
            dbg('INFO', tag + ' (no filter instance)');
            return;
        }
            var configuredTypes = [];
            try {
                var configured = [];
                if (filter.params && Array.isArray(filter.params.FILTER) && filter.params.FILTER.length) {
                    configured = filter.params.FILTER;
                } else if (filter.params && Array.isArray(filter.params.FIELDS)) {
                    configured = filter.params.FIELDS;
                }
                configuredTypes = configured.map(function (f) {
                    return {
                        id: f && (f.id || f.ID) ? normalizeRuntimeFieldId(String(f.id || f.ID)) : '',
                        type: f && (f.type || f.TYPE) ? String(f.type || f.TYPE) : 'string'
                    };
                });
            } catch (e) {}

        var availableTypes = [];
        try {
            availableTypes = filter.types ? Object.keys(filter.types) : [];
        } catch (e2) {}

        var runtimeFields = [];
        try {
            runtimeFields = Array.isArray(filter.fields) ? filter.fields.map(function (f) {
                return {
                    id: f && f.id ? normalizeRuntimeFieldId(String(f.id)) : '',
                    type: f && f.type ? String(f.type) : 'string'
                };
            }) : [];
        } catch (e3) {}

        dbg('INFO', tag, {
            configuredFieldTypes: configuredTypes,
            runtimeFieldTypes: runtimeFields,
            availableFilterTypes: availableTypes
        });
    }

    logFilterInternals(logFilterState('Filter instance check'), 'Filter internals check');
    setTimeout(function () {
        logFilterInternals(logFilterState('Filter instance delayed check (300ms)'), 'Filter internals delayed check (300ms)');
    }, 300);
    setTimeout(function () {
        logFilterInternals(logFilterState('Filter instance delayed check (1000ms)'), 'Filter internals delayed check (1000ms)');
    }, 1000);

    function setPreviewEmpty(message) {
        if (previewHeadRow) {
            previewHeadRow.innerHTML = '<th class="gnc-empty">Нет данных</th>';
        }
        if (previewBody) {
            previewBody.innerHTML = '<tr><td class="gnc-empty">' + (message || 'Нет данных') + '</td></tr>';
        }
    }

    function renderPreview(payload) {
        var columns = payload && Array.isArray(payload.columns) ? payload.columns : [];
        var rows = payload && Array.isArray(payload.rows) ? payload.rows : [];
        var total = payload && typeof payload.total !== 'undefined' ? Number(payload.total) : 0;

        if (!columns.length) {
            setPreviewEmpty('В шаблоне нет выбранных колонок.');
            if (previewMetaText) {
                previewMetaText.textContent = 'Всего строк: ' + total + '.';
            }
            return;
        }

        if (previewHeadRow) {
            previewHeadRow.innerHTML = '';
            columns.forEach(function (col) {
                var th = document.createElement('th');
                th.textContent = String(col.title || col.fieldCode || col.key || '');
                previewHeadRow.appendChild(th);
            });
        }

        if (previewBody) {
            previewBody.innerHTML = '';
            if (!rows.length) {
                var emptyTr = document.createElement('tr');
                var emptyTd = document.createElement('td');
                emptyTd.className = 'gnc-empty';
                emptyTd.colSpan = columns.length;
                emptyTd.textContent = 'Ничего не найдено по текущему фильтру.';
                emptyTr.appendChild(emptyTd);
                previewBody.appendChild(emptyTr);
            } else {
                rows.forEach(function (row) {
                    var tr = document.createElement('tr');
                    columns.forEach(function (col) {
                        var td = document.createElement('td');
                        var key = String(col.key || '');
                        var value = row && row.cells ? row.cells[key] : '';
                        td.textContent = value == null ? '' : String(value);
                        tr.appendChild(td);
                    });
                    previewBody.appendChild(tr);
                });
            }
        }

        if (previewMetaText) {
            previewMetaText.textContent = 'Всего строк: ' + total + '.';
        }
    }

    function requestPreview(filterValues) {
        return new Promise(function (resolve, reject) {
            BX.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    sessid: BX.bitrix_sessid(),
                    action: 'preview',
                    templateId: templateId,
                    page: 1,
                    pageSize: 50,
                    filterValues: JSON.stringify(filterValues || {})
                },
                onsuccess: function (res) {
                    if (!res || res.status !== 'success') {
                        var message = 'Preview request failed';
                        if (res && Array.isArray(res.errors) && res.errors.length && res.errors[0].message) {
                            message = String(res.errors[0].message);
                        }
                        reject(new Error(message));
                        return;
                    }
                    resolve(res.data || {});
                },
                onfailure: function (err) {
                    reject(err instanceof Error ? err : new Error('Preview request failed'));
                }
            });
        });
    }

    waitForFilter('<?=$filterId?>', 3000, 100).then(function (filter) {
        dbg('INFO', 'Filter ready', { id: filter && filter.getId ? filter.getId() : '' });

        var triggerPreview = function () {
            var currentFilter = getFilterInstance();
            var values = getFilterValues(currentFilter);
            dbg('INFO', 'Preview request started', values);
            dbg('BUILD', 'Нажата кнопка "Выгрузить в эксель"', values);
            requestPreview(values).then(function (previewData) {
                dbg('INFO', 'Preview response received', {
                    total: previewData && previewData.total ? previewData.total : 0,
                    columns: previewData && Array.isArray(previewData.columns) ? previewData.columns.length : 0
                });
                renderPreview(previewData);
            }).catch(function (error) {
                dbg('ERROR', 'Preview request failed', { message: error && error.message ? error.message : String(error) });
                setPreviewEmpty('Ошибка preview: ' + (error && error.message ? error.message : 'unknown'));
            });
        };

        btn.addEventListener('click', triggerPreview);

        if (window.BX && typeof BX.addCustomEvent === 'function') {
            BX.addCustomEvent(window, 'BX.Main.Filter:apply', function (filterId) {
                if (String(filterId || '') !== '<?=$filterId?>') {
                    return;
                }
                dbg('INFO', 'Filter apply event', { filterId: filterId });
            });
            BX.addCustomEvent(window, 'BX.Main.Filter:reset', function (filterId) {
                if (String(filterId || '') !== '<?=$filterId?>') {
                    return;
                }
                dbg('INFO', 'Filter reset event', { filterId: filterId });
            });
        }
    }).catch(function (error) {
        dbg('ERROR', 'waitForFilter failed', { message: error && error.message ? error.message : String(error) });
        setPreviewEmpty('Не удалось инициализировать фильтр.');
    });
})();
</script>
<?php require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';

function loadTemplateData(string $templateId, int $userId, bool $allowAnyUser = false): ?array
{
    $templateId = trim($templateId);
    if ($templateId === '')
    {
        return null;
    }

    $row = loadTemplateFromHl($templateId, $userId, $allowAnyUser);
    if ($row)
    {
        return $row;
    }

    return loadTemplateFromFile($templateId, $userId, $allowAnyUser);
}

function loadTemplateFromHl(string $templateId, int $userId, bool $allowAnyUser = false): ?array
{
    if (!Loader::includeModule('highloadblock'))
    {
        return null;
    }

    $id = (int)$templateId;
    if ($id <= 0)
    {
        return null;
    }

    $block = HL\HighloadBlockTable::getList([
        'select' => ['*'],
        'filter' => ['=TABLE_NAME' => 'gnc_report_presets'],
        'limit' => 1,
    ])->fetch();
    if (!$block)
    {
        return null;
    }

    $entity = HL\HighloadBlockTable::compileEntity($block);
    $dataClass = $entity->getDataClass();

    $row = $dataClass::getById($id)->fetch();
    if (!$row)
    {
        return null;
    }

    if (!$allowAnyUser && (int)$row['UF_USER_ID'] !== $userId)
    {
        return null;
    }

    $config = [];
    if (!empty($row['UF_CONFIG_JSON']))
    {
        $decoded = Json::decode((string)$row['UF_CONFIG_JSON']);
        $config = is_array($decoded) ? $decoded : [];
    }

    return [
        'id' => (string)$row['ID'],
        'name' => (string)($row['UF_NAME'] ?? ''),
        'config' => $config,
    ];
}

function loadTemplateFromFile(string $templateId, int $userId, bool $allowAnyUser = false): ?array
{
    $baseDir = $_SERVER['DOCUMENT_ROOT'].'/local/otchet/storage/templates';
    $file = $baseDir.'/u'.$userId.'_'.$templateId.'.json';
    if (!is_file($file) && $allowAnyUser)
    {
        $matches = glob($baseDir.'/u*_'.$templateId.'.json') ?: [];
        if (!empty($matches))
        {
            $file = (string)$matches[0];
        }
    }

    if (!is_file($file))
    {
        return null;
    }

    $json = file_get_contents($file);
    if ($json === false)
    {
        return null;
    }

    $decoded = Json::decode($json);
    if (!is_array($decoded))
    {
        return null;
    }

    return [
        'id' => (string)($decoded['id'] ?? $templateId),
        'name' => (string)($decoded['name'] ?? ''),
        'config' => is_array($decoded['config'] ?? null) ? $decoded['config'] : [],
    ];
}

function getFactoryByCode(string $entityCode)
{
    $entityCode = strtoupper(trim($entityCode));
    $container = Container::getInstance();

    if ($entityCode === 'CONTACT') { return $container->getFactory(\CCrmOwnerType::Contact); }
    if ($entityCode === 'DEAL') { return $container->getFactory(\CCrmOwnerType::Deal); }
    if ($entityCode === 'COMPANY') { return $container->getFactory(\CCrmOwnerType::Company); }
    if ($entityCode === 'LEAD') { return $container->getFactory(\CCrmOwnerType::Lead); }
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
    if ($entityCode === 'DEAL') { return 'CRM_DEAL'; }
    if ($entityCode === 'COMPANY') { return 'CRM_COMPANY'; }
    if ($entityCode === 'LEAD') { return 'CRM_LEAD'; }
    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        $typeId = (int)substr($entityCode, 8);
        if ($typeId > 0) { return 'CRM_'.$typeId; }
    }

    return '';
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

function pickUfLabel(array $uf, string $fallback): string
{
    $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
    foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $key)
    {
        $v = $uf[$key] ?? null;
        if (is_array($v) && !empty($v[$lang]))
        {
            return trim((string)$v[$lang]);
        }
        if (is_string($v) && trim($v) !== '')
        {
            return trim($v);
        }
    }

    return $fallback;
}

function mapCrmEntityNameToCode(string $name): string
{
    $name = strtoupper(trim($name));
    if ($name === '') { return ''; }
    if ($name === 'CONTACT' || $name === 'CRM_CONTACT') { return 'CONTACT'; }
    if ($name === 'COMPANY' || $name === 'CRM_COMPANY') { return 'COMPANY'; }
    if ($name === 'DEAL' || $name === 'CRM_DEAL') { return 'DEAL'; }
    if ($name === 'LEAD' || $name === 'CRM_LEAD') { return 'LEAD'; }
    if (strpos($name, 'DYNAMIC_') === 0) { return $name; }
    if (preg_match('/^CRM_(\d+)$/', $name, $m)) { return 'DYNAMIC_'.(int)$m[1]; }
    if (preg_match('/^DYNAMICS?[-_:]?(\\d+)$/', $name, $m)) { return 'DYNAMIC_'.(int)$m[1]; }
    if (preg_match('/^CRMDYNAMIC[-_:]?(\\d+)$/', $name, $m)) { return 'DYNAMIC_'.(int)$m[1]; }
    if (preg_match('/^T(\\d+)$/', $name, $m)) { return 'DYNAMIC_'.(int)$m[1]; }
    if (ctype_digit($name)) { return 'DYNAMIC_'.(int)$name; }
    return '';
}

function mapEntityCodeToCrmTypeId(string $entityCode): int
{
    $code = strtoupper(trim($entityCode));
    if ($code === 'LEAD') { return 1; }
    if ($code === 'DEAL') { return 2; }
    if ($code === 'CONTACT') { return 3; }
    if ($code === 'COMPANY') { return 4; }
    if (strpos($code, 'DYNAMIC_') === 0)
    {
        return (int)substr($code, 8);
    }

    return 0;
}

function mapEntityCodeToCrmPrefixType(string $entityCode): string
{
    $code = strtoupper(trim($entityCode));
    if ($code === 'LEAD') { return 'CRMLEAD'; }
    if ($code === 'DEAL') { return 'CRMDEAL'; }
    if ($code === 'CONTACT') { return 'CRMCONTACT'; }
    if ($code === 'COMPANY') { return 'CRMCOMPANY'; }
    if (strpos($code, 'DYNAMIC_') === 0)
    {
        return 'CRM_'.(int)substr($code, 8);
    }

    return '';
}

function buildCrmEntitySelectorEntities(array $targets, array $entityTypeIds = []): array
{
    $entities = [];
    $targets = array_values(array_unique(array_filter(array_map(static function ($v) {
        return strtoupper(trim((string)$v));
    }, $targets))));

    foreach ($targets as $target)
    {
        $entityTypeId = mapEntityCodeToCrmTypeId($target);
        if ($entityTypeId <= 0)
        {
            continue;
        }
        $entities[] = [
            'id' => 'crm',
            'dynamicLoad' => true,
            'dynamicSearch' => true,
            'options' => ['entityTypeId' => $entityTypeId],
        ];
    }

    $entityTypeIds = array_values(array_unique(array_filter(array_map('intval', $entityTypeIds))));
    if (!empty($entityTypeIds))
    {
        $entities[] = [
            'id' => 'crm',
            'dynamicLoad' => true,
            'dynamicSearch' => true,
            'options' => [
                'entityTypeIds' => $entityTypeIds,
                'entityTypeId' => $entityTypeIds[0],
            ],
        ];
    }

    if (empty($entities))
    {
        $entities[] = ['id' => 'crm', 'dynamicLoad' => true, 'dynamicSearch' => true, 'options' => ['entityTypeId' => 3]];
    }

    $uniq = [];
    foreach ($entities as $entity)
    {
        $uniq[md5(serialize($entity))] = $entity;
    }

    return array_values($uniq);
}

function buildCrmLinkSelectItems(array $targets, int $limit = 200): array
{
    $targets = array_values(array_unique(array_filter(array_map(static function ($v) {
        return strtoupper(trim((string)$v));
    }, $targets))));
    if (empty($targets))
    {
        return [];
    }

    $multiTarget = count($targets) > 1;
    $items = [];
    foreach ($targets as $targetCode)
    {
        $factory = getFactoryByCode($targetCode);
        if (!$factory)
        {
            continue;
        }

        try
        {
            $rows = (array)$factory->getItems([
                'select' => ['ID', 'TITLE'],
                'order' => ['ID' => 'DESC'],
                'limit' => $limit,
            ]);
        }
        catch (\Throwable $e)
        {
            continue;
        }

        foreach ($rows as $row)
        {
            if (!is_object($row) || !method_exists($row, 'getId'))
            {
                continue;
            }
            $itemId = (int)$row->getId();
            if ($itemId <= 0)
            {
                continue;
            }

            $title = trim((string)$row->get('TITLE'));
            if ($title === '')
            {
                $title = '#'.$itemId;
            }

            $value = $multiTarget ? ($targetCode.':'.$itemId) : (string)$itemId;
            $label = $multiTarget ? ('['.$targetCode.'] '.$title) : $title;
            $items[$value] = $label;
        }
    }

    return $items;
}


function detectLinkTargets(string $code, array $settings = []): array
{
    $upper = strtoupper($code);
    $targets = [];
    if (strpos($upper, 'CONTACT') !== false) { $targets[] = 'CONTACT'; }
    if (strpos($upper, 'COMPANY') !== false) { $targets[] = 'COMPANY'; }
    if (strpos($upper, 'DEAL') !== false) { $targets[] = 'DEAL'; }
    if (strpos($upper, 'LEAD') !== false) { $targets[] = 'LEAD'; }
    if (preg_match('/PARENT_ID_(\d+)/', $upper, $m)) { $targets[] = 'DYNAMIC_'.(int)$m[1]; }

    $crmEntityType = findLocalSettingValueByKey($settings, 'CRM_ENTITY_TYPE');
    if ($crmEntityType !== null && $crmEntityType !== '')
    {
        if (is_array($crmEntityType))
        {
            foreach ($crmEntityType as $entityName)
            {
                $targets[] = mapCrmEntityNameToCode((string)$entityName);
            }
        }
        else
        {
            $targets[] = mapCrmEntityNameToCode((string)$crmEntityType);
        }
    }
    $crmEntityTypeList = findLocalSettingValueByKey($settings, 'CRM_ENTITY_TYPE_LIST');
    if (!empty($crmEntityTypeList) && is_array($crmEntityTypeList))
    {
        foreach ($crmEntityTypeList as $item)
        {
            $targets[] = mapCrmEntityNameToCode((string)$item);
        }
    }

    foreach (extractLocalSettingKeyTokens($settings) as $token)
    {
        $targets[] = mapCrmEntityNameToCode($token);
    }

    return array_values(array_unique(array_filter($targets)));
}

function findLocalSettingValueByKey(array $settings, string $wantedKey)
{
    $wanted = strtoupper($wantedKey);
    foreach ($settings as $key => $value)
    {
        if (strtoupper((string)$key) === $wanted)
        {
            return $value;
        }
        if (is_array($value))
        {
            $nested = findLocalSettingValueByKey($value, $wantedKey);
            if ($nested !== null)
            {
                return $nested;
            }
        }
    }

    return null;
}

function extractLocalSettingKeyTokens(array $settings): array
{
    $result = [];
    foreach ($settings as $key => $value)
    {
        $token = trim((string)$key);
        if ($token !== '')
        {
            $result[] = $token;
        }
        if (is_array($value))
        {
            $result = array_merge($result, extractLocalSettingKeyTokens($value));
        }
    }

    return array_values(array_unique($result));
}

function normalizeLocalFieldSettings($settings): array
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
    }

    return [];
}

function isDictionaryLocalUserType(string $userTypeId): bool
{
    $value = strtoupper(trim($userTypeId));
    return in_array($value, ['ENUMERATION', 'HLBLOCK', 'IBLOCK_ELEMENT', 'CRMSTATUS', 'CRM_STATUS', 'DATE', 'DATETIME'], true);
}

function normalizeLocalMetaFieldType(string $fieldCode, string $rawType): string
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

function isCrmLikeField(string $fieldCode, string $type, string $userTypeId): bool
{
    $upperCode = strtoupper(trim($fieldCode));
    $upperType = strtoupper(trim($type));
    $upperUserType = strtoupper(trim($userTypeId));

    if (isDictionaryLocalUserType($upperUserType))
    {
        return false;
    }

    if ((bool)preg_match('/^UF_CRM_\\d+_/', $upperCode))
    {
        return true;
    }

    if (strpos($upperType, 'CRM') !== false || strpos($upperUserType, 'CRM') !== false)
    {
        return true;
    }

    return strpos($upperCode, 'PARENT_ID_') !== false;
}

function getEntityFieldMetaMap(string $entityCode): array
{
    global $USER_FIELD_MANAGER;

    $result = [];
    $entityId = resolveUserFieldEntityId($entityCode);
    $ufMap = [];
    if ($entityId !== '' && $USER_FIELD_MANAGER)
    {
        $ufMap = (array)$USER_FIELD_MANAGER->GetUserFields($entityId, 0, LANGUAGE_ID);
    }

    $factory = getFactoryByCode($entityCode);
    if ($factory)
    {
        $fields = $factory->getFieldsCollection();
        foreach ($fields as $field)
        {
            $code = method_exists($field, 'getName') ? (string)$field->getName() : '';
            if ($code === '') { continue; }

            $title = method_exists($field, 'getTitle') ? trim((string)$field->getTitle()) : $code;
            if ($title === '') { $title = $code; }

            $type = method_exists($field, 'getTypeId') ? (string)$field->getTypeId() : 'string';
            $userTypeId = $type;
            $settings = method_exists($field, 'getSettings') ? normalizeLocalFieldSettings($field->getSettings()) : [];
            $isMultiple = method_exists($field, 'isMultiple') ? (bool)$field->isMultiple() : false;
            $isRequired = method_exists($field, 'isRequired') ? (bool)$field->isRequired() : false;

            $enumItems = [];
            if (method_exists($field, 'getUserField'))
            {
                $uf = (array)$field->getUserField();
                if (!empty($uf['USER_TYPE_ID'])) { $userTypeId = (string)$uf['USER_TYPE_ID']; }
                $settings = array_replace_recursive($settings, normalizeLocalFieldSettings($uf['SETTINGS'] ?? []));
                if (isset($uf['MANDATORY']))
                {
                    $isRequired = (string)$uf['MANDATORY'] === 'Y';
                }
                $enumItems = getUserFieldEnumItems((int)($uf['ID'] ?? 0), (string)($uf['USER_TYPE_ID'] ?? ''));
            }
            elseif (isset($ufMap[$code]) && is_array($ufMap[$code]))
            {
                $uf = $ufMap[$code];
                if (!empty($uf['USER_TYPE_ID'])) { $userTypeId = (string)$uf['USER_TYPE_ID']; }
                $settings = array_replace_recursive($settings, normalizeLocalFieldSettings($uf['SETTINGS'] ?? []));
                if (isset($uf['MULTIPLE'])) { $isMultiple = (string)$uf['MULTIPLE'] === 'Y'; }
                if (isset($uf['MANDATORY'])) { $isRequired = (string)$uf['MANDATORY'] === 'Y'; }
                $enumItems = getUserFieldEnumItems((int)($uf['ID'] ?? 0), (string)($uf['USER_TYPE_ID'] ?? ''));
            }

            $type = normalizeLocalMetaFieldType($code, $userTypeId !== '' ? $userTypeId : $type);

            $isDate = stripos($type, 'date') !== false || stripos($type, 'time') !== false || stripos($title, 'дата') !== false || stripos($title, 'время') !== false;
            $linkTargets = detectLinkTargets($code, $settings);
            $isCrmLink = isCrmLikeField($code, $type, $userTypeId);
            $isLink = !empty($linkTargets) || $isCrmLink;

            $result[$code] = [
                'code' => $code,
                'title' => $title,
                'type' => $type,
                'userTypeId' => $userTypeId,
                'settings' => $settings,
                'isMultiple' => $isMultiple,
                'isRequired' => $isRequired,
                'isDate' => $isDate,
                'isLink' => $isLink,
                'isCrmLink' => $isCrmLink,
                'linkTargets' => $linkTargets,
                'enumItems' => $enumItems,
            ];
        }
    }

    if ($entityId !== '' && $USER_FIELD_MANAGER)
    {
        foreach ($ufMap as $code => $uf)
        {
            $code = (string)$code;
            if ($code === '' || isset($result[$code])) { continue; }

            $userTypeId = (string)($uf['USER_TYPE_ID'] ?? 'string');
            $type = normalizeLocalMetaFieldType($code, $userTypeId);
            $settings = normalizeLocalFieldSettings($uf['SETTINGS'] ?? []);
            $linkTargets = detectLinkTargets($code, $settings);
            $isCrmLink = isCrmLikeField($code, $type, $userTypeId);
            $isLink = !empty($linkTargets) || $isCrmLink;

            $result[$code] = [
                'code' => $code,
                'title' => pickUfLabel((array)$uf, $code),
                'type' => $type,
                'userTypeId' => $userTypeId,
                'settings' => $settings,
                'isMultiple' => (string)($uf['MULTIPLE'] ?? 'N') === 'Y',
                'isRequired' => (string)($uf['MANDATORY'] ?? 'N') === 'Y',
                'isDate' => stripos($type, 'date') !== false || stripos($type, 'time') !== false,
                'isLink' => $isLink,
                'isCrmLink' => $isCrmLink,
                'linkTargets' => $linkTargets,
                'enumItems' => getUserFieldEnumItems((int)($uf['ID'] ?? 0), $userTypeId),
            ];
        }
    }

    return $result;
}

function buildTemplateColumns(array $config, array $metaByEntity): array
{
    $nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
    $nodeMap = [];
    foreach ($nodes as $node)
    {
        $nodeMap[(string)($node['id'] ?? '')] = $node;
    }

    $rows = [];
    foreach ($nodes as $node)
    {
        $nodeId = (string)($node['id'] ?? '');
        if ($nodeId === '') { continue; }
        $entityCode = (string)($node['entityCode'] ?? '');
        $entityTitle = (string)($node['entityTitle'] ?? $entityCode);
        $selectedFields = is_array($node['selectedFields'] ?? null) ? $node['selectedFields'] : [];

        foreach ($selectedFields as $fieldCode)
        {
            $fieldCode = (string)$fieldCode;
            $meta = $metaByEntity[$entityCode][$fieldCode] ?? [];
            $rows[] = [
                'key' => $nodeId.'::'.$fieldCode,
                'title' => (string)($meta['title'] ?? $fieldCode),
                'source' => $entityTitle.' / Уровень '.resolveNodeLevel($nodeId, $nodeMap),
            ];
        }
    }

    $order = is_array($config['columnOrder'] ?? null) ? $config['columnOrder'] : [];
    if (empty($order))
    {
        return $rows;
    }

    $byKey = [];
    foreach ($rows as $row)
    {
        $byKey[$row['key']] = $row;
    }

    $result = [];
    foreach ($order as $key)
    {
        $key = (string)$key;
        if (isset($byKey[$key]))
        {
            $result[] = $byKey[$key];
            unset($byKey[$key]);
        }
    }

    foreach ($byKey as $row)
    {
        $result[] = $row;
    }

    return $result;
}

function resolveNodeLevel(string $nodeId, array $nodeMap): int
{
    $lvl = 1;
    $parentId = (string)($nodeMap[$nodeId]['parentId'] ?? '');
    while ($parentId !== '' && isset($nodeMap[$parentId]))
    {
        $lvl++;
        $parentId = (string)($nodeMap[$parentId]['parentId'] ?? '');
    }

    return $lvl;
}

function makeFilterFieldId(string $key): string
{
    return preg_replace('/[^A-Za-z0-9_]/', '_', str_replace(['::', '.'], ['__', '_'], $key));
}

function buildLogicalFilterFieldId(array $item, string $rootEntityCode): string
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
            return makeFilterFieldId($key);
        }
        return preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($entityCode).'__'.$fieldCode);
    }

    $entityToken = preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($entityCode));
    return 'rel_'.$entityToken.'__'.preg_replace('/[^A-Za-z0-9_]/', '_', $fieldCode);
}


function buildFilterFields(array $config, array $metaByEntity, string $filterId = '', string $gridId = ''): array
{
    $filterItems = collectTemplateFilterItems($config, $metaByEntity);
    $rootEntityCode = (string)($config['rootEntity'] ?? '');
    $targetsMap = buildTemplateTargetsMap($config);
    $nodeMap = [];
    foreach ((array)($config['nodes'] ?? []) as $node)
    {
        $nodeId = (string)($node['id'] ?? '');
        if ($nodeId !== '')
        {
            $nodeMap[$nodeId] = (array)$node;
        }
    }
    $crmMapByEntity = [];
    foreach ($filterItems as $item)
    {
        $entityCode = (string)($item['entityCode'] ?? '');
        if ($entityCode === '' || isset($crmMapByEntity[$entityCode]))
        {
            continue;
        }

        $crmMapByEntity[$entityCode] = getCrmFilterFieldMap($entityCode, $filterId, $gridId);
    }

    $result = [];
    foreach ($filterItems as $item)
    {
        if (shouldSkipFilterItemForMvp($item))
        {
            continue;
        }
        $nodeId = (string)($item['nodeId'] ?? '');
        $fieldCode = (string)($item['fieldCode'] ?? '');
        $entityCode = (string)($item['entityCode'] ?? '');
        if ($fieldCode === '' || $entityCode === '')
        {
            continue;
        }

        $id = buildLogicalFilterFieldId($item, $rootEntityCode);
        if ($id === '')
        {
            continue;
        }
        $meta = $metaByEntity[$entityCode][$fieldCode] ?? [];
        $entityTitle = (string)($item['entityTitle'] ?? $entityCode);
        $fieldTitle = (string)($meta['title'] ?? $item['fieldTitle'] ?? $fieldCode);
        $level = ($nodeId !== '' && isset($nodeMap[$nodeId])) ? resolveNodeLevel($nodeId, $nodeMap) : 1;
        $levelTitle = ($level <= 1) ? 'Основная сущность' : ('Уровень '.$level);
        $name = $levelTitle.' / '.$entityTitle.': '.$fieldTitle;
        $targetKey = $nodeId.'::'.$fieldCode;
        $templateTargets = $targetsMap[$targetKey] ?? [];
        $forceManual = isForcedManualFilterFieldCode($fieldCode);

        $crmField = $forceManual ? null : ($crmMapByEntity[$entityCode][strtoupper($fieldCode)] ?? null);
        if (is_array($crmField))
        {
            $entry = adaptCrmFilterField($crmField, $id, $name, $meta, $fieldCode, $templateTargets);
            $entry['_source'] = 'crm_factory';
            $entry['_level'] = $level;
            $entry['_section'] = $levelTitle.' / '.$entityTitle;
            $result[] = $entry;
            continue;
        }

        $entry = buildManualFilterFieldEntry($id, $name, $meta, $fieldCode, $templateTargets);
        $entry['_source'] = 'manual_fallback';
        $entry['_level'] = $level;
        $entry['_section'] = $levelTitle.' / '.$entityTitle;
        $result[] = $entry;
    }

    usort($result, static function (array $a, array $b): int {
        $la = (int)($a['_level'] ?? 1);
        $lb = (int)($b['_level'] ?? 1);
        if ($la !== $lb)
        {
            return $la <=> $lb;
        }

        $sa = (string)($a['_section'] ?? '');
        $sb = (string)($b['_section'] ?? '');
        if ($sa !== $sb)
        {
            return strcmp($sa, $sb);
        }

        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    foreach ($result as &$entry)
    {
        unset($entry['_level'], $entry['_section']);
    }
    unset($entry);

    return $result;
}

function collectTemplateFilterItems(array $config, array $metaByEntity): array
{
    $filterItems = is_array($config['filterFields'] ?? null) ? $config['filterFields'] : [];
    if (!empty($filterItems))
    {
        return array_values(array_filter($filterItems, static function ($item): bool {
            return !shouldSkipFilterItemForMvp((array)$item);
        }));
    }

    $nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
    foreach ($nodes as $node)
    {
        $nodeId = (string)($node['id'] ?? '');
        $entityCode = (string)($node['entityCode'] ?? '');
        $entityTitle = (string)($node['entityTitle'] ?? $entityCode);
        $selected = is_array($node['selectedFields'] ?? null) ? $node['selectedFields'] : [];

        foreach ($selected as $fieldCode)
        {
            $fieldCode = (string)$fieldCode;
            if ($fieldCode === '' || $entityCode === '')
            {
                continue;
            }
            $item = [
                'key' => $nodeId.'::'.$fieldCode,
                'nodeId' => $nodeId,
                'entityCode' => $entityCode,
                'entityTitle' => $entityTitle,
                'fieldCode' => $fieldCode,
                'fieldTitle' => (string)(($metaByEntity[$entityCode][$fieldCode] ?? [])['title'] ?? $fieldCode),
            ];
            if (shouldSkipFilterItemForMvp($item))
            {
                continue;
            }
            $filterItems[] = $item;
        }
    }

    return $filterItems;
}

function isDisallowedNestedFilterFieldCode(string $fieldCode): bool
{
    $upper = strtoupper(trim($fieldCode));
    if ($upper === '')
    {
        return false;
    }
    if (strpos($upper, '__CONTACT__') !== false)
    {
        return true;
    }
    if (substr_count($upper, '__') > 1)
    {
        return true;
    }
    if ($upper === 'CONTACT_ID__CONTACT__TYPE_ID')
    {
        return true;
    }
    return false;
}

function shouldSkipFilterItemForMvp(array $item): bool
{
    $fieldCode = (string)($item['fieldCode'] ?? '');
    if (isDisallowedNestedFilterFieldCode($fieldCode))
    {
        return true;
    }
    return false;
}

function isUserFilterFieldCode(string $fieldCode): bool
{
    $upper = strtoupper(trim($fieldCode));
    if ($upper === '')
    {
        return false;
    }
    return (bool)preg_match('/(^|_)(CREATED_BY|UPDATED_BY|MOVED_BY|ASSIGNED_BY_ID|CREATED_BY_ID|UPDATED_BY_ID|MODIFY_BY_ID)$/', $upper);
}

function isContactFieldCode(string $fieldCode): bool
{
    $upper = strtoupper(trim($fieldCode));
    if ($upper === '')
    {
        return false;
    }

    return $upper === 'CONTACT_ID' || (bool)preg_match('/_CONTACT_ID$/', $upper);
}

function isForcedManualFilterFieldCode(string $fieldCode): bool
{
    if (isUserFilterFieldCode($fieldCode))
    {
        return true;
    }

    if (isContactFieldCode($fieldCode))
    {
        return true;
    }

    return false;
}

function buildTemplateTargetsMap(array $config): array
{
    $result = [];
    $nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
    foreach ($nodes as $node)
    {
        $parentId = (string)($node['parentId'] ?? '');
        $parentFieldCode = (string)($node['parentFieldCode'] ?? '');
        $entityCode = (string)($node['entityCode'] ?? '');
        if ($parentId === '' || $parentFieldCode === '' || $entityCode === '')
        {
            continue;
        }
        $key = $parentId.'::'.$parentFieldCode;
        if (!isset($result[$key]))
        {
            $result[$key] = [];
        }
        $result[$key][] = $entityCode;
    }

    foreach ($result as $key => $targets)
    {
        $result[$key] = array_values(array_unique(array_filter(array_map('strtoupper', $targets))));
    }

    return $result;
}

function extractStrictCrmTargetsFromSettings(array $settings): array
{
    $targets = [];

    $crmEntityType = findLocalSettingValueByKey($settings, 'CRM_ENTITY_TYPE');
    if ($crmEntityType !== null && $crmEntityType !== '')
    {
        if (is_array($crmEntityType))
        {
            foreach ($crmEntityType as $entityName)
            {
                $targets[] = mapCrmEntityNameToCode((string)$entityName);
            }
        }
        else
        {
            $targets[] = mapCrmEntityNameToCode((string)$crmEntityType);
        }
    }

    $crmEntityTypeList = findLocalSettingValueByKey($settings, 'CRM_ENTITY_TYPE_LIST');
    if (!empty($crmEntityTypeList) && is_array($crmEntityTypeList))
    {
        foreach ($crmEntityTypeList as $entityName)
        {
            $targets[] = mapCrmEntityNameToCode((string)$entityName);
        }
    }

    // Some CRM UF settings keep entity ids/types in arbitrary keys/values, e.g. DYNAMICS_1038.
    $stack = [$settings];
    while (!empty($stack))
    {
        $chunk = array_pop($stack);
        if (!is_array($chunk))
        {
            continue;
        }

        foreach ($chunk as $k => $v)
        {
            $targets[] = mapCrmEntityNameToCode((string)$k);
            if (is_array($v))
            {
                $stack[] = $v;
            }
            else
            {
                $targets[] = mapCrmEntityNameToCode((string)$v);
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('strtoupper', $targets))));
}

function useMvpNumberForCrmLinks(): bool
{
    return false;
}

function resolveCrmTargetsForField(array $meta, array $templateTargets, string $fieldCode): array
{
    $targets = [];
    foreach ((array)($meta['linkTargets'] ?? []) as $target)
    {
        $targets[] = strtoupper(trim((string)$target));
    }
    foreach (extractStrictCrmTargetsFromSettings((array)($meta['settings'] ?? [])) as $target)
    {
        $targets[] = strtoupper(trim((string)$target));
    }
    if (empty($targets))
    {
        foreach ($templateTargets as $target)
        {
            $targets[] = strtoupper(trim((string)$target));
        }
    }
    $metaType = strtoupper(trim((string)($meta['type'] ?? '')));
    $userType = strtoupper(trim((string)($meta['userTypeId'] ?? '')));
    $upperCode = strtoupper(trim($fieldCode));

    if (isContactFieldCode($fieldCode))
    {
        $targets = ['CONTACT'];
    }
    elseif (preg_match('/^PARENT_ID_(\d+)$/', $upperCode, $m))
    {
        $targets = ['DYNAMIC_'.(int)$m[1]];
    }
    elseif ($metaType === 'CRM_CONTACT' || $upperCode === 'CONTACT_ID' || $upperCode === 'CONTACT_IDS')
    {
        $targets[] = 'CONTACT';
    }
    if ($metaType === 'CRM_COMPANY' || $upperCode === 'COMPANY_ID' || $upperCode === 'COMPANY_IDS')
    {
        $targets[] = 'COMPANY';
    }
    if ($metaType === 'CRM_DEAL' || $upperCode === 'DEAL_ID' || $upperCode === 'DEAL_IDS')
    {
        $targets[] = 'DEAL';
    }

    return array_values(array_unique(array_filter($targets)));
}

function resolveEntityTypeIdForField(string $fieldCode, array $meta, array $templateTargets = []): int
{
    $upperCode = strtoupper(trim($fieldCode));
    if (isContactFieldCode($fieldCode))
    {
        return 3;
    }
    if (preg_match('/^PARENT_ID_(\d+)$/', $upperCode, $m))
    {
        return (int)$m[1];
    }

    $targets = resolveCrmTargetsForField($meta, $templateTargets, $fieldCode);
    foreach ($targets as $target)
    {
        $entityTypeId = mapEntityCodeToCrmTypeId((string)$target);
        if ($entityTypeId > 0)
        {
            return $entityTypeId;
        }
    }

    return 0;
}

function buildEntitySelectorParams(string $filterFieldId, string $fieldCode, bool $isMultiple, array $targets, array $meta = []): array
{
    $entityTypeId = resolveEntityTypeIdForField($fieldCode, $meta, $targets);
    if ($entityTypeId > 0)
    {
        $dialogEntities = [[
            'id' => 'crm',
            'dynamicLoad' => true,
            'dynamicSearch' => true,
            'options' => ['entityTypeId' => $entityTypeId],
        ]];
    }
    else
    {
        $dialogEntities = buildCrmEntitySelectorEntities($targets);
    }
    return [
        'multiple' => $isMultiple ? 'Y' : 'N',
        'dialogOptions' => [
            'id' => $filterFieldId,
            'context' => 'GNC_OTCHET_'.preg_replace('/[^A-Za-z0-9_]/', '_', $filterFieldId),
            'entities' => $dialogEntities,
        ],
    ];
}

function buildCrmUfDestSelectorParams(string $fieldCode, bool $isMultiple, array $targets, array $meta = []): array
{
    $dynamicIds = [];
    foreach ($targets as $target)
    {
        $target = strtoupper((string)$target);
        if (preg_match('/^DYNAMIC_(\d+)$/', $target, $m))
        {
            $dynamicIds[] = (int)$m[1];
        }
    }
    $dynamicIds = array_values(array_unique(array_filter($dynamicIds)));

    $enableCrmDynamics = [];
    $addTabCrmDynamics = [];
    $crmDynamicTitles = [];
    foreach ($dynamicIds as $dynamicId)
    {
        $enableCrmDynamics[(string)$dynamicId] = 'Y';
        $addTabCrmDynamics[(string)$dynamicId] = 'N';
        $title = 'DYNAMIC_'.$dynamicId;
        try
        {
            $mappedTitle = mapEntityTitle('DYNAMIC_'.$dynamicId);
            if (is_string($mappedTitle) && $mappedTitle !== '')
            {
                $title = $mappedTitle;
            }
        }
        catch (\Throwable $e)
        {
        }
        $crmDynamicTitles['DYNAMICS_'.$dynamicId] = $title;
    }

    return [
        'multiple' => $isMultiple ? 'Y' : 'N',
        'context' => 'CRM_UF_FILTER_ENTITY',
        'contextCode' => 'CRM',
        'apiVersion' => 3,
        'enableUsers' => 'N',
        'enableDepartments' => 'N',
        'enableCrm' => 'Y',
        'convertJson' => 'Y',
        'useClientDatabase' => 'N',
        'enableCrmDynamics' => $enableCrmDynamics,
        'addTabCrmDynamics' => $addTabCrmDynamics,
        'addTabCrmContacts' => 'N',
        'addTabCrmCompanies' => 'N',
        'addTabCrmLeads' => 'N',
        'addTabCrmDeals' => 'N',
        'crmDynamicTitles' => $crmDynamicTitles,
    ];
}

function buildCrmContactDestSelectorParams(bool $isMultiple): array
{
    return [
        'multiple' => $isMultiple ? 'Y' : 'N',
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

function buildManualFilterFieldEntry(string $id, string $name, array $meta, string $fieldCode, array $templateTargets = []): array
{
    $type = 'string';
    $params = [];
    $items = null;

    $metaType = strtolower((string)($meta['type'] ?? 'string'));
    $userTypeId = strtolower((string)($meta['userTypeId'] ?? ''));
    $isMultiple = !empty($meta['isMultiple']);
    $isDate = !empty($meta['isDate']);
    $isCrmLink = !empty($meta['isCrmLink']);
    $enumItems = is_array($meta['enumItems'] ?? null) ? $meta['enumItems'] : [];
    $effectiveTargets = resolveCrmTargetsForField($meta, $templateTargets, $fieldCode);

    if (isContactFieldCode($fieldCode) || strtolower((string)($meta['type'] ?? '')) === 'crm_contact')
    {
        $type = 'dest_selector';
        $params = buildCrmContactDestSelectorParams($isMultiple);
    }
    elseif (in_array($metaType, ['employee', 'user'], true) || in_array($userTypeId, ['employee', 'user'], true))
    {
        $type = 'dest_selector';
        $params = [
            'multiple' => $isMultiple ? 'Y' : 'N',
            'apiVersion' => 3,
            'context' => 'GNC_OTCHET_'.preg_replace('/[^A-Za-z0-9_]/', '_', $fieldCode),
            'enableAll' => 'N',
            'enableUsers' => 'Y',
            'enableSonetgroups' => 'N',
            'enableDepartments' => 'Y',
            'allowEmailInvitation' => 'N',
            'enableCrm' => 'N',
        ];
    }
    elseif (!empty($enumItems))
    {
        $type = 'list';
        if ($isMultiple)
        {
            $params['multiple'] = 'Y';
        }
        $items = [];
        foreach ($enumItems as $enum)
        {
            $items[(string)($enum['id'] ?? '')] = (string)($enum['title'] ?? '');
        }
    }
    elseif ($isDate)
    {
        $type = 'date';
    }
    elseif ($metaType === 'boolean')
    {
        $type = 'list';
        $items = ['Y' => 'Да', 'N' => 'Нет'];
    }
    elseif (in_array($metaType, ['integer', 'double', 'float', 'money'], true))
    {
        $type = 'number';
    }
    elseif ($userTypeId === 'crm')
    {
        $type = 'dest_selector';
        $params = buildCrmUfDestSelectorParams($fieldCode, $isMultiple, $effectiveTargets, $meta);
    }
    elseif (in_array($metaType, ['crm_contact', 'crm_company', 'crm_deal', 'crm_entity'], true))
    {
        if (useMvpNumberForCrmLinks())
        {
            $type = 'number';
        }
        else
        {
            $type = 'entity_selector';
            $params = buildEntitySelectorParams($id, $fieldCode, $isMultiple, $effectiveTargets, $meta);
        }
    }
    elseif ($userTypeId === 'crm_status')
    {
        $type = 'list';
        $params['multiple'] = $isMultiple ? 'Y' : 'N';
        $items = [];
        foreach ($enumItems as $enum)
        {
            $items[(string)($enum['id'] ?? '')] = (string)($enum['title'] ?? '');
        }
    }
    elseif ($isCrmLink)
    {
        if (useMvpNumberForCrmLinks())
        {
            $type = 'number';
        }
        else
        {
            $type = 'entity_selector';
            $params = buildEntitySelectorParams($id, $fieldCode, $isMultiple, $effectiveTargets, $meta);
        }
    }

    $entry = [
        'id' => $id,
        'name' => $name,
        'type' => $type,
        'default' => true,
    ];
    if ($items !== null)
    {
        $entry['items'] = $items;
    }
    if (!empty($params))
    {
        $entry['params'] = $params;
    }
    if ($isCrmLink)
    {
        $entry['_debugTargets'] = [
            'templateTargets' => array_values($templateTargets),
            'settingsTargets' => extractStrictCrmTargetsFromSettings((array)($meta['settings'] ?? [])),
            'finalTargets' => array_values($effectiveTargets),
        ];
    }

    return $entry;
}

function adaptCrmFilterField(array $crmField, string $id, string $name, array $meta, string $fieldCode, array $templateTargets = []): array
{
    $entry = [
        'id' => $id,
        'name' => $name,
        'type' => strtolower((string)($crmField['type'] ?? 'string')),
        'default' => true,
    ];

    foreach (['items', 'params', 'exclude', 'strict'] as $key)
    {
        if (array_key_exists($key, $crmField))
        {
            $entry[$key] = $crmField[$key];
        }
    }

    if (!isset($entry['params']) || !is_array($entry['params']))
    {
        $entry['params'] = [];
    }
    $effectiveTargets = resolveCrmTargetsForField($meta, $templateTargets, $fieldCode);
    if (isContactFieldCode($fieldCode) || strtolower((string)($meta['type'] ?? '')) === 'crm_contact')
    {
        $entry['type'] = 'dest_selector';
        unset($entry['items']);
        $entry['params'] = buildCrmContactDestSelectorParams(!empty($meta['isMultiple']));
        return $entry;
    }
    if (!empty($meta['isMultiple']) && !isset($entry['params']['multiple']))
    {
        $entry['params']['multiple'] = 'Y';
    }
    if (strtolower((string)($meta['userTypeId'] ?? '')) === 'crm')
    {
        $entry['type'] = 'dest_selector';
        unset($entry['items']);
        $entry['params'] = buildCrmUfDestSelectorParams($fieldCode, !empty($meta['isMultiple']), $effectiveTargets, $meta);
        $entry['_debugTargets'] = [
            'templateTargets' => array_values($templateTargets),
            'settingsTargets' => extractStrictCrmTargetsFromSettings((array)($meta['settings'] ?? [])),
            'finalTargets' => array_values($effectiveTargets),
            'transport' => 'main.ui.selector',
        ];
    }
    elseif (!empty($meta['isCrmLink']))
    {
        $entry['type'] = useMvpNumberForCrmLinks() ? 'number' : 'entity_selector';
        unset($entry['items']);
        $entry['params'] = useMvpNumberForCrmLinks()
            ? []
            : buildEntitySelectorParams($id, $fieldCode, !empty($meta['isMultiple']), $effectiveTargets, $meta);
        $entry['_debugTargets'] = [
            'templateTargets' => array_values($templateTargets),
            'settingsTargets' => extractStrictCrmTargetsFromSettings((array)($meta['settings'] ?? [])),
            'finalTargets' => array_values($effectiveTargets),
        ];
    }

    return $entry;
}

function getCrmFilterFieldMap(string $entityCode, string $filterId, string $gridId): array
{
    $entityTypeId = mapEntityCodeToCrmTypeId($entityCode);
    if ($entityTypeId <= 0)
    {
        return [];
    }

    $rawFields = loadCrmFilterRawFields($entityTypeId, $filterId, $gridId);
    $map = [];
    foreach ($rawFields as $field)
    {
        if (!is_array($field))
        {
            continue;
        }
        $fieldId = (string)($field['id'] ?? '');
        if ($fieldId === '')
        {
            continue;
        }
        $upper = strtoupper($fieldId);
        $map[$upper] = $field;
        if (strpos($upper, 'UF_CRM_') !== false && preg_match('/(UF_CRM_[A-Z0-9_]+)/', $upper, $m))
        {
            $map[$m[1]] = $field;
        }
        if (strpos($upper, '.') !== false)
        {
            $parts = explode('.', $upper);
            $last = trim((string)end($parts));
            if ($last !== '')
            {
                $map[$last] = $field;
            }
        }
    }

    return $map;
}

function loadCrmFilterRawFields(int $entityTypeId, string $filterId, string $gridId): array
{
    $factoryClass = '\\Bitrix\\Crm\\Filter\\Factory';
    if (!class_exists($factoryClass))
    {
        return [];
    }

    $factory = null;
    foreach (['getInstance', 'create'] as $method)
    {
        if (method_exists($factoryClass, $method))
        {
            try
            {
                $factory = $method === 'getInstance'
                    ? $factoryClass::getInstance()
                    : $factoryClass::create($entityTypeId);
            }
            catch (\Throwable $e)
            {
                $factory = null;
            }
            if ($factory)
            {
                break;
            }
        }
    }
    if (!$factory)
    {
        return [];
    }

    $candidates = [
        ['createEntityFilter', [$entityTypeId, $filterId, $gridId]],
        ['createFilter', [$entityTypeId, $filterId, $gridId]],
        ['createFilter', [$entityTypeId]],
        ['getFields', [$entityTypeId]],
    ];

    foreach ($candidates as [$method, $args])
    {
        if (!method_exists($factory, $method))
        {
            continue;
        }
        try
        {
            $value = $factory->{$method}(...$args);
            $normalized = extractCrmFilterFieldsFromValue($value);
            if (!empty($normalized))
            {
                return $normalized;
            }
        }
        catch (\Throwable $e)
        {
        }
    }

    return [];
}

function extractCrmFilterFieldsFromValue($value): array
{
    if (is_array($value))
    {
        if (!empty($value) && is_array(reset($value)))
        {
            return $value;
        }
        return [];
    }
    if (!is_object($value))
    {
        return [];
    }

    foreach (['getFieldArrays', 'getFields', 'getItems'] as $method)
    {
        if (!method_exists($value, $method))
        {
            continue;
        }
        try
        {
            $fields = $value->{$method}();
            if (is_array($fields))
            {
                $out = [];
                foreach ($fields as $field)
                {
                    if (is_array($field))
                    {
                        $out[] = $field;
                    }
                    elseif (is_object($field))
                    {
                        if (method_exists($field, 'toArray'))
                        {
                            $arr = $field->toArray();
                            if (is_array($arr))
                            {
                                $out[] = $arr;
                            }
                        }
                        elseif (method_exists($field, 'getId'))
                        {
                            $arr = [
                                'id' => (string)$field->getId(),
                                'name' => method_exists($field, 'getName') ? (string)$field->getName() : '',
                                'type' => method_exists($field, 'getType') ? (string)$field->getType() : 'string',
                            ];
                            if (method_exists($field, 'getItems'))
                            {
                                $arr['items'] = (array)$field->getItems();
                            }
                            if (method_exists($field, 'getParams'))
                            {
                                $arr['params'] = (array)$field->getParams();
                            }
                            $out[] = $arr;
                        }
                    }
                }
                if (!empty($out))
                {
                    return $out;
                }
            }
        }
        catch (\Throwable $e)
        {
        }
    }

    return [];
}

function extractCrmBindingDebug(array $params): array
{
    $entityTypeIds = [];
    $providers = [];

    if (isset($params['entityTypeId']) && (int)$params['entityTypeId'] > 0)
    {
        $entityTypeIds[] = (int)$params['entityTypeId'];
    }
    if (isset($params['crmEntityTypeId']) && (int)$params['crmEntityTypeId'] > 0)
    {
        $entityTypeIds[] = (int)$params['crmEntityTypeId'];
    }
    if (!empty($params['entityTypeIds']) && is_array($params['entityTypeIds']))
    {
        foreach ($params['entityTypeIds'] as $id)
        {
            if ((int)$id > 0)
            {
                $entityTypeIds[] = (int)$id;
            }
        }
    }

    if (!empty($params['providers']) && is_array($params['providers']))
    {
        foreach ($params['providers'] as $provider)
        {
            if (is_string($provider) && $provider !== '')
            {
                $providers[] = $provider;
            }
            elseif (is_array($provider) && !empty($provider['id']))
            {
                $providers[] = (string)$provider['id'];
            }
        }
    }

    if (!empty($params['dialogOptions']['entities']) && is_array($params['dialogOptions']['entities']))
    {
        foreach ($params['dialogOptions']['entities'] as $entity)
        {
            if (!is_array($entity))
            {
                continue;
            }
            if (!empty($entity['id']))
            {
                $providers[] = (string)$entity['id'];
            }
            $entityTypeId = (int)($entity['options']['entityTypeId'] ?? 0);
            if ($entityTypeId > 0)
            {
                $entityTypeIds[] = $entityTypeId;
            }
        }
    }

    return [
        'entityTypeIds' => array_values(array_unique($entityTypeIds)),
        'providers' => array_values(array_unique($providers)),
    ];
}

function buildFilterDebugPayload(array $config, array $metaByEntity, array $filterFields, array $moduleVersions = []): array
{
    $targetsMap = buildTemplateTargetsMap($config);
    $byId = [];
    foreach ($filterFields as $field)
    {
        $id = (string)($field['id'] ?? '');
        if ($id === '')
        {
            continue;
        }
        $byId[$id] = $field;
    }

    $rows = [];
    $filterItems = is_array($config['filterFields'] ?? null) ? $config['filterFields'] : [];
    foreach ($filterItems as $item)
    {
        $nodeId = (string)($item['nodeId'] ?? '');
        $fieldCode = (string)($item['fieldCode'] ?? '');
        $entityCode = (string)($item['entityCode'] ?? '');
        if ($fieldCode === '' || $entityCode === '')
        {
            continue;
        }

        $key = (string)($item['key'] ?? ($nodeId.'::'.$fieldCode));
        $id = makeFilterFieldId($key);
        $meta = $metaByEntity[$entityCode][$fieldCode] ?? [];
        $built = $byId[$id] ?? [];
        $rows[] = [
            'entityCode' => $entityCode,
            'fieldCode' => $fieldCode,
            'nodeId' => $nodeId,
            'id' => $id,
            'meta' => [
                'type' => (string)($meta['type'] ?? ''),
                'userTypeId' => (string)($meta['userTypeId'] ?? ''),
                'isMultiple' => !empty($meta['isMultiple']),
                'isRequired' => !empty($meta['isRequired']),
                'isLink' => !empty($meta['isLink']),
                'isCrmLink' => !empty($meta['isCrmLink']),
                'linkTargets' => array_values((array)($meta['linkTargets'] ?? [])),
                'enumItemsCount' => count((array)($meta['enumItems'] ?? [])),
                'settingsKeys' => array_values(array_slice(array_map('strval', array_keys((array)($meta['settings'] ?? []))), 0, 20)),
                'crmEntityType' => findLocalSettingValueByKey((array)($meta['settings'] ?? []), 'CRM_ENTITY_TYPE'),
                'crmEntityTypeList' => findLocalSettingValueByKey((array)($meta['settings'] ?? []), 'CRM_ENTITY_TYPE_LIST'),
            ],
            'filterField' => [
                'type' => (string)($built['type'] ?? ''),
                'source' => (string)($built['_source'] ?? ''),
                'params' => (array)($built['params'] ?? []),
                'crmBinding' => extractCrmBindingDebug((array)($built['params'] ?? [])),
                'itemsCount' => is_array($built['items'] ?? null) ? count((array)$built['items']) : 0,
                'targetsFromTemplate' => array_values($targetsMap[$nodeId.'::'.$fieldCode] ?? []),
                'targetsFromSettings' => extractStrictCrmTargetsFromSettings((array)($meta['settings'] ?? [])),
                'targetsFinal' => array_values((array)($built['_debugTargets']['finalTargets'] ?? [])),
            ],
        ];
    }

    return [
        'moduleVersions' => $moduleVersions,
        'filterFieldsCount' => count($filterFields),
        'filterFields' => array_map(static function (array $field): array {
            return [
                'id' => (string)($field['id'] ?? ''),
                'name' => (string)($field['name'] ?? ''),
                'type' => (string)($field['type'] ?? ''),
                'source' => (string)($field['_source'] ?? ''),
                'itemsCount' => is_array($field['items'] ?? null) ? count((array)$field['items']) : 0,
            ];
        }, $filterFields),
        'templateFilterItems' => $rows,
    ];
}

function getModuleVersionSafe(string $moduleId): string
{
    $moduleId = trim($moduleId);
    if ($moduleId === '')
    {
        return '';
    }

    try
    {
        if (!Loader::includeModule($moduleId))
        {
            return '';
        }
        return (string)ModuleManager::getVersion($moduleId);
    }
    catch (\Throwable $e)
    {
        return '';
    }
}

function safeLoadUiExtensions(array $extensions): void
{
    foreach ($extensions as $extension)
    {
        $extension = trim((string)$extension);
        if ($extension === '')
        {
            continue;
        }
        try
        {
            Extension::load([$extension]);
        }
        catch (\Throwable $e)
        {
            // optional extension for cross-version compatibility
        }
    }
}

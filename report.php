<?php
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\UI\Filter\Options as FilterOptions;
use Bitrix\Main\Grid\Options as GridOptions;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Json;
use Bitrix\Highloadblock as HL;
use Bitrix\Crm\Service\Container;

$isExportExcel = (string)($_GET['export'] ?? '') === 'excel';
if ($isExportExcel)
{
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
}
else
{
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';
}

global $APPLICATION, $USER;

$templateId = (string)($_GET['id'] ?? '');
$userId = (int)($USER ? $USER->GetID() : 0);

if ($userId <= 0)
{
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Пользователь не авторизован.</span></div>';
    if (!$isExportExcel)
    {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    }
    return;
}

if (!Loader::includeModule('crm'))
{
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Модуль CRM не подключен.</span></div>';
    if (!$isExportExcel)
    {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    }
    return;
}

\CJSCore::Init(['ajax', 'date', 'popup', 'fx']);
safeLoadUiExtensions([
    'main.core',
    'main.ui.filter',
    'main.ui.buttons',
    'main.ui.grid',
    'ui',
]);

$template = loadTemplateData($templateId, $userId, true);
if (!$template)
{
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Шаблон не найден.</span></div>';
    if (!$isExportExcel)
    {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    }
    return;
}

$config = is_array($template['config'] ?? null) ? $template['config'] : [];
$nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
$nodeMap = buildNodeMap($nodes);
$rootNode = findRootNode($nodes);
$rootNodeId = (string)($rootNode['id'] ?? '');
$rootEntityCode = (string)($rootNode['entityCode'] ?? '');

if ($rootEntityCode === '' || $rootNodeId === '')
{
    echo '<div class="ui-alert ui-alert-warning"><span class="ui-alert-message">В шаблоне не задана основная сущность.</span></div>';
    if (!$isExportExcel)
    {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    }
    return;
}

$rootFactory = getFactoryByCode($rootEntityCode);
if (!$rootFactory)
{
    echo '<div class="ui-alert ui-alert-warning"><span class="ui-alert-message">Не найдена фабрика CRM для сущности '.$rootEntityCode.'.</span></div>';
    if (!$isExportExcel)
    {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
    }
    return;
}

$metaByEntity = [];
foreach ($nodes as $node)
{
    $entityCode = trim((string)($node['entityCode'] ?? ''));
    if ($entityCode === '' || isset($metaByEntity[$entityCode]))
    {
        continue;
    }
    $metaByEntity[$entityCode] = getEntityFieldMetaMap($entityCode);
}
if (!isset($metaByEntity[$rootEntityCode]))
{
    $metaByEntity[$rootEntityCode] = getEntityFieldMetaMap($rootEntityCode);
}
$rootMetaMap = (array)$metaByEntity[$rootEntityCode];

$templateColumns = buildTemplateColumns($config, $metaByEntity);
if (empty($templateColumns))
{
    $fallback = array_values(array_filter(array_map('strval', (array)($rootNode['selectedFields'] ?? [])), static function (string $v): bool {
        return $v !== '';
    }));
    if (empty($fallback))
    {
        $fallback = ['TITLE', 'CREATED_TIME'];
    }
    foreach ($fallback as $fieldCode)
    {
        $templateColumns[] = [
            'key' => $rootNodeId.'::'.$fieldCode,
            'nodeId' => $rootNodeId,
            'entityCode' => $rootEntityCode,
            'fieldCode' => $fieldCode,
            'title' => (string)($rootMetaMap[$fieldCode]['title'] ?? $fieldCode),
            'source' => (string)($rootNode['entityTitle'] ?? $rootEntityCode).' / Уровень 1',
        ];
    }
}

$gridColumns = [];
$gridColumnMap = [];
$gridColumnsById = [];
foreach ($templateColumns as $col)
{
    $columnId = makeGridColumnId((string)$col['key']);
    $gridCol = [
        'id' => $columnId,
        'name' => (string)($col['title'] ?? ''),
        'sort' => $columnId,
        'default' => true,
    ];
    $gridColumns[] = $gridCol;
    $gridColumnsById[$columnId] = $gridCol;
    $gridColumnMap[$columnId] = $col;
}

$filterId = 'GNC_OTCHET2_FILTER_'.preg_replace('/[^A-Za-z0-9_]/', '_', (string)$template['id']);
$gridId = 'GNC_OTCHET2_GRID_'.preg_replace('/[^A-Za-z0-9_]/', '_', (string)$template['id']);

$filterDefinition = [];

$columnFilterMap = [];
foreach ($templateColumns as $col)
{
    $columnId = makeGridColumnId((string)$col['key']);
    $entityCode = (string)($col['entityCode'] ?? '');
    $fieldCode = (string)($col['fieldCode'] ?? '');
    $meta = (array)($metaByEntity[$entityCode][$fieldCode] ?? []);
    $isSyntheticAddress = !empty($meta['isSyntheticAddress']);

    $isDate = !empty($meta['isDate']);
    $isNumeric = !empty($meta['isNumeric']);
    $isBoolean = !empty($meta['isBoolean']);
    $isEmployee = !empty($meta['isEmployee']);
    $isMultiple = !empty($meta['isMultiple']);
    $enumItems = is_array($meta['enumItems'] ?? null) ? $meta['enumItems'] : [];

    $type = 'string';
    if ($isDate) { $type = 'date'; }
    elseif ($isEmployee) { $type = 'dest_selector'; }
    elseif (!empty($enumItems) || $isBoolean) { $type = 'list'; }
    elseif ($isNumeric) { $type = 'number'; }

    $label = (string)($col['title'] ?? $fieldCode);
    $source = (string)($col['source'] ?? '');
    if ($source !== '')
    {
        $label .= ' ('.$source.')';
    }

    $entry = [
        'id' => $columnId,
        'name' => $label,
        'type' => $type,
        'ID' => $columnId,
        'NAME' => $label,
        'TYPE' => strtoupper($type),
        'default' => false,
    ];

    if ($type === 'list')
    {
        $items = ['' => 'Не указано'];
        if (!empty($enumItems))
        {
            foreach ($enumItems as $id => $title)
            {
                $items[(string)$id] = (string)$title;
            }
        }
        elseif ($isBoolean)
        {
            $items['Y'] = 'Да';
            $items['N'] = 'Нет';
        }
        $entry['items'] = $items;
        $entry['ITEMS'] = $items;
        if ($isMultiple)
        {
            $entry['params'] = ['multiple' => 'Y'];
            $entry['PARAMS'] = ['multiple' => 'Y'];
        }
    }
    elseif ($type === 'dest_selector')
    {
        $destParams = [
            'context' => 'GNC_OTCHET_EMPLOYEE_'.$template['id'],
            'multiple' => 'N',
            'enableAll' => 'N',
            'enableUsers' => 'Y',
            'enableDepartments' => 'Y',
            'enableSonetgroups' => 'N',
            'allowEmailInvitation' => 'N',
            'allowAddUser' => 'N',
            'enableCrm' => 'N',
        ];
        $entry['params'] = $destParams;
        $entry['PARAMS'] = $destParams;
    }

    $filterDefinition[] = $entry;
    $columnFilterMap[$columnId] = [
        'type' => $type,
        'isDate' => $isDate,
        'isNumeric' => $isNumeric,
        'isEmployee' => $isEmployee,
        'isSyntheticAddress' => $isSyntheticAddress,
        'entityCode' => $entityCode,
        'fieldCode' => $fieldCode,
    ];
}

$gridOptions = new GridOptions($gridId);
$sortData = $gridOptions->GetSorting([
    'sort' => [makeGridColumnId((string)($templateColumns[0]['key'] ?? 'ID')) => 'DESC'],
    'vars' => ['by' => 'by', 'order' => 'order'],
]);
$visibleColumnIds = array_values(array_filter(array_map('strval', (array)$gridOptions->GetVisibleColumns()), static function (string $id) use ($gridColumnsById): bool {
    return $id !== '' && isset($gridColumnsById[$id]);
}));
if (empty($visibleColumnIds))
{
    $visibleColumnIds = array_values(array_map(static function (array $col): string {
        return (string)($col['id'] ?? '');
    }, $gridColumns));
}
$exportColumns = [];
foreach ($visibleColumnIds as $visibleColumnId)
{
    if (isset($gridColumnsById[$visibleColumnId]))
    {
        $exportColumns[] = $gridColumnsById[$visibleColumnId];
    }
}
if (empty($exportColumns))
{
    $exportColumns = $gridColumns;
}
$navParams = $gridOptions->GetNavParams(['nPageSize' => 50, 'iNumPage' => 1]);
$rawSort = is_array($sortData['sort'] ?? null) ? $sortData['sort'] : [];
$ormSort = ['ID' => 'DESC'];
$sortColumnId = '';
$sortDirection = 'ASC';
$needsInMemorySort = false;
$sortRequestedFromUi = isset($_REQUEST['by']) || isset($_REQUEST['order']);
if (!empty($rawSort))
{
    $sortField = (string)array_key_first($rawSort);
    $sortDir = strtoupper((string)($rawSort[$sortField] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $sortCol = (array)($gridColumnMap[$sortField] ?? []);
    $sortNodeId = (string)($sortCol['nodeId'] ?? '');
    $sortFieldCode = (string)($sortCol['fieldCode'] ?? '');

    if ($sortNodeId !== '' && $sortNodeId === $rootNodeId && $sortFieldCode !== '')
    {
        $ormSort = [$sortFieldCode => $sortDir];
    }
    else
    {
        // При первом входе (без явного клика по сортировке) не тянем тяжелую in-memory сортировку.
        if ($sortRequestedFromUi)
        {
            $needsInMemorySort = $sortField !== '';
            $sortColumnId = $sortField;
            $sortDirection = $sortDir;
        }
    }
}

$nav = new PageNavigation($gridId);
$pageSize = max(1, min(200, (int)($navParams['nPageSize'] ?? 50)));
$nav->allowAllRecords(true)->setPageSize($pageSize)->initFromUri();

$filterOptions = new FilterOptions($filterId);
$filterData = $filterOptions->getFilter([]);
$ormFilter = buildOrmFilterByRootColumns($filterData, $columnFilterMap, $gridColumnMap, $rootNodeId);
$findText = trim((string)($filterData['FIND'] ?? ''));
$allDynamicRules = buildDynamicFilterRules($filterData, $columnFilterMap);
$dynamicRules = keepNonRootRules($allDynamicRules, $gridColumnMap, $rootNodeId);
$needsInMemoryFilter = !empty($dynamicRules) || $findText !== '';
$allowHeavyInMemory = $isExportExcel || $needsInMemoryFilter || $needsInMemorySort;
if (!$allowHeavyInMemory)
{
    // На экране по умолчанию работаем штатно и постранично, без полного сканирования.
    $dynamicRules = [];
    $findText = '';
}
$rootSelect = collectRootSelectFields($nodes, $rootNodeId);
$select = array_values(array_unique(array_merge(['ID'], $rootSelect)));

$queryParams = [
    'select' => $select,
    'filter' => $ormFilter,
    'order' => $ormSort,
];
if ($allowHeavyInMemory && ($needsInMemorySort || $needsInMemoryFilter))
{
    // Для in-memory фильтрации/сортировки нужен полный набор основной сущности.
}
elseif ($isExportExcel)
{
    // Для Excel всегда выгружаем полный набор отфильтрованных строк.
}
else
{
    $queryParams['offset'] = $nav->getOffset();
    $queryParams['limit'] = $nav->getLimit();
}

$total = getItemsCountSafe($rootFactory, $ormFilter, $userId);
$rowsRaw = $isExportExcel
    ? getItemsSafeByPaging($rootFactory, $queryParams, $userId, 2000)
    : getItemsSafe($rootFactory, $queryParams, $userId);
if ($total === null)
{
    $total = countItemsSafeByPaging($rootFactory, $ormFilter, $userId);
}

$rowsWithContext = buildRowsWithNodeItems($rowsRaw, $nodes, $rootNodeId, $userId);

$gridRows = [];
foreach ($rowsWithContext as $ctx)
{
    $rootItem = $ctx['rootItem'] ?? null;
    if (!is_object($rootItem) || !method_exists($rootItem, 'getId'))
    {
        continue;
    }

    $cols = [];
    $colsHtml = [];
    $raw = [];
    foreach ($templateColumns as $col)
    {
        $columnId = makeGridColumnId((string)$col['key']);
        $nodeId = (string)($col['nodeId'] ?? '');
        $fieldCode = (string)($col['fieldCode'] ?? '');
        $entityCode = (string)($col['entityCode'] ?? '');
        $meta = (array)($metaByEntity[$entityCode][$fieldCode] ?? []);
        $nodeItem = $ctx['nodeItems'][$nodeId] ?? null;
        if (!is_object($nodeItem) || !method_exists($nodeItem, 'get'))
        {
            $cols[$columnId] = '';
            $colsHtml[$columnId] = '';
            $raw[$columnId] = '';
            continue;
        }
        $rawValue = getItemFieldValueSafe($nodeItem, $entityCode, $fieldCode);
        $textValue = formatValueForOutput($rawValue, $meta);
        $cols[$columnId] = $textValue;
        $colsHtml[$columnId] = $textValue;

        if ($textValue !== '' && shouldRenderAsEntityLink($entityCode, $fieldCode) && method_exists($nodeItem, 'getId'))
        {
            $entityId = (int)$nodeItem->getId();
            $url = buildEntityItemUrl($entityCode, $entityId);
            if ($url !== '')
            {
                $colsHtml[$columnId] = '<a href="'.htmlspecialcharsbx($url).'" target="_blank">'.htmlspecialcharsbx($textValue).'</a>';
            }
        }
        $raw[$columnId] = stringifyValue($rawValue);
    }

    $gridRows[] = [
        'id' => (int)$rootItem->getId(),
        'data' => $cols,
        'columns' => $colsHtml,
        'raw' => $raw ?? [],
    ];
}

if ($allowHeavyInMemory && $needsInMemoryFilter)
{
    $gridRows = applyDynamicRulesToRows($gridRows, $dynamicRules, $findText);
    $total = count($gridRows);
}

if ($allowHeavyInMemory && $needsInMemorySort && $sortColumnId !== '')
{
    $dir = $sortDirection === 'DESC' ? -1 : 1;
    usort($gridRows, static function (array $a, array $b) use ($sortColumnId, $dir): int {
        $av = trim((string)($a['columns'][$sortColumnId] ?? ''));
        $bv = trim((string)($b['columns'][$sortColumnId] ?? ''));
        if ($av === $bv)
        {
            return 0;
        }

        $an = normalizeNumeric($av);
        $bn = normalizeNumeric($bv);
        if ($an !== null && $bn !== null)
        {
            return ($an <=> $bn) * $dir;
        }

        return strcasecmp($av, $bv) * $dir;
    });
}

if (!$isExportExcel && $allowHeavyInMemory && ($needsInMemorySort || $needsInMemoryFilter))
{
    if (!$needsInMemoryFilter)
    {
        $total = count($gridRows);
    }
    $gridRows = array_slice($gridRows, $nav->getOffset(), $nav->getLimit());
}

$nav->setRecordCount((int)$total);

$debugPayload = [
    'templateId' => (string)$template['id'],
    'templateName' => (string)$template['name'],
    'rootEntityCode' => $rootEntityCode,
    'grid' => [
        'gridId' => $gridId,
        'columnIds' => array_values(array_map(static function (array $col): string {
            return (string)($col['id'] ?? '');
        }, $gridColumns)),
        'sort' => $rawSort,
        'ormSort' => $ormSort,
        'inMemorySort' => $needsInMemorySort,
        'inMemoryFilter' => $needsInMemoryFilter,
        'pageSize' => $pageSize,
        'offset' => $nav->getOffset(),
        'limit' => $nav->getLimit(),
        'total' => (int)$total,
    ],
    

    'filter' => [
        'filterId' => $filterId,
        'currentValues' => $filterData,
        'ormFilter' => $ormFilter,
        'dynamicRules' => $dynamicRules,
        'findText' => $findText,
    ],
];

if ($isExportExcel)
{
    sendExcelFromGridRows($template, $exportColumns, $gridRows);
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';
    return;
}
?>
<style>
    .otchet-report2-layout {
        padding: 16px 24px 24px;
    }

    .otchet-report2-layout .ui-alert {
        margin: 0 0 14px;
    }

    .otchet-report2-layout .otchet-report2-debug {
        margin: 0 0 16px;
        max-height: 240px;
        overflow: auto;
        white-space: pre-wrap;
        font-size: 12px;
        color: #263845;
        background: #f8fbff;
        border: 1px solid #d9e1e8;
        border-radius: 10px;
        padding: 12px;
    }

    .otchet-report2-layout .main-ui-filter-search {
        margin-bottom: 14px;
        border: 1px solid #d8e1ea;
        border-radius: 10px;
        background: #fff;
        padding: 10px;
    }

    .otchet-report2-layout .main-grid {
        border: 1px solid #dfe5ec;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }

    .otchet-report2-toolbar {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        margin: 0 0 12px;
    }

    .otchet-report2-toolbar .ui-btn {
        white-space: nowrap;
    }

    .otchet-report2-loading {
        position: fixed;
        inset: 0;
        background: rgba(255, 255, 255, 0.75);
        backdrop-filter: blur(1px);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .otchet-report2-loading-box {
        background: #fff;
        border: 1px solid #d8e1ea;
        border-radius: 10px;
        padding: 14px 18px;
        font-size: 14px;
        color: #2f3f50;
        box-shadow: 0 8px 20px rgba(30, 53, 78, 0.12);
    }
</style>

<div class="ui-page-slider-workarea otchet-report2-layout">
    <div class="otchet-report2-loading" id="reportLoadingMask">
        <div class="otchet-report2-loading-box">
            <span class="otchet-report2-loading-text">Формируем отчет, подождите...</span>
        </div>
    </div>
    <div class="ui-alert ui-alert-primary">
        <span class="ui-alert-message">report.php: связка <b>Bitrix Filter + Bitrix Grid</b> для шаблона #<?=htmlspecialcharsbx((string)$template['id'])?>.</span>
    </div>

    <pre id="reportDbgBox" class="otchet-report2-debug"><?=htmlspecialcharsbx(Json::encode($debugPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre>

    <div class="otchet-report2-toolbar">
        <a class="ui-btn ui-btn-light-border" href="/local/otchet/index.php">Назад к шаблонам</a>
        <a class="ui-btn ui-btn-light-border" href="/local/otchet/slider.php?id=<?=urlencode((string)$template['id'])?>">Корректировать шаблон</a>
        <a class="ui-btn ui-btn-primary" id="exportExcelLink" href="/local/otchet/report.php?id=<?=urlencode((string)$template['id'])?>&export=excel">Выгрузить в Excel</a>
    </div>

    <?php
    $APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', [
        'FILTER_ID' => $filterId,
        'GRID_ID' => $gridId,
        'FILTER' => $filterDefinition,
        'ENABLE_LABEL' => true,
        'ENABLE_LIVE_SEARCH' => true,
        'ENABLE_ADDITIONAL_FILTERS' => true,
        'RESET_TO_DEFAULT_MODE' => false,
        'DISABLE_SEARCH' => false,
        'ENABLE_FIELDS_SEARCH' => true,
    ], false);

    $APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
        'GRID_ID' => $gridId,
        'COLUMNS' => $gridColumns,
        'ROWS' => $gridRows,
        'SHOW_ROW_CHECKBOXES' => false,
        'SHOW_CHECK_ALL_CHECKBOXES' => false,
        'SHOW_ROW_ACTIONS_MENU' => false,
        'SHOW_GRID_SETTINGS_MENU' => true,
        'SHOW_NAVIGATION_PANEL' => true,
        'SHOW_PAGINATION' => true,
        'SHOW_MORE_BUTTON' => true,
        'SHOW_SELECTED_COUNTER' => false,
        'SHOW_TOTAL_COUNTER' => true,
        'SHOW_PAGESIZE' => true,
        'SHOW_ACTION_PANEL' => false,
        'ALLOW_COLUMNS_SORT' => true,
        'ALLOW_SORT' => true,
        'AJAX_MODE' => 'Y',
        'AJAX_OPTION_HISTORY' => 'N',
        'AJAX_OPTION_STYLE' => 'Y',
        'TOTAL_ROWS_COUNT' => (int)$total,
        'NAV_OBJECT' => $nav,
        'PAGE_SIZES' => [
            ['NAME' => '5', 'VALUE' => '5'],
            ['NAME' => '10', 'VALUE' => '10'],
            ['NAME' => '20', 'VALUE' => '20'],
            ['NAME' => '50', 'VALUE' => '50'],
            ['NAME' => '100', 'VALUE' => '100'],
            ['NAME' => '500', 'VALUE' => '500'],
            ['NAME' => '1000', 'VALUE' => '1000'],
        ],
        'DEFAULT_PAGE_SIZE' => 20,
    ], false);
    ?>
    <script>
        (function () {
            var mask = document.getElementById('reportLoadingMask');
            var textNode = mask ? mask.querySelector('.otchet-report2-loading-text') : null;
            function showLoading(text) {
                if (!mask) { return; }
                if (textNode && text) { textNode.textContent = text; }
                mask.style.display = 'flex';
            }
            function hideLoading() {
                if (!mask) { return; }
                mask.style.display = 'none';
            }

            document.addEventListener('DOMContentLoaded', hideLoading);
            window.addEventListener('pageshow', hideLoading);

            var exportLink = document.getElementById('exportExcelLink');
            if (exportLink) {
                exportLink.addEventListener('click', function () {
                    showLoading('Подготавливаем Excel-файл...');
                    // Нельзя надежно отследить завершение download по ссылке,
                    // поэтому прячем маску при возврате фокуса и по таймауту.
                    var done = false;
                    var finish = function () {
                        if (done) { return; }
                        done = true;
                        hideLoading();
                        window.removeEventListener('focus', onFocusBack);
                    };
                    var onFocusBack = function () {
                        setTimeout(finish, 250);
                    };
                    window.addEventListener('focus', onFocusBack, { once: true });
                    setTimeout(finish, 8000);
                });
            }

            // На этой странице отключаем keep-alive /bitrix/tools/public_session.php,
            // чтобы он не блокировал setFilter/grid ajax при проблемах с session lock.
            if (window.BX && typeof BX.ajax === 'function' && !BX.__gncPublicSessionPatched) {
                BX.__gncPublicSessionPatched = true;
                var originalAjax = BX.ajax;
                var wrappedAjax = function (config) {
                    var url = '';
                    if (typeof config === 'string') {
                        url = config;
                    } else if (config && typeof config.url === 'string') {
                        url = config.url;
                    }

                    if (url.indexOf('/bitrix/tools/public_session.php') !== -1) {
                        if (config && typeof config.onsuccess === 'function') {
                            setTimeout(function () { config.onsuccess('OK'); }, 0);
                        }
                        return { abort: function () {} };
                    }

                    return originalAjax.apply(this, arguments);
                };
                // Сохраняем статические методы BX.ajax (loadJSON/xhrSuccess/...)
                // иначе ядро начинает падать на main.rating и прочих расширениях.
                for (var key in originalAjax) {
                    if (Object.prototype.hasOwnProperty.call(originalAjax, key)) {
                        wrappedAjax[key] = originalAjax[key];
                    }
                }
                BX.ajax = wrappedAjax;
            }
        })();
    </script>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';

function safeLoadUiExtensions(array $extensions): void
{
    foreach ($extensions as $extension)
    {
        try
        {
            Extension::load($extension);
        }
        catch (\Throwable $e)
        {
        }
    }
}

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

    if (!$allowAnyUser && (int)($row['UF_USER_ID'] ?? 0) !== $userId)
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

function buildNodeMap(array $nodes): array
{
    $map = [];
    foreach ($nodes as $node)
    {
        $id = (string)($node['id'] ?? '');
        if ($id !== '')
        {
            $map[$id] = is_array($node) ? $node : [];
        }
    }
    return $map;
}

function findRootNode(array $nodes): array
{
    foreach ($nodes as $node)
    {
        if (empty($node['parentId']))
        {
            return is_array($node) ? $node : [];
        }
    }
    return [];
}

function resolveNodeLevel(string $nodeId, array $nodeMap): int
{
    $level = 1;
    $parentId = (string)($nodeMap[$nodeId]['parentId'] ?? '');
    $guard = 0;
    while ($parentId !== '' && isset($nodeMap[$parentId]) && $guard < 100)
    {
        $level++;
        $parentId = (string)($nodeMap[$parentId]['parentId'] ?? '');
        $guard++;
    }
    return $level;
}

function makeGridColumnId(string $key): string
{
    $id = preg_replace('/[^A-Za-z0-9_]/', '_', str_replace(['::', '.'], ['__', '_'], $key));
    return $id !== '' ? $id : 'COL_'.md5($key);
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

function getEntityFieldMetaMap(string $entityCode): array
{
    $result = [];
    $factory = getFactoryByCode($entityCode);
    if (!$factory)
    {
        return $result;
    }

    $fields = $factory->getFieldsCollection();
    foreach ($fields as $field)
    {
        $code = method_exists($field, 'getName') ? (string)$field->getName() : '';
        if ($code === '')
        {
            continue;
        }

        $title = method_exists($field, 'getTitle') ? trim((string)$field->getTitle()) : '';
        if ($title === '')
        {
            $title = $code;
        }

        $type = method_exists($field, 'getTypeId') ? strtolower((string)$field->getTypeId()) : 'string';
        $userTypeId = $type;
        $isMultiple = false;
        $enumItems = [];
        if (method_exists($field, 'getUserField'))
        {
            $uf = (array)$field->getUserField();
            if (!empty($uf['USER_TYPE_ID']))
            {
                $userTypeId = strtolower((string)$uf['USER_TYPE_ID']);
            }
            $isMultiple = isset($uf['MULTIPLE']) && (string)$uf['MULTIPLE'] === 'Y';
            if ($userTypeId === 'enumeration' && !empty($uf['ID']))
            {
                $enum = new \CUserFieldEnum();
                $res = $enum->GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['USER_FIELD_ID' => (int)$uf['ID']]);
                while ($row = $res->Fetch())
                {
                    $id = (string)($row['ID'] ?? '');
                    $name = (string)($row['VALUE'] ?? '');
                    if ($id !== '')
                    {
                        $enumItems[$id] = $name;
                    }
                }
            }
        }

        $upper = strtoupper($code);
        $isDate = in_array($type, ['date', 'datetime'], true)
            || strpos($upper, 'DATE') !== false
            || strpos($upper, 'TIME') !== false
            || mb_stripos($title, 'дата') !== false
            || mb_stripos($title, 'время') !== false;
        $isNumeric = in_array($type, ['integer', 'double', 'float', 'money'], true)
            || strpos($upper, 'SUM') !== false
            || strpos($upper, 'KURS') !== false
            || strpos($upper, 'OPPORTUNITY') !== false;
        $isBoolean = $type === 'boolean' || $userTypeId === 'boolean';
        $isEmployee = $userTypeId === 'employee'
            || $type === 'user'
            || in_array($upper, ['CREATED_BY', 'UPDATED_BY', 'ASSIGNED_BY_ID', 'LAST_ACTIVITY_BY'], true)
            || substr($upper, -8) === '_BY_ID';

        $result[$code] = [
            'code' => $code,
            'title' => $title,
            'type' => $type,
            'userTypeId' => $userTypeId,
            'isDate' => $isDate,
            'isNumeric' => $isNumeric,
            'isBoolean' => $isBoolean,
            'isEmployee' => $isEmployee,
            'isMultiple' => $isMultiple,
            'enumItems' => $enumItems,
        ];
    }

    if (strtoupper($entityCode) === 'CONTACT')
    {
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
            if (isset($result[$code]))
            {
                continue;
            }
            $result[$code] = [
                'code' => $code,
                'title' => $title,
                'type' => 'string',
                'userTypeId' => 'string',
                'isDate' => false,
                'isNumeric' => false,
                'isBoolean' => false,
                'isEmployee' => false,
                'isMultiple' => false,
                'isSyntheticAddress' => true,
                'enumItems' => [],
            ];
        }
    }

    return $result;
}

function buildTemplateColumns(array $config, array $metaByEntity): array
{
    $nodes = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
    $nodeMap = buildNodeMap($nodes);
    $rows = [];

    foreach ($nodes as $node)
    {
        $nodeId = (string)($node['id'] ?? '');
        if ($nodeId === '')
        {
            continue;
        }

        $entityCode = (string)($node['entityCode'] ?? '');
        $entityTitle = (string)($node['entityTitle'] ?? $entityCode);
        $selectedFields = is_array($node['selectedFields'] ?? null) ? $node['selectedFields'] : [];

        foreach ($selectedFields as $fieldCode)
        {
            $fieldCode = (string)$fieldCode;
            if ($fieldCode === '')
            {
                continue;
            }

            $meta = (array)($metaByEntity[$entityCode][$fieldCode] ?? []);
            $rows[] = [
                'key' => $nodeId.'::'.$fieldCode,
                'nodeId' => $nodeId,
                'entityCode' => $entityCode,
                'fieldCode' => $fieldCode,
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
        $byKey[(string)$row['key']] = $row;
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

function collectRootSelectFields(array $nodes, string $rootNodeId): array
{
    $nodeMap = buildNodeMap($nodes);
    $rootNode = (array)($nodeMap[$rootNodeId] ?? []);
    $select = array_values(array_filter(array_map('strval', (array)($rootNode['selectedFields'] ?? [])), static function (string $v): bool {
        return $v !== '';
    }));

    foreach ($nodeMap as $node)
    {
        if ((string)($node['parentId'] ?? '') !== $rootNodeId)
        {
            continue;
        }

        $relationField = trim((string)($node['parentFieldCode'] ?? ''));
        if ($relationField !== '')
        {
            $select[] = $relationField;
        }
    }

    return array_values(array_unique($select));
}

function buildRootPeriodFilter(array $filterValues, array $rootMetaMap): array
{
    $filter = [];

    $periodFieldRaw = $filterValues['PERIOD_FIELD'] ?? '';
    if (is_array($periodFieldRaw))
    {
        $periodFieldRaw = reset($periodFieldRaw);
    }
    $periodField = trim((string)$periodFieldRaw);
    if ($periodField === '' || ctype_digit($periodField))
    {
        return $filter;
    }

    $meta = (array)($rootMetaMap[$periodField] ?? []);
    if (empty($meta) || empty($meta['isDate']))
    {
        return $filter;
    }

    $from = trim((string)($filterValues['PERIOD_from'] ?? ''));
    $to = trim((string)($filterValues['PERIOD_to'] ?? ''));
    $dateSel = strtoupper(trim((string)($filterValues['PERIOD_datesel'] ?? '')));

    if ($from === '' && $to === '')
    {
        [$from, $to] = resolveDateRangeBySelector($dateSel, (string)($filterValues['PERIOD_days'] ?? ''));
    }

    if ($from !== '') { $filter['>='.$periodField] = $from; }
    if ($to !== '') { $filter['<='.$periodField] = $to; }

    return $filter;
}

function resolveDateRangeBySelector(string $dateSel, string $daysRaw): array
{
    $today = new \DateTimeImmutable('today');
    $from = '';
    $to = '';

    switch ($dateSel)
    {
        case 'CURRENT_DAY':
            $from = $today->format('Y-m-d');
            $to = $from;
            break;
        case 'CURRENT_WEEK':
            $from = $today->modify('monday this week')->format('Y-m-d');
            $to = $today->modify('sunday this week')->format('Y-m-d');
            break;
        case 'CURRENT_MONTH':
            $from = $today->modify('first day of this month')->format('Y-m-d');
            $to = $today->modify('last day of this month')->format('Y-m-d');
            break;
        case 'LAST_7_DAYS':
            $from = $today->modify('-6 day')->format('Y-m-d');
            $to = $today->format('Y-m-d');
            break;
        case 'LAST_30_DAYS':
            $from = $today->modify('-29 day')->format('Y-m-d');
            $to = $today->format('Y-m-d');
            break;
        case 'PREV_DAYS':
            $days = (int)trim($daysRaw);
            if ($days > 0)
            {
                $from = $today->modify('-'.($days - 1).' day')->format('Y-m-d');
                $to = $today->format('Y-m-d');
            }
            break;
    }

    return [$from, $to];
}

function buildDynamicFilterRules(array $filterValues, array $columnFilterMap): array
{
    $rules = [];
    foreach ($columnFilterMap as $columnId => $meta)
    {
        $columnId = (string)$columnId;
        if ($columnId === '')
        {
            continue;
        }

        $isDate = !empty($meta['isDate']);
        $isNumeric = !empty($meta['isNumeric']);
        $isEmployee = !empty($meta['isEmployee']);
        if ($isDate)
        {
            [$from, $to] = resolveDateRangeFromFilterField($filterValues, $columnId);
            if ($from !== '' || $to !== '')
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'date_range', 'from' => $from, 'to' => $to];
            }
            continue;
        }

        if ($isNumeric)
        {
            $numSel = strtolower(trim((string)($filterValues[$columnId.'_numsel'] ?? '')));
            $from = trim((string)($filterValues[$columnId.'_from'] ?? ''));
            $to = trim((string)($filterValues[$columnId.'_to'] ?? ''));
            $exact = trim((string)($filterValues[$columnId] ?? ''));

            if ($numSel === 'more' && $from !== '')
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'number_more', 'value' => $from];
                continue;
            }
            if ($numSel === 'less' && $to !== '')
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'number_less', 'value' => $to];
                continue;
            }
            if (($numSel === 'range' || ($from !== '' || $to !== '')) && ($from !== '' || $to !== ''))
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'number_range', 'from' => $from, 'to' => $to];
                continue;
            }
            if ($exact !== '')
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'number_eq', 'value' => $exact];
            }
            continue;
        }

        if ($isEmployee)
        {
            $rawEmployee = $filterValues[$columnId] ?? null;
            $employeeValues = [];
            if (is_array($rawEmployee))
            {
                foreach ($rawEmployee as $v)
                {
                    $id = normalizeUserId((string)$v);
                    if ($id > 0)
                    {
                        $employeeValues[] = (string)$id;
                    }
                }
            }
            else
            {
                $id = normalizeUserId((string)$rawEmployee);
                if ($id > 0)
                {
                    $employeeValues[] = (string)$id;
                }
            }

            $employeeValues = array_values(array_unique($employeeValues));
            if (!empty($employeeValues))
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'employee_in', 'values' => $employeeValues];
            }
            continue;
        }

        $raw = $filterValues[$columnId] ?? null;
        if (is_array($raw))
        {
            $values = array_values(array_filter(array_map(static function ($v): string {
                return trim((string)$v);
            }, $raw), static function (string $v): bool {
                return $v !== '';
            }));
            if (!empty($values))
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'in', 'values' => $values];
            }
            continue;
        }

        $value = trim((string)$raw);
        if ($value !== '')
        {
            if ((string)($meta['type'] ?? '') === 'list')
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'in', 'values' => [$value]];
            }
            else
            {
                $rules[] = ['columnId' => $columnId, 'type' => 'contains', 'value' => mb_strtolower($value)];
            }
        }
    }

    return $rules;
}

function resolveDateRangeFromFilterField(array $filterValues, string $fieldId): array
{
    $from = trim((string)($filterValues[$fieldId.'_from'] ?? ''));
    $to = trim((string)($filterValues[$fieldId.'_to'] ?? ''));
    if ($from !== '' || $to !== '')
    {
        return [$from, $to];
    }

    $dateSel = strtoupper(trim((string)($filterValues[$fieldId.'_datesel'] ?? '')));
    if ($dateSel === '')
    {
        return ['', ''];
    }

    return resolveDateRangeBySelector($dateSel, (string)($filterValues[$fieldId.'_days'] ?? ''));
}

function buildOrmFilterByRootColumns(array $filterValues, array $columnFilterMap, array $gridColumnMap, string $rootNodeId): array
{
    $filter = [];

    foreach ($columnFilterMap as $columnId => $meta)
    {
        $columnId = (string)$columnId;
        $gridCol = (array)($gridColumnMap[$columnId] ?? []);
        $nodeId = (string)($gridCol['nodeId'] ?? '');
        if ($nodeId !== $rootNodeId)
        {
            continue;
        }

        $fieldCode = (string)($meta['fieldCode'] ?? '');
        if ($fieldCode === '')
        {
            continue;
        }

        $isDate = !empty($meta['isDate']);
        $isNumeric = !empty($meta['isNumeric']);
        $isEmployee = !empty($meta['isEmployee']);

        if ($isDate)
        {
            [$from, $to] = resolveDateRangeFromFilterField($filterValues, $columnId);
            if ($from !== '') { $filter['>='.$fieldCode] = $from; }
            if ($to !== '') { $filter['<='.$fieldCode] = $to; }
            continue;
        }

        if ($isNumeric)
        {
            $numSel = strtolower(trim((string)($filterValues[$columnId.'_numsel'] ?? '')));
            $from = trim((string)($filterValues[$columnId.'_from'] ?? ''));
            $to = trim((string)($filterValues[$columnId.'_to'] ?? ''));
            $exact = trim((string)($filterValues[$columnId] ?? ''));

            if ($numSel === 'more' && $from !== '')
            {
                $filter['>'.$fieldCode] = (float)str_replace(',', '.', $from);
                continue;
            }
            if ($numSel === 'less' && $to !== '')
            {
                $filter['<'.$fieldCode] = (float)str_replace(',', '.', $to);
                continue;
            }
            if (($numSel === 'range' || ($from !== '' || $to !== '')) && ($from !== '' || $to !== ''))
            {
                if ($from !== '') { $filter['>='.$fieldCode] = (float)str_replace(',', '.', $from); }
                if ($to !== '') { $filter['<='.$fieldCode] = (float)str_replace(',', '.', $to); }
                continue;
            }
            if ($exact !== '')
            {
                $filter['='.$fieldCode] = (float)str_replace(',', '.', $exact);
            }
            continue;
        }

        $raw = $filterValues[$columnId] ?? null;
        if (is_array($raw))
        {
            $values = [];
            foreach ($raw as $v)
            {
                $s = trim((string)$v);
                if ($s === '')
                {
                    continue;
                }
                if ($isEmployee)
                {
                    $userId = normalizeUserId($s);
                    if ($userId > 0)
                    {
                        $values[] = $userId;
                    }
                }
                else
                {
                    $values[] = $s;
                }
            }
            $values = array_values(array_unique($values));
            if (!empty($values))
            {
                $filter[(count($values) > 1 ? '@' : '=') . $fieldCode] = count($values) > 1 ? $values : $values[0];
            }
            continue;
        }

        $value = trim((string)$raw);
        if ($value === '')
        {
            continue;
        }

        if ($isEmployee)
        {
            $userId = normalizeUserId($value);
            if ($userId > 0)
            {
                $filter['='.$fieldCode] = $userId;
            }
            continue;
        }

        $filter['%'.$fieldCode] = $value;
    }

    return $filter;
}

function keepNonRootRules(array $rules, array $gridColumnMap, string $rootNodeId): array
{
    $result = [];
    foreach ($rules as $rule)
    {
        $columnId = (string)($rule['columnId'] ?? '');
        if ($columnId === '')
        {
            continue;
        }
        $gridCol = (array)($gridColumnMap[$columnId] ?? []);
        $nodeId = (string)($gridCol['nodeId'] ?? '');
        if ($nodeId !== '' && $nodeId !== $rootNodeId)
        {
            $result[] = $rule;
        }
    }

    return $result;
}

function applyDynamicRulesToRows(array $rows, array $rules, string $findText = ''): array
{
    $find = mb_strtolower(trim($findText));
    $result = [];

    foreach ($rows as $row)
    {
        $cols = is_array($row['data'] ?? null) ? $row['data'] : [];
        $rawCols = is_array($row['raw'] ?? null) ? $row['raw'] : [];
        $ok = true;

        foreach ($rules as $rule)
        {
            $columnId = (string)($rule['columnId'] ?? '');
            $value = trim((string)($cols[$columnId] ?? ''));
            $rawValue = trim((string)($rawCols[$columnId] ?? ''));
            $type = (string)($rule['type'] ?? '');

            if ($type === 'contains')
            {
                $needle = (string)($rule['value'] ?? '');
                if ($needle !== '' && mb_stripos(mb_strtolower($value), $needle) === false)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'in')
            {
                $values = array_values(array_map('strval', (array)($rule['values'] ?? [])));
                $matched = in_array($value, $values, true) || in_array($rawValue, $values, true);
                if (!$matched && $rawValue !== '')
                {
                    foreach (splitMultiValueTokens($rawValue) as $token)
                    {
                        if (in_array($token, $values, true))
                        {
                            $matched = true;
                            break;
                        }
                    }
                }
                if (!$matched)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'employee_in')
            {
                $values = array_values(array_map('strval', (array)($rule['values'] ?? [])));
                $candidateIds = [];
                $direct = normalizeUserId($rawValue !== '' ? $rawValue : $value);
                if ($direct > 0)
                {
                    $candidateIds[] = (string)$direct;
                }
                if (empty($candidateIds) && $rawValue !== '')
                {
                    foreach (splitMultiValueTokens($rawValue) as $token)
                    {
                        $id = normalizeUserId($token);
                        if ($id > 0)
                        {
                            $candidateIds[] = (string)$id;
                        }
                    }
                }

                $matched = false;
                foreach (array_unique($candidateIds) as $candidateId)
                {
                    if (in_array($candidateId, $values, true))
                    {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'date_range')
            {
                $valueTs = parseDateToTs($rawValue !== '' ? $rawValue : $value);
                $fromTs = parseDateToTs((string)($rule['from'] ?? ''));
                $toTs = parseDateToTs((string)($rule['to'] ?? ''));
                if ($valueTs === null)
                {
                    $ok = false;
                    break;
                }
                if ($fromTs !== null && $valueTs < $fromTs)
                {
                    $ok = false;
                    break;
                }
                if ($toTs !== null && $valueTs > $toTs)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'number_eq')
            {
                $l = normalizeNumeric($rawValue !== '' ? $rawValue : $value);
                $r = normalizeNumeric((string)($rule['value'] ?? ''));
                if ($l === null || $r === null || $l !== $r)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'number_more')
            {
                $l = normalizeNumeric($rawValue !== '' ? $rawValue : $value);
                $r = normalizeNumeric((string)($rule['value'] ?? ''));
                if ($l === null || $r === null || $l <= $r)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'number_less')
            {
                $l = normalizeNumeric($rawValue !== '' ? $rawValue : $value);
                $r = normalizeNumeric((string)($rule['value'] ?? ''));
                if ($l === null || $r === null || $l >= $r)
                {
                    $ok = false;
                    break;
                }
            }
            elseif ($type === 'number_range')
            {
                $l = normalizeNumeric($rawValue !== '' ? $rawValue : $value);
                $f = normalizeNumeric((string)($rule['from'] ?? ''));
                $t = normalizeNumeric((string)($rule['to'] ?? ''));
                if ($l === null)
                {
                    $ok = false;
                    break;
                }
                if ($f !== null && $l < $f)
                {
                    $ok = false;
                    break;
                }
                if ($t !== null && $l > $t)
                {
                    $ok = false;
                    break;
                }
            }
        }

        if (!$ok)
        {
            continue;
        }

        if ($find !== '')
        {
            $hay = mb_strtolower(implode(' ', array_map('strval', $cols)));
            if (mb_stripos($hay, $find) === false)
            {
                continue;
            }
        }

        $result[] = $row;
    }

    return $result;
}

function parseDateToTs(string $value): ?int
{
    $value = trim($value);
    if ($value === '')
    {
        return null;
    }

    $ts = strtotime($value);
    return $ts === false ? null : (int)$ts;
}

function normalizeNumeric(string $value): ?float
{
    $value = trim($value);
    if ($value === '')
    {
        return null;
    }

    $value = str_replace([' ', "\xc2\xa0"], '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value))
    {
        return null;
    }

    return (float)$value;
}

function getItemsSafe($factory, array $params, int $userId): array
{
    if (!$factory)
    {
        return [];
    }

    if (method_exists($factory, 'getItemsFilteredByPermissions'))
    {
        try
        {
            return (array)$factory->getItemsFilteredByPermissions($params, $userId);
        }
        catch (\Throwable $e)
        {
        }
    }

    return (array)$factory->getItems($params);
}

function getItemsSafeByPaging($factory, array $params, int $userId, int $chunkSize = 2000): array
{
    $chunkSize = max(100, $chunkSize);
    $offset = 0;
    $all = [];
    $guard = 0;
    $maxIterations = 1000;
    $lastFirstId = null;
    $lastLastId = null;

    while (true)
    {
        if ($guard++ > $maxIterations)
        {
            break;
        }

        $chunkParams = $params;
        unset($chunkParams['offset'], $chunkParams['limit']);
        $chunkParams['offset'] = $offset;
        $chunkParams['limit'] = $chunkSize;

        $chunk = getItemsSafe($factory, $chunkParams, $userId);
        $count = count($chunk);
        if ($count <= 0)
        {
            break;
        }

        $firstId = null;
        $lastId = null;
        if ($count > 0)
        {
            $first = $chunk[0] ?? null;
            $last = $chunk[$count - 1] ?? null;
            if (is_object($first) && method_exists($first, 'getId'))
            {
                $firstId = (int)$first->getId();
            }
            if (is_object($last) && method_exists($last, 'getId'))
            {
                $lastId = (int)$last->getId();
            }
        }
        if ($firstId !== null && $lastId !== null && $firstId === $lastFirstId && $lastId === $lastLastId)
        {
            // Защита от циклов, если фабрика игнорирует OFFSET.
            break;
        }
        $lastFirstId = $firstId;
        $lastLastId = $lastId;

        $all = array_merge($all, $chunk);
        if ($count < $chunkSize)
        {
            break;
        }
        $offset += $chunkSize;
    }

    return $all;
}

function getItemsCountSafe($factory, array $filter, int $userId): ?int
{
    if (!$factory)
    {
        return 0;
    }

    if (method_exists($factory, 'getItemsCountFilteredByPermissions'))
    {
        try
        {
            return (int)$factory->getItemsCountFilteredByPermissions(['filter' => $filter], $userId);
        }
        catch (\Throwable $e)
        {
        }
    }

    if (method_exists($factory, 'getItemsCount'))
    {
        try
        {
            return (int)$factory->getItemsCount(['filter' => $filter]);
        }
        catch (\Throwable $e)
        {
        }
    }

    return null;
}

function countItemsSafeByPaging($factory, array $filter, int $userId): int
{
    if (!$factory)
    {
        return 0;
    }

    $offset = 0;
    $pageSize = 2000;
    $total = 0;
    $guard = 0;
    $maxIterations = 1000;
    $lastFirstId = null;
    $lastLastId = null;

    while (true)
    {
        if ($guard++ > $maxIterations)
        {
            break;
        }

        $chunk = getItemsSafe($factory, [
            'select' => ['ID'],
            'filter' => $filter,
            'order' => ['ID' => 'ASC'],
            'offset' => $offset,
            'limit' => $pageSize,
        ], $userId);

        $count = count($chunk);
        if ($count <= 0)
        {
            break;
        }

        $firstId = null;
        $lastId = null;
        if ($count > 0)
        {
            $first = $chunk[0] ?? null;
            $last = $chunk[$count - 1] ?? null;
            if (is_object($first) && method_exists($first, 'getId'))
            {
                $firstId = (int)$first->getId();
            }
            if (is_object($last) && method_exists($last, 'getId'))
            {
                $lastId = (int)$last->getId();
            }
        }
        if ($firstId !== null && $lastId !== null && $firstId === $lastFirstId && $lastId === $lastLastId)
        {
            break;
        }
        $lastFirstId = $firstId;
        $lastLastId = $lastId;

        $total += $count;
        if ($count < $pageSize)
        {
            break;
        }

        $offset += $pageSize;
    }

    return $total;
}

function sendExcelFromGridRows(array $template, array $gridColumns, array $gridRows): void
{
    while (ob_get_level() > 0)
    {
        ob_end_clean();
    }

    $fileName = 'report_'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($template['id'] ?? 'export')).'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fileName.'"');
    header('Pragma: public');
    header('Cache-Control: max-age=0');

    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"4\">";
    echo "<tr>";
    foreach ($gridColumns as $col)
    {
        echo '<th>'.htmlspecialcharsbx((string)($col['name'] ?? '')).'</th>';
    }
    echo "</tr>";

    foreach ($gridRows as $row)
    {
        $cols = is_array($row['data'] ?? null) ? $row['data'] : [];
        echo "<tr>";
        foreach ($gridColumns as $col)
        {
            $id = (string)($col['id'] ?? '');
            echo '<td>'.htmlspecialcharsbx((string)($cols[$id] ?? '')).'</td>';
        }
        echo "</tr>";
    }

    echo "</table></body></html>";
}

function buildFactorySelectFields($factory, array $candidates): array
{
    $available = [];
    if (is_object($factory) && method_exists($factory, 'getFieldsCollection'))
    {
        try
        {
            $fields = $factory->getFieldsCollection();
            foreach ($fields as $field)
            {
                if (method_exists($field, 'getName'))
                {
                    $name = strtoupper(trim((string)$field->getName()));
                    if ($name !== '')
                    {
                        $available[$name] = true;
                    }
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
        $field = strtoupper(trim((string)$field));
        if ($field === '')
        {
            continue;
        }

        if (empty($available) || isset($available[$field]))
        {
            $result[] = $field;
        }
    }

    if (empty($result))
    {
        $result = ['ID'];
    }

    return array_values(array_unique($result));
}

function buildRowsWithNodeItems(array $rootItems, array $nodes, string $rootNodeId, int $userId): array
{
    $nodeMap = buildNodeMap($nodes);
    $rows = [];
    foreach ($rootItems as $rootItem)
    {
        if (!is_object($rootItem))
        {
            continue;
        }
        $rows[] = [
            'rootItem' => $rootItem,
            'nodeItems' => [$rootNodeId => $rootItem],
        ];
    }

    if (empty($rows))
    {
        return $rows;
    }

    $childrenByParent = [];
    foreach ($nodeMap as $nodeId => $node)
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
        $childrenByParent[$parentId][] = $nodeId;
    }

    $queue = [$rootNodeId];
    while (!empty($queue))
    {
        $parentId = array_shift($queue);
        foreach ((array)($childrenByParent[$parentId] ?? []) as $childId)
        {
            $child = (array)($nodeMap[$childId] ?? []);
            $entityCode = (string)($child['entityCode'] ?? '');
            $relationField = (string)($child['parentFieldCode'] ?? '');
            if ($entityCode === '' || $relationField === '')
            {
                $queue[] = $childId;
                continue;
            }

            $factory = getFactoryByCode($entityCode);
            if (!$factory)
            {
                $queue[] = $childId;
                continue;
            }

            $idsByRow = [];
            $allIds = [];
            foreach ($rows as $idx => $row)
            {
                $parentItem = $row['nodeItems'][$parentId] ?? null;
                if (!is_object($parentItem) || !method_exists($parentItem, 'get'))
                {
                    $idsByRow[$idx] = [];
                    continue;
                }

                $ids = extractEntityIdsFromRelationValue($parentItem->get($relationField));
                $idsByRow[$idx] = $ids;
                $allIds = array_merge($allIds, $ids);
            }

            $allIds = array_values(array_unique(array_filter(array_map('intval', $allIds), static function (int $id): bool {
                return $id > 0;
            })));
            if (empty($allIds))
            {
                $queue[] = $childId;
                continue;
            }

            $selectCandidates = ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'COMPANY_TITLE'];
            foreach ((array)($child['selectedFields'] ?? []) as $f)
            {
                $f = trim((string)$f);
                if ($f !== '')
                {
                    $selectCandidates[] = $f;
                }
            }
            foreach ((array)($childrenByParent[$childId] ?? []) as $nextChildId)
            {
                $nextField = trim((string)($nodeMap[$nextChildId]['parentFieldCode'] ?? ''));
                if ($nextField !== '')
                {
                    $selectCandidates[] = $nextField;
                }
            }

            $select = buildFactorySelectFields($factory, $selectCandidates);
            $items = getItemsSafe($factory, [
                'select' => $select,
                'filter' => ['@ID' => $allIds],
                'limit' => count($allIds),
            ], $userId);

            $itemsById = [];
            foreach ($items as $item)
            {
                if (is_object($item) && method_exists($item, 'getId'))
                {
                    $itemsById[(int)$item->getId()] = $item;
                }
            }

            foreach ($rows as $idx => &$row)
            {
                $picked = null;
                foreach ((array)($idsByRow[$idx] ?? []) as $id)
                {
                    $id = (int)$id;
                    if ($id > 0 && isset($itemsById[$id]))
                    {
                        $picked = $itemsById[$id];
                        break;
                    }
                }
                if ($picked)
                {
                    $row['nodeItems'][$childId] = $picked;
                }
            }
            unset($row);

            $queue[] = $childId;
        }
    }

    return $rows;
}

function extractEntityIdsFromRelationValue($value): array
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
            $ids = array_merge($ids, extractEntityIdsFromRelationValue($part));
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    if (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return extractEntityIdsFromRelationValue($value->getValue());
        }
        if (method_exists($value, '__toString'))
        {
            return extractEntityIdsFromRelationValue((string)$value);
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
            return extractEntityIdsFromRelationValue($decoded);
        }
    }

    if (preg_match_all('/(?:^|_)(\d+)(?:$|[^0-9])/', $text, $m) && !empty($m[1]))
    {
        return array_values(array_unique(array_filter(array_map('intval', $m[1]), static function (int $id): bool {
            return $id > 0;
        })));
    }

    return [];
}

function getItemFieldValueSafe($item, string $entityCode, string $fieldCode)
{
    if (!is_object($item))
    {
        return null;
    }

    try
    {
        if (method_exists($item, 'get'))
        {
            return $item->get($fieldCode);
        }
    }
    catch (\Throwable $e)
    {
        if (strtoupper($entityCode) === 'CONTACT' && isContactAddressFieldCode($fieldCode))
        {
            $value = getContactAddressFieldValue($item, $fieldCode);
            if ($value !== null)
            {
                return $value;
            }
        }
    }

    return null;
}

function isContactAddressFieldCode(string $fieldCode): bool
{
    static $codes = [
        'ADDRESS' => true,
        'ADDRESS_2' => true,
        'ADDRESS_CITY' => true,
        'ADDRESS_POSTAL_CODE' => true,
        'ADDRESS_REGION' => true,
        'ADDRESS_PROVINCE' => true,
        'ADDRESS_COUNTRY' => true,
        'ADDRESS_COUNTRY_CODE' => true,
        'REG_ADDRESS' => true,
        'REG_ADDRESS_2' => true,
        'REG_ADDRESS_CITY' => true,
        'REG_ADDRESS_POSTAL_CODE' => true,
        'REG_ADDRESS_REGION' => true,
        'REG_ADDRESS_PROVINCE' => true,
        'REG_ADDRESS_COUNTRY' => true,
        'REG_ADDRESS_COUNTRY_CODE' => true,
    ];

    return !empty($codes[strtoupper($fieldCode)]);
}

function getContactAddressFieldValue($contactItem, string $fieldCode)
{
    $fieldCode = strtoupper($fieldCode);

    if (method_exists($contactItem, 'getCompatibleData'))
    {
        try
        {
            $compatible = (array)$contactItem->getCompatibleData();
            if (array_key_exists($fieldCode, $compatible))
            {
                return $compatible[$fieldCode];
            }
        }
        catch (\Throwable $e)
        {
        }
    }

    if (!method_exists($contactItem, 'getId'))
    {
        return null;
    }

    $contactId = (int)$contactItem->getId();
    if ($contactId <= 0)
    {
        return null;
    }

    static $contactCache = [];
    if (!array_key_exists($contactId, $contactCache))
    {
        $contactCache[$contactId] = \CCrmContact::GetByID($contactId, false) ?: [];
    }

    $row = (array)$contactCache[$contactId];
    return $row[$fieldCode] ?? null;
}

function shouldRenderAsEntityLink(string $entityCode, string $fieldCode): bool
{
    $fieldCode = strtoupper(trim($fieldCode));
    if ($fieldCode === 'TITLE')
    {
        return true;
    }
    if (strtoupper(trim($entityCode)) === 'CONTACT' && $fieldCode === 'NAME')
    {
        return true;
    }
    return false;
}

function buildEntityItemUrl(string $entityCode, int $id): string
{
    if ($id <= 0)
    {
        return '';
    }

    $entityCode = strtoupper(trim($entityCode));
    if ($entityCode === 'CONTACT')
    {
        return '/crm/contact/details/'.$id.'/';
    }
    if ($entityCode === 'COMPANY')
    {
        return '/crm/company/details/'.$id.'/';
    }
    if ($entityCode === 'DEAL')
    {
        return '/crm/deal/details/'.$id.'/';
    }
    if ($entityCode === 'LEAD')
    {
        return '/crm/lead/details/'.$id.'/';
    }
    if (strpos($entityCode, 'DYNAMIC_') === 0)
    {
        $typeId = (int)substr($entityCode, 8);
        if ($typeId > 0)
        {
            return '/crm/type/'.$typeId.'/details/'.$id.'/';
        }
    }

    return '';
}

function stringifyValue($value): string
{
    if (is_array($value))
    {
        $parts = [];
        foreach ($value as $part)
        {
            $s = stringifyValue($part);
            if ($s !== '')
            {
                $parts[] = $s;
            }
        }
        return implode(', ', $parts);
    }

    if (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return stringifyValue($value->getValue());
        }
        if (method_exists($value, '__toString'))
        {
            return trim((string)$value);
        }
        return '';
    }

    return trim((string)$value);
}

function formatValueForOutput($value, array $meta): string
{
    $enumItems = is_array($meta['enumItems'] ?? null) ? $meta['enumItems'] : [];
    $isBoolean = !empty($meta['isBoolean']);
    $isEmployee = !empty($meta['isEmployee']);
    $userTypeId = strtolower((string)($meta['userTypeId'] ?? ''));
    $isMoney = strtolower((string)($meta['type'] ?? '')) === 'money' || $userTypeId === 'money';

    if (is_array($value))
    {
        $parts = [];
        foreach ($value as $part)
        {
            $s = formatValueForOutput($part, $meta);
            if ($s !== '')
            {
                $parts[] = $s;
            }
        }
        return implode(', ', $parts);
    }

    if (is_object($value))
    {
        if (method_exists($value, 'getValue'))
        {
            return formatValueForOutput($value->getValue(), $meta);
        }
        if (method_exists($value, '__toString'))
        {
            return formatValueForOutput((string)$value, $meta);
        }
        return '';
    }

    $scalar = trim((string)$value);
    if ($scalar === '')
    {
        return '';
    }

    if (!empty($enumItems) && array_key_exists($scalar, $enumItems))
    {
        return (string)$enumItems[$scalar];
    }

    if ($isBoolean)
    {
        if ($scalar === 'Y' || $scalar === '1')
        {
            return 'Да';
        }
        if ($scalar === 'N' || $scalar === '0')
        {
            return 'Нет';
        }
    }

    if ($isEmployee)
    {
        $userId = normalizeUserId($scalar);
        if ($userId > 0)
        {
            $name = getUserDisplayNameById($userId);
            if ($name !== '')
            {
                return $name;
            }
        }
    }

    if ($isMoney)
    {
        return stripMoneySuffix($scalar);
    }

    return $scalar;
}

function splitMultiValueTokens(string $value): array
{
    $value = trim($value);
    if ($value === '')
    {
        return [];
    }

    $parts = preg_split('/\s*,\s*/', $value) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static function (string $part): bool {
        return $part !== '';
    }));
}

function normalizeUserId(string $value): int
{
    $value = trim($value);
    if ($value === '')
    {
        return 0;
    }
    if (preg_match('/^U(\d+)$/i', $value, $m))
    {
        return (int)$m[1];
    }
    return ctype_digit($value) ? (int)$value : 0;
}

function buildUserDisplayName(array $user): string
{
    $parts = array_filter([
        trim((string)($user['LAST_NAME'] ?? '')),
        trim((string)($user['NAME'] ?? '')),
        trim((string)($user['SECOND_NAME'] ?? '')),
    ], static function (string $part): bool {
        return $part !== '';
    });
    if (!empty($parts))
    {
        return implode(' ', $parts);
    }
    return trim((string)($user['LOGIN'] ?? ''));
}

function getUserDisplayNameById(int $userId): string
{
    static $cache = [];
    if ($userId <= 0)
    {
        return '';
    }
    if (isset($cache[$userId]))
    {
        return $cache[$userId];
    }

    $rs = \CUser::GetByID($userId);
    $user = $rs ? $rs->Fetch() : false;
    if (!$user)
    {
        $cache[$userId] = '';
        return '';
    }

    $cache[$userId] = buildUserDisplayName($user);
    return $cache[$userId];
}

function stripMoneySuffix(string $value): string
{
    $value = trim($value);
    if ($value === '')
    {
        return '';
    }
    return preg_replace('/\|I?[A-Z]{3,5}$/', '', $value) ?? $value;
}

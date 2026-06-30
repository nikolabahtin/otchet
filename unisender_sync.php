<?php
/**
 * Страница синхронизации контактов отчёта с UniSender.
 * Архитектура: Bitrix загружается один раз → JSON на фронт → AJAX только UniSender.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Highloadblock as HL;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
require_once __DIR__ . '/../unisendermass/lib/UnifiedWizard.php';

global $APPLICATION, $USER;

// ── helpers ───────────────────────────────────────────────────────────────────

function loadOtchetTemplateForSync(string $templateId): ?array
{
    $id = (int)$templateId;
    if ($id > 0) {
        try {
            $block = HL\HighloadBlockTable::getList([
                'select' => ['*'],
                'filter' => ['=TABLE_NAME' => 'gnc_report_presets'],
                'limit'  => 1,
            ])->fetch();
            if ($block) {
                $dataClass = HL\HighloadBlockTable::compileEntity($block)->getDataClass();
                $row = $dataClass::getById($id)->fetch();
                if ($row) {
                    $config = !empty($row['UF_CONFIG_JSON'])
                        ? json_decode((string)$row['UF_CONFIG_JSON'], true)
                        : null;
                    return [
                        'id'     => (string)$row['ID'],
                        'name'   => (string)($row['UF_NAME'] ?? $templateId),
                        'config' => is_array($config) ? $config : [],
                    ];
                }
            }
        } catch (\Throwable $e) {}
    }
    foreach (glob(__DIR__ . '/storage/templates/u*_' . $templateId . '.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;
        return [
            'id'     => $templateId,
            'name'   => (string)($data['name'] ?? $templateId),
            'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
        ];
    }
    return null;
}

// ── авторизация ───────────────────────────────────────────────────────────────
$userId = (int)($USER ? $USER->GetID() : 0);
if ($userId <= 0) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Пользователь не авторизован.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}
if (!Loader::includeModule('crm')) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Модуль CRM не подключен.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}
Loader::includeModule('highloadblock');

// ── загрузка шаблона ──────────────────────────────────────────────────────────
$templateId = (string)($_GET['id'] ?? '');
$template   = $templateId !== '' ? loadOtchetTemplateForSync($templateId) : null;

if (!$template) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Шаблон отчёта не найден.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

$config = is_array($template['config'] ?? null) ? $template['config'] : [];
if (empty($config['filterValues'])) {
    echo '<div class="ui-alert ui-alert-warning"><span class="ui-alert-message">В шаблоне не сохранён фильтр. Перейдите в отчёт и сохраните фильтр.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

$APPLICATION->SetTitle('Синхронизация с UniSender: ' . htmlspecialchars((string)($template['name'] ?? $templateId)));

// ── UniSender списки ──────────────────────────────────────────────────────────
$uniLists  = [];
$initError = '';
try {
    $client   = new UsmUniSenderClient(UsmConfig::apiKey());
    $uniLists = $client->lists();
} catch (\Throwable $e) {
    $initError = $e->getMessage();
}

// ── выбранный список ──────────────────────────────────────────────────────────
$selectedListId = (string)($_GET['list_id'] ?? '');

// ── Загрузка контактов из Bitrix (ОДИН РАЗ) ──────────────────────────────────
// Развернуть multi-email, отфильтровать пустые — результат отдаём как JSON во фронт.
$contactRows = [];   // [{email, contactId, name}]
$loadError   = '';

if ($selectedListId !== '') {
    try {
        $syncParams = [
            'audience' => [
                'reportId'      => '',
                'rootEntity'    => '',
                'fields'        => [],
                '_otchetConfig' => $config,
            ],
            'template' => ['id' => '', 'variables' => []],
            'mapping'  => [],
            'list'     => ['id' => $selectedListId],
        ];
        $reportProvider = new UsmReportProvider();
        $all = $reportProvider->loadRecipients($syncParams);

        // Развернуть: один recipient уже может быть одним email (loadRecipients делает foreach $emails)
        // Фильтруем пустые и дедуплицируем email
        $seenEmails = [];
        foreach ($all as $r) {
            $email = UsmTools::normalizeEmail((string)($r['email'] ?? ''));
            if ($email === '') continue;
            if (isset($seenEmails[$email])) continue;
            $seenEmails[$email] = true;

            $name = trim((string)($r['values']['NAME'] ?? $r['values']['TITLE'] ?? ''));
            $contactId = (int)($r['contact_id'] ?? $r['root_id'] ?? 0);

            $contactRows[] = [
                'email'     => $email,
                'name'      => $name,
                'contactId' => $contactId,
            ];
        }
    } catch (\Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$totalContacts = count($contactRows);
$contactsJson  = json_encode($contactRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
<style>
/* Полная ширина рабочей области */
.pagetitle-theme-bx-bigbitrix .pagetitle-inner,
.content-area, .content-area-inner, .bx-content, .bx-content-inner { max-width:none !important; }

.usync-page { padding:18px 24px 60px; box-sizing:border-box; }
.usync-head { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.usync-title { font-size:20px; font-weight:700; margin:0 0 3px; }
.usync-subtitle { color:#66758c; font-size:13px; }

/* Карточки */
.usync-card { background:#fff; border:1px solid #d9e1ec; border-radius:6px; padding:16px 20px; margin-bottom:12px; }
.usync-card-title { font-weight:700; font-size:13px; color:#45556d; text-transform:uppercase; letter-spacing:.4px; margin:0 0 12px; }

/* Списки UniSender */
.usync-list-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:7px; }
.usync-list-item { border:2px solid #d9e1ec; border-radius:5px; padding:8px 11px; cursor:pointer; background:#fff; transition:border-color .12s,background .12s; user-select:none; }
.usync-list-item:hover { border-color:#2357d6; background:#f5f8ff; }
.usync-list-item.active { border-color:#2357d6; background:#edf3ff; }
.usync-list-name { font-weight:700; font-size:12px; }
.usync-list-meta { color:#99a8bc; font-size:11px; margin-top:1px; }

/* Статистика */
.usync-stats { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:7px; margin:0 0 14px; }
.usync-stat { border:1px solid #e4eaf2; background:#f8fafc; border-radius:5px; padding:9px 11px; }
.usync-stat-label { color:#66758c; font-size:10px; text-transform:uppercase; letter-spacing:.3px; line-height:1.3; }
.usync-stat-value { font-weight:700; font-size:20px; margin-top:3px; color:#172033; }
.usync-stat-value.green { color:#065f46; }
.usync-stat-value.red   { color:#b91c1c; }
.usync-stat-value.blue  { color:#1d4ed8; }

/* Прогресс */
.usync-progress-wrap { margin:8px 0 12px; }
.usync-progress-track { background:#e4eaf2; border-radius:3px; height:5px; overflow:hidden; }
.usync-progress-fill { height:100%; background:#2357d6; border-radius:3px; transition:width .25s; width:0%; }
.usync-progress-text { font-size:12px; color:#526070; margin-top:4px; }

/* Кнопки */
.usync-toolbar { display:flex; gap:7px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
.usync-btn { display:inline-flex; align-items:center; gap:6px; min-height:34px; padding:0 14px; border:0; border-radius:5px; background:#2357d6; color:#fff; font-weight:700; cursor:pointer; font-size:13px; text-decoration:none; transition:background .12s; white-space:nowrap; }
.usync-btn:hover { background:#1a46b0; color:#fff; text-decoration:none; }
.usync-btn.secondary { background:#526070; } .usync-btn.secondary:hover { background:#3e4e5d; }
.usync-btn.warning  { background:#d97706; } .usync-btn.warning:hover  { background:#b45309; }
.usync-btn.danger   { background:#dc2626; } .usync-btn.danger:hover   { background:#b91c1c; }
.usync-btn[disabled], .usync-btn.busy { opacity:.55; pointer-events:none; }
.usync-btn.busy { opacity:1; }
.usync-spinner { width:12px; height:12px; border:2px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.usync-sep { width:1px; height:24px; background:#d9e1ec; flex-shrink:0; }

/* Таблица */
.usync-table-wrap { border:1px solid #e4eaf2; border-radius:5px; overflow:auto; max-height:600px; }
.usync-table { width:100%; border-collapse:collapse; font-size:12px; }
.usync-table th, .usync-table td { border-bottom:1px solid #edf1f6; padding:6px 9px; text-align:left; vertical-align:middle; }
.usync-table tbody tr:last-child td { border-bottom:none; }
.usync-table th { position:sticky; top:0; z-index:2; background:#f4f6f9; color:#45556d; font-size:10px; text-transform:uppercase; font-weight:700; white-space:nowrap; }
.usync-badge { display:inline-flex; align-items:center; min-height:18px; padding:0 6px; border-radius:99px; font-size:11px; font-weight:700; }
.usync-badge.ok   { background:#d1fae5; color:#065f46; }
.usync-badge.no   { background:#fee2e2; color:#b91c1c; }
.usync-badge.warn { background:#fef3c7; color:#92400e; }
.usync-row-work td { background:#fffce8 !important; }
.usync-row-done td { background:#f0fdf4 !important; }
.usync-row-err  td { background:#fff5f5 !important; }
.usync-empty { color:#99a8bc; font-style:italic; text-align:center; padding:30px 0; }
.usync-num { color:#c4cdd8; font-size:11px; }

/* Алерты */
.usync-alert { border-radius:5px; padding:9px 13px; margin:8px 0; border:1px solid #fca5a5; background:#fff1f0; color:#7a1b12; font-size:13px; }
.usync-note  { border-radius:5px; padding:9px 13px; margin:8px 0; border:1px solid #93c5fd; background:#eff6ff; color:#1e3a8a; font-size:13px; }

@media(max-width:1000px) { .usync-stats { grid-template-columns:repeat(4,1fr); } }
@media(max-width:640px)  { .usync-stats { grid-template-columns:repeat(2,1fr); } }
</style>

<div class="usync-page">

    <!-- Шапка -->
    <div class="usync-head">
        <div>
            <div class="usync-title">Синхронизация с UniSender</div>
            <div class="usync-subtitle">
                Отчёт: <strong><?= htmlspecialchars((string)($template['name'] ?? $templateId)) ?></strong>
                <?php if ($selectedListId !== '' && $totalContacts > 0): ?>
                &nbsp;·&nbsp; Строк для синхронизации: <strong><?= $totalContacts ?></strong>
                <?php endif; ?>
            </div>
        </div>
        <a class="usync-btn secondary" href="/local/otchet/report.php?id=<?= urlencode($templateId) ?>">← Назад к отчёту</a>
    </div>

    <?php if ($initError !== ''): ?>
    <div class="usync-alert">Ошибка подключения к UniSender: <?= htmlspecialchars($initError) ?></div>
    <?php endif; ?>
    <?php if ($loadError !== ''): ?>
    <div class="usync-alert">Ошибка загрузки контактов из Bitrix: <?= htmlspecialchars($loadError) ?></div>
    <?php endif; ?>

    <!-- 1. Выбор списка UniSender -->
    <div class="usync-card">
        <div class="usync-card-title">Список UniSender</div>
        <?php if (empty($uniLists)): ?>
        <div class="usync-alert">Списки не найдены. Проверьте API-ключ.</div>
        <?php else: ?>
        <div class="usync-list-grid">
            <?php foreach ($uniLists as $lst): ?>
            <?php $lid = (string)($lst['id'] ?? ''); ?>
            <div class="usync-list-item <?= $lid === $selectedListId ? 'active' : '' ?>" data-list-id="<?= htmlspecialchars($lid) ?>">
                <div class="usync-list-name"><?= htmlspecialchars((string)($lst['title'] ?? $lid)) ?></div>
                <div class="usync-list-meta">ID: <?= htmlspecialchars($lid) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 2. Синхронизация -->
    <?php if ($selectedListId !== ''): ?>
    <div class="usync-card" id="syncSection">

        <!-- Статистика -->
        <div class="usync-stats">
            <div class="usync-stat"><div class="usync-stat-label">Всего строк</div><div class="usync-stat-value blue" id="statTotal"><?= $totalContacts ?></div></div>
            <div class="usync-stat"><div class="usync-stat-label">Обработано</div><div class="usync-stat-value" id="statDone">0</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Найдено в UniSender</div><div class="usync-stat-value green" id="statFound">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Не найдено</div><div class="usync-stat-value red" id="statNotFound">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">В выбранном списке</div><div class="usync-stat-value green" id="statInList">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Не в списке</div><div class="usync-stat-value red" id="statNotInList">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Ошибок</div><div class="usync-stat-value red" id="statErrors">0</div></div>
        </div>

        <!-- Прогресс -->
        <div class="usync-progress-wrap" id="progressWrap" style="display:none">
            <div class="usync-progress-track"><div class="usync-progress-fill" id="progressFill"></div></div>
            <div class="usync-progress-text" id="progressText"></div>
        </div>

        <!-- Панель кнопок -->
        <div class="usync-toolbar">
            <button class="usync-btn" id="btnSync"   data-action="sync"        title="Добавить/обновить контакты в базе UniSender без привязки к списку (importContacts)">Синхронизировать с UniSender</button>
            <button class="usync-btn" id="btnImport" data-action="add_to_list" title="Добавить все email в выбранный список UniSender (importContacts с list_id)">Добавить в список</button>
            <div class="usync-sep"></div>
            <button class="usync-btn warning" id="btnPause" style="display:none">⏸ Пауза</button>
            <button class="usync-btn danger"  id="btnStop"  style="display:none">⏹ Стоп</button>
        </div>

        <div id="resultNote" style="display:none"></div>

        <!-- Таблица (рендерится JS'ом) -->
        <div class="usync-table-wrap">
            <table class="usync-table" id="syncTable">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Email</th>
                        <th style="width:100px">В UniSender</th>
                        <th style="width:140px">В выбранном списке</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody id="syncTableBody"></tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){
    'use strict';

    var templateId     = <?= json_encode($templateId) ?>;
    var selectedListId = <?= json_encode($selectedListId) ?>;
    var sessid         = <?= json_encode(bitrix_sessid()) ?>;

    // ── Данные контактов — загружены из Bitrix ОДИН РАЗ ──────────────────────
    // contacts[i] = {email, name, contactId}
    var contacts = <?= $contactsJson ?? '[]' ?>;
    var total    = contacts.length;

    // ── Состояние строк (индекс по email) ────────────────────────────────────
    // rowState[email] = {exists: null|bool, inList: null|bool, status: '', error: ''}
    var rowState = {};
    contacts.forEach(function(c){ rowState[c.email] = {exists:null, inList:null, status:'', error:''}; });

    // ── Состояние процесса ────────────────────────────────────────────────────
    var proc = { running:false, paused:false, stopped:false, action:'', offset:0, batchSize:500,
                 done:0, errors:0, found:0, inList:0 };

    // ── DOM ───────────────────────────────────────────────────────────────────
    var tbody        = document.getElementById('syncTableBody');
    var progressWrap = document.getElementById('progressWrap');
    var progressFill = document.getElementById('progressFill');
    var progressText = document.getElementById('progressText');
    var resultNote   = document.getElementById('resultNote');
    var btnPause     = document.getElementById('btnPause');
    var btnStop      = document.getElementById('btnStop');

    function $s(id){ return document.getElementById(id); }

    // ── Рендер таблицы из contacts[] ─────────────────────────────────────────
    function renderTable(){
        var rows = contacts.map(function(c, i){
            var s = rowState[c.email];
            var usBadge   = s.exists  === true ? badge('да','ok') : s.exists  === false ? badge('нет','no') : badge('—','warn');
            var listBadge = s.inList  === true ? badge('да','ok') : s.inList  === false ? badge('нет','no') : badge('—','warn');
            var rowCls = s.error ? 'usync-row-err' : (s.status && s.exists !== null ? 'usync-row-done' : '');
            return '<tr class="' + rowCls + '" data-idx="' + i + '">' +
                '<td class="usync-num">' + (i+1) + '</td>' +
                '<td>' + esc(c.email) + (c.name ? ' <span style="color:#99a8bc;font-size:11px">· ' + esc(c.name) + '</span>' : '') + '</td>' +
                '<td>' + usBadge + '</td>' +
                '<td>' + listBadge + '</td>' +
                '<td style="font-size:11px;color:' + (s.error ? '#b91c1c' : '#526070') + '">' + esc(s.error || s.status) + '</td>' +
            '</tr>';
        });
        tbody.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="5" class="usync-empty">Нет контактов с email по фильтру отчёта</td></tr>';
    }

    function badge(t, cls){ return '<span class="usync-badge '+cls+'">'+esc(t)+'</span>'; }
    function esc(v){ return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // ── Обновить одну строку по индексу ──────────────────────────────────────
    function updateRow(idx){
        var row = tbody.querySelector('[data-idx="'+idx+'"]');
        if (!row) return;
        var c = contacts[idx];
        var s = rowState[c.email];
        row.className = s.error ? 'usync-row-err' : (s.exists !== null ? 'usync-row-done' : '');
        row.cells[2].innerHTML = s.exists  === true ? badge('да','ok') : s.exists  === false ? badge('нет','no') : badge('—','warn');
        row.cells[3].innerHTML = s.inList  === true ? badge('да','ok') : s.inList  === false ? badge('нет','no') : badge('—','warn');
        row.cells[4].style.color = s.error ? '#b91c1c' : '#526070';
        row.cells[4].textContent = s.error || s.status || '';
    }

    function markWorking(startIdx, count){
        for (var i = startIdx; i < Math.min(startIdx+count, total); i++){
            var row = tbody.querySelector('[data-idx="'+i+'"]');
            if (row){ row.className = 'usync-row-work'; row.cells[4].textContent = 'В работе...'; }
        }
    }

    // ── Статистика ────────────────────────────────────────────────────────────
    function updateStats(){
        $s('statDone').textContent     = proc.done;
        $s('statErrors').textContent   = proc.errors;
        $s('statFound').textContent    = proc.found >= 0    ? proc.found    : '—';
        $s('statNotFound').textContent = proc.found >= 0    ? (proc.done - proc.found) : '—';
        $s('statInList').textContent   = proc.inList >= 0   ? proc.inList   : '—';
        $s('statNotInList').textContent= proc.inList >= 0   ? (proc.done - proc.inList) : '—';
    }

    function updateProgress(){
        var pct = total > 0 ? Math.round(proc.offset / total * 100) : 0;
        progressFill.style.width = pct + '%';
        var label = proc.paused ? ' — ПАУЗА' : (proc.stopped ? ' — ОСТАНОВЛЕНО' : '');
        progressText.textContent = proc.offset + ' / ' + total + ' (' + pct + '%)' + label;
    }

    // ── AJAX: отправляем массив email серверу ────────────────────────────────
    // action: 'check' | 'sync' | 'import'
    // emails: ['a@b.com', ...]
    function ajaxEmails(action, emailBatch){
        var fd = new FormData();
        fd.append('sessid', sessid);
        fd.append('action', action);
        fd.append('list_id', selectedListId);
        fd.append('report_id', templateId);
        emailBatch.forEach(function(e){ fd.append('emails[]', e); });
        return fetch('/local/otchet/unisender_sync_ajax.php', {
            method:'POST', body:fd, credentials:'same-origin'
        }).then(function(r){ return r.json(); }).then(function(j){
            if (!j.ok) throw new Error(j.error || 'Ошибка сервера');
            return j.result || {};
        });
    }

    // ── Основной цикл обработки ───────────────────────────────────────────────
    function runNext(){
        if (!proc.running || proc.paused || proc.stopped) return;
        if (proc.offset >= total){ finishProc('завершено'); return; }

        var batchStart = proc.offset;
        var batchEnd   = Math.min(batchStart + proc.batchSize, total);
        var batch      = contacts.slice(batchStart, batchEnd);
        var emails     = batch.map(function(c){ return c.email; });

        markWorking(batchStart, batch.length);
        updateProgress();

        ajaxEmails(proc.action, emails).then(function(result){
            if (proc.stopped) return;

            var items = result.items || [];
            items.forEach(function(item, ii){
                var idx = batchStart + ii;
                var c   = contacts[idx];
                if (!c) return;
                var s = rowState[c.email];
                s.exists = item.exists  !== undefined ? !!item.exists  : s.exists;
                s.inList = item.in_list !== null && item.in_list !== undefined ? !!item.in_list : s.inList;
                s.status = item.status || '';
                s.error  = item.error  || '';
                if (s.exists)            proc.found++;
                if (s.inList === true)   proc.inList++;
                if (s.error)             proc.errors++;
                proc.done++;
                updateRow(idx);
            });

            proc.offset = batchEnd;
            updateStats();
            updateProgress();

            // Скролл к последней обработанной строке
            var lastRow = tbody.querySelector('[data-idx="'+(batchEnd-1)+'"]');
            if (lastRow) lastRow.scrollIntoView({block:'nearest', behavior:'smooth'});

            runNext();

        }).catch(function(err){
            if (proc.stopped) return;
            // Помечаем весь батч как ошибку
            for (var ii = batchStart; ii < batchEnd; ii++){
                var c = contacts[ii];
                if (!c) continue;
                rowState[c.email].error = err.message;
                rowState[c.email].status = '';
                proc.errors++;
                proc.done++;
                updateRow(ii);
            }
            proc.offset = batchEnd;
            updateStats();
            runNext();
        });
    }

    // ── Старт/финиш процесса ─────────────────────────────────────────────────
    function startProc(action){
        if (proc.running) return;
        proc = { running:true, paused:false, stopped:false, action:action,
                 offset:0, batchSize:20, done:0, errors:0, found:0, inList:0 };

        // Сбросить состояние строк
        contacts.forEach(function(c){ rowState[c.email] = {exists:null,inList:null,status:'',error:''}; });
        renderTable();

        resultNote.style.display = 'none';
        progressWrap.style.display = '';
        btnPause.style.display = '';
        btnStop.style.display  = '';
        btnPause.textContent = '⏸ Пауза';

        // Заблокировать кнопки действий
        ['btnSync','btnImport'].forEach(function(id){
            var b = $s(id);
            if (!b) return;
            if (b.getAttribute('data-action') === action){
                b.classList.add('busy');
                b.innerHTML = '<span class="usync-spinner"></span> ' + b.textContent.trim() + '...';
            } else {
                b.disabled = true;
            }
        });

        updateStats();
        updateProgress();
        runNext();
    }

    function finishProc(reason){
        proc.running = false;
        btnPause.style.display = 'none';
        btnStop.style.display  = 'none';

        ['btnSync','btnImport'].forEach(function(id){
            var b = $s(id);
            if (!b) return;
            b.classList.remove('busy');
            b.disabled = false;
            var labels = {btnSync:'Синхронизировать с UniSender', btnImport:'Добавить в список'};
            b.innerHTML = labels[id] || b.textContent;
        });

        updateProgress();
        resultNote.style.display = '';
        resultNote.className = proc.errors > 0 ? 'usync-alert' : 'usync-note';
        resultNote.textContent = 'Процесс ' + reason + '. ' +
            'Обработано: ' + proc.done + ', ' +
            'найдено в UniSender: ' + proc.found + ', ' +
            'в списке: ' + proc.inList + ', ' +
            'ошибок: ' + proc.errors + '.';
    }

    // ── Кнопки управления ─────────────────────────────────────────────────────
    $s('btnSync')   && $s('btnSync').addEventListener('click',   function(){ startProc('sync'); });
    $s('btnImport') && $s('btnImport').addEventListener('click', function(){ startProc('add_to_list'); });

    btnPause && btnPause.addEventListener('click', function(){
        if (!proc.running) return;
        if (!proc.paused){
            proc.paused = true;
            btnPause.textContent = '▶ Продолжить';
            updateProgress();
        } else {
            proc.paused = false;
            btnPause.textContent = '⏸ Пауза';
            runNext();
        }
    });

    btnStop && btnStop.addEventListener('click', function(){
        proc.stopped = true;
        proc.paused  = false;
        proc.running = false;
        finishProc('остановлено пользователем');
    });

    // ── Выбор списка — перезагрузка страницы ─────────────────────────────────
    document.querySelectorAll('.usync-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var url = new URL(window.location.href);
            url.searchParams.set('list_id', el.getAttribute('data-list-id'));
            window.location.href = url.toString();
        });
    });

    // ── Начальный рендер таблицы ──────────────────────────────────────────────
    if (total > 0) renderTable();

})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>

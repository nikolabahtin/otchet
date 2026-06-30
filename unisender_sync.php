<?php
/**
 * Страница синхронизации контактов отчёта с UniSender.
 * Схема: Bitrix один раз → JSON на фронт → async exportContacts (2 вызова) → таблица со статусами.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Highloadblock as HL;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
require_once __DIR__ . '/../unisendermass/lib/UnifiedWizard.php';

global $APPLICATION, $USER;

// ── загрузка шаблона из HL-блока ─────────────────────────────────────────────
function loadOtchetTemplateForSync(string $templateId): ?array
{
    $id = (int)$templateId;
    if ($id > 0) {
        try {
            $block = HL\HighloadBlockTable::getList([
                'select' => ['*'], 'filter' => ['=TABLE_NAME' => 'gnc_report_presets'], 'limit' => 1,
            ])->fetch();
            if ($block) {
                $dataClass = HL\HighloadBlockTable::compileEntity($block)->getDataClass();
                $row = $dataClass::getById($id)->fetch();
                if ($row) {
                    $config = !empty($row['UF_CONFIG_JSON']) ? json_decode((string)$row['UF_CONFIG_JSON'], true) : null;
                    return ['id' => (string)$row['ID'], 'name' => (string)($row['UF_NAME'] ?? $templateId),
                            'config' => is_array($config) ? $config : []];
                }
            }
        } catch (\Throwable $e) {}
    }
    foreach (glob(__DIR__ . '/storage/templates/u*_' . $templateId . '.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;
        return ['id' => $templateId, 'name' => (string)($data['name'] ?? $templateId),
                'config' => is_array($data['config'] ?? null) ? $data['config'] : []];
    }
    return null;
}

// ── авторизация ───────────────────────────────────────────────────────────────
$userId = (int)($USER ? $USER->GetID() : 0);
if ($userId <= 0) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Пользователь не авторизован.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; return;
}
if (!Loader::includeModule('crm')) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Модуль CRM не подключен.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; return;
}
Loader::includeModule('highloadblock');

// ── шаблон ────────────────────────────────────────────────────────────────────
$templateId = (string)($_GET['id'] ?? '');
$template   = $templateId !== '' ? loadOtchetTemplateForSync($templateId) : null;
if (!$template) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Шаблон отчёта не найден.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; return;
}

$config = is_array($template['config'] ?? null) ? $template['config'] : [];
if (empty($config['filterValues'])) {
    echo '<div class="ui-alert ui-alert-warning"><span class="ui-alert-message">В шаблоне не сохранён фильтр. Перейдите в отчёт и сохраните фильтр.</span></div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; return;
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

$selectedListId = (string)($_GET['list_id'] ?? '');

// ── Контакты из Bitrix (ОДИН РАЗ) ────────────────────────────────────────────
$contactRows = [];
$loadError   = '';
if ($selectedListId !== '') {
    try {
        $syncParams = [
            'audience' => ['reportId' => '', 'rootEntity' => '', 'fields' => [], '_otchetConfig' => $config],
            'template' => ['id' => '', 'variables' => []], 'mapping' => [], 'list' => ['id' => $selectedListId],
        ];
        $reportProvider = new UsmReportProvider();
        $all = $reportProvider->loadRecipients($syncParams);
        $seenEmails = [];
        foreach ($all as $r) {
            $email = UsmTools::normalizeEmail((string)($r['email'] ?? ''));
            if ($email === '' || isset($seenEmails[$email])) continue;
            $seenEmails[$email] = true;
            $contactRows[] = [
                'email'     => $email,
                'name'      => trim((string)($r['values']['NAME'] ?? $r['values']['TITLE'] ?? '')),
                'contactId' => (int)($r['contact_id'] ?? $r['root_id'] ?? 0),
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
.pagetitle-theme-bx-bigbitrix .pagetitle-inner,
.content-area,.content-area-inner,.bx-content,.bx-content-inner{max-width:none!important}

.usync-page{padding:18px 24px 60px;box-sizing:border-box}
.usync-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.usync-title{font-size:20px;font-weight:700;margin:0 0 3px}
.usync-subtitle{color:#66758c;font-size:13px}

.usync-card{background:#fff;border:1px solid #d9e1ec;border-radius:6px;padding:16px 20px;margin-bottom:12px}
.usync-card-title{font-weight:700;font-size:13px;color:#45556d;text-transform:uppercase;letter-spacing:.4px;margin:0 0 12px}

.usync-list-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:7px}
.usync-list-item{border:2px solid #d9e1ec;border-radius:5px;padding:8px 11px;cursor:pointer;background:#fff;transition:border-color .12s,background .12s;user-select:none}
.usync-list-item:hover{border-color:#2357d6;background:#f5f8ff}
.usync-list-item.active{border-color:#2357d6;background:#edf3ff}
.usync-list-name{font-weight:700;font-size:12px}
.usync-list-meta{color:#99a8bc;font-size:11px;margin-top:1px}

.usync-stats{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:7px;margin:0 0 14px}
.usync-stat{border:1px solid #e4eaf2;background:#f8fafc;border-radius:5px;padding:9px 11px}
.usync-stat-label{color:#66758c;font-size:10px;text-transform:uppercase;letter-spacing:.3px;line-height:1.3}
.usync-stat-value{font-weight:700;font-size:20px;margin-top:3px;color:#172033}
.usync-stat-value.green{color:#065f46}
.usync-stat-value.red{color:#b91c1c}
.usync-stat-value.blue{color:#1d4ed8}
.usync-stat-value.orange{color:#92400e}

.usync-progress-wrap{margin:8px 0 12px}
.usync-progress-track{background:#e4eaf2;border-radius:3px;height:5px;overflow:hidden}
.usync-progress-fill{height:100%;background:#2357d6;border-radius:3px;transition:width .3s;width:0%}
.usync-progress-text{font-size:12px;color:#526070;margin-top:4px}

.usync-toolbar{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.usync-btn{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 14px;border:0;border-radius:5px;background:#2357d6;color:#fff;font-weight:700;cursor:pointer;font-size:13px;text-decoration:none;transition:background .12s;white-space:nowrap}
.usync-btn:hover{background:#1a46b0;color:#fff;text-decoration:none}
.usync-btn.secondary{background:#526070}.usync-btn.secondary:hover{background:#3e4e5d}
.usync-btn.warning{background:#d97706}.usync-btn.warning:hover{background:#b45309}
.usync-btn.danger{background:#dc2626}.usync-btn.danger:hover{background:#b91c1c}
.usync-btn.success{background:#059669}.usync-btn.success:hover{background:#047857}
.usync-btn[disabled],.usync-btn.busy{opacity:.55;pointer-events:none}
.usync-btn.busy{opacity:1}
.usync-spinner{width:12px;height:12px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.usync-sep{width:1px;height:24px;background:#d9e1ec;flex-shrink:0}

.usync-loader{display:flex;align-items:center;gap:10px;padding:12px 0;color:#526070;font-size:13px}
.usync-loader-spin{width:18px;height:18px;border:2px solid #d9e1ec;border-top-color:#2357d6;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0}

.usync-table-wrap{border:1px solid #e4eaf2;border-radius:5px;overflow:auto;max-height:600px}
.usync-table{width:100%;border-collapse:collapse;font-size:12px}
.usync-table th,.usync-table td{border-bottom:1px solid #edf1f6;padding:6px 9px;text-align:left;vertical-align:middle}
.usync-table tbody tr:last-child td{border-bottom:none}
.usync-table th{position:sticky;top:0;z-index:2;background:#f4f6f9;color:#45556d;font-size:10px;text-transform:uppercase;font-weight:700;white-space:nowrap}
.usync-badge{display:inline-flex;align-items:center;min-height:18px;padding:0 6px;border-radius:99px;font-size:11px;font-weight:700}
.usync-badge.ok{background:#d1fae5;color:#065f46}
.usync-badge.no{background:#fee2e2;color:#b91c1c}
.usync-badge.pend{background:#f3f4f6;color:#6b7280}
.usync-row-synced td{background:#f0fdf4!important}
.usync-row-partial td{background:#fffce8!important}
.usync-empty{color:#99a8bc;font-style:italic;text-align:center;padding:30px 0}
.usync-num{color:#c4cdd8;font-size:11px}

.usync-alert{border-radius:5px;padding:9px 13px;margin:8px 0;border:1px solid #fca5a5;background:#fff1f0;color:#7a1b12;font-size:13px}
.usync-note{border-radius:5px;padding:9px 13px;margin:8px 0;border:1px solid #93c5fd;background:#eff6ff;color:#1e3a8a;font-size:13px}
.usync-success{border-radius:5px;padding:9px 13px;margin:8px 0;border:1px solid #6ee7b7;background:#ecfdf5;color:#065f46;font-size:13px}

@media(max-width:1000px){.usync-stats{grid-template-columns:repeat(4,1fr)}}
@media(max-width:640px){.usync-stats{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="usync-page">

<div class="usync-head">
    <div>
        <div class="usync-title">Синхронизация с UniSender</div>
        <div class="usync-subtitle">
            Отчёт: <strong><?= htmlspecialchars((string)($template['name'] ?? $templateId)) ?></strong>
            <?php if ($selectedListId !== '' && $totalContacts > 0): ?>
            &nbsp;·&nbsp; Контактов из Bitrix: <strong><?= $totalContacts ?></strong>
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

<!-- Выбор списка -->
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

<?php if ($selectedListId !== ''): ?>
<div class="usync-card" id="syncSection">

    <!-- Индикатор загрузки -->
    <div id="checkLoader" class="usync-loader">
        <div class="usync-loader-spin"></div>
        <span id="checkLoaderText">Запрашиваем данные из UniSender...</span>
    </div>

    <!-- Блок статистики и управления (показывается после проверки) -->
    <div id="statsBlock" style="display:none">
        <div class="usync-stats">
            <div class="usync-stat"><div class="usync-stat-label">Всего из Bitrix</div><div class="usync-stat-value blue" id="statTotal"><?= $totalContacts ?></div></div>
            <div class="usync-stat"><div class="usync-stat-label">Есть в UniSender</div><div class="usync-stat-value green" id="statInUs">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Нет в UniSender</div><div class="usync-stat-value red" id="statNotInUs">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Есть в списке</div><div class="usync-stat-value green" id="statInList">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Нет в списке</div><div class="usync-stat-value orange" id="statNotInList">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Синхронизировано</div><div class="usync-stat-value green" id="statSynced">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Ошибок</div><div class="usync-stat-value red" id="statErrors">—</div></div>
        </div>

        <div class="usync-progress-wrap" id="progressWrap" style="display:none">
            <div class="usync-progress-track"><div class="usync-progress-fill" id="progressFill"></div></div>
            <div class="usync-progress-text" id="progressText"></div>
        </div>

        <div class="usync-toolbar">
            <button class="usync-btn success" id="btnSync">Синхронизировать и добавить в список</button>
            <div class="usync-sep"></div>
            <button class="usync-btn warning" id="btnPause" style="display:none">⏸ Пауза</button>
            <button class="usync-btn danger"  id="btnStop"  style="display:none">⏹ Стоп</button>
        </div>

        <div id="resultNote" style="display:none"></div>
    </div>

    <!-- Таблица -->
    <div class="usync-table-wrap" id="tableWrap" style="display:none">
        <table class="usync-table">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>Email</th>
                    <th style="width:110px">В UniSender</th>
                    <th style="width:110px">В списке</th>
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

var selectedListId = <?= json_encode($selectedListId) ?>;
var sessid         = <?= json_encode(bitrix_sessid()) ?>;
var ajaxUrl        = '/local/otchet/unisender_sync_ajax.php';

var contacts = <?= $contactsJson ?? '[]' ?>;
var total    = contacts.length;

// Email-множества из UniSender (заполняются после экспорта)
var usEmailSet   = null;
var listEmailSet = null;

// Состояние каждого контакта
var rowState = {};
contacts.forEach(function(c){
    rowState[c.email] = {exists:null, inList:null, syncStatus:'', syncMsg:''};
});

// Состояние процесса синхронизации
var proc = {running:false, paused:false, stopped:false, emails:[], offset:0, batchSize:500, done:0, errors:0, synced:0};

function $s(id){ return document.getElementById(id); }
var tbody        = $s('syncTableBody');
var statsBlock   = $s('statsBlock');
var tableWrap    = $s('tableWrap');
var checkLoader  = $s('checkLoader');
var loaderText   = $s('checkLoaderText');
var progressWrap = $s('progressWrap');
var progressFill = $s('progressFill');
var progressText = $s('progressText');
var resultNote   = $s('resultNote');
var btnSync      = $s('btnSync');
var btnPause     = $s('btnPause');
var btnStop      = $s('btnStop');

function esc(v){ return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function badge(t,cls){ return '<span class="usync-badge '+cls+'">'+esc(t)+'</span>'; }
function sleep(ms){ return new Promise(function(r){ setTimeout(r,ms); }); }

function post(data){
    var fd = new FormData();
    fd.append('sessid', sessid);
    Object.keys(data).forEach(function(k){
        var v = data[k];
        if (Array.isArray(v)) v.forEach(function(x){ fd.append(k+'[]', x); });
        else fd.append(k, String(v));
    });
    return fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(j){ if (!j.ok) throw new Error(j.error || 'Ошибка сервера'); return j; });
}

// ── Рендер таблицы ────────────────────────────────────────────────────────
function rowStatusText(s){
    if (s.syncMsg) return s.syncMsg;
    if (s.inList)  return 'В списке' + (s.exists === null ? ' (проверка базы...)' : '');
    if (s.exists === null) return 'Не в списке, проверка базы...';
    if (s.exists)  return 'В UniSender, не в списке';
    return 'Отсутствует в UniSender';
}

function renderTable(){
    if (!total){ tbody.innerHTML = '<tr><td colspan="5" class="usync-empty">Нет контактов с email</td></tr>'; return; }
    tbody.innerHTML = contacts.map(function(c,i){
        var s = rowState[c.email];
        var usBadge   = s.exists===true?badge('да','ok'):s.exists===false?badge('нет','no'):badge('...','pend');
        var listBadge = s.inList===true?badge('да','ok'):s.inList===false?badge('нет','no'):badge('...','pend');
        var rc = s.syncStatus==='synced'||s.inList?'usync-row-synced':s.exists?'usync-row-partial':'';
        return '<tr class="'+rc+'" data-idx="'+i+'">' +
            '<td class="usync-num">'+(i+1)+'</td>' +
            '<td>'+esc(c.email)+(c.name?' <span style="color:#99a8bc;font-size:11px">· '+esc(c.name)+'</span>':'')+'</td>'+
            '<td>'+usBadge+'</td><td>'+listBadge+'</td>'+
            '<td style="font-size:11px;color:'+(s.syncStatus==='error'?'#b91c1c':'#526070')+'">'+esc(rowStatusText(s))+'</td>'+
            '</tr>';
    }).join('');
}

function updateRow(idx){
    var row = tbody.querySelector('[data-idx="'+idx+'"]');
    if (!row) return;
    var s = rowState[contacts[idx].email];
    row.className = s.syncStatus==='synced'||s.inList?'usync-row-synced':s.exists?'usync-row-partial':'';
    row.cells[2].innerHTML = s.exists===true?badge('да','ok'):s.exists===false?badge('нет','no'):badge('...','pend');
    row.cells[3].innerHTML = s.inList===true?badge('да','ok'):s.inList===false?badge('нет','no'):badge('...','pend');
    row.cells[4].style.color = s.syncStatus==='error'?'#b91c1c':'#526070';
    row.cells[4].textContent = rowStatusText(s);
}

// ── Статистика ────────────────────────────────────────────────────────────
function updateCheckStats(){
    var inUs=0, notInUs=0, inList=0, notInList=0;
    contacts.forEach(function(c){
        var s=rowState[c.email];
        if(s.exists===true)  inUs++;
        if(s.exists===false) notInUs++;
        if(s.inList===true)  inList++;
        if(s.inList===false&&s.exists!==null) notInList++;
    });
    $s('statInUs').textContent      = inUs;
    $s('statNotInUs').textContent   = notInUs;
    $s('statInList').textContent    = inList;
    $s('statNotInList').textContent = notInList;
}

function updateSyncStats(){
    $s('statSynced').textContent = proc.synced >= 0 ? proc.synced : '—';
    $s('statErrors').textContent = proc.errors >= 0 ? proc.errors : '—';
}

// ── Шаг 1: Запрос двух async-экспортов из UniSender (независимо друг от друга) ──
// scope=list — обычно быстро (десятки секунд), от него зависит кнопка синхронизации.
// scope=all  — может быть долгим если в UniSender большая база, не блокирует работу со списком.
function startExportCheck(){
    if (!total){ setLoaderDone('Контактов для синхронизации нет.'); return; }

    statsBlock.style.display = '';
    tableWrap.style.display  = '';
    renderTable();

    loaderText.textContent = 'Проверяем выбранный список UniSender...';

    post({action:'export_start', scope:'list', list_id:selectedListId}).then(function(r){
        return pollOne(r.task_uuid, 80, function(msg){ loaderText.textContent = msg; });
    }).then(function(emails){
        listEmailSet = new Set(emails);
        contacts.forEach(function(c){ rowState[c.email].inList = listEmailSet.has(c.email); });
        setLoaderDone(null);
        updateCheckStats();
        renderTable();
    }).catch(function(err){
        checkLoader.innerHTML = '<span style="color:#b91c1c;font-size:13px">Ошибка проверки списка: '+esc(err.message)+'</span>';
        contacts.forEach(function(c){ rowState[c.email].inList = false; });
        updateCheckStats();
        renderTable();
    });

    // Полная проверка базы UniSender — отдельно, медленнее, не блокирует кнопку
    var usLoaderNote = document.createElement('div');
    usLoaderNote.className = 'usync-note';
    usLoaderNote.textContent = 'Проверяем всю базу UniSender (может занять несколько минут)...';
    statsBlock.parentNode.insertBefore(usLoaderNote, statsBlock);

    post({action:'export_start', scope:'all', list_id:selectedListId}).then(function(r){
        return pollOne(r.task_uuid, 200, function(msg){ usLoaderNote.textContent = msg; });
    }).then(function(emails){
        usEmailSet = new Set(emails);
        contacts.forEach(function(c){ rowState[c.email].exists = usEmailSet.has(c.email); });
        usLoaderNote.remove();
        updateCheckStats();
        renderTable();
    }).catch(function(err){
        usLoaderNote.className = 'usync-alert';
        usLoaderNote.textContent = 'Не удалось проверить всю базу UniSender: '+err.message+'. Колонка "В UniSender" может быть неточной для контактов вне списка.';
    });
}

function pollOne(uuid, maxAttempts, onProgress){
    return new Promise(function(resolve, reject){
        var attempts = 0;
        function tick(){
            if (++attempts > maxAttempts){ reject(new Error('Превышено время ожидания ('+maxAttempts+' попыток).')); return; }
            post({action:'export_status', task_uuid:uuid}).then(function(r){
                if (r.status === 'completed'){ resolve(r.emails || []); return; }
                if (onProgress) onProgress('Ожидаем данные UniSender... ('+attempts*3+' сек, статус: '+(r.raw_status||'?')+')');
                sleep(3000).then(tick);
            }).catch(reject);
        }
        tick();
    });
}

function setLoaderDone(msg){
    checkLoader.style.display='none';
    if(msg){
        var d=document.createElement('div');
        d.className='usync-note'; d.textContent=msg;
        checkLoader.parentNode.insertBefore(d, checkLoader.nextSibling);
    }
}

// ── Шаг 2: Синхронизация (только те, кого нет в списке) ──────────────────
function startSync(){
    if(proc.running) return;

    var toSync = contacts.filter(function(c){ return !rowState[c.email].inList; }).map(function(c){ return c.email; });

    if(!toSync.length){
        resultNote.className = 'usync-success';
        resultNote.textContent = 'Все контакты уже синхронизированы и находятся в списке.';
        resultNote.style.display = '';
        return;
    }

    proc = {running:true,paused:false,stopped:false,emails:toSync,offset:0,batchSize:500,done:0,errors:0,synced:0};
    progressWrap.style.display='';
    btnPause.style.display=''; btnStop.style.display='';
    btnSync.classList.add('busy');
    btnSync.innerHTML='<span class="usync-spinner"></span> Синхронизация...';
    resultNote.style.display='none';
    runSyncNext();
}

function runSyncNext(){
    if(!proc.running||proc.paused||proc.stopped) return;
    if(proc.offset>=proc.emails.length){ finishSync('завершена'); return; }

    var batch = proc.emails.slice(proc.offset, proc.offset+proc.batchSize);
    updateSyncProgress();

    post({action:'sync_and_add', list_id:selectedListId, emails:batch}).then(function(r){
        if(proc.stopped) return;
        batch.forEach(function(email){
            var idx = contacts.findIndex(function(c){ return c.email===email; });
            if(idx<0) return;
            var s = rowState[email];
            s.exists=true; s.inList=true; s.syncStatus='synced';
            s.syncMsg='Синхронизировано (добавлено: '+r.inserted+', обновлено: '+r.updated+')';
            proc.synced++;
            updateRow(idx);
        });
        proc.done+=batch.length; proc.offset+=proc.batchSize;
        updateCheckStats(); updateSyncStats(); updateSyncProgress();
        runSyncNext();
    }).catch(function(err){
        if(proc.stopped) return;
        batch.forEach(function(email){
            var idx=contacts.findIndex(function(c){ return c.email===email; });
            if(idx<0) return;
            rowState[email].syncStatus='error'; rowState[email].syncMsg=err.message;
            proc.errors++; updateRow(idx);
        });
        proc.done+=batch.length; proc.offset+=proc.batchSize;
        updateSyncStats(); runSyncNext();
    });
}

function updateSyncProgress(){
    var t=proc.emails.length, pct=t>0?Math.round(proc.offset/t*100):0;
    progressFill.style.width=pct+'%';
    progressText.textContent=proc.offset+' / '+t+' ('+pct+'%)'+(proc.paused?' — ПАУЗА':proc.stopped?' — ОСТАНОВЛЕНО':'');
}

function finishSync(reason){
    proc.running=false;
    btnPause.style.display='none'; btnStop.style.display='none';
    btnSync.classList.remove('busy');
    btnSync.innerHTML='Синхронизировать и добавить в список';
    updateSyncProgress();
    resultNote.className = proc.errors>0?'usync-note':'usync-success';
    resultNote.textContent='Синхронизация '+reason+'. Обработано: '+proc.done+', синхронизировано: '+proc.synced+', ошибок: '+proc.errors+'.';
    resultNote.style.display='';
}

// ── Кнопки ────────────────────────────────────────────────────────────────
if(btnSync)  btnSync.addEventListener('click', startSync);
if(btnPause) btnPause.addEventListener('click', function(){
    if(!proc.running) return;
    proc.paused=!proc.paused;
    btnPause.textContent=proc.paused?'▶ Продолжить':'⏸ Пауза';
    updateSyncProgress();
    if(!proc.paused) runSyncNext();
});
if(btnStop) btnStop.addEventListener('click', function(){
    proc.stopped=true; proc.paused=false; proc.running=false;
    finishSync('остановлена пользователем');
});

// Выбор списка
document.querySelectorAll('.usync-list-item').forEach(function(el){
    el.addEventListener('click', function(){
        var url=new URL(window.location.href);
        url.searchParams.set('list_id', el.getAttribute('data-list-id'));
        window.location.href=url.toString();
    });
});

// Автостарт проверки
if(total>0 && selectedListId!=='') startExportCheck();
else if(selectedListId!==''){
    setLoaderDone('Нет контактов с email по фильтру отчёта.');
    statsBlock.style.display='';
}

})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>

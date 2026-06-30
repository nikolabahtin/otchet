<?php
/**
 * Страница синхронизации контактов отчёта с UniSender.
 * URL: /local/otchet/unisender_sync.php?id={templateId}
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

    // Fallback: файл
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
    echo '<div class="ui-alert ui-alert-warning"><span class="ui-alert-message">В шаблоне не сохранён фильтр. Перейдите в отчёт, настройте фильтр и сохраните его.</span></div>';
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

// ── получить контакты (только если выбран список) ─────────────────────────────
$recipients = [];
$loadError  = '';
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
        // Только контакты с email
        $recipients = array_values(array_filter($all, static function (array $r): bool {
            return trim((string)($r['email'] ?? '')) !== '';
        }));
    } catch (\Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$totalContacts = count($recipients);

?>
<style>
/* Растягиваем рабочую область на всю ширину */
.pagetitle-theme-bx-bigbitrix .pagetitle-inner,
.content-area,
.content-area-inner,
.bx-content,
.bx-content-inner { max-width: none !important; }

.usync-page { padding: 20px 24px 60px; box-sizing: border-box; }
.usync-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:12px; }
.usync-title { font-size:22px; font-weight:700; margin:0; }
.usync-subtitle { color:#66758c; font-size:13px; margin:4px 0 0; }
.usync-card { background:#fff; border:1px solid #d9e1ec; border-radius:6px; padding:18px 20px; margin-bottom:14px; }
.usync-card-title { font-weight:700; font-size:14px; margin:0 0 12px; color:#172033; }
.usync-list-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:8px; }
.usync-list-item { border:2px solid #d9e1ec; border-radius:5px; padding:9px 12px; cursor:pointer; background:#fff; transition:border-color .15s,background .15s; user-select:none; }
.usync-list-item:hover { border-color:#2357d6; background:#f5f8ff; }
.usync-list-item.active { border-color:#2357d6; background:#edf3ff; }
.usync-list-name { font-weight:700; font-size:13px; }
.usync-list-meta { color:#66758c; font-size:11px; margin-top:2px; }

/* Статистика — 6 карточек */
.usync-stats { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:8px; margin:12px 0 16px; }
.usync-stat { border:1px solid #edf1f6; background:#f8fafc; border-radius:5px; padding:10px 12px; }
.usync-stat-label { color:#66758c; font-size:10px; text-transform:uppercase; letter-spacing:.4px; }
.usync-stat-value { font-weight:700; font-size:22px; margin-top:4px; }

/* Прогресс-бар */
.usync-progress-wrap { margin:10px 0; display:none; }
.usync-progress-bar-track { background:#e4eaf2; border-radius:4px; height:6px; overflow:hidden; margin-bottom:6px; }
.usync-progress-bar-fill { height:100%; background:#2357d6; border-radius:4px; transition:width .3s; width:0%; }
.usync-progress-text { font-size:13px; color:#163b73; }

/* Кнопки */
.usync-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:4px 0 12px; }
.usync-btn { display:inline-flex; align-items:center; gap:7px; min-height:36px; padding:0 16px; border:0; border-radius:5px; background:#2357d6; color:#fff; font-weight:700; cursor:pointer; font-size:13px; text-decoration:none; transition:background .15s,opacity .15s; white-space:nowrap; }
.usync-btn:hover { background:#1a46b0; color:#fff; text-decoration:none; }
.usync-btn.secondary { background:#526070; }
.usync-btn.secondary:hover { background:#3e4f5e; }
.usync-btn.pause { background:#d97706; }
.usync-btn.pause:hover { background:#b45309; }
.usync-btn.stop { background:#dc2626; }
.usync-btn.stop:hover { background:#b91c1c; }
.usync-btn:disabled, .usync-btn.running { opacity:.6; pointer-events:none; }
.usync-btn.running { opacity:1; pointer-events:none; }
.usync-spinner { width:13px; height:13px; border:2px solid rgba(255,255,255,.35); border-top-color:#fff; border-radius:50%; animation:spin .75s linear infinite; flex-shrink:0; }
@keyframes spin { to { transform:rotate(360deg); } }

.usync-alert { border-radius:5px; padding:10px 14px; margin:8px 0; border:1px solid #ffccc7; background:#fff1f0; color:#7a1b12; font-size:13px; }
.usync-note { border-radius:5px; padding:10px 14px; margin:8px 0; border:1px solid #b9d4ff; background:#eef5ff; color:#163b73; font-size:13px; }

/* Таблица */
.usync-table-wrap { margin-top:8px; max-height:580px; overflow:auto; border:1px solid #e4eaf2; border-radius:5px; }
.usync-table { width:100%; border-collapse:collapse; font-size:13px; }
.usync-table th, .usync-table td { border-bottom:1px solid #edf1f6; padding:7px 10px; text-align:left; vertical-align:middle; }
.usync-table tbody tr:last-child td { border-bottom:none; }
.usync-table th { position:sticky; top:0; z-index:2; background:#f4f6f9; color:#45556d; font-size:11px; text-transform:uppercase; font-weight:700; }
.usync-badge { display:inline-flex; align-items:center; min-height:20px; padding:0 7px; border-radius:999px; background:#edf3ff; color:#1b4db3; font-size:11px; font-weight:700; }
.usync-badge.warn { background:#fff4df; color:#8a4b00; }
.usync-badge.no { background:#fff1f0; color:#b42318; }
.usync-badge.ok { background:#d1fae5; color:#065f46; }
.usync-row-work td { background:#fffce6 !important; }
.usync-row-done td { background:#f0fdf4 !important; }
.usync-row-err td { background:#fff5f5 !important; }
.usync-empty { color:#66758c; font-style:italic; padding:24px 0; text-align:center; }

@media(max-width:900px) { .usync-stats { grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px) { .usync-stats { grid-template-columns:repeat(2,1fr); } .usync-actions { flex-direction:column; } }
</style>

<div class="usync-page">
    <div class="usync-head">
        <div>
            <h1 class="usync-title">Синхронизация с UniSender</h1>
            <div class="usync-subtitle">Отчёт: <?= htmlspecialchars((string)($template['name'] ?? $templateId)) ?> &nbsp;·&nbsp; Контактов с email: <strong><?= $totalContacts ?></strong></div>
        </div>
        <a class="usync-btn secondary" href="/local/otchet/report.php?id=<?= urlencode($templateId) ?>">← Назад к отчёту</a>
    </div>

    <?php if ($initError !== ''): ?>
    <div class="usync-alert">Ошибка подключения к UniSender: <?= htmlspecialchars($initError) ?></div>
    <?php endif; ?>
    <?php if ($loadError !== ''): ?>
    <div class="usync-alert">Ошибка загрузки контактов: <?= htmlspecialchars($loadError) ?></div>
    <?php endif; ?>

    <!-- 1. Выбор списка UniSender -->
    <div class="usync-card">
        <div class="usync-card-title">1. Выберите список UniSender</div>
        <?php if (empty($uniLists)): ?>
        <div class="usync-alert">Списки UniSender не найдены. Проверьте API-ключ.</div>
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
    <div class="usync-card" id="syncSection" <?= $selectedListId === '' ? 'style="display:none"' : '' ?>>
        <div class="usync-card-title">2. Синхронизация контактов</div>

        <!-- Статистика (6 плиток) -->
        <div class="usync-stats">
            <div class="usync-stat">
                <div class="usync-stat-label">Контактов с email</div>
                <div class="usync-stat-value"><?= $totalContacts ?></div>
            </div>
            <div class="usync-stat">
                <div class="usync-stat-label">Обработано</div>
                <div class="usync-stat-value" id="statDone">0</div>
            </div>
            <div class="usync-stat">
                <div class="usync-stat-label">Найдено в UniSender</div>
                <div class="usync-stat-value" id="statFound">—</div>
            </div>
            <div class="usync-stat">
                <div class="usync-stat-label">В выбранном списке</div>
                <div class="usync-stat-value" id="statInList">—</div>
            </div>
            <div class="usync-stat">
                <div class="usync-stat-label">Не в списке</div>
                <div class="usync-stat-value" id="statNotInList">—</div>
            </div>
            <div class="usync-stat">
                <div class="usync-stat-label">Ошибок</div>
                <div class="usync-stat-value" id="statErrors">0</div>
            </div>
        </div>

        <!-- Прогресс-бар -->
        <div class="usync-progress-wrap" id="progressWrap">
            <div class="usync-progress-bar-track"><div class="usync-progress-bar-fill" id="progressBar"></div></div>
            <div class="usync-progress-text" id="progressText"></div>
        </div>

        <!-- Кнопки действий -->
        <div class="usync-actions" id="actionButtons">
            <button class="usync-btn" type="button" id="btnCheck" data-action="ajax_check_unisender" data-label="Проверка UniSender">
                Проверить контакты в UniSender
            </button>
            <button class="usync-btn" type="button" id="btnSync" data-action="ajax_sync_unisender" data-label="Синхронизация UniSender">
                Синхронизировать контакты с UniSender
            </button>
            <button class="usync-btn" type="button" id="btnImport" data-action="ajax_import_to_list" data-label="Загрузка в список">
                Загрузить контакты в список
            </button>
        </div>

        <!-- Кнопки управления процессом (появляются во время работы) -->
        <div class="usync-actions" id="controlButtons" style="display:none">
            <button class="usync-btn pause" type="button" id="btnPause">⏸ Пауза</button>
            <button class="usync-btn stop"  type="button" id="btnStop">⏹ Остановить</button>
        </div>

        <div id="resultNote" style="display:none"></div>

        <!-- Таблица контактов -->
        <div class="usync-table-wrap">
            <table class="usync-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Email</th>
                        <th>В UniSender</th>
                        <th>В выбранном списке</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody id="syncTableBody">
                    <?php if (empty($recipients) && $selectedListId !== ''): ?>
                    <tr><td colspan="5" class="usync-empty">Контактов с email не найдено по фильтру отчёта</td></tr>
                    <?php else: ?>
                    <?php foreach ($recipients as $i => $r): ?>
                    <?php $em = htmlspecialchars((string)($r['email'] ?? '')); ?>
                    <tr data-email="<?= $em ?>">
                        <td style="color:#99a8bc;font-size:11px"><?= $i + 1 ?></td>
                        <td><?= $em ?></td>
                        <td data-col="US"><span class="usync-badge warn">не проверено</span></td>
                        <td data-col="LIST"><span class="usync-badge warn">не проверено</span></td>
                        <td data-col="STATUS" style="color:#66758c;font-size:12px"></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function(){
    var templateId    = <?= json_encode($templateId) ?>;
    var selectedListId = <?= json_encode($selectedListId) ?>;
    var sessid        = <?= json_encode(bitrix_sessid()) ?>;
    var totalRows     = <?= $totalContacts ?>;

    // ── Выбор списка — перезагружаем страницу ────────────────────────────────
    document.querySelectorAll('.usync-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var url = new URL(window.location.href);
            url.searchParams.set('list_id', el.getAttribute('data-list-id'));
            window.location.href = url.toString();
        });
    });

    // ── Состояние процесса ────────────────────────────────────────────────────
    var state = {
        running: false,
        paused: false,
        stopped: false,
        action: '',
        label: '',
        offset: 0,
        limit: 10,
        done: 0,
        errors: 0,
        foundCount: 0,
        inListCount: 0
    };

    // ── DOM refs ──────────────────────────────────────────────────────────────
    var progressWrap  = document.getElementById('progressWrap');
    var progressBar   = document.getElementById('progressBar');
    var progressText  = document.getElementById('progressText');
    var actionButtons = document.getElementById('actionButtons');
    var controlButtons = document.getElementById('controlButtons');
    var resultNote    = document.getElementById('resultNote');
    var btnPause      = document.getElementById('btnPause');
    var btnStop       = document.getElementById('btnStop');

    function stat(id){ return document.getElementById(id); }

    function badge(text, cls){
        return '<span class="usync-badge' + (cls ? ' '+cls : '') + '">' +
            String(text).replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</span>';
    }

    function rowByEmail(email){
        return document.querySelector('#syncTableBody [data-email="' +
            String(email).replace(/["\\]/g,'\\$&') + '"]');
    }

    // ── Обновить статистику в реальном времени ────────────────────────────────
    function updateStats(){
        stat('statDone').textContent      = String(state.done);
        stat('statErrors').textContent    = String(state.errors);
        stat('statFound').textContent     = String(state.foundCount);
        stat('statInList').textContent    = String(state.inListCount);
        stat('statNotInList').textContent = String(state.done - state.inListCount);
    }

    function updateProgress(){
        var pct = totalRows > 0 ? Math.round(state.offset / totalRows * 100) : 0;
        progressBar.style.width  = pct + '%';
        progressText.textContent = state.label + ': ' + Math.min(state.offset, totalRows) +
            ' из ' + totalRows + ' (' + pct + '%)' +
            (state.paused ? ' — ПАУЗА' : '');
    }

    // ── Применить результат по одному контакту ────────────────────────────────
    function applyItem(item){
        var row = rowByEmail(item.email);
        if (!row) return;
        row.classList.remove('usync-row-work');
        row.classList.add(item.error ? 'usync-row-err' : 'usync-row-done');
        var usCell     = row.querySelector('[data-col="US"]');
        var listCell   = row.querySelector('[data-col="LIST"]');
        var statusCell = row.querySelector('[data-col="STATUS"]');
        if (usCell)     usCell.innerHTML   = item.exists          ? badge('да','ok') : badge('нет','no');
        if (listCell)   listCell.innerHTML = item.in_selected_list ? badge('да','ok') : badge('нет','no');
        if (statusCell) statusCell.textContent = item.status || item.error || '';
    }

    // ── AJAX один батч ────────────────────────────────────────────────────────
    function ajaxBatch(){
        var fd = new FormData();
        fd.append('sessid',    sessid);
        fd.append('action',    state.action);
        fd.append('offset',    String(state.offset));
        fd.append('limit',     String(state.limit));
        fd.append('report_id', templateId);
        fd.append('list_id',   selectedListId);
        return fetch('/local/otchet/unisender_sync_ajax.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        }).then(function(r){ return r.json(); }).then(function(json){
            if (!json.ok) throw new Error(json.error || 'Ошибка сервера');
            return json.result || {};
        });
    }

    // ── Запустить/возобновить обработку ──────────────────────────────────────
    function runNext(){
        if (state.stopped || state.paused || !state.running) return;
        if (state.offset >= totalRows){
            finishProcess('завершено');
            return;
        }

        // Подсветить текущий батч
        var rows = document.querySelectorAll('#syncTableBody tr[data-email]');
        Array.prototype.slice.call(rows, state.offset, state.offset + state.limit).forEach(function(r){
            r.classList.add('usync-row-work');
            var s = r.querySelector('[data-col="STATUS"]');
            if (s) s.textContent = 'В работе...';
        });
        updateProgress();

        ajaxBatch().then(function(result){
            if (state.stopped) return;
            (result.items || []).forEach(function(item){
                applyItem(item);
                if (item.exists)          state.foundCount++;
                if (item.in_selected_list) state.inListCount++;
                if (item.error)           state.errors++;
                state.done++;
            });
            state.offset += state.limit;
            updateStats();
            updateProgress();
            // Scroll последней обработанной строки в видимость
            var tbody = document.getElementById('syncTableBody');
            var lastRow = tbody && tbody.querySelectorAll('tr[data-email]')[state.offset - 1];
            if (lastRow) lastRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

            runNext();
        }).catch(function(e){
            if (state.stopped) return;
            // Помечаем батч как ошибку и продолжаем
            Array.prototype.slice.call(rows, state.offset, state.offset + state.limit).forEach(function(r){
                r.classList.remove('usync-row-work');
                r.classList.add('usync-row-err');
                var s = r.querySelector('[data-col="STATUS"]');
                if (s) s.textContent = 'Ошибка: ' + e.message;
                state.errors++;
                state.done++;
            });
            state.offset += state.limit;
            updateStats();
            runNext();
        });
    }

    function finishProcess(reason){
        state.running = false;
        actionButtons.style.display  = '';
        controlButtons.style.display = 'none';
        document.querySelectorAll('#actionButtons .usync-btn').forEach(function(b){
            b.classList.remove('running');
            b.innerHTML = b.getAttribute('data-orig-label');
        });
        updateProgress();
        progressText.textContent += ' — ' + reason + '.';
        resultNote.style.display = '';
        resultNote.className = 'usync-note';
        resultNote.textContent = state.label + ': ' + reason + '. Обработано ' +
            state.done + ', найдено в UniSender ' + state.foundCount +
            ', в списке ' + state.inListCount + ', ошибок ' + state.errors + '.';
    }

    // ── Старт процесса ────────────────────────────────────────────────────────
    function startProcess(action, label, triggerBtn){
        if (state.running) return;
        state.running    = true;
        state.paused     = false;
        state.stopped    = false;
        state.action     = action;
        state.label      = label;
        state.offset     = 0;
        state.done       = 0;
        state.errors     = 0;
        state.foundCount = 0;
        state.inListCount = 0;

        resultNote.style.display = 'none';
        progressWrap.style.display = '';
        controlButtons.style.display = '';
        actionButtons.style.display = '';

        // Заблокировать все кнопки действий, на нажатой — спиннер
        document.querySelectorAll('#actionButtons .usync-btn').forEach(function(b){
            if (!b.getAttribute('data-orig-label')) b.setAttribute('data-orig-label', b.innerHTML);
            if (b === triggerBtn) {
                b.innerHTML = '<span class="usync-spinner"></span> ' + label + '...';
            }
            b.classList.add('running');
        });

        updateStats();
        updateProgress();
        runNext();
    }

    // ── Пауза / Продолжить ────────────────────────────────────────────────────
    btnPause && btnPause.addEventListener('click', function(){
        if (!state.running) return;
        if (!state.paused){
            state.paused = true;
            btnPause.textContent = '▶ Продолжить';
            progressText.textContent += ' — ПАУЗА';
        } else {
            state.paused = false;
            btnPause.textContent = '⏸ Пауза';
            runNext();
        }
    });

    // ── Остановить ────────────────────────────────────────────────────────────
    btnStop && btnStop.addEventListener('click', function(){
        state.stopped = true;
        state.paused  = false;
        state.running = false;
        finishProcess('остановлено пользователем');
    });

    // ── Навесить клики на кнопки действий ────────────────────────────────────
    document.querySelectorAll('#actionButtons .usync-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            startProcess(btn.getAttribute('data-action'), btn.getAttribute('data-label'), btn);
        });
    });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>

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
    try {
        $block = HL\HighloadBlockTable::getList([
            'filter' => ['=NAME' => 'gnc_report_presets'],
            'limit'  => 1,
        ])->fetch();
        if ($block) {
            $dataClass = HL\HighloadBlockTable::compileEntity($block)->getDataClass();
            $row = $dataClass::getList([
                'filter' => ['=UF_TEMPLATE_ID' => $templateId],
                'limit'  => 1,
            ])->fetch();
            if ($row) {
                $config = is_string($row['UF_CONFIG'] ?? null) ? json_decode($row['UF_CONFIG'], true) : null;
                return [
                    'id'     => $templateId,
                    'name'   => (string)($row['UF_NAME'] ?? $templateId),
                    'config' => is_array($config) ? $config : [],
                ];
            }
        }
    } catch (\Throwable $e) {}

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
.usync-wrap{max-width:1200px;margin:0 auto;padding:20px 16px 60px}
.usync-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.usync-title{font-size:24px;font-weight:700;margin:0}
.usync-subtitle{color:#66758c;font-size:13px;margin:4px 0 0}
.usync-card{background:#fff;border:1px solid #d9e1ec;border-radius:6px;padding:20px;margin-bottom:16px}
.usync-card-title{font-weight:700;font-size:15px;margin:0 0 14px}
.usync-list-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.usync-list-item{border:2px solid #d9e1ec;border-radius:5px;padding:10px 12px;cursor:pointer;background:#fff;transition:border-color .15s}
.usync-list-item:hover{border-color:#2357d6}
.usync-list-item.active{border-color:#2357d6;background:#edf3ff}
.usync-list-name{font-weight:700;font-size:13px}
.usync-list-meta{color:#66758c;font-size:12px;margin-top:2px}
.usync-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0}
.usync-stat{border:1px solid #edf1f6;background:#fbfcfe;border-radius:5px;padding:10px}
.usync-stat-label{color:#66758c;font-size:11px;text-transform:uppercase}
.usync-stat-value{font-weight:700;font-size:20px;margin-top:4px}
.usync-actions{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}
.usync-btn{display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:0 16px;border:0;border-radius:5px;background:#2357d6;color:#fff;font-weight:700;cursor:pointer;font-size:14px;text-decoration:none}
.usync-btn:hover{color:#fff;text-decoration:none}
.usync-btn.secondary{background:#526070}
.usync-btn:disabled{opacity:.45;pointer-events:none}
.usync-spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.usync-progress{border-radius:5px;padding:10px 14px;margin:10px 0;background:#eef5ff;color:#163b73;font-size:13px;display:none}
.usync-alert{border-radius:5px;padding:10px 14px;margin:10px 0;border:1px solid #ffccc7;background:#fff1f0;color:#7a1b12;font-size:13px}
.usync-table-wrap{overflow-x:auto;margin-top:12px;max-height:600px;overflow-y:auto}
.usync-table{width:100%;border-collapse:collapse;min-width:600px;font-size:13px}
.usync-table th,.usync-table td{border-bottom:1px solid #edf1f6;padding:8px 10px;text-align:left;vertical-align:middle}
.usync-table th{position:sticky;top:0;z-index:2;background:#f4f6f9;color:#45556d;font-size:11px;text-transform:uppercase;font-weight:700}
.usync-badge{display:inline-flex;align-items:center;min-height:20px;padding:0 7px;border-radius:999px;background:#edf3ff;color:#1b4db3;font-size:12px;font-weight:700}
.usync-badge.warn{background:#fff4df;color:#8a4b00}
.usync-badge.no{background:#fff1f0;color:#b42318}
.usync-row-work td{background:#fffbe6}
.usync-row-done td{background:#f1fbf4}
.usync-empty{color:#66758c;font-style:italic;padding:20px 0;text-align:center}
@media(max-width:700px){.usync-stats{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="usync-wrap">
    <div class="usync-head">
        <div>
            <h1 class="usync-title">Синхронизация с UniSender</h1>
            <div class="usync-subtitle">Отчёт: <?= htmlspecialchars((string)($template['name'] ?? $templateId)) ?></div>
        </div>
        <a class="usync-btn secondary" href="/local/otchet/report.php?id=<?= urlencode($templateId) ?>">← Назад к отчёту</a>
    </div>

    <?php if ($initError !== ''): ?>
    <div class="usync-alert">Ошибка подключения к UniSender: <?= htmlspecialchars($initError) ?></div>
    <?php endif; ?>

    <?php if ($loadError !== ''): ?>
    <div class="usync-alert">Ошибка загрузки контактов: <?= htmlspecialchars($loadError) ?></div>
    <?php endif; ?>

    <!-- 1. Выбор списка -->
    <div class="usync-card">
        <div class="usync-card-title">1. Выберите список UniSender</div>
        <?php if (empty($uniLists)): ?>
        <div class="usync-alert">Списки UniSender не найдены. Проверьте API-ключ.</div>
        <?php else: ?>
        <div class="usync-list-grid">
            <?php foreach ($uniLists as $lst): ?>
            <?php $lid = (string)($lst['id'] ?? ''); ?>
            <div class="usync-list-item <?= $lid === $selectedListId ? 'active' : '' ?>"
                 data-list-id="<?= htmlspecialchars($lid) ?>">
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

        <div class="usync-stats">
            <div class="usync-stat"><div class="usync-stat-label">Контактов с email</div><div class="usync-stat-value"><?= $totalContacts ?></div></div>
            <div class="usync-stat"><div class="usync-stat-label">Найдено в UniSender</div><div class="usync-stat-value" id="statFound">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">В выбранном списке</div><div class="usync-stat-value" id="statInList">—</div></div>
            <div class="usync-stat"><div class="usync-stat-label">Не в выбранном списке</div><div class="usync-stat-value" id="statNotInList">—</div></div>
        </div>

        <div class="usync-actions">
            <button class="usync-btn" type="button" id="btnCheck">Проверить контакты в UniSender</button>
            <button class="usync-btn" type="button" id="btnSync">Синхронизировать контакты с UniSender</button>
            <button class="usync-btn" type="button" id="btnImport">Загрузить контакты в список</button>
        </div>

        <div class="usync-progress" id="syncProgress"></div>

        <div class="usync-table-wrap">
            <table class="usync-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>В UniSender</th>
                        <th>В выбранном списке</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody id="syncTableBody">
                    <?php if (empty($recipients) && $selectedListId !== ''): ?>
                    <tr><td colspan="4" class="usync-empty">Контактов с email не найдено по фильтру отчёта</td></tr>
                    <?php else: ?>
                    <?php foreach ($recipients as $r): ?>
                    <?php $em = htmlspecialchars((string)($r['email'] ?? '')); ?>
                    <tr data-email="<?= $em ?>">
                        <td><?= $em ?></td>
                        <td data-col="US"><span class="usync-badge warn">не проверено</span></td>
                        <td data-col="LIST"><span class="usync-badge warn">не проверено</span></td>
                        <td data-col="STATUS"></td>
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
    var templateId = <?= json_encode($templateId) ?>;
    var selectedListId = <?= json_encode($selectedListId) ?>;
    var sessid = <?= json_encode(bitrix_sessid()) ?>;

    // Выбор списка — перезагружаем страницу
    document.querySelectorAll('.usync-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var lid = el.getAttribute('data-list-id');
            var url = new URL(window.location.href);
            url.searchParams.set('list_id', lid);
            window.location.href = url.toString();
        });
    });

    function setProgress(text){
        var el = document.getElementById('syncProgress');
        if (!el) return;
        el.style.display = text ? '' : 'none';
        el.textContent = text;
    }

    function badge(text, cls){
        return '<span class="usync-badge' + (cls ? ' '+cls : '') + '">' +
            String(text).replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</span>';
    }

    function rowByEmail(email){
        return document.querySelector('#syncTableBody [data-email="' +
            String(email).replace(/["\\]/g,'\\$&') + '"]');
    }

    function applyItem(item){
        var row = rowByEmail(item.email);
        if (!row) return;
        row.classList.remove('usync-row-work');
        row.classList.add('usync-row-done');
        var usCell = row.querySelector('[data-col="US"]');
        var listCell = row.querySelector('[data-col="LIST"]');
        var statusCell = row.querySelector('[data-col="STATUS"]');
        if (usCell) usCell.innerHTML = item.exists ? badge('да') : badge('нет','no');
        if (listCell) listCell.innerHTML = item.in_selected_list ? badge('да') : badge('нет','no');
        if (statusCell) statusCell.textContent = item.status || '';
    }

    function ajaxBatch(action, offset, limit){
        var fd = new FormData();
        fd.append('sessid', sessid);
        fd.append('action', action);
        fd.append('offset', String(offset));
        fd.append('limit', String(limit));
        fd.append('report_id', templateId);
        fd.append('list_id', selectedListId);
        return fetch('/local/otchet/unisender_sync_ajax.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        }).then(function(r){ return r.json(); }).then(function(json){
            if (!json.ok) throw new Error(json.error || 'Ошибка сервера');
            return json.result || {};
        });
    }

    function runBatch(action, label){
        var rows = Array.prototype.slice.call(
            document.querySelectorAll('#syncTableBody tr[data-email]')
        );
        var total = rows.length;
        if (!total){ setProgress('Нет контактов.'); return; }
        var limit = 10, offset = 0, done = 0, errors = 0;
        var foundCount = 0, inListCount = 0;

        function next(){
            if (offset >= total){
                setProgress(label + ': завершено. Обработано ' + done + ', ошибок ' + errors + '.');
                var sf = document.getElementById('statFound');
                var sl = document.getElementById('statInList');
                var sn = document.getElementById('statNotInList');
                if (sf) sf.textContent = String(foundCount);
                if (sl) sl.textContent = String(inListCount);
                if (sn) sn.textContent = String(total - inListCount);
                return;
            }
            rows.slice(offset, offset+limit).forEach(function(r){
                r.classList.add('usync-row-work');
                var s = r.querySelector('[data-col="STATUS"]');
                if (s) s.textContent = 'В работе...';
            });
            setProgress(label + ': ' + Math.min(offset+limit, total) + ' из ' + total + '...');
            ajaxBatch(action, offset, limit).then(function(result){
                (result.items || []).forEach(function(item){
                    applyItem(item);
                    if (item.exists) foundCount++;
                    if (item.in_selected_list) inListCount++;
                    if (item.error) errors++;
                    done++;
                });
                offset += limit;
                next();
            }).catch(function(e){
                rows.slice(offset, offset+limit).forEach(function(r){
                    r.classList.remove('usync-row-work');
                    var s = r.querySelector('[data-col="STATUS"]');
                    if (s) s.textContent = 'Ошибка: ' + e.message;
                    errors++;
                });
                done += Math.min(limit, total-offset);
                offset += limit;
                next();
            });
        }
        next();
    }

    var btnCheck  = document.getElementById('btnCheck');
    var btnSync   = document.getElementById('btnSync');
    var btnImport = document.getElementById('btnImport');
    if (btnCheck)  btnCheck.addEventListener('click',  function(){ runBatch('ajax_check_unisender', 'Проверка UniSender'); });
    if (btnSync)   btnSync.addEventListener('click',   function(){ runBatch('ajax_sync_unisender', 'Синхронизация UniSender'); });
    if (btnImport) btnImport.addEventListener('click', function(){ runBatch('ajax_import_to_list', 'Загрузка в список'); });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>

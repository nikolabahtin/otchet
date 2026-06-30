<?php
/**
 * AJAX endpoint для синхронизации контактов отчёта с UniSender.
 * Вызывается из unisender_sync.php батчами.
 */

declare(strict_types=1);

define('PUBLIC_AJAX_MODE', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/../unisendermass/lib/UnifiedWizard.php';
require_once __DIR__ . '/lib/Filter/FieldMapper.php';

use Bitrix\Highloadblock as HL;

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Метод не поддерживается.');
    }

    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized()) {
        throw new RuntimeException('Требуется авторизация.');
    }
    if (!check_bitrix_sessid()) {
        throw new RuntimeException('Сессия истекла. Обновите страницу.');
    }
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        throw new RuntimeException('Модуль CRM не подключен.');
    }
    \Bitrix\Main\Loader::includeModule('highloadblock');

    $reportId     = (string)($_POST['report_id'] ?? '');
    $listId       = (string)($_POST['list_id'] ?? '');
    $action       = (string)($_POST['action'] ?? '');
    $offset       = (int)($_POST['offset'] ?? 0);
    $limit        = max(1, min(50, (int)($_POST['limit'] ?? 10)));

    if ($reportId === '') throw new RuntimeException('Не указан report_id.');
    if ($listId === '')   throw new RuntimeException('Не указан list_id.');

    // Загружаем шаблон отчёта
    $template = loadOtchetTemplateForAjax($reportId, (int)($USER->GetID()));
    if (!$template) throw new RuntimeException('Шаблон отчёта не найден.');

    $config = is_array($template['config'] ?? null) ? $template['config'] : [];
    if (empty($config['filterValues'])) {
        throw new RuntimeException('В шаблоне не сохранён фильтр.');
    }

    $client  = new UsmUniSenderClient(UsmConfig::apiKey());
    $service = new UsmContactSyncService($client, new UsmReportProvider());

    // Строим params в формате UsmContactSyncService
    $params = buildOtchetSyncParams($config, $listId);

    if ($action === 'ajax_check_unisender') {
        $result = $service->diagnostics(0, $params, $offset, $limit);
    } elseif ($action === 'ajax_sync_unisender') {
        $result = $service->syncContactBatch(0, $params, $offset, $limit);
    } elseif ($action === 'ajax_import_to_list') {
        $result = $service->importMissingToListBatch(0, $params, $offset, $limit);
    } else {
        throw new RuntimeException('Неизвестное действие: ' . $action);
    }

    $result['logs'] = $client->logs;
    echo \Bitrix\Main\Web\Json::encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    echo \Bitrix\Main\Web\Json::encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ── helpers ───────────────────────────────────────────────────────────────────

function loadOtchetTemplateForAjax(string $templateId, int $userId): ?array
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
                return ['id' => $templateId, 'name' => (string)($row['UF_NAME'] ?? ''), 'config' => is_array($config) ? $config : []];
            }
        }
    } catch (\Throwable $e) {}

    $storageDir = __DIR__ . '/storage/templates';
    foreach (glob($storageDir . '/u*_' . $templateId . '.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;
        return ['id' => $templateId, 'name' => (string)($data['name'] ?? ''), 'config' => is_array($data['config'] ?? null) ? $data['config'] : []];
    }
    return null;
}

function buildOtchetSyncParams(array $config, string $listId): array
{
    $nodes    = is_array($config['nodes'] ?? null) ? $config['nodes'] : [];
    $rootNode = null;
    foreach ($nodes as $n) {
        if (empty($n['parentId'])) { $rootNode = $n; break; }
    }
    $rootNode = $rootNode ?? ($nodes[0] ?? []);

    return [
        'audience' => [
            'reportId'      => '',         // не нужен — config передаётся напрямую
            'name'          => '',
            'rootEntity'    => (string)($rootNode['entityCode'] ?? 'CONTACT'),
            'fields'        => [],
            '_otchetConfig' => $config,    // UsmReportProvider::loadRecipients поддерживает этот ключ
        ],
        'template' => ['id' => '', 'variables' => []],
        'mapping'  => [],
        'list'     => ['id' => $listId],
    ];
}

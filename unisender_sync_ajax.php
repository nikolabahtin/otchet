<?php
/**
 * AJAX endpoint синхронизации с UniSender.
 * Работает ТОЛЬКО с UniSender — Bitrix не трогает.
 *
 * POST actions:
 *   export_start  — запустить async exportContacts, вернуть task_uuid
 *   export_status — опросить статус задачи, вернуть emails[] если готово
 *   sync_and_add  — importContacts: добавить emails в UniSender + в список одним запросом
 */

declare(strict_types=1);

define('PUBLIC_AJAX_MODE', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/../unisendermass/lib/UnifiedWizard.php';

header('Content-Type: application/json; charset=UTF-8');

function jsonOk(array $data): void
{
    echo \Bitrix\Main\Web\Json::encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function jsonErr(string $msg): void
{
    echo \Bitrix\Main\Web\Json::encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

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

    $action = (string)($_POST['action'] ?? '');
    $listId = (string)($_POST['list_id'] ?? '');
    $client = new UsmUniSenderClient(UsmConfig::apiKey());

    // ── 1. Запуск async-экспорта контактов ───────────────────────────────────
    if ($action === 'export_start') {
        // scope: 'all' = вся база UniSender, 'list' = только выбранный список
        $scope    = (string)($_POST['scope'] ?? 'all');
        $params   = ['field_names' => ['email']];
        if ($scope === 'list') {
            if ($listId === '') throw new RuntimeException('Не указан list_id для экспорта списка.');
            $params['list_id'] = $listId;
        }
        $resp = $client->request('async/exportContacts', $params);
        $uuid = (string)($resp['result']['task_uuid'] ?? '');
        if ($uuid === '') {
            $errMsg = (string)($resp['error'] ?? json_encode($resp));
            throw new RuntimeException('UniSender exportContacts не вернул task_uuid: ' . $errMsg);
        }
        jsonOk(['task_uuid' => $uuid]);

    // ── 2. Опрос статуса экспорта ─────────────────────────────────────────────
    } elseif ($action === 'export_status') {
        $uuid = (string)($_POST['task_uuid'] ?? '');
        if ($uuid === '') throw new RuntimeException('Не указан task_uuid.');

        $resp   = $client->request('async/getTaskResult', ['task_uuid' => $uuid]);
        $status = (string)($resp['result']['status'] ?? '');

        if ($status === 'completed') {
            // Скачиваем файл с результатами
            $fileUrl = (string)($resp['result']['file_to_download'] ?? '');
            if ($fileUrl === '') {
                throw new RuntimeException('Задача завершена, но файл не найден.');
            }
            $csv = @file_get_contents($fileUrl);
            if ($csv === false) {
                throw new RuntimeException('Не удалось скачать файл экспорта: ' . $fileUrl);
            }
            // Парсим CSV — первая строка заголовок, остальные данные
            $lines  = explode("\n", trim($csv));
            $header = str_getcsv(array_shift($lines) ?? '');
            $emailIdx = array_search('email', array_map('strtolower', $header));
            if ($emailIdx === false) {
                $emailIdx = 0; // fallback: первая колонка
            }
            $emails = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $row = str_getcsv($line);
                $e   = UsmTools::normalizeEmail((string)($row[$emailIdx] ?? ''));
                if ($e !== '') $emails[] = $e;
            }
            jsonOk(['status' => 'completed', 'emails' => $emails]);
        } elseif ($status === 'processing' || $status === 'queued' || $status === '') {
            jsonOk(['status' => 'processing']);
        } else {
            throw new RuntimeException('Задача завершилась со статусом: ' . $status . '. ' . json_encode($resp['result'] ?? []));
        }

    // ── 3. Синхронизировать + добавить в список (один вызов) ─────────────────
    } elseif ($action === 'sync_and_add') {
        if ($listId === '') throw new RuntimeException('Не указан list_id.');
        $rawEmails = array_map('strval', (array)($_POST['emails'] ?? []));
        $emails = array_values(array_unique(array_filter(array_map(
            [UsmTools::class, 'normalizeEmail'], $rawEmails
        ))));
        if (empty($emails)) throw new RuntimeException('Нет валидных email.');

        $resp = $client->request('importContacts', [
            'overwrite_tags'  => 0,
            'overwrite_lists' => 0,
            'field_names'     => ['email', 'email_list_ids'],
            'data'            => array_map(static function(string $e) use ($listId): array {
                return [$e, $listId];
            }, $emails),
        ]);
        if (isset($resp['error'])) {
            throw new RuntimeException('UniSender importContacts: ' . $resp['error']);
        }
        $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
        jsonOk([
            'inserted' => (int)($result['inserted'] ?? 0),
            'updated'  => (int)($result['updated']  ?? 0),
            'invalid'  => (int)($result['invalid']  ?? 0),
            'skipped'  => (int)($result['skipped']  ?? 0),
            'total'    => count($emails),
        ]);

    } else {
        throw new RuntimeException('Неизвестный action: ' . $action);
    }

} catch (\Throwable $e) {
    jsonErr($e->getMessage());
}

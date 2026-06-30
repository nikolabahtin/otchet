<?php
/**
 * AJAX endpoint синхронизации с UniSender.
 * Принимает массив emails (не offset/limit), работает ТОЛЬКО с UniSender — Bitrix не трогает.
 *
 * POST params:
 *   action   = check | sync | import
 *   emails[] = array of email strings
 *   list_id  = UniSender list ID
 *   sessid   = Bitrix session ID
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
    $emails = array_values(array_filter(array_map('strval', (array)($_POST['emails'] ?? [])), static function(string $e): bool {
        return UsmTools::normalizeEmail($e) !== '';
    }));

    if ($action === '') throw new RuntimeException('Не указан action.');
    if ($listId === '') throw new RuntimeException('Не указан list_id.');
    if (empty($emails)) throw new RuntimeException('Список emails пуст.');

    $client = new UsmUniSenderClient(UsmConfig::apiKey());

    $items = [];

    // Нормализуем emails
    $emails = array_values(array_unique(array_filter(array_map(
        static function(string $e): string { return UsmTools::normalizeEmail($e); },
        $emails
    ))));
    if (empty($emails)) throw new RuntimeException('Нет валидных email после нормализации.');

    $fieldNames = ['email'];
    $data       = array_map(static function(string $e): array { return [$e]; }, $emails);

    if ($action === 'sync') {
        // Добавить/обновить контакты в базе UniSender (без привязки к списку)
        try {
            $resp    = $client->importContacts($fieldNames, $data);
            $result  = is_array($resp['result'] ?? null) ? $resp['result'] : [];
            $status  = 'Синхронизировано';
            if (!empty($result)) {
                $status .= ' (вставлено: ' . (int)($result['inserted'] ?? 0) .
                           ', обновлено: ' . (int)($result['updated'] ?? 0) .
                           ', невалидных: ' . (int)($result['invalid'] ?? 0) . ')';
            }
            foreach ($emails as $email) {
                $items[] = ['email' => $email, 'exists' => true, 'in_list' => null, 'status' => $status];
            }
        } catch (\Throwable $e) {
            foreach ($emails as $email) {
                $items[] = ['email' => $email, 'exists' => false, 'in_list' => false, 'error' => $e->getMessage()];
            }
        }

    } elseif ($action === 'add_to_list') {
        // Добавить контакты в выбранный список UniSender
        // UniSender принимает список через поле 'Lists' в данных, не через 'list_ids'
        if ($listId === '') throw new RuntimeException('Не выбран список UniSender.');
        try {
            $resp   = $client->request('importContacts', [
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
            $result  = is_array($resp['result'] ?? null) ? $resp['result'] : [];
            $status  = 'Добавлено в список';
            if (!empty($result)) {
                $status .= ' (вставлено: ' . (int)($result['inserted'] ?? 0) .
                           ', обновлено: ' . (int)($result['updated'] ?? 0) .
                           ', невалидных: ' . (int)($result['invalid'] ?? 0) . ')';
            }
            foreach ($emails as $email) {
                $items[] = ['email' => $email, 'exists' => true, 'in_list' => true, 'status' => $status];
            }
        } catch (\Throwable $e) {
            foreach ($emails as $email) {
                $items[] = ['email' => $email, 'exists' => false, 'in_list' => false, 'error' => $e->getMessage()];
            }
        }

    } else {
        throw new RuntimeException('Неизвестный action: ' . $action);
    }

    echo \Bitrix\Main\Web\Json::encode([
        'ok'     => true,
        'result' => ['items' => $items],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    echo \Bitrix\Main\Web\Json::encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

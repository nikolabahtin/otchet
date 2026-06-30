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

    if ($action === 'check') {
        // Проверить каждый email: есть ли в UniSender, есть ли в списке
        foreach ($emails as $email) {
            $email = UsmTools::normalizeEmail($email);
            if ($email === '') continue;
            try {
                $resp   = $client->getContact($email);
                $exists = isset($resp['result']) && is_array($resp['result']) && !empty($resp['result']);
                $inList = false;
                if ($exists) {
                    $inList = (bool)$client->isContactInList($email, $listId);
                }
                $items[] = ['email' => $email, 'exists' => $exists, 'in_list' => $inList, 'status' => $exists ? 'Найден в UniSender' : 'Не найден в UniSender'];
            } catch (\Throwable $e) {
                $items[] = ['email' => $email, 'exists' => false, 'in_list' => false, 'error' => $e->getMessage()];
            }
        }

    } elseif ($action === 'sync') {
        // Подписать каждый email в список через subscribe
        foreach ($emails as $email) {
            $email = UsmTools::normalizeEmail($email);
            if ($email === '') continue;
            try {
                $resp   = $client->subscribe($email, $listId, []);
                $exists = true;
                $items[] = ['email' => $email, 'exists' => $exists, 'in_list' => true, 'status' => 'Синхронизировано'];
            } catch (\Throwable $e) {
                $items[] = ['email' => $email, 'exists' => false, 'in_list' => false, 'error' => $e->getMessage()];
            }
        }

    } elseif ($action === 'import') {
        // Пакетный импорт через importContacts (один запрос на весь батч)
        $fieldNames = ['email'];
        $data = array_map(static function(string $e): array { return [$e]; }, $emails);
        try {
            $resp = $client->importContacts($fieldNames, $data);
            // importContacts возвращает общую статистику, не per-email
            // Помечаем все email как успешные
            foreach ($emails as $email) {
                $email = UsmTools::normalizeEmail($email);
                if ($email === '') continue;
                $items[] = ['email' => $email, 'exists' => true, 'in_list' => true, 'status' => 'Импортировано'];
            }
        } catch (\Throwable $e) {
            foreach ($emails as $email) {
                $email = UsmTools::normalizeEmail($email);
                if ($email === '') continue;
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

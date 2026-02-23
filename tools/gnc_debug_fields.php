<?php
/**
 * /local/tools/gnc_debug_fields.php
 *
 * Диагностика полей:
 * - Смарт-процесс (по entityTypeId)
 * - Контакт (CRM Contact)
 *
 * Открывать в браузере:
 *   /local/tools/gnc_debug_fields.php?entityTypeId=1060
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Crm\Service\Container;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $USER;
if (!$USER || !$USER->IsAdmin())
{
    die('Access denied (admin only).');
}

if (!Loader::includeModule('crm'))
{
    die('CRM module is not installed.');
}

header('Content-Type: text/html; charset=UTF-8');

$entityTypeId = isset($_GET['entityTypeId']) ? (int)$_GET['entityTypeId'] : 0;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Простейший “маппинг” в тип фильтра (как в main.ui.filter),
 * чтобы сопоставить с логами configuredFieldTypes.
 *
 * Возвращает: string|number|date|list|dest_selector|entity_selector
 */
function guessFilterType(array $field): string
{
    // Битрикс CRM fieldsInfo обычно содержит TYPE / isMultiple / isRequired / items / settings / etc.
    $type = strtolower((string)($field['TYPE'] ?? $field['type'] ?? ''));
    $isMultiple = ($field['isMultiple'] ?? $field['IS_MULTIPLE'] ?? false) ? true : false;

    // user / employee
    if ($type === 'user' || $type === 'employee')
    {
        return 'dest_selector';
    }

    // date / datetime
    if ($type === 'date' || $type === 'datetime')
    {
        return 'date';
    }

    // numeric-ish
    if (in_array($type, ['integer', 'int', 'double', 'float', 'number'], true))
    {
        return 'number';
    }

    // enumerations / list
    // иногда TYPE=enumeration или есть ITEMS
    if ($type === 'enumeration' || $type === 'list' || isset($field['ITEMS']) || isset($field['items']))
    {
        return 'list';
    }

    // CRM entity link (contact/company/deal) — чаще всего отдельные типы
    // В разных версиях CRM это может выглядеть по-разному. Проверяем по "SETTINGS" и "CRM_ENTITY_TYPE".
    $settings = $field['SETTINGS'] ?? $field['settings'] ?? [];
    $crmEntityType = $settings['CRM_ENTITY_TYPE'] ?? $settings['crmEntityType'] ?? null;
    $isCrmLink = !empty($crmEntityType);

    if ($isCrmLink)
    {
        // красиво: entity_selector
        return 'entity_selector';
    }

    // по умолчанию строка
    return 'string';
}

function printTable(array $rows, array $columns)
{
    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
    echo '<thead><tr style="background:#f2f2f2;">';
    foreach ($columns as $c)
    {
        echo '<th style="text-align:left; vertical-align:top;">'.h($c).'</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r)
    {
        echo '<tr>';
        foreach ($columns as $key)
        {
            $val = $r[$key] ?? '';
            if (is_array($val))
            {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            echo '<td style="vertical-align:top; white-space:pre-wrap;">'.h($val).'</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo '<h2>GNC Debug Fields</h2>';
echo '<div style="margin:8px 0; padding:10px; background:#fffbe6; border:1px solid #ffe58f;">';
echo '<div><b>Использование:</b></div>';
echo '<div>1) Открой: <code>/local/tools/gnc_debug_fields.php?entityTypeId=1060</code> (подставь свой entityTypeId)</div>';
echo '<div>2) Если entityTypeId не указан — покажу список смарт-процессов, выберешь нужный.</div>';
echo '</div>';

/**
 * 1) Список типов смарт-процессов (для удобства)
 */
echo '<h3>1) Смарт-процессы (типы)</h3>';

$types = [];
try {
    $dynamicTypes = Container::getInstance()->getDynamicTypeDataClass()::getList([
        'select' => ['ID', 'TITLE', 'ENTITY_TYPE_ID'],
        'order' => ['ENTITY_TYPE_ID' => 'ASC'],
    ])->fetchAll();

    foreach ($dynamicTypes as $t)
    {
        $types[] = [
            'ENTITY_TYPE_ID' => (int)$t['ENTITY_TYPE_ID'],
            'TITLE' => (string)$t['TITLE'],
            'LINK' => '/local/tools/gnc_debug_fields.php?entityTypeId='.(int)$t['ENTITY_TYPE_ID'],
        ];
    }
} catch (\Throwable $e) {
    echo '<div style="color:#b00;">Ошибка получения типов смарт-процессов: '.h($e->getMessage()).'</div>';
}

if ($types)
{
    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;">';
    echo '<tr style="background:#f2f2f2;"><th>ENTITY_TYPE_ID</th><th>TITLE</th><th>LINK</th></tr>';
    foreach ($types as $t)
    {
        echo '<tr>';
        echo '<td>'.h($t['ENTITY_TYPE_ID']).'</td>';
        echo '<td>'.h($t['TITLE']).'</td>';
        echo '<td><a href="'.h($t['LINK']).'">'.h($t['LINK']).'</a></td>';
        echo '</tr>';
    }
    echo '</table>';
}
else
{
    echo '<div>Список типов не получен (возможно нет прав или нет смарт-процессов).</div>';
}

/**
 * 2) Поля смарт-процесса
 */
if ($entityTypeId > 0)
{
    echo '<h3>2) Поля смарт-процесса entityTypeId='.h($entityTypeId).'</h3>';

    $factory = Container::getInstance()->getFactory($entityTypeId);
    if (!$factory)
    {
        echo '<div style="color:#b00;">Factory не найден для entityTypeId='.h($entityTypeId).'</div>';
    }
    else
    {
        $fieldsInfo = $factory->getFieldsInfo(); // мета по полям

        $rows = [];
        foreach ($fieldsInfo as $code => $info)
        {
            // нормализуем
            $type = $info['TYPE'] ?? ($info['type'] ?? '');
            $isMultiple = $info['isMultiple'] ?? ($info['IS_MULTIPLE'] ?? false);
            $title = $info['TITLE'] ?? ($info['title'] ?? $code);

            // items для списков
            $items = $info['ITEMS'] ?? ($info['items'] ?? null);

            // settings, если есть
            $settings = $info['SETTINGS'] ?? ($info['settings'] ?? null);

            $rows[] = [
                'CODE' => $code,
                'TITLE' => $title,
                'TYPE' => $type,
                'isMultiple' => $isMultiple ? 'Y' : 'N',
                'isRequired' => (!empty($info['isRequired']) || !empty($info['IS_REQUIRED'])) ? 'Y' : 'N',
                'isReadOnly' => (!empty($info['isReadOnly']) || !empty($info['IS_READ_ONLY'])) ? 'Y' : 'N',
                'FILTER_TYPE_GUESS' => guessFilterType($info),
                'ITEMS_count' => is_array($items) ? count($items) : '',
                'SETTINGS' => $settings,
                'RAW' => $info,
            ];
        }

        printTable($rows, [
            'CODE','TITLE','TYPE','isMultiple','isRequired','isReadOnly','FILTER_TYPE_GUESS','ITEMS_count','SETTINGS'
        ]);

        echo '<details style="margin-top:12px;"><summary><b>Показать RAW (полный дамп полей)</b></summary>';
        echo '<pre style="white-space:pre-wrap; background:#111; color:#eee; padding:12px; border-radius:8px;">'
            .h(json_encode($fieldsInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            .'</pre>';
        echo '</details>';
    }
}

/**
 * 3) Поля контакта CRM
 */
echo '<h3>3) Поля контакта (CRM Contact)</h3>';

$contactFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Contact);
if (!$contactFactory)
{
    echo '<div style="color:#b00;">Contact factory не найден.</div>';
}
else
{
    $fieldsInfo = $contactFactory->getFieldsInfo();

    $rows = [];
    foreach ($fieldsInfo as $code => $info)
    {
        $type = $info['TYPE'] ?? ($info['type'] ?? '');
        $isMultiple = $info['isMultiple'] ?? ($info['IS_MULTIPLE'] ?? false);
        $title = $info['TITLE'] ?? ($info['title'] ?? $code);

        $items = $info['ITEMS'] ?? ($info['items'] ?? null);
        $settings = $info['SETTINGS'] ?? ($info['settings'] ?? null);

        $rows[] = [
            'CODE' => $code,
            'TITLE' => $title,
            'TYPE' => $type,
            'isMultiple' => $isMultiple ? 'Y' : 'N',
            'isRequired' => (!empty($info['isRequired']) || !empty($info['IS_REQUIRED'])) ? 'Y' : 'N',
            'FILTER_TYPE_GUESS' => guessFilterType($info),
            'ITEMS_count' => is_array($items) ? count($items) : '',
            'SETTINGS' => $settings,
            'RAW' => $info,
        ];
    }

    printTable($rows, [
        'CODE','TITLE','TYPE','isMultiple','isRequired','FILTER_TYPE_GUESS','ITEMS_count','SETTINGS'
    ]);

    echo '<details style="margin-top:12px;"><summary><b>Показать RAW (полный дамп полей контакта)</b></summary>';
    echo '<pre style="white-space:pre-wrap; background:#111; color:#eee; padding:12px; border-radius:8px;">'
        .h(json_encode($fieldsInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
        .'</pre>';
    echo '</details>';
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';
$APPLICATION->SetTitle('GNC Export - Настройка шаблона');
$assetVersion = '20260225-01';
?>
<div class="gnc-slider-page">
    <script>
        (function () {
            window.BX = window.BX || {};
            BX.Messenger = BX.Messenger || {};
            BX.Messenger.v2 = BX.Messenger.v2 || {};
            BX.Messenger.v2.Const = BX.Messenger.v2.Const || {};
            BX.Messenger.v2.Application = BX.Messenger.v2.Application || {};

            if (typeof BX.Messenger.v2.Application.Launch !== 'function') {
                BX.Messenger.v2.Application.Launch = function () {};
            }

            BX.Messenger.v2.Const.ErrorCode = BX.Messenger.v2.Const.ErrorCode || {};
            BX.Messenger.v2.Const.ActionByRole = BX.Messenger.v2.Const.ActionByRole || {};
            BX.Messenger.v2.Const.ChatType = BX.Messenger.v2.Const.ChatType || {};
            BX.Messenger.v2.Const.SoundType = BX.Messenger.v2.Const.SoundType || {};
            BX.Messenger.v2.Const.FileType = BX.Messenger.v2.Const.FileType || {};
            BX.Messenger.v2.Const.NavigationMenuItem = BX.Messenger.v2.Const.NavigationMenuItem || {};
            BX.Messenger.v2.Const.Settings = BX.Messenger.v2.Const.Settings || {};
            BX.Messenger.v2.Const.RecentType = BX.Messenger.v2.Const.RecentType || {};
            BX.Messenger.v2.Const.BuilderModel = BX.Messenger.v2.Const.BuilderModel || {};

            BX.desktop = BX.desktop || {};
            if (typeof BX.desktop.isDesktop !== 'function') {
                BX.desktop.isDesktop = function () { return false; };
            }

            BX.componentParameters = BX.componentParameters || {};
            if (typeof BX.componentParameters.GetParameter !== 'function') {
                BX.componentParameters.GetParameter = function () { return null; };
            }
        })();
    </script>
    <section class="gnc-config-card">
        <div class="gnc-slider-page-head">
            <h2 id="sliderFormTitle">Новый шаблон</h2>
            <div class="gnc-slider-page-actions">
                <a href="/local/otchet/index.php" class="ui-btn ui-btn-light-border">Отмена</a>
                <button type="button" class="ui-btn ui-btn-light-border" id="openReportBtn">Сформировать отчет</button>
                <button type="button" class="ui-btn ui-btn-primary" id="saveTemplateBtn">Сохранить</button>
            </div>
        </div>

        <div class="gnc-field-row">
            <label for="templateNameInput">Название шаблона</label>
            <input type="text" id="templateNameInput" placeholder="Введите название шаблона">
        </div>

        <div class="gnc-field-row">
            <label for="rootEntitySelect">Основная сущность</label>
            <select id="rootEntitySelect"></select>
        </div>

        <p>Отмечайте поля для колонок. Связанные сущности выбираются через значок связи в заголовке каждого блока.</p>
        <div id="entityTree" class="gnc-levels"></div>
    </section>
</div>

<link rel="stylesheet" href="/local/otchet/assets/style.css?v=<?=$assetVersion?>">
<script>
    window.GncOtchetSlider = {
        sessid: '<?=bitrix_sessid()?>',
        ajaxUrl: '/local/otchet/ajax.php',
        templateId: '<?=htmlspecialcharsbx((string)($_GET['id'] ?? ''))?>',
        listUrl: '/local/otchet/index.php'
    };
</script>
<script src="/local/otchet/assets/slider.js?v=<?=$assetVersion?>"></script>
<?php require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'; ?>

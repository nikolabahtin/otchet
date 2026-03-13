<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';
$APPLICATION->SetTitle('GNC Export - Шаблоны отчетов');
$assetVersion = '20260309-02';
?>
<div class="gnc-page">
    <header class="gnc-hero">
        <h1>GNC Export</h1>
        <p>Стартовая страница: управление шаблонами отчетов.</p>
    </header>

    
    <main>
        <section class="gnc-card gnc-presets">
            <div class="gnc-card-head">
                <h2>Шаблоны отчетов</h2>
                <p>На этой странице выводится список настроенных шаблонов.</p>
            </div>

            <div class="gnc-preset-list" id="presetList"></div>

            <div class="gnc-actions">
                <button type="button" class="ui-btn ui-btn-primary" id="createPresetBtn">Создать новый шаблон</button>
            </div>
        </section>
    </main>
</div>

<link rel="stylesheet" href="/local/otchet/assets/style.css?v=<?=$assetVersion?>">
<script>
    window.GncOtchet = {
        sessid: '<?=bitrix_sessid()?>',
        ajaxUrl: '/local/otchet/ajax.php',
        sliderUrl: '/local/otchet/slider.php',
        reportUrl: '/local/otchet/report.php'
    };
</script>
<script src="/local/otchet/assets/app.js?v=<?=$assetVersion?>"></script>
<?php require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'; ?>

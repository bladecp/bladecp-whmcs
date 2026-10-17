<?php

declare(strict_types=1);

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
?>
<link rel="stylesheet" href="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/fast-deploy.css?v=9">
<section class="bcp-deploy bcp-deploy-card" aria-labelledby="bcp-deploy-card-title">
    <div class="bcp-deploy-card-mark"><?= $icon('bolt') ?></div>
    <div class="bcp-deploy-card-copy">
        <div class="bcp-deploy-eyebrow">Fast Deploy</div>
        <h2 id="bcp-deploy-card-title"><?= $escape($copy['card_title']) ?></h2>
        <p><?= $escape($copy['card_description']) ?></p>
    </div>
    <a class="bcp-deploy-button bcp-deploy-primary" href="<?= $escape($basePath) ?>/index.php?m=bladecp&amp;action=fast-deploy"><?= $escape($copy['open']) ?> <?= $icon('arrow') ?></a>
</section>

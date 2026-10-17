<?php

declare(strict_types=1);

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
?>
<link rel="stylesheet" href="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/fast-deploy.css?v=9">
<link rel="stylesheet" href="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/fast-deploy-flow.css?v=3">
<section class="bcp-deploy bcp-deploy-page" aria-labelledby="bcp-deploy-title">
    <a class="bcp-deploy-back" href="<?= $escape($basePath) ?>/clientarea.php"><?= $escape($copy['back_home']) ?></a>
    <header class="bcp-deploy-header">
        <div class="bcp-deploy-eyebrow"><?= $icon('bolt') ?> Fast Deploy</div>
        <h1 id="bcp-deploy-title"><?= $escape($copy['title']) ?></h1>
        <p><?= $escape($copy['intro']) ?></p>
    </header>
    <ol class="bcp-deploy-steps" aria-label="Fast Deploy">
        <?php foreach (['destination', 'backup', 'analysis', 'review', 'migration', 'completion'] as $index => $step): ?>
            <li<?= $index === 0 ? ' aria-current="step" class="bcp-deploy-step-active"' : '' ?>><span><?= $index + 1 ?></span><?= $escape($copy[$step]) ?></li>
        <?php endforeach; ?>
    </ol>
    <div class="bcp-deploy-layout">
        <div class="bcp-deploy-panel">
            <h2><?= $escape($copy['configured_products']) ?></h2>
            <p class="bcp-deploy-muted"><?= $escape($copy[$catalog['state'] === 'unavailable' ? 'catalog_unavailable' : 'configured_products_help']) ?></p>
            <?php if (!empty($flow['error'])): ?><p role="alert"><?= $escape($flow['error']) ?></p><?php endif; ?>
            <?php if ($invalidSelection): ?><p role="alert"><?= $escape($copy['invalid_selection']) ?></p><?php endif; ?>
            <?php if ($products !== []): ?>
                <form method="post" action="<?= $escape($basePath) ?>/index.php?m=bladecp&amp;action=fast-deploy" data-deploy-picker>
                    <input type="hidden" name="token" value="<?= $escape($csrf) ?>">
                    <input type="hidden" name="request_id" value="<?= $escape($nonce ?? '') ?>">
                    <input type="hidden" name="operation" value="create">
                    <input type="hidden" name="m" value="bladecp">
                    <input type="hidden" name="action" value="fast-deploy">
                    <div class="bcp-deploy-plan-options" role="group" aria-label="<?= $escape($copy['plan']) ?>">
                        <?php foreach ($products as $product): ?>
                            <label class="bcp-deploy-plan-option">
                                <input type="radio" name="product_id" value="<?= $product['id'] ?>" required<?= $product['locations'] === [] ? ' disabled' : '' ?><?= ($activeProduct['id'] ?? null) === $product['id'] ? ' checked' : '' ?>>
                                <span><?= $escape($product['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($activeProduct !== null): ?>
                        <div class="bcp-deploy-plan-detail">
                            <div class="bcp-deploy-plan-heading">
                                <h3 data-deploy-name><?= $escape($activeProduct['name']) ?></h3>
                                <span class="bcp-deploy-policy-badge<?= $activeProduct['policy']['mode'] === 'trial' ? ' bcp-deploy-policy-trial' : '' ?>" data-deploy-policy><?= $escape($activeProduct['policyLabel']) ?></span>
                            </div>
                            <div class="bcp-deploy-specs" aria-live="polite">
                                <?php foreach (['cpu', 'ram', 'disk', 'price'] as $field): ?>
                                    <div class="bcp-deploy-spec<?= $field === 'price' ? ' bcp-deploy-spec-price' : '' ?>">
                                        <span class="bcp-deploy-spec-icon"><?= \WHMCS\Module\Addon\Bladecp\FastDeployPresentation::icon($field) ?></span>
                                        <div><span<?= $field === 'price' ? ' data-deploy-price-label' : '' ?>><?= $escape($field === 'price' ? $activeProduct['priceLabel'] : $copy[$field]) ?></span>
                                            <strong data-deploy-value="<?= $field ?>"><?= $escape($field === 'price' ? $activeProduct['price'] : $activeProduct['specs'][$field]) ?></strong></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="bcp-deploy-price-note"><span data-deploy-setup><?= $escape($activeProduct['setup']) ?></span> <?= $escape($copy['price_note']) ?></p>
                            <fieldset class="bcp-deploy-location-field">
                                <legend><?= $escape($copy['location']) ?></legend>
                                <div class="bcp-deploy-location-options" data-deploy-locations>
                                    <?php foreach ($activeProduct['locations'] as $locationIndex => $location): ?>
                                        <label class="bcp-deploy-location-option"><input type="radio" name="location_id" value="<?= $location['id'] ?>" required<?= ($selection['location']['id'] ?? array_key_first($activeProduct['locations'])) === $location['id'] ? ' checked' : '' ?>>
                                            <?= $icon('globe') ?><span><?= $escape($location['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                        </div>
                        <button type="submit" class="bcp-deploy-button bcp-deploy-primary"><?= $escape($copy['select_destination']) ?></button>
                    <?php else: ?><p class="bcp-deploy-muted"><?= $escape($copy['destination_unavailable']) ?></p><?php endif; ?>
                    <script type="application/json" data-deploy-products><?= json_encode(array_values($products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
                </form>
                <script src="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/fast-deploy.js?v=1" defer></script>
            <?php else: ?>
                <p class="bcp-deploy-muted"><?= $escape($copy['no_destinations_help']) ?></p>
            <?php endif; ?>
        </div>
        <aside class="bcp-deploy-sidebar" role="status" data-deploy-sidebar>
            <?php if ($selection !== null): ?>
                <h3><?= $escape($copy['selected_destination']) ?></h3>
                <dl><div><dt><?= $escape($copy['plan']) ?></dt><dd data-deploy-summary-plan><?= $escape($selection['product']['name']) ?></dd></div>
                    <div><dt><?= $escape($copy['location']) ?></dt><dd data-deploy-summary-location><?= $escape($selection['location']['name']) ?></dd></div></dl>
            <?php else: ?><h3><?= $escape($copy['selected_destination']) ?></h3><?php endif; ?>
            <p><?= $escape($copy['start_help']) ?></p>
        </aside>
    </div>
    <?= \WHMCS\Module\Addon\Bladecp\FastDeploy\View::history($flow['history'] ?? [], $basePath) ?>
</section>

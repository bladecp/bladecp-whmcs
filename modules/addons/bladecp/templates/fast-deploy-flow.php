<?php

declare(strict_types=1);

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
$migration = is_array($detail['migration'] ?? null) ? $detail['migration'] : [];
$actions = is_array($detail['actions'] ?? null) ? $detail['actions'] : [];
$commercial = is_array($detail['commercial'] ?? null) ? $detail['commercial'] : [];
$phase = is_string($migration['phase'] ?? null) ? $migration['phase'] : 'draft';
$requestUuid = (string) ($detail['requestUuid'] ?? '');
$steps = ['step_destination', 'step_backup', 'step_analysis', 'step_review', 'step_migration', 'step_completion'];
$phaseStep = ['draft'=>1,'uploading'=>1,'queued'=>2,'parsing'=>2,'discovery_review'=>3,'provisioning'=>4,'importing'=>4,'validating'=>4,'awaiting_approval'=>4,'syncing'=>5,'cutover_pending'=>6,'cutover_stalled'=>6,'live'=>6,'failed'=>0,'cancelled'=>0][$phase] ?? 0;
$domain = $migration['primaryDomain'] ?? $migration['requestedPrimaryDomain'] ?? $migration['sourcePrimaryDomain'] ?? '';
$commercialState = is_string($commercial['state'] ?? null) ? $commercial['state'] : '';
$commercialLabel = $copy['commercial_' . $commercialState] ?? $copy['unknown'];
?>
<link rel="stylesheet" href="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/fast-deploy-flow.css?v=3">
<section class="bcp-deploy-flow" data-fast-deploy-flow data-detail="<?= $escape($json) ?>" data-copy="<?= $escape($copyJson) ?>" data-token="<?= $escape($csrf) ?>" data-endpoint="<?= $escape($basePath) ?>/index.php?m=bladecp&amp;action=fast-deploy" aria-labelledby="bcp-flow-title">
  <header class="bcp-flow-header">
    <div class="bcp-flow-nav"><a href="<?= $escape($basePath) ?>/clientarea.php">← <?= $escape($copy['back_home']) ?></a><a href="<?= $escape($basePath) ?>/index.php?m=bladecp&amp;action=fast-deploy&amp;new=1"><?= $escape($copy['new_migration']) ?> →</a></div>
    <p class="bcp-flow-eyebrow">✦ <?= $escape($copy['eyebrow']) ?></p>
    <div class="bcp-flow-title-row"><h2 id="bcp-flow-title"><?= $escape($copy['title']) ?></h2><span class="bcp-flow-phase" data-flow-phase><?= $escape(ucwords(str_replace('_', ' ', $phase))) ?></span></div>
  </header>
  <ol class="bcp-flow-steps" aria-label="<?= $escape($copy['eyebrow']) ?>">
  <?php foreach ($steps as $index => $step): $number = $index + 1; ?>
    <li class="<?= $phaseStep === $number ? 'is-current' : ($phaseStep > $number ? 'is-done' : '') ?>"<?= $phaseStep === $number ? ' aria-current="step"' : '' ?>><span><?= $number ?></span><b><?= $escape($copy[$step]) ?></b></li>
  <?php endforeach; ?>
  </ol>
  <div class="bcp-flow-layout">
    <main class="bcp-flow-panel" data-flow-main>
      <div class="bcp-flow-status" aria-live="polite" data-flow-status></div>
      <section data-flow-upload <?= $actions['upload'] ? '' : ' hidden' ?> aria-labelledby="bcp-flow-upload-title">
        <h3 id="bcp-flow-upload-title"><?= $escape($copy['upload_title']) ?></h3><p><?= $escape($copy['upload_help']) ?></p>
        <label class="bcp-flow-drop" data-flow-drop for="bcp-flow-file"><span class="bcp-flow-upload-icon">↑</span><strong data-flow-file-label><?= $escape($copy['drop']) ?></strong><small><?= $escape($copy['file_limit']) ?></small></label>
        <input id="bcp-flow-file" data-flow-file type="file" hidden>
        <div class="bcp-flow-upload-meta" data-flow-upload-meta hidden><span data-flow-file-name></span><button type="button" data-flow-remove-file><?= $escape($copy['remove_file']) ?></button></div>
        <div class="bcp-flow-progress" data-flow-progress hidden><div><span data-flow-progress-label><?= $escape($copy['uploading']) ?></span><strong data-flow-progress-text>0%</strong></div><progress value="0" max="100" data-flow-progress-bar></progress><small data-flow-part></small></div>
        <div class="bcp-flow-actions"><button type="button" class="bcp-flow-primary" data-flow-start-upload disabled><?= $escape($copy['start_upload']) ?></button></div>
      </section>
      <section data-flow-confirm-upload <?= $actions['confirmUpload'] ? '' : ' hidden' ?>><h3><?= $escape($copy['uploading']) ?></h3><p><?= $escape($copy['upload_help']) ?></p><div class="bcp-flow-actions"><button type="button" class="bcp-flow-primary" data-flow-operation="confirm-upload"><?= $escape($copy['confirm_upload']) ?></button></div></section>
      <section data-flow-review <?= $actions['review'] ? '' : ' hidden' ?>><h3><?= $escape($copy['review_title']) ?></h3><p><?= $escape($copy['review_help']) ?></p><label class="bcp-flow-field"><?= $escape($copy['domain']) ?><input data-flow-domain value="<?= $escape($domain) ?>" autocomplete="url"></label><div class="bcp-flow-actions"><button type="button" class="bcp-flow-primary" data-flow-operation="discovery-review"><?= $escape($copy['complete']) ?></button></div></section>
      <section data-flow-progress-view><h3 data-flow-title><?= $escape($copy['waiting']) ?></h3><p data-flow-description></p><div class="bcp-flow-components" data-flow-components hidden><h4><?= $escape($copy['components']) ?></h4><ul></ul></div><div class="bcp-flow-discovery" data-flow-discovery hidden><h4><?= $escape($copy['analysis']) ?></h4><dl></dl></div><div class="bcp-flow-actions" data-flow-actions>
        <a data-flow-staging class="bcp-flow-button" hidden target="_blank" rel="noopener noreferrer"><?= $escape($copy['staging']) ?></a>
        <button type="button" class="bcp-flow-primary" data-flow-operation="approve"<?= $actions['approve'] ? '' : ' hidden' ?>><?= $escape($copy['approve']) ?></button>
        <button type="button" class="bcp-flow-button" data-flow-operation="retry"<?= $actions['retry'] ? '' : ' hidden' ?>><?= $escape($copy['retry']) ?></button>
        <button type="button" class="bcp-flow-button" data-flow-operation="reconcile" hidden><?= $escape($copy['reconcile']) ?></button>
        <button type="button" class="bcp-flow-danger" data-flow-operation="cancel"<?= $actions['cancel'] ? '' : ' hidden' ?>><?= $escape($copy['cancel']) ?></button>
      </div></section>
      <p class="bcp-flow-error" data-flow-error role="alert" hidden></p>
    </main>
    <aside class="bcp-flow-sidebar">
      <h3><?= $escape($copy['destination']) ?></h3><dl data-flow-summary><div><dt><?= $escape($copy['domain']) ?></dt><dd><?= $escape($domain ?: $copy['unknown']) ?></dd></div><div><dt><?= $escape($copy['payment']) ?></dt><dd><?= $escape($commercialLabel) ?></dd></div></dl>
      <div class="bcp-flow-commercial" data-flow-commercial></div>
      <button type="button" class="bcp-flow-refresh" data-flow-refresh><?= $escape($copy['refresh']) ?></button>
    </aside>
  </div>
  <?= \WHMCS\Module\Addon\Bladecp\FastDeploy\View::history($history, $basePath) ?>
</section>
<script src="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/fast-deploy-flow.js?v=3" defer></script>

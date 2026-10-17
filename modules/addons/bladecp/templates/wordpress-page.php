<?php
declare(strict_types=1);
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
?>
<link rel="stylesheet" href="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/wordpress.css?v=1">
<section class="bcp-wordpress" aria-labelledby="bcp-wordpress-title">
  <a href="<?= $escape($basePath) ?>/clientarea.php" class="bcp-wordpress-back"><?= $escape($copy['back']) ?></a>
  <header><p>BladeCP</p><h1 id="bcp-wordpress-title"><?= $escape($copy['title']) ?></h1><p><?= $escape($copy['intro']) ?></p></header>
  <?php if ($services === []): ?><p class="bcp-wordpress-notice" role="status"><?= $escape($copy['unavailable']) ?></p>
  <?php else: ?><form method="get" action="<?= $escape($basePath) ?>/index.php" class="bcp-wordpress-picker"><input type="hidden" name="m" value="bladecp"><input type="hidden" name="action" value="wordpress"><label><?= $escape($copy['service']) ?><select name="service" onchange="this.form.submit()"><?php foreach ($services as $entry): ?><option value="<?= $entry['id'] ?>"<?= ($service['id'] ?? null) === $entry['id'] ? ' selected' : '' ?>><?= $escape($entry['name']) ?></option><?php endforeach; ?></select></label><button type="submit"><?= $escape($copy['select']) ?></button></form>
    <?php if ($service !== null && ($state['available'] ?? false) === true): ?><div class="bcp-wordpress-ready" role="status"><strong><?= $escape($copy['ready']) ?></strong><span><?= $escape((string) ($state['primaryDomain'] ?? '')) ?></span></div><div class="bcp-wordpress-actions" data-wordpress-actions data-service="<?= $service['id'] ?>" data-endpoint="<?= $escape($basePath) ?>/index.php?m=bladecp&amp;action=wordpress" data-token="<?= $escape($csrf) ?>" data-text-sending="<?= $escape($copy['sending']) ?>" data-text-done="<?= $escape($copy['done']) ?>" data-text-failed="<?= $escape($copy['failed']) ?>" data-text-update="<?= $escape($copy['update_item']) ?>"><button type="button" data-wordpress-action="dashboard"><?= $escape($copy['refresh_dashboard']) ?></button><button type="button" data-wordpress-action="core"><?= $escape($copy['update_core']) ?></button></div><div class="bcp-wordpress-dashboard" data-wordpress-dashboard aria-live="polite"></div><script src="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/wordpress.js?v=3" defer></script>
    <?php else: ?><p class="bcp-wordpress-notice" role="status"><?= $escape($copy['unavailable']) ?></p><?php endif; ?>
  <?php endif; ?>
</section>

<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Rendered exclusively by VibePromptCard with an authenticated local context.
?>
<link rel="stylesheet" href="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/vibe-prompt.css?v=11">
<section class="bcp-vibe-prompt card panel panel-default" aria-labelledby="bcp-vibe-title"
    data-endpoint="<?= $escape($context['endpoint']) ?>"
    data-continue="<?= $escape($basePath) ?>/index.php?m=bladecp&amp;action=vibe-free"
    data-available="<?= $context['available'] ? '1' : '0' ?>"
    data-copy="<?= $escape(json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
    <div class="bcp-vibe-prompt__heading">
        <span class="bcp-vibe-prompt__badge"><?= $icon('sparkles') ?> BladeCP Vibe</span>
        <h2 id="bcp-vibe-title"><?= $escape($copy['title']) ?></h2>
    </div>
    <form class="bcp-vibe-prompt__form" onsubmit="return false">
        <label class="bcp-vibe-prompt__sr" for="bcp-vibe-text"><?= $escape($copy['prompt']) ?></label>
        <div class="bcp-vibe-prompt__input">
            <span class="bcp-vibe-prompt__spark"><?= $icon('sparkles') ?></span>
            <textarea class="form-control" id="bcp-vibe-text" rows="3" maxlength="5000" placeholder="<?= $escape($copy['placeholder']) ?>" aria-describedby="bcp-vibe-count bcp-vibe-note"></textarea>
        </div>
        <div class="bcp-vibe-prompt__attachment" hidden>
            <span data-file-name></span>
            <button type="button" data-remove-image><?= $escape($copy['remove']) ?></button>
        </div>
        <div class="bcp-vibe-prompt__toolbar">
            <div class="bcp-vibe-prompt__meta text-muted">
                <button type="button" class="btn btn-default bcp-vibe-prompt__tool" data-add-image><?= $icon('image') ?><span><?= $escape($copy['image']) ?></span></button>
                <span class="bcp-vibe-prompt__help">
                    <button type="button" class="btn btn-link bcp-vibe-prompt__info" aria-label="<?= $escape($copy['image_info']) ?>" aria-describedby="bcp-vibe-image-help"><?= $icon('info') ?></button>
                    <span class="card panel panel-default" role="tooltip" id="bcp-vibe-image-help"><?= $escape($copy['image_hint']) ?></span>
                </span>
                <input type="file" data-image accept="image/jpeg,image/png,image/webp,image/gif,image/avif" hidden>
                <span class="bcp-vibe-prompt__stats">
                    <small id="bcp-vibe-count"><span data-count>0</span> / 5.000<span class="bcp-vibe-prompt__sr"> <?= $escape($copy['characters']) ?></span></small>
                    <small class="bcp-vibe-prompt__enter" title="<?= $escape($copy['newline']) ?>"><?= $icon('enter') ?><?= $escape($copy['enter']) ?></small>
                </span>
            </div>
            <div class="bcp-vibe-prompt__options">
                <div class="bcp-vibe-prompt__mode" role="group" aria-label="<?= $escape($copy['mode']) ?>">
                    <button type="button" class="btn btn-default active" data-mode="build" aria-pressed="true"><?= $escape($copy['build']) ?></button>
                    <button type="button" class="btn btn-default" data-mode="plan" aria-pressed="false"><?= $escape($copy['plan']) ?></button>
                </div>
                <button type="button" class="btn btn-default bcp-vibe-prompt__tool" data-thinking aria-pressed="false" title="<?= $escape($copy['thinking_hint']) ?>"><?= $icon('brain') ?><span><?= $escape($copy['thinking']) ?></span></button>
                <button type="button" class="btn btn-default bcp-vibe-prompt__tool bcp-vibe-prompt__voice" data-voice aria-pressed="false" aria-label="<?= $escape($copy['voice']) ?>" title="<?= $escape($copy['voice_hint']) ?>"><?= $icon('mic') ?></button>
                <button type="submit" class="btn btn-primary bcp-vibe-prompt__submit" disabled><?= $icon('bolt') ?><span data-submit-label><?= $escape($copy['submit']) ?></span><?= $icon('arrow') ?></button>
            </div>
        </div>
    </form>
    <div class="bcp-vibe-prompt__suggestions" aria-label="<?= $escape($copy['suggestions']) ?>">
        <?php foreach (['globe', 'target', 'store', 'camera', 'article', 'dashboard'] as $index => $suggestionIcon): ?>
            <button type="button" class="btn btn-default" data-suggestion="<?= $escape($copy['suggestion_prompt' . ($index + 1)]) ?>"><?= $icon($suggestionIcon) ?><span><?= $escape($copy['suggestion' . ($index + 1)]) ?></span></button>
        <?php endforeach; ?>
    </div>
    <p class="bcp-vibe-prompt__note text-muted" id="bcp-vibe-note"><?= $escape($copy['notice']) ?></p>
    <p class="bcp-vibe-prompt__status" role="status" aria-live="polite" hidden></p>
    <noscript><p><?= $escape($copy['noscript']) ?></p></noscript>
</section>
<?php $launchEndpoints = \WHMCS\Module\Addon\Bladecp\VibePromptContext::additionalLaunchEndpoints(); ?>
<script src="<?= $escape($basePath) ?>/modules/addons/bladecp/assets/vibe-prompt.js?v=7"<?= $launchEndpoints === [] ? '' : ' data-launch-endpoints="' . $escape(json_encode($launchEndpoints, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"' ?> defer></script>

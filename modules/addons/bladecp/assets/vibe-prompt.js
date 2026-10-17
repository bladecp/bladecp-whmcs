(function (root) {
    'use strict';

    const endpoints = new Set([
        'https://bladecp.app/api/reseller/launch-intents',
        'https://bladecp.work/api/reseller/launch-intents'
    ]);
    const imageTypes = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif']);

    function validate(input) {
        const prompt = String(input.prompt || '').trim();
        if (!prompt) throw new Error('empty');
        if (prompt.length > 5000) throw new Error('too_long');
        if (!['build', 'plan'].includes(input.mode)) throw new Error('error');
        if (input.image && (!imageTypes.has(input.image.type) || input.image.size > 8 * 1024 * 1024 || input.image.size <= 0)) {
            throw new Error('bad_image');
        }
        return prompt;
    }

    function capability(env) {
        if (env.location.protocol !== 'https:') return 'secure';
        if (!env.crypto || !env.crypto.subtle || !env.TextEncoder || !env.FormData || !env.fetch || !env.AbortController) {
            return 'unsupported';
        }
        return '';
    }

    async function launch(input, endpoint, continuation, env) {
        const unsupported = capability(env);
        if (unsupported) throw new Error(unsupported);
        const prompt = validate(input);
        const next = new URL(continuation, env.location.href);
        if (!endpoints.has(endpoint) || next.origin !== env.location.origin
            || !next.pathname.endsWith('/index.php') || next.search !== '?m=bladecp&action=vibe-free'
            || next.hash || next.username || next.password) throw new Error('error');

        const form = new env.FormData();
        form.append('prompt', prompt);
        form.append('mode', input.mode);
        form.append('thinking', input.thinking ? 'true' : 'false');
        if (input.image) form.append('image', input.image);
        const controller = new env.AbortController();
        const timer = env.setTimeout(() => controller.abort(), 20000);
        let response;
        try {
            response = await env.fetch(endpoint, {
                method: 'POST', body: form, credentials: 'omit', mode: 'cors',
                cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', signal: controller.signal
            });
            if (!response.ok) throw new Error('error');
            const result = await response.json();
            if (!result || typeof result !== 'object' || Array.isArray(result)
                || Object.keys(result).length !== 1 || typeof result.launchToken !== 'string'
                || !/^[A-Za-z0-9_-]{43}$/.test(result.launchToken)) throw new Error('error');

            const digest = await env.crypto.subtle.digest('SHA-256', new env.TextEncoder().encode(result.launchToken));
            const hash = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('').slice(0, 24);
            const cookie = '__Host-bladecp-vibe-start-' + hash;
            env.document.cookie = cookie + '=1; Path=/; Max-Age=600; SameSite=Lax; Secure';
            if (!env.document.cookie.split(';').some(part => part.trim() === cookie + '=1')) throw new Error('cookies');
            next.searchParams.set('start', result.launchToken);
            return next.href;
        } finally {
            env.clearTimeout(timer);
        }
    }

    function pressed(button, active) {
        button.setAttribute('aria-pressed', String(active));
        button.classList.toggle('active', active);
    }

    function dictation(voice, text, copy, env, show, updateCount) {
        const Recognition = env.SpeechRecognition || env.webkitSpeechRecognition;
        const unavailable = env.location.protocol !== 'https:' ? 'voice_secure' : (!Recognition ? 'voice_unsupported' : '');
        let recognition = null;
        const finish = () => {
            recognition = null;
            text.readOnly = false;
            pressed(voice, false);
            voice.setAttribute('aria-label', copy.voice);
            voice.title = copy[unavailable] || copy.voice_hint;
        };
        const stop = () => {
            const previous = recognition;
            finish();
            if (previous) { try { previous.abort(); } catch (_) {} }
        };
        voice.disabled = !!unavailable;
        finish();
        voice.addEventListener('click', () => {
            if (voice.disabled) return;
            if (recognition) { stop(); show('voice_stopped'); return; }
            if (unavailable) { show(unavailable); return; }
            const base = text.value;
            try {
                const current = new Recognition();
                recognition = current;
                current.lang = copy.speech_locale;
                current.continuous = true;
                current.interimResults = true;
                current.onresult = event => {
                    if (recognition !== current) return;
                    let transcript = '';
                    for (let i = 0; i < event.results.length; i++) transcript += event.results[i][0].transcript;
                    text.value = (base + (base && transcript ? ' ' : '') + transcript).slice(0, 5000);
                    updateCount();
                };
                current.onerror = event => {
                    if (recognition !== current) return;
                    stop();
                    show(['not-allowed', 'service-not-allowed'].includes(event.error) ? 'voice_denied' : (event.error === 'no-speech' ? 'voice_empty' : 'voice_error'));
                };
                current.onend = () => {
                    if (recognition !== current) return;
                    finish();
                    show('voice_stopped');
                };
                text.readOnly = true;
                pressed(voice, true);
                voice.setAttribute('aria-label', copy.voice_stop);
                voice.title = copy.voice_stop;
                show('voice_listening');
                current.start();
            } catch (_) { stop(); show('voice_error'); }
        });
        if (env.addEventListener) env.addEventListener('pagehide', stop);
        return {stop, unavailable};
    }

    function mount(card, env) {
        if (card.dataset.mounted) return;
        card.dataset.mounted = '1';
        const copy = JSON.parse(card.dataset.copy);
        const form = card.querySelector('form');
        const text = card.querySelector('textarea');
        const file = card.querySelector('[data-image]');
        const button = card.querySelector('[type="submit"]');
        const attachment = card.querySelector('.bcp-vibe-prompt__attachment');
        const status = card.querySelector('[role="status"]');
        const buttonLabel = card.querySelector('[data-submit-label]');
        const voice = card.querySelector('[data-voice]');
        let busy = false, mode = 'build', thinking = false;
        const show = key => {
            status.textContent = copy[key] || copy.error;
            status.hidden = !key;
        };
        const unavailable = card.dataset.available !== '1' ? 'unavailable' : capability(env);
        const updateCount = () => { card.querySelector('[data-count]').textContent = String(text.value.length); };
        const speech = dictation(voice, text, copy, env, show, updateCount);
        text.addEventListener('input', updateCount);
        text.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
                event.preventDefault();
                if (!event.repeat && !busy) form.requestSubmit();
            }
        });
        const modes = card.querySelectorAll('[data-mode]');
        modes.forEach(control => control.addEventListener('click', () => {
            if (busy) return;
            mode = control.dataset.mode;
            modes.forEach(option => pressed(option, option.dataset.mode === mode));
        }));
        const thinkingButton = card.querySelector('[data-thinking]');
        thinkingButton.addEventListener('click', () => {
            if (busy) return;
            thinking = !thinking;
            pressed(thinkingButton, thinking);
        });
        card.querySelectorAll('[data-suggestion]').forEach(suggestion => {
            suggestion.addEventListener('click', () => {
                if (busy) return;
                speech.stop();
                text.value = suggestion.dataset.suggestion;
                updateCount();
                text.focus();
            });
        });
        card.querySelector('[data-add-image]').addEventListener('click', () => { if (!busy) file.click(); });
        file.addEventListener('change', () => {
            const image = file.files[0];
            if (image && (!imageTypes.has(image.type) || image.size > 8 * 1024 * 1024 || image.size <= 0)) {
                file.value = '';
                attachment.hidden = true;
                show('bad_image');
                return;
            }
            attachment.hidden = !image;
            card.querySelector('[data-file-name]').textContent = image ? image.name : '';
            show(unavailable === 'secure' ? '' : unavailable);
        });
        card.querySelector('[data-remove-image]').addEventListener('click', () => {
            if (busy) return;
            file.value = '';
            attachment.hidden = true;
            show(unavailable === 'secure' ? '' : unavailable);
        });
        button.disabled = !!unavailable;
        show(unavailable === 'secure' ? '' : unavailable);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            if (unavailable) { show(unavailable === 'secure' ? '' : unavailable); return; }
            speech.stop();
            const input = {
                prompt: text.value, image: file.files[0] || null,
                mode, thinking
            };
            try { validate(input); } catch (error) { show(error.message); text.focus(); return; }
            busy = true;
            card.setAttribute('aria-busy', 'true');
            const controls = Array.from(card.querySelectorAll('button, input, textarea, select'));
            controls.forEach(control => { control.disabled = true; });
            buttonLabel.textContent = copy.loading;
            show('loading');
            try {
                const destination = await launch(input, card.dataset.endpoint, card.dataset.continue, env);
                env.location.assign(destination);
            } catch (error) {
                // Draft stays only in the current page. Never log or persist it.
                show(['secure', 'unsupported', 'empty', 'too_long', 'bad_image', 'cookies'].includes(error.message) ? error.message : 'error');
                busy = false;
                card.setAttribute('aria-busy', 'false');
                controls.forEach(control => { control.disabled = false; });
                buttonLabel.textContent = copy.submit;
                voice.disabled = !!speech.unavailable;
            }
        });
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = {validate, capability, launch, dictation, mount};
    if (root.document) {
        const start = () => root.document.querySelectorAll('.bcp-vibe-prompt').forEach(card => {
            try { mount(card, root); } catch (_) { /* Form remains disabled without a working composer. */ }
        });
        if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', start);
        else start();
    }
})(typeof window !== 'undefined' ? window : globalThis);

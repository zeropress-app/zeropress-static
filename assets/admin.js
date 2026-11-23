/* global zeroPressPublisher */
(function initialize() {
	'use strict';
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
		return;
	}
	const root = document.querySelector('.zpsp[data-page]');
	if (!root || !window.zeroPressPublisher) return;
	const config = zeroPressPublisher;
	const publication = window.zeroPressPublication(config);
	const reviews = window.zeroPressReview?.(config, publication);
	const { request, message } = publication;
	const $ = (id) => document.getElementById('zpsp-' + id);
	const forms = [...root.querySelectorAll('form[data-section]')];
	const baselines = new Map();
	const dirty = new Set();
	const loadingIndicators = new Map();
	const repositoryViews = new Map();
	let exportView;
	let settings = config.settings;
	let processingBlocked = !!$('processing');
	let processingStatusError = '';
	let prepared = null;
	let session = null;
	let busy = false;
	let confirmation = null;
	let turnstileEditing = false;
	let menuRowId = 0;

	function stopLoading(id) {
		loadingIndicators.get(id)?.unmount();
		loadingIndicators.delete(id);
		$(id + '-notice').querySelector('.zpsp-loading')?.remove();
	}
	function notice(id, text, error = false, commitUrl = '', code = '') {
		stopLoading(id);
		repositoryViews.get(id + '-notice')?.unmount();
		repositoryViews.delete(id + '-notice');
		const element = $(id + '-notice');
		const kind = typeof error === 'string' ? error : error ? 'error' : 'info';
		element.textContent = '';
		if (text) {
			if (id === 'result') {
				const icon = document.createElement('span');
				icon.className = 'zpsp-status-icon dashicons dashicons-' + publication.statusIcon(kind);
				icon.setAttribute('aria-hidden', 'true');
				element.append(icon);
			}
			const label = document.createElement('span');
			label.className = 'zpsp-feedback-message';
			label.textContent = text;
			element.append(label);
		}
		element.className = 'zpsp-feedback' + (text ? ' notice notice-' + kind : '');
		const help = publication.helpUrl(code);
		if (commitUrl || help) {
			const link = document.createElement('a');
			link.href = commitUrl || help;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = message(commitUrl ? 'commit' : 'publishing_help');
			const icon = document.createElement('span');
			icon.className = 'dashicons dashicons-external';
			icon.setAttribute('aria-hidden', 'true');
			link.append(' ', icon);
			element.append(' ', link);
		}
		if (id === 'result') syncPublicationStatus();
	}
	function syncPublicationStatus() {
		if (!$('publication-status')) return;
		$('publication-status').hidden = !$('result-notice').textContent && $('state').hidden && !session;
	}
	function showRepository(id, target, options) {
		const { createElement: el, createRoot } = window.wp.element;
		const container = $(id);
		if (!repositoryViews.has(id)) repositoryViews.set(id, createRoot(container));
		repositoryViews.get(id).render(publication.repositoryView(el, target, options));
		container.hidden = false;
	}
	function startLoading(id, progress) {
		const component = { preparing: 'Spinner', downloading: 'Spinner', session_refreshing: 'Spinner', session_cancelling: 'Spinner', publishing: 'ProgressBar' }[progress];
		const Component = window.wp?.components?.[component];
		const { createElement: el, createRoot } = window.wp?.element || {};
		if (!Component || !el || !createRoot) return;
		const isProgressBar = component === 'ProgressBar';
		const indicator = document.createElement(isProgressBar ? 'div' : 'span');
		indicator.className = 'zpsp-loading ' + (isProgressBar ? 'zpsp-loading-progress' : 'zpsp-loading-spinner');
		const element = $(id + '-notice');
		if (isProgressBar) element.append(indicator);
		else element.prepend(indicator);
		const view = createRoot(indicator);
		loadingIndicators.set(id, view);
		view.render(el(Component, isProgressBar ? { className: 'zpsp-progress-bar', 'aria-label': message(progress) } : { 'aria-hidden': true }));
	}
	function values(form) {
		if (form.dataset.section === 'menus') {
			return { menu_mapping: Object.fromEntries([...$('menu-rows').children]
				.filter((row) => row.querySelector('select').value)
				.map((row) => [row.querySelector('input').value.trim(), row.querySelector('select').value])
				.sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0)) };
		}
		if (form.dataset.section === 'turnstile') {
			const enabled = $('comment_verification').checked;
			return {
				comment_verification: enabled ? 'turnstile' : 'pow',
				...(turnstileEditing || (enabled && !hasTurnstileKeys()) ? {
					turnstile_keys: { site_key: $('turnstile_site_key').value.trim(), secret_key: $('turnstile_secret').value.trim() },
				} : {}),
			};
		}
		const result = {};
		for (const input of form.elements) {
			if (!input.name) continue;
			if (input.name === 'comment_verification') result[input.name] = input.checked ? 'turnstile' : 'pow';
			else result[input.name] = input.type === 'checkbox' ? input.checked : input.value.trim();
		}
		if (form.dataset.section === 'media') {
			result.media_rules = [...$('rules').children].map((row) => [...row.querySelectorAll('input')].map((input) => input.value.trim()));
		}
		return result;
	}
	function clearFieldErrors(form) {
		form?.querySelectorAll('.zpsp-field-error').forEach((element) => element.remove());
		form?.querySelectorAll('[aria-invalid]').forEach((input) => {
			input.removeAttribute('aria-invalid');
			const help = document.getElementById(input.id + '-help');
			if (help) input.setAttribute('aria-describedby', help.id);
			else input.removeAttribute('aria-describedby');
		});
	}
	function changed(form) {
		const section = form.dataset.section;
		if (JSON.stringify(values(form)) === baselines.get(section)) dirty.delete(section);
		else dirty.add(section);
		$(section + '-dirty').hidden = !dirty.has(section);
		notice(section, '');
		clearFieldErrors(form);
		if (section === 'menus') validateMenus();
		if (section === 'comments') commentControls();
		if (section === 'newsletter' || section === 'discovery') featureControls();
		if (section === 'turnstile') turnstileControls();
		if (section === 'github') {
			tokenLink();
			if ($('test-notice').textContent) notice('test', message('test_changed'));
		}
	}
	function addRule(from = '', to = '') {
		const row = document.createElement('div');
		row.className = 'zpsp-rule';
		[from, to].forEach((value, index) => {
			const label = document.createElement('label');
			label.append(document.createTextNode(message(index ? 'to' : 'from')));
			const input = document.createElement('input');
			input.type = 'url';
			input.value = value;
			input.required = true;
			const example = $(index ? 'media-to-example' : 'media-from-example');
			input.placeholder = example.querySelector('code').textContent;
			input.setAttribute('aria-describedby', example.id);
			label.append(input);
			row.append(label);
		});
		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'button zpsp-icon-button';
		const icon = document.createElement('span');
		icon.className = 'dashicons dashicons-trash';
		icon.setAttribute('aria-hidden', 'true');
		const label = document.createElement('span');
		label.className = 'zpsp-button-label';
		label.textContent = message('remove');
		remove.append(icon, label);
		remove.addEventListener('click', () => {
			row.remove();
			changed($('media'));
			$('add-rule').focus();
		});
		row.append(remove);
		$('rules').append(row);
		return row;
	}
	function validateMenus() {
		const inputs = [...$('menu-rows').querySelectorAll('input')];
		for (const input of inputs) {
			const id = input.value.trim();
			const valid = /^[a-z][a-z0-9_-]{0,63}$/.test(id) && !['constructor', 'prototype'].includes(id)
				&& inputs.filter((other) => other.value.trim() === id).length === 1;
			input.setCustomValidity(valid ? '' : message('INVALID_MENU_MAPPING'));
		}
	}
	function addMenu(id = '', source = '', fixed = false) {
		const row = document.createElement('div');
		row.className = 'zpsp-menu-row';
		const idLabel = document.createElement('label');
		idLabel.append(document.createTextNode(message('menu_id')));
		const input = document.createElement('input');
		input.type = 'text'; input.value = id; input.readOnly = fixed;
		input.required = true; input.maxLength = 64;
		input.setAttribute('aria-describedby', 'zpsp-menu-help');
		idLabel.append(input); row.append(idLabel);
		const sourceField = document.createElement('div');
		const sourceLabel = document.createElement('label');
		sourceLabel.append(document.createTextNode(message('menu_source')));
		const select = document.createElement('select');
		select.id = 'zpsp-menu-source-' + (++menuRowId);
		sourceLabel.htmlFor = select.id;
		select.add(new Option(message('menu_none'), ''));
		for (const menu of config.menus) select.add(new Option(menu.name, menu.id));
		if (source && !config.menus.some((menu) => menu.id === source)) select.add(new Option(message('menu_missing', { id: source }), source));
		select.value = source;
		sourceField.append(sourceLabel, select); row.append(sourceField);
		const menuActions = document.createElement('div');
		menuActions.className = 'zpsp-menu-actions';
		if (reviews) {
			const preview = document.createElement('button');
			preview.type = 'button'; preview.className = 'button-link zpsp-menu-preview';
			preview.setAttribute('aria-haspopup', 'dialog');
			preview.textContent = message('menu_preview');
			const updatePreview = () => {
				const selected = config.menus.some((menu) => menu.id === select.value);
				preview.hidden = !selected;
				preview.disabled = !selected;
			};
			updatePreview();
			select.addEventListener('change', updatePreview);
			preview.addEventListener('click', () => {
				if (!busy && !confirmation && select.value) reviews.open({ kind: 'menu_preview', menu_id: select.value }, preview);
			});
			menuActions.append(preview);
		}
		if (!fixed) {
			const remove = document.createElement('button');
			remove.type = 'button'; remove.className = 'button zpsp-icon-button';
			const icon = document.createElement('span');
			icon.className = 'dashicons dashicons-trash'; icon.setAttribute('aria-hidden', 'true');
			remove.append(icon, document.createTextNode(message('menu_remove')));
			remove.addEventListener('click', () => { row.remove(); changed($('menus')); $('add-menu').focus(); });
			menuActions.append(remove);
		}
		row.append(menuActions);
		$('menu-rows').append(row);
		return row;
	}
	function fill(form) {
		for (const input of form.elements) {
			if (!input.name) continue;
			const value = settings[input.name];
			if (input.name === 'comment_verification') input.checked = value === 'turnstile';
			else if (input.type === 'checkbox') input.checked = !!value;
			else input.value = input.name === 'publication_backend' && config.runtime?.fixed ? config.runtime.backend : value || '';
		}
		const section = form.dataset.section;
		if (section === 'media') {
			$('rules').replaceChildren();
			settings.media_rules.forEach(([from, to]) => addRule(from, to));
		}
		if (section === 'menus') {
			$('menu-rows').replaceChildren();
			const mapping = settings.menu_mapping;
			for (const id of ['primary', 'footer']) addMenu(id, mapping[id] || '', true);
			for (const [id, source] of Object.entries(mapping)) if (!['primary', 'footer'].includes(id)) addMenu(id, source);
			validateMenus();
		}
		if (section === 'github') { tokenLink(); repositoryLink(); }
		processingControls();
		if (section === 'comments') commentControls();
		if (section === 'newsletter' || section === 'discovery') featureControls();
		if (section === 'turnstile') { turnstileEditing = false; turnstileControls(); }
		baselines.set(section, JSON.stringify(values(form)));
		dirty.delete(section);
		$(section + '-dirty').hidden = true;
	}
	function processingControls() {
		const runtime = config.runtime || { backend: config.memory_publication ? 'memory' : 'files', fixed: '' };
		const fixed = $('processing-fixed');
		if (fixed) {
			fixed.hidden = !runtime.fixed;
			fixed.textContent = runtime.fixed ? message('processing_' + runtime.fixed) : '';
			$('publication_backend').disabled = !!runtime.fixed || busy || processingBlocked;
			$('save-processing').disabled = !!runtime.fixed || busy || processingBlocked;
			if (runtime.fixed) $('publication_backend').value = runtime.backend;
		}
		if ($('processing-job')) {
			$('processing-job').hidden = !processingBlocked;
			$('processing-job').querySelector('span').textContent = processingStatusError || message('processing_busy');
		}
		const format = $('wxr_publish_format');
		if (format) {
			format.querySelector('option[value="bundle"]').disabled = runtime.backend === 'memory';
			const selected = format.value || 'auto';
			let help = selected + '_format_help';
			if (runtime.backend === 'memory') {
				if (selected === 'auto') help = 'memory_format_help';
				else if (selected === 'bundle') help = 'MEMORY_SINGLE_REQUIRED';
			}
			$('wxr_publish_format-help').textContent = message(help);
		}
	}
	function commentControls() {
		if (!$('comments')) return;
		const enabled = $('comments_enabled').checked;
		$('comments_api_url').disabled = !enabled;
		$('anonymous_comments').disabled = !enabled;
	}
	function featureControls() {
		if ($('newsletter')) {
			const disabled = !$('newsletter_enabled').checked || $('newsletter').getAttribute('aria-busy') === 'true';
			$('newsletter-fields').querySelectorAll('input, textarea').forEach((input) => { input.disabled = disabled; });
		}
		if ($('feed-url-help')) {
			$('feed-url-help').hidden = !$('feed_enabled').checked || !!settings.public_url;
			if ($('feed-url-help').hidden) $('feed_enabled').removeAttribute('aria-describedby');
			else $('feed_enabled').setAttribute('aria-describedby', 'zpsp-feed-url-help');
		}
	}
	function hasTurnstileKeys() {
		return settings.has_turnstile_secret && !!settings.turnstile_site_key;
	}
	function turnstileControls() {
		const form = $('turnstile-form');
		if (!form) return;
		const enabled = $('comment_verification').checked;
		const editing = !hasTurnstileKeys() || turnstileEditing;
		const visible = enabled || hasTurnstileKeys() || turnstileEditing;
		const locked = form.getAttribute('aria-busy') === 'true';
		$('turnstile-fields').hidden = !visible;
		$('turnstile-editor').hidden = !editing;
		$('turnstile-registered').hidden = editing;
		$('turnstile_site_key').readOnly = !editing;
		$('turnstile_site_key').disabled = !visible || locked;
		$('turnstile_site_key').required = visible && editing;
		$('turnstile_secret').disabled = !visible || !editing || locked;
		$('turnstile_secret').required = visible && editing;
		$('cancel-turnstile').hidden = !turnstileEditing;
	}
	function tokenLink() {
		const repository = $('repository').value.trim().replace(/^https:\/\/github\.com\//, '').replace(/\/+$/, '').replace(/\.git$/, '');
		const owner = repository.split('/')[0];
		const url = new URL($('token-link').href);
		url.searchParams.delete('target_name');
		if (/^[a-z0-9-]+$/i.test(owner)) url.searchParams.set('target_name', owner);
		$('token-link').href = url.href;
		$('token-repository').textContent = repository;
		$('token-repository-hint').hidden = !repository;
	}
	function repositoryLink() {
		const link = $('repository-link');
		const repository = settings.repository;
		const valid = /^[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_.-]{1,100}$/.test(repository) && !['.', '..'].includes(repository.split('/')[1]);
		link.hidden = !valid;
		if (valid) link.href = 'https://github.com/' + repository.split('/').map(encodeURIComponent).join('/');
		else link.removeAttribute('href');
	}
	function tokenControls() {
		const form = $('token-form');
		if (!form) return;
		$('token-unregistered').hidden = settings.has_token;
		$('token-registered').hidden = !settings.has_token;
		$('token').disabled = settings.has_token || form.getAttribute('aria-busy') === 'true';
		$('register-token').disabled = busy || settings.has_token || !$('token').value.trim();
		$('delete-token').disabled = busy || !settings.has_token;
		$('test').disabled = busy || !settings.has_token;
	}
	function tokenSaved(next, status) {
		settings = next;
		prepared = null;
		$('token').value = '';
		dirty.delete('token');
		$('token-dirty').hidden = true;
		notice('test', '');
		notice('token', message(status));
	}

	function focusError(error, id, form) {
		const fields = {
			NEWSLETTER_URL_REQUIRED: 'newsletter_signup_url',
			INVALID_NEWSLETTER_TITLE: 'newsletter_title',
			INVALID_NEWSLETTER_DESCRIPTION: 'newsletter_description',
			INVALID_NEWSLETTER_BUTTON_LABEL: 'newsletter_button_label',
			INVALID_NEWSLETTER_SIGNUP_URL: 'newsletter_signup_url',
			INVALID_NEWSLETTER_EMBED_URL: 'newsletter_embed_url',

			INVALID_SITE_URL: 'public_url', COMMENT_SITE_URL_REQUIRED: 'public_url',
			INVALID_TURNSTILE_SETTINGS: 'turnstile_site_key', TURNSTILE_SETTINGS_REQUIRED: 'turnstile_site_key', TURNSTILE_SITE_KEY_REQUIRED: 'turnstile_site_key',
			INVALID_COMMENTS_URL: 'comments_api_url', COMMENTS_URL_REQUIRED: 'comments_api_url',
			INVALID_REPOSITORY: 'repository', REPOSITORY_REQUIRED: 'repository', INVALID_BRANCH: 'branch', INVALID_TOKEN: 'token',
			TOKEN_REQUIRED: 'token', TOKEN_UNREADABLE: 'token', GITHUB_AUTH_FAILED: 'token',
			TURNSTILE_SECRET_REQUIRED: 'turnstile_secret', INVALID_TURNSTILE_SECRET: 'turnstile_secret',
		};
		const input = form?.elements.namedItem(fields[error.code] || '');
		const text = error instanceof Error ? error.message : message('error');
		if (input && !input.disabled) {
			notice(id, '');
			input.setAttribute('aria-invalid', 'true');
			const errorId = input.id + '-error';
			document.getElementById(errorId)?.remove();
			const detail = document.createElement('span');
			detail.id = errorId;
			detail.className = 'zpsp-field-error';
			detail.textContent = text;
			input.after(detail);
			const help = document.getElementById(input.id + '-help');
			input.setAttribute('aria-describedby', [help?.id, errorId].filter(Boolean).join(' '));
			input.focus();
		} else {
			notice(id, text, id === 'result' ? publication.resultKind({ state: 'failed', code: error.code }) : true, '', error.code);
			$(id + '-notice').focus();
		}
	}
	function publishControls() {
		if (!$('publish')) return;
		const publishable = ['ready', 'unchanged'].includes(prepared?.state);
		$('publish').disabled = busy || !publishable || !!session;
		$('publish').classList.toggle('button-primary', prepared?.state === 'ready' && !session);
		$('prepare').classList.toggle('button-primary', !session && prepared?.state !== 'ready');
		root.querySelectorAll('.zpsp-publication-recovery button').forEach((button) => { button.disabled = busy; });
		if (!busy && prepared?.state !== 'attempted') {
			const republish = prepared?.state === 'unchanged';
			const published = prepared?.state === 'published';
			const actions = $(republish ? 'republish-actions' : 'publish-actions');
			if ($('publish').parentElement !== actions) actions.append($('publish'));
			$('republish-options').hidden = !republish;
			$('publish').querySelector('.zpsp-button-label').textContent = message(published ? 'published_button' : republish ? 'republish_title' : 'confirm_title');
			$('publish').querySelector('.dashicons').className = 'dashicons ' + (republish ? 'dashicons-update' : 'dashicons-upload');
		}
		root.querySelectorAll('[data-download]').forEach((button) => { button.disabled = busy || !prepared; });
	}
	async function run(button, id, progress, work, form = null) {
		if (busy || confirmation) return;
		busy = true;
		clearFieldErrors(form);
		// Serialize requests using the shared revision, but leave other panels' inputs alone.
		const locked = new Map([...root.querySelectorAll('button'), ...(form ? form.querySelectorAll('input, select, textarea') : [])].map((input) => [input, input.disabled]));
		locked.forEach((_, input) => { input.disabled = true; });
		const labelElement = button.querySelector('.zpsp-button-label') || button;
		const label = labelElement.textContent;
		labelElement.textContent = message(progress);
		// Keep live stage announcements outside the busy element during publication.
		const scope = id === 'result' ? button : form || button.closest('section');
		scope.setAttribute('aria-busy', 'true');
		notice(id, message(progress));
		let failure;
		try {
			startLoading(id, progress);
			if (id === 'result') $(id + '-notice').focus();
			await work();
		} catch (error) {
			failure = error;
			if (['PREPARATION_EXPIRED', 'PREPARATION_INVALID', 'CONTENT_CHANGED', 'SETTINGS_CHANGED', 'TARGET_CHANGED'].includes(error.code)) prepared = null;
		} finally {
			stopLoading(id);
			locked.forEach((disabled, input) => { input.disabled = disabled; });
			labelElement.textContent = label;
			scope.removeAttribute('aria-busy');
			busy = false;
			processingControls();
			commentControls();
			featureControls();
			turnstileControls();
			tokenControls();
			publishControls();
			if (failure) focusError(failure, id, form);
		}
		return !failure;
	}
	forms.forEach((form) => {
		fill(form);
		form.addEventListener('input', () => changed(form));
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			const section = form.dataset.section;
			if (section === 'github') notice('test', '');
			const input = { ...values(form), revision: settings.revision };
			const saved = await run(event.submitter || [...form.elements].find((input) => input.type === 'submit'), section, 'saving', async () => {
				settings = await request('save', { section, settings: input });
				prepared = null;
				fill(form); // Never replace another panel's unsaved input, including tokens.
				if (section === 'media') {
					root.querySelectorAll('[data-publishing-icon]').forEach((element) => {
						element.textContent = settings.publishing_icons[element.dataset.publishingIcon] || '';
					});
				}
				const feedback = section === 'processing' ? 'saved_processing' : section === 'api' ? 'saved_api' : section === 'comments' ? (settings.restrict_public_rest_api ? 'saved_comments_api' : 'saved_comments') : section === 'turnstile' ? 'saved_runtime' : section === 'github' ? 'saved' : 'saved_publish';
				notice(section, message(feedback));
			}, form);
			if (saved && section === 'turnstile') $('save-turnstile').focus();
			if (saved) {
				const activeForm = document.activeElement.closest('form');
				if (!activeForm || activeForm === form) $(section + '-notice').scrollIntoView({ block: 'nearest' });
			}
		});
		form.querySelector('fieldset').disabled = false;
		processingControls();
	});
	$('wxr_publish_format')?.addEventListener('change', processingControls);
	async function refreshProcessingStatus() {
		if (!$('processing') || busy) return;
		try {
			const status = await publication.sessionStatus();
			processingBlocked = !['idle', 'published', 'unchanged', 'cancelled', 'failed'].includes(status.state);
			processingStatusError = '';
		} catch (error) {
			processingBlocked = true;
			processingStatusError = error.message;
		}
		processingControls();
	}
	if ($('processing')) {
		refreshProcessingStatus();
		window.addEventListener('focus', refreshProcessingStatus);
	}
	const settingsNav = root.querySelector('.zpsp-settings-nav');
	if (settingsNav) {
		const links = [...settingsNav.querySelectorAll('a')];
		const sections = new Map(links.map((link) => [link.hash, document.getElementById(link.hash.slice(1))]));
		sections.forEach((section) => section.querySelector('h2').setAttribute('tabindex', '-1'));
		const measureNav = () => root.style.setProperty('--zpsp-nav-height', settingsNav.offsetHeight + 'px');
		new ResizeObserver(measureNav).observe(settingsNav);
		const navigate = () => {
			const section = sections.get(window.location.hash);
			if (!section) return;
			links.forEach((link) => {
				if (link.hash === window.location.hash) link.setAttribute('aria-current', 'location');
				else link.removeAttribute('aria-current');
			});
			section.querySelector('h2').focus({ preventScroll: true });
			section.scrollIntoView({ block: 'start' });
		};
		window.addEventListener('hashchange', navigate);
		root.querySelectorAll('[data-settings-anchor]').forEach((link) => link.addEventListener('click', () => {
			if (link.hash === window.location.hash) requestAnimationFrame(navigate);
		}));
		requestAnimationFrame(() => { measureNav(); navigate(); });
	}
	$('change-turnstile')?.addEventListener('click', () => {
		if (busy || confirmation) return;
		turnstileEditing = true;
		$('turnstile_secret').value = '';
		changed($('turnstile-form'));
		$('turnstile_site_key').focus();
	});
	$('cancel-turnstile')?.addEventListener('click', () => {
		if (busy || confirmation) return;
		const form = $('turnstile-form');
		fill(form);
		clearFieldErrors(form);
		notice('turnstile', '');
		(hasTurnstileKeys() ? $('change-turnstile') : $('comment_verification')).focus();
	});
	$('delete-turnstile')?.addEventListener('click', (event) => {
		if (busy || confirmation || !hasTurnstileKeys()) return;
		const revision = settings.revision;
		const button = event.currentTarget;
		const form = $('turnstile-form');
		confirmAction(button, {
			title: message('turnstile_delete_title'), confirmLabel: message('turnstile_delete_confirm'),
			description: message('turnstile_delete_help'), feedback: 'turnstile',
			onConfirm: async () => {
				const deleted = await run(button, 'turnstile', 'deleting_turnstile', async () => {
					settings = await request('delete_turnstile_keys', { settings: { revision } });
					prepared = null;
					fill(form);
					notice('turnstile', message('turnstile_deleted'));
				}, form);
				if (deleted) $('comment_verification').focus();
			},
		});
	});
	const tokenForm = $('token-form');
	if (tokenForm) {
		tokenForm.querySelector('fieldset').disabled = false;
		$('token').addEventListener('input', () => {
			if ($('token').value) dirty.add('token');
			else dirty.delete('token');
			$('token-dirty').hidden = !dirty.has('token');
			clearFieldErrors(tokenForm);
			notice('token', '');
			tokenControls();
		});
		tokenForm.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (busy || confirmation || settings.has_token) return;
			const input = { revision: settings.revision, token: $('token').value.trim() };
			const registered = await run($('register-token'), 'token', 'registering_token', async () => {
				tokenSaved(await request('register_token', { settings: input }), 'token_registered');
			}, tokenForm);
			if (registered) $('token-state').focus();
		});
		$('delete-token').addEventListener('click', (event) => {
			if (busy || confirmation || !settings.has_token) return;
			const revision = settings.revision;
			const button = event.currentTarget;
			confirmAction(button, {
				title: message('token_delete_title'), confirmLabel: message('token_delete_confirm'),
				description: message('token_delete_description'), feedback: 'token',
				onConfirm: async () => {
					const deleted = await run(button, 'token', 'deleting_token', async () => {
						tokenSaved(await request('delete_token', { settings: { revision } }), 'token_deleted');
					}, tokenForm);
					if (deleted) $('token').focus();
				},
			});
		});
	}
	tokenControls();
	window.addEventListener('beforeunload', (event) => {
		if (dirty.size) { event.preventDefault(); event.returnValue = ''; }
	});
	$('add-menu')?.addEventListener('click', () => { addMenu().querySelector('input').focus(); changed($('menus')); });
	$('add-rule')?.addEventListener('click', () => { addRule().querySelector('input').focus(); changed($('media')); });
	$('test')?.addEventListener('click', (event) => {
		if (!settings.has_token) return;
		const form = $('github');
		if (!form.reportValidity()) return;
		const input = { ...values(form), revision: settings.revision };
		notice('github', '');
		run(event.currentTarget, 'test', 'testing', async () => {
			const target = await request('test', { settings: input });
			const unsaved = target.repository !== settings.repository || target.branch !== settings.branch || input.wxr_publish_format !== settings.wxr_publish_format;
			notice('test', '');
			showRepository('test-notice', target, { connected: true, unsaved });
		}, form);
	});
	if ($('prepare')) $('prepare').disabled = false;
	$('prepare')?.addEventListener('click', (event) => {
		if (busy || confirmation) return;
		prepared = null;
		$('result').hidden = true;
		$('republish-options').open = false;
		$('result-heading').textContent = message('review_prepared');
		$('state').hidden = true;
		if (!session) notice('result', '');
		notice('download', '');
		run(event.currentTarget, 'prepare', 'preparing', async () => {
			prepared = await request('prepare');
			const { createElement: el, createRoot } = window.wp.element;
			exportView ||= createRoot($('summary'));
			exportView.render(publication.exportView(el, prepared, { onDetails: reviews && ((kind, trigger) => {
				if (!busy && !confirmation && prepared?.receipt) reviews.open({ kind, receipt: prepared.receipt }, trigger);
			}) }));
			showRepository('visibility', prepared.target);
			const unchanged = prepared.state === 'unchanged';
			const state = $('state');
			state.className = unchanged ? 'notice notice-info zpsp-unchanged' : '';
			state.textContent = '';
			if (unchanged) {
				const title = document.createElement('strong');
				title.className = 'zpsp-unchanged-title';
				const icon = document.createElement('span');
				icon.className = 'dashicons dashicons-info-outline';
				icon.setAttribute('aria-hidden', 'true');
				title.append(icon, message('unchanged'));
				const description = document.createElement('p');
				description.textContent = message('unchanged_description');
				state.append(title, description);
			}
			$('state').hidden = !unchanged || !!session;
			$('result').hidden = false;
			notice('prepare', '');
			syncPublicationStatus();
			// Wait for the shared card to render before focusing its specific next step.
			requestAnimationFrame(() => {
				const check = prepared?.state === 'download_only' && $('summary').querySelector('.zpsp-export-check');
				(check || $(unchanged && !session ? 'state' : 'result-heading')).focus();
			});
		});
	});

	// This panel survives preparing again; server-side status is authoritative.
	const recovery = config.session_publication && $('prepare') ? document.createElement('div') : null;
	let publicationControl = null;
	const livePause = recovery ? document.createElement('button') : null;
	if (livePause) {
		livePause.id = 'zpsp-pause-publication'; livePause.type = 'button';
		livePause.className = 'button button-secondary'; livePause.textContent = message('session_pause');
		livePause.hidden = true; livePause.disabled = true;
		livePause.addEventListener('click', () => publicationControl?.pause?.());
		$('result-notice').after(livePause);
	}
	function updatePublicationControl(control) {
		publicationControl = control;
		if (livePause) {
			livePause.hidden = !control?.pause && !control?.pausing; livePause.disabled = !control?.pause;
			livePause.textContent = message(control?.pausing ? 'session_pausing' : 'session_pause');
		}
		if (recovery) recovery.hidden = !!control || !session;
		syncPublicationStatus();
	}
	function updatePublicationStage(text) {
		// Keep the progress bar mounted while announcing actual server stages.
		const label = $('result-notice').querySelector('.zpsp-feedback-message');
		if (label && label.textContent !== text) label.textContent = text;
	}
	function showSession(value) {
		if (!recovery) return;
		session = ['active', 'paused', 'unknown', 'expired', 'cancel_pending', 'busy'].includes(value?.state) ? value : null;
		recovery.replaceChildren(); recovery.hidden = !!publicationControl || !session;
		syncPublicationStatus();
		if (!session) { publishControls(); return; }
		const description = publication.resultMessage(session);
		if (!$('result-notice')?.textContent.includes(description)) {
			notice('result', description, publication.resultKind(session), session.commit_url, session.code);
		}
		const actions = document.createElement('div'); actions.className = 'zpsp-actions'; recovery.append(actions);
		const captured = session;
		const add = (key, work, progress, primary = false) => {
			const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-' + (primary ? 'primary' : 'secondary'); button.textContent = message(key);
			button.addEventListener('click', () => run(button, 'result', progress, async () => {
				const result = await work(captured);
				notice('result', publication.resultMessage(result), publication.resultKind(result), result.commit_url, result.code);
				showSession(result);
				$('result-notice').focus();
			})); actions.append(button);
		};
		if (session.state === 'unknown' || session.can_continue) add(session.state === 'unknown' ? 'session_verify' : 'session_continue', (s) => publication.resume(s, updatePublicationStage, updatePublicationControl), 'publishing', true);
		if (session.job_id && session.state !== 'unknown') add('session_cancel', (s) => publication.cancel(s.job_id), 'session_cancelling');
		add('session_refresh', (s) => publication.sessionStatus(s.job_id), 'session_refreshing');
		publishControls();
	}
	if (recovery) {
		recovery.hidden = true; recovery.className = 'zpsp-publication-recovery';
		$('publication-status').append(recovery);
		publication.sessionStatus().then(showSession).catch(() => {});
	}

	function publish(button, snapshot) {
		if (busy || confirmation || prepared !== snapshot || !['ready', 'unchanged'].includes(snapshot.state)) return;
		const republish = snapshot.state === 'unchanged';
		const receipt = snapshot.receipt;
		run(button, 'result', 'publishing', async () => {
			notice('prepare', '');
			notice('download', '');
			$('state').textContent = '';
			$('state').hidden = true;
			// Every write attempt requires a new preparation, even when its response is uncertain.
			snapshot.state = 'attempted';
			const result = await publication.publish(receipt, republish, updatePublicationStage, updatePublicationControl);
			if (result.state === 'published') {
				snapshot.state = 'published';
			}
			notice('result', publication.resultMessage(result), publication.resultKind(result), result.commit_url, result.code);
			showSession(result);
			$('result-notice').focus();
		});
	}
	function initializePrivacy() {
		const link = $('privacy');
		const available = () => window.wp?.components?.Modal && window.wp?.element?.createElement && window.wp?.element?.createRoot;
		if (!link || !available()) return;
		link.setAttribute('aria-haspopup', 'dialog');
		link.querySelector('.dashicons-external').hidden = true;
		let open = false;
		link.addEventListener('click', (event) => {
			// Keep the original document link for modified clicks and unavailable JavaScript components.
			if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
			if (!available()) {
				link.removeAttribute('aria-haspopup');
				link.querySelector('.dashicons-external').hidden = false;
				return;
			}
			event.preventDefault();
			if (open || confirmation) return;
			open = true;
			const { Modal } = window.wp.components;
			const { createElement: el, createRoot } = window.wp.element;
			const container = document.createElement('div');
			root.append(container);
			const modalRoot = createRoot(container);
			let overlay = null;
			function keepFocus(event) {
				// A preparation or publication response can otherwise focus the page behind the dialog.
				if (overlay?.isConnected && !overlay.contains(event.target)) overlay.querySelector('[role="dialog"]')?.focus({ preventScroll: true });
			}
			function close() {
				if (!open) return;
				open = false;
				document.removeEventListener('focusin', keepFocus);
				modalRoot.unmount();
				container.remove();
				link.focus();
			}
			const section = (id) => el('section', { className: 'zpsp-privacy-section', 'aria-labelledby': 'zpsp-privacy-' + id },
				el('h2', { id: 'zpsp-privacy-' + id }, message('privacy_' + id + '_title')),
				el('p', null, message('privacy_' + id)),
			);
			// Underline only the label and keep the icon beside it when the text wraps.
			const policyLink = (id, href) => el('li', null, el('a', { href, target: '_blank', rel: 'noopener noreferrer' },
				el('span', { className: 'zpsp-privacy-link-label' }, message(id)),
				el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true }),
			));
			document.addEventListener('focusin', keepFocus);
			modalRoot.render(el(Modal, {
				ref: (node) => { overlay = node; },
				title: message('privacy_title'),
				className: 'zpsp-privacy-modal',
				focusOnMount: true,
				closeButtonLabel: message('privacy_close'),
				shouldCloseOnClickOutside: true, shouldCloseOnEsc: true,
				onRequestClose: close,
				aria: { describedby: 'zpsp-privacy-description' },
			},
			el('p', { id: 'zpsp-privacy-description' }, message('privacy_intro')),
			section('github'), section('turnstile'), section('newsletter'),
			el('p', null, message('privacy_storage')),
			el('p', null, message('privacy_policies')),
			el('ul', { className: 'zpsp-privacy-links' },
				policyLink('privacy_github_policy', 'https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement'),
				policyLink('privacy_cloudflare_policy', 'https://www.cloudflare.com/privacypolicy/'),
				policyLink('privacy_details', link.href),
			),
			el('div', { className: 'zpsp-privacy-actions' }, el('button', { type: 'button', className: 'button', onClick: close }, message('privacy_close'))),
			));
		});
	}
	function confirmAction(button, options) {
		const Modal = window.wp?.components?.Modal;
		const { createElement: el, createRoot } = window.wp?.element || {};
		if (!Modal || !el || !createRoot) {
			notice(options.feedback, message('confirmation_unavailable'), true);
			$(options.feedback + '-notice').focus();
			return;
		}
		const container = document.createElement('div');
		root.append(container);
		const modalRoot = createRoot(container);
		confirmation = options;
		let settled = false;
		let overlay = null;
		function keepBackdropFocus(event) {
			if (event.target === event.currentTarget) event.preventDefault();
		}
		function setOverlay(node) {
			overlay?.removeEventListener('pointerdown', keepBackdropFocus);
			overlay = node;
			// WordPress omits its backdrop focus guard when outside closing is disabled.
			// Keep keyboard focus inside so Esc and constrained tabbing still work.
			if (options.shouldCloseOnClickOutside === false) overlay?.addEventListener('pointerdown', keepBackdropFocus);
		}
		function close(accepted) {
			if (settled) return;
			settled = true;
			modalRoot.unmount();
			container.remove();
			confirmation = null;
			button.focus();
			if (accepted) options.onConfirm();
		}
		modalRoot.render(el(Modal, {
			ref: setOverlay,
			title: options.title,
			className: 'zpsp-confirm-modal',
			size: 'medium',
			focusOnMount: 'firstContentElement',
			closeButtonLabel: message('confirm_close'),
			shouldCloseOnClickOutside: options.shouldCloseOnClickOutside ?? true,
			onRequestClose: () => close(false),
			aria: { describedby: 'zpsp-confirm-description' },
		},
		options.content?.(el),
		el('p', { id: 'zpsp-confirm-description' }, options.description),
		el('div', { className: 'zpsp-confirm-actions' },
			el('button', { type: 'button', className: 'button', onClick: () => close(false) }, message('confirm_cancel')),
			el('button', { type: 'button', className: 'button button-primary', onClick: () => close(true) }, options.confirmLabel || options.title),
		)));
	}
	function confirmPublication(button, snapshot) {
		const republish = snapshot.state === 'unchanged';
		const change = publication.formatChange(snapshot);
		confirmAction(button, {
			title: message(republish ? 'republish_title' : 'confirm_title'),
			description: message(republish ? 'republish_description' : 'confirm_description'),
			shouldCloseOnClickOutside: false,
			feedback: 'result', onConfirm: () => publish(button, snapshot),
			content: (el) => el('div', null,
				publication.repositoryView(el, snapshot.target, { headingLevel: 2 }),
				el('section', { className: 'zpsp-confirm-files', 'aria-labelledby': 'zpsp-confirm-files-heading' },
					el('h2', { id: 'zpsp-confirm-files-heading' }, message('confirm_files')),
					change ? el('p', null, change) : null,
					el('ul', null,
						el('li', null, el('code', null, snapshot.publication?.format === 'bundle' ? 'wordpress-export.manifest.json' : 'wordpress-export.xml')),
						!republish && snapshot.publication?.format === 'bundle' ? el('li', null, el('code', null, 'wordpress-export.parts/')) : null,
						republish ? null : el('li', null, el('code', null, 'wxr-import-base.json')),
					),
				)),
		});
	}
	$('publish')?.addEventListener('click', (event) => {
		if (busy || confirmation || !['ready', 'unchanged'].includes(prepared?.state)) return;
		confirmPublication(event.currentTarget, prepared);
	});
	root.querySelectorAll('[data-download]').forEach((button) => button.addEventListener('click', () => {
		if (!prepared || busy || confirmation) return;
		const file = button.dataset.download;
		const receipt = prepared.receipt;
		const expectedBytes = prepared.bytes?.[file];
		run(button, 'download', 'downloading', async () => {
			const blob = await request('download', { receipt, file }, { expectedBytes });
			const url = URL.createObjectURL(blob);
			const link = document.createElement('a');
			link.href = url;
			link.download = file === 'wxr' ? 'wordpress-export.xml' : 'wxr-import-base.json';
			link.click();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
			notice('download', message('downloaded'));
		});
	}));
	initializePrivacy();
})();

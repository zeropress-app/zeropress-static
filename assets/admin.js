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
	const messages = config.messages;
	const $ = (id) => document.getElementById('zpsp-' + id);
	const forms = [...root.querySelectorAll('form[data-section]')];
	const baselines = new Map();
	const dirty = new Set();
	const loadingIndicators = new Map();
	let settings = config.settings;
	let prepared = null;
	let busy = false;
	let confirmation = null;
	let turnstileEditing = false;
	let menuRowId = 0;
	const numbers = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 2 });
	function formatBytes(bytes, binary = false) {
		const units = binary ? ['B', 'KiB', 'MiB', 'GiB'] : ['B', 'KB', 'MB', 'GB'];
		const step = binary ? 1024 : 1000;
		let unit = 0;
		while (bytes >= step && unit < units.length - 1) { bytes /= step; unit++; }
		return numbers.format(bytes) + ' ' + units[unit];
	}
	const message = (key, values = {}) => (messages[key] || messages.error).replace(/\{(\w+)\}/g, (_, name) => String(values[name] ?? ''));
	function failureMessage(code, details = {}) {
		let text = message(code);
		const numeric = (keys) => keys.every((key) => Number.isFinite(details?.[key]) && details[key] >= 0);
		if (code === 'MEMORY_LIMIT_EXCEEDED' && numeric(['memory_limit_bytes', 'estimated_memory_bytes'])) {
			text += ' ' + message('memory_details', { limit: formatBytes(details.memory_limit_bytes, true), estimated: formatBytes(details.estimated_memory_bytes, true) });
		} else if (code === 'REQUEST_TIME_LIMIT_EXCEEDED' && numeric(['time_limit_seconds', 'time_budget_seconds', 'elapsed_seconds'])) {
			text += ' ' + message('time_details', { limit: numbers.format(details.time_limit_seconds), budget: numbers.format(details.time_budget_seconds), elapsed: numbers.format(details.elapsed_seconds) });
		}
		return text;
	}

	function stopLoading(id) {
		loadingIndicators.get(id)?.unmount();
		loadingIndicators.delete(id);
		$(id + '-notice').querySelector('.zpsp-loading')?.remove();
	}
	function notice(id, text, error = false, commitUrl = '') {
		stopLoading(id);
		const element = $(id + '-notice');
		element.textContent = '';
		if (text) {
			const label = document.createElement('span');
			label.className = 'zpsp-feedback-message';
			label.textContent = text;
			element.append(label);
		}
		element.className = 'zpsp-feedback' + (text ? ' notice ' + (error ? 'notice-error' : 'notice-info') : '');
		if (commitUrl) {
			const link = document.createElement('a');
			link.href = commitUrl;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = message('commit');
			const icon = document.createElement('span');
			icon.className = 'dashicons dashicons-external';
			icon.setAttribute('aria-hidden', 'true');
			link.append(' ', icon);
			element.append(' ', link);
		}
	}
	function startLoading(id, progress) {
		const component = { preparing: 'Spinner', downloading: 'Spinner', publishing: 'ProgressBar' }[progress];
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
		if (!fixed) {
			const remove = document.createElement('button');
			remove.type = 'button'; remove.className = 'button zpsp-icon-button';
			const icon = document.createElement('span');
			icon.className = 'dashicons dashicons-trash'; icon.setAttribute('aria-hidden', 'true');
			remove.append(icon, document.createTextNode(message('menu_remove')));
			remove.addEventListener('click', () => { row.remove(); changed($('menus')); $('add-menu').focus(); });
			row.append(remove);
		}
		$('menu-rows').append(row);
		return row;
	}
	function fill(form) {
		for (const input of form.elements) {
			if (!input.name) continue;
			const value = settings[input.name];
			if (input.name === 'comment_verification') input.checked = value === 'turnstile';
			else if (input.type === 'checkbox') input.checked = !!value;
			else input.value = value || '';
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
		if (section === 'comments') commentControls();
		if (section === 'newsletter' || section === 'discovery') featureControls();
		if (section === 'turnstile') { turnstileEditing = false; turnstileControls(); }
		baselines.set(section, JSON.stringify(values(form)));
		dirty.delete(section);
		$(section + '-dirty').hidden = true;
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
	async function request(operation, extra = {}, download = false, signal) {
		let response;
		try {
			response = await fetch(config.url, {
				method: 'POST', credentials: 'same-origin', signal,
				body: new URLSearchParams({ action: 'zeropress_static', nonce: config.nonce, payload: JSON.stringify({ operation, ...extra }) }),
			});
		} catch {
			const error = new Error(message(operation === 'publish' ? 'unknown' : 'error'));
			error.uncertain = operation === 'publish';
			throw error;
		}
		if (download && response.ok && response.headers.get('Content-Type')?.startsWith('application/octet-stream')) return response.blob();
		const result = await response.json().catch(() => null);
		if (!response.ok || !result?.success) {
			const error = new Error(failureMessage(result?.data?.code, result?.data?.details));
			error.code = result?.data?.code;
			error.uncertain = operation === 'publish' && !error.code && (response.status >= 500 || response.ok);
			throw error;
		}
		return result.data;
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
			notice(id, text, true);
			$(id + '-notice').focus();
		}
	}
	function publishControls() {
		if (!$('publish')) return;
		const publishable = ['ready', 'unchanged'].includes(prepared?.state);
		$('publish').disabled = busy || !publishable;
		if (!busy && prepared?.state !== 'attempted') {
			const republish = prepared?.state === 'unchanged';
			const published = prepared?.state === 'published';
			const actions = $(republish ? 'republish-actions' : 'publish-actions');
			if ($('publish').parentElement !== actions) actions.append($('publish'));
			$('republish-options').hidden = !republish;
			$('publish').querySelector('.zpsp-button-label').textContent = message(published ? 'published_button' : republish ? 'republish_title' : 'confirm_title');
			$('publish').classList.toggle('button-primary', !republish && !published);
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
		const scope = progress === 'publishing' ? button : form || button.closest('section');
		scope.setAttribute('aria-busy', 'true');
		notice(id, message(progress));
		let failure;
		try {
			startLoading(id, progress);
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
				fill(form); // Never replace another panel's unsaved input, including tokens.
				if (section === 'media') {
					root.querySelectorAll('[data-publishing-icon]').forEach((element) => {
						element.textContent = settings.publishing_icons[element.dataset.publishingIcon] || '';
					});
				}
				const feedback = section === 'comments' ? 'saved_comments' : section === 'turnstile' ? 'saved_runtime' : section === 'github' ? 'saved' : 'saved_publish';
				notice(section, message(feedback));
			}, form);
			if (saved && section === 'turnstile') $('save-turnstile').focus();
			if (saved) {
				const activeForm = document.activeElement.closest('form');
				if (!activeForm || activeForm === form) $(section + '-notice').scrollIntoView({ block: 'nearest' });
			}
		});
		form.querySelector('fieldset').disabled = false;
	});
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
			const unsaved = target.repository !== settings.repository || target.branch !== settings.branch;
			notice('test', message('connected') + (unsaved ? ' ' + message('connected_unsaved') : ''));
		}, form);
	});
	if ($('prepare')) $('prepare').disabled = false;
	$('prepare')?.addEventListener('click', (event) => {
		if (busy || confirmation) return;
		prepared = null;
		$('result').hidden = true;
		$('republish-options').open = false;
		$('result-heading').textContent = message('review_prepared');
		notice('result', '');
		notice('download', '');
		run(event.currentTarget, 'prepare', 'preparing', async () => {
			prepared = await request('prepare');
			$('summary').textContent = message('summary', {
				...Object.fromEntries(Object.entries(prepared.counts).map(([key, count]) => [key, numbers.format(count)])),
				wxr: formatBytes(prepared.bytes.wxr), base: formatBytes(prepared.bytes.base),
				replacements: numbers.format(prepared.rewritten_values),
			});
			const unchanged = prepared.state === 'unchanged';
			const state = $('state');
			state.className = unchanged ? 'notice notice-success zpsp-unchanged' : '';
			state.textContent = '';
			if (unchanged) {
				const title = document.createElement('strong');
				title.className = 'zpsp-unchanged-title';
				const icon = document.createElement('span');
				icon.className = 'dashicons dashicons-yes-alt';
				icon.setAttribute('aria-hidden', 'true');
				title.append(icon, message('unchanged'));
				const description = document.createElement('p');
				description.textContent = message('unchanged_description');
				state.append(title, description);
			} else state.textContent = message(prepared.state, prepared.target || {});
			$('state').hidden = false;
			$('result').hidden = false;
			notice('prepare', prepared.connection_error ? failureMessage(prepared.connection_error, prepared.connection_error_details) : unchanged ? '' : message('prepared'), !!prepared.connection_error);
			$(unchanged ? 'state' : 'result-heading').focus();
		});
	});
	function watchPublication(publicationId) {
		const stages = ['validating', 'inspecting', 'uploading', 'committing', 'verifying'];
		const label = $('result-notice').querySelector('.zpsp-feedback-message');
		let stopped = false;
		let timer;
		let controller;
		function show(stage) {
			const text = message(stages.includes(stage) ? 'publish_' + stage : 'publishing');
			// Leave the progress bar mounted and announce only changed stage text.
			if (!stopped && label.textContent !== text) label.textContent = text;
		}
		async function poll() {
			controller = new AbortController();
			const timeout = setTimeout(() => controller.abort(), 4000);
			try {
				const status = await request('publish_progress', { publication_id: publicationId }, false, controller.signal);
				show(status?.stage);
			} catch {
				show(null);
			} finally {
				clearTimeout(timeout);
				if (!stopped) timer = setTimeout(poll, 1000);
			}
		}
		function stop() {
			stopped = true;
			clearTimeout(timer);
			controller?.abort();
			window.removeEventListener('pagehide', stop);
		}
		timer = setTimeout(poll, 1000);
		window.addEventListener('pagehide', stop, { once: true });
		return stop;
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
			$('result-heading').textContent = message('publication_result');
			// Every write attempt requires a new preparation, even when its response is uncertain.
			snapshot.state = 'attempted';
			const publicationId = [...window.crypto.getRandomValues(new Uint8Array(16))].map((byte) => byte.toString(16).padStart(2, '0')).join('');
			const stopWatching = watchPublication(publicationId);
			let result;
			try { result = await request('publish', { receipt, republish, publication_id: publicationId }); }
			catch (error) {
				if (error.uncertain) result = { state: 'unknown' };
				else throw error;
			} finally {
				stopWatching();
			}
			if (result.state === 'published') {
				snapshot.state = 'published';
			}
			notice('result', message(result.state), result.state === 'unknown', result.commit_url);
			if (result.state === 'published') $('result-notice').classList.replace('notice-info', 'notice-success');
			$('result-notice').focus();
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
			title: options.title,
			className: 'zpsp-confirm-modal',
			size: 'medium',
			focusOnMount: 'firstContentElement',
			closeButtonLabel: message('confirm_close'),
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
		confirmAction(button, {
			title: message(republish ? 'republish_title' : 'confirm_title'),
			description: message(republish ? 'republish_description' : 'confirm_description'),
			feedback: 'result', onConfirm: () => publish(button, snapshot),
			content: (el) => el('dl', { className: 'zpsp-confirm-target' },
				el('dt', null, message('confirm_repository')),
				el('dd', null, snapshot.target.repository),
				el('dt', null, message('confirm_branch')),
				el('dd', null, snapshot.target.branch),
				el('dt', null, message('confirm_files')),
				el('dd', null, el('ul', null,
					el('li', null, el('code', null, 'wordpress-export.xml')),
					republish ? null : el('li', null, el('code', null, 'wxr-import-base.json')),
				)),
			),
		});
	}
	$('publish')?.addEventListener('click', (event) => {
		if (busy || confirmation || !['ready', 'unchanged'].includes(prepared?.state)) return;
		confirmPublication(event.currentTarget, prepared);
	});
	root.querySelectorAll('[data-download]').forEach((button) => button.addEventListener('click', () => {
		if (!prepared || busy || confirmation) return;
		run(button, 'download', 'downloading', async () => {
			const blob = await request('download', { receipt: prepared.receipt, file: button.dataset.download }, true);
			const url = URL.createObjectURL(blob);
			const link = document.createElement('a');
			link.href = url;
			link.download = button.dataset.download === 'wxr' ? 'wordpress-export.xml' : 'wxr-import-base.json';
			link.click();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
			notice('download', message('downloaded'));
		});
	}));
})();

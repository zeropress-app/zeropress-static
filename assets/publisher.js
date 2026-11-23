/* Shared requests and publication stages for the admin page and post editors. */
window.zeroPressPublication = function createPublication(config) {
	'use strict';
	const messages = config.messages;
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
		if (code === 'PUBLICATION_BUSY' && details?.processing_change === true) return message('processing_pending');
		const numeric = (keys) => keys.every((key) => Number.isFinite(details?.[key]) && details[key] >= 0);
		code = ({ PUBLICATION_INVALID: 'PREPARATION_INVALID', PUBLICATION_CONFLICT: 'session_paused', PUBLICATION_WAIT: 'session_paused', PUBLICATION_INTERRUPTED: 'session_paused', PUBLICATION_CANCELLED: 'session_cancelled', PUBLICATION_ACTIVE: 'session_active', PUBLICATION_BUSY: 'session_busy', PUBLICATION_VERIFY_REQUIRED: 'unknown' })[code] || code;
		const insufficient = code === 'REQUEST_TIME_LIMIT_EXCEEDED' && numeric(['remaining_time_seconds', 'minimum_time_seconds']);
		let text = message(insufficient ? 'time_insufficient' : code);
		if (code === 'CONTENT_BODY_TOO_LARGE' && numeric(['post_id', 'body_bytes'])) {
			text += ' ' + message('body_details', { id: numbers.format(details.post_id), bytes: numbers.format(details.body_bytes) });
		}
		if (['MEMORY_LIMIT_EXCEEDED', 'WXR_SINGLE_MEMORY_LIMIT'].includes(code) && numeric(['memory_limit_bytes', 'estimated_memory_bytes'])) {
			text += ' ' + message('memory_details', { limit: formatBytes(details.memory_limit_bytes, true), estimated: formatBytes(details.estimated_memory_bytes, true) });
		} else if (code === 'REQUEST_TIME_LIMIT_EXCEEDED' && numeric(['time_limit_seconds', 'time_budget_seconds', 'elapsed_seconds'])) {
			text += ' ' + message('time_details', { limit: numbers.format(details.time_limit_seconds), budget: numbers.format(details.time_budget_seconds), elapsed: numbers.format(details.elapsed_seconds) });
			if (insufficient) text += ' ' + message('time_needed_details', { remaining: numbers.format(details.remaining_time_seconds), minimum: numbers.format(details.minimum_time_seconds) });
		}
		return text;
	}

	function helpUrl(code) {
		if (['MEMORY_EXPORT_TOO_LARGE', 'MEMORY_SINGLE_REQUIRED', 'MEMORY_LOCK_UNAVAILABLE', 'MEMORY_LOCK_LOST', 'MEMORY_PUBLICATION_INTERRUPTED', 'PUBLICATION_BACKEND_CHANGED'].includes(code) || (config.memory_publication && code === 'WXR_SINGLE_MEMORY_LIMIT')) return 'https://github.com/zeropress-app/zeropress-static#export-processing';
		if (['CONTENT_BODY_TOO_LARGE', 'SOURCE_READ_UNBOUNDED', 'DOWNLOAD_INCOMPLETE', 'REQUEST_TIME_LIMIT_EXCEEDED', 'PUBLICATION_STORAGE_UNAVAILABLE'].includes(code)) return 'https://github.com/zeropress-app/zeropress-static#export-scope-and-limits';
		return ['WXR_SPLIT_REQUIRED', 'WXR_SINGLE_MEMORY_LIMIT', 'WXR_SPLIT_UNSUPPORTED', 'WXR_TARGET_INVALID', 'WXR_RECORD_TOO_LARGE', 'WXR_BUNDLE_LIMIT_EXCEEDED', 'EXPORT_STORAGE_UNAVAILABLE', 'GITHUB_PAYLOAD_TOO_LARGE', 'MEMORY_LIMIT_EXCEEDED'].includes(code)
			? 'https://github.com/zeropress-app/zeropress-starter-wxr#split-wxr-input' : '';
	}

	function downloadError(code = 'DOWNLOAD_INCOMPLETE') {
		const error = new Error(failureMessage(code));
		error.code = code;
		return error;
	}
	async function request(operation, extra = {}, download = false, signal) {
		const isDownload = operation === 'download';
		const expectedBytes = download?.expectedBytes;
		if (isDownload && (!Number.isSafeInteger(expectedBytes) || expectedBytes <= 0)) throw downloadError('PREPARATION_INVALID');
		let response;
		try {
			response = await fetch(config.url, {
				method: 'POST', credentials: 'same-origin', signal,
				body: new URLSearchParams({ action: 'zeropress_static', nonce: config.nonce, payload: JSON.stringify({ operation, ...extra }) }),
			});
		} catch {
			if (isDownload) throw downloadError();
			const error = new Error(message(operation === 'publish' ? 'unknown' : 'error'));
			error.uncertain = operation === 'publish';
			throw error;
		}
		if (isDownload) {
			const type = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
			if (response.status === 200 && type === 'application/octet-stream') {
				let blob;
				try { blob = await response.blob(); } catch { throw downloadError(); }
				// Fetch decodes compression. Compare against the captured preparation,
				// never a proxy's Content-Length or a rounded display size.
				if (blob.size !== expectedBytes) throw downloadError();
				return blob;
			}
			const failure = type === 'application/json' ? await response.json().catch(() => null) : null;
			if (failure?.success === false && typeof failure.data?.code === 'string') {
				const error = downloadError(failure.data.code);
				error.message = failureMessage(failure.data.code, failure.data.details);
				throw error;
			}
			throw downloadError();
		}
		const result = await response.json().catch(() => null);
		if (!response.ok || !result?.success) {
			const error = new Error(failureMessage(result?.data?.code, result?.data?.details));
			error.code = result?.data?.code;
			error.uncertain = operation === 'publish' && !error.code && (response.status >= 500 || response.ok);
			throw error;
		}
		if (['files', 'memory'].includes(result.data?.runtime?.backend)) {
			config.runtime = result.data.runtime;
			config.memory_publication = config.runtime.backend === 'memory';
		}
		return result.data;
	}
	function watchPublication(publicationId, onStage) {
		const stages = ['validating', 'inspecting', 'uploading', 'committing', 'verifying'];
		let stopped = false;
		let timer;
		let controller;
		function show(stage) {
			if (!stopped) onStage(message(stages.includes(stage) ? 'publish_' + stage : 'publishing'));
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
	async function legacyPublish(receipt, republish, onStage) {
		const publicationId = [...window.crypto.getRandomValues(new Uint8Array(16))].map((byte) => byte.toString(16).padStart(2, '0')).join('');
		const stopWatching = watchPublication(publicationId, onStage);
		try {
			return await request('publish', { receipt, republish, publication_id: publicationId });
		} catch (error) {
			if (error.uncertain) return { state: 'unknown' };
			throw error;
		} finally {
			stopWatching();
		}
	}
	const requestId = () => [...window.crypto.getRandomValues(new Uint8Array(16))].map((byte) => byte.toString(16).padStart(2, '0')).join('');
	const sessionStatus = (jobId = '', requestId = '') => request('publication_status', { job_id: jobId, request_id: requestId });
	const cancel = (jobId) => request('publication_cancel', { job_id: jobId });
	function resultMessage(result) {
		if (result.state === 'idle') return '';
		if (result.state === 'unknown') return message('unknown');
		if (result.backend === 'memory' && result.state === 'active') return message('memory_active');
		if (result.state === 'paused' && result.code === 'REQUEST_TIME_LIMIT_EXCEEDED') return message('session_time_paused');
		if (result.code) return failureMessage(result.code, result.details);
		if (result.state === 'paused' && result.pause_reason === 'requested') return message('session_paused_by_user');
		return message(['active', 'paused', 'expired', 'cancel_pending', 'cancelled', 'busy'].includes(result.state) ? 'session_' + result.state : result.state);
	}
	// Presentation only: an uncertain result is not a confirmed failure.
	function resultKind(result) {
		if (result.state === 'unknown' || result.code === 'PUBLICATION_VERIFY_REQUIRED') return 'warning';
		if (result.state === 'published') return 'success';
		if (result.state === 'failed') return 'error';
		if (result.state === 'paused') return result.pause_reason === 'requested' && !result.code ? 'info' : 'warning';
		return ['expired', 'busy'].includes(result.state) ? 'warning' : 'info';
	}
	const statusIcon = (kind) => ({ success: 'yes-alt', warning: 'warning', error: 'dismiss', info: 'info-outline' })[kind] || 'info-outline';
	const pausable = (state) => !!state?.job_id && ['active', 'paused'].includes(state.state) && state.can_continue && !state.busy;
	const paused = (state) => state.state === 'active' && state.can_continue !== false ? { ...state, state: 'paused' } : state;
	// A requested pause stops this page's loop at a confirmed request boundary.
	// Keep the server job, its approval deadline and acknowledged progress intact.
	const requestedPause = (state) => pausable(state) && !state.code ? { ...state, state: 'paused', pause_reason: 'requested' } : state;
	async function runPublication(work, onStage, onControl, canPause = true) {
		let stopped = false;
		let requested = false;
		let allowed = canPause;
		let wake;
		const notify = () => onControl({ pause: allowed && !requested && !stopped ? requestPause : null, pausing: requested });
		function requestPause() {
			if (!allowed || requested || stopped) return;
			requested = true;
			notify();
			onStage(message('session_pause_pending'));
			wake?.();
		}
		const stop = () => { stopped = true; notify(); wake?.(); };
		const run = {
			get stopped() { return stopped; },
			get pauseRequested() { return requested; },
			pausable(value) { allowed = value; notify(); },
			async wait(ms) {
				if (!ms || stopped || requested) return;
				await new Promise((resolve) => {
					const finish = () => { clearTimeout(timer); wake = null; resolve(); };
					const timer = setTimeout(finish, ms);
					wake = finish;
				});
			},
		};
		window.addEventListener('pagehide', stop, { once: true });
		try { notify(); return await work(run); }
		finally { window.removeEventListener('pagehide', stop); wake?.(); allowed = false; onControl(null); }
	}
	// Only an explicit click starts this loop. Reloading a page reads status only.
	async function continueSession(initial, onStage, run) {
		let current = initial;
		for (let step = 0; step < 1024 && !run.stopped; step++) {
			run.pausable(pausable(current));
			if (run.pauseRequested) return requestedPause(current);
			if (!['active', 'paused', 'unknown'].includes(current.state) || !current.job_id || current.busy) return current;
			// A user can request another read-only verification cycle explicitly.
			if (step > 0 && (current.state === 'paused' || current.verification_complete)) return current;
			if (current.state !== 'unknown' && !current.can_continue) return current;
			const stage = current.state === 'unknown' ? 'verifying' : ({ capturing: 'validating', inspecting: 'inspecting', uploading: 'uploading' }[current.phase] || 'committing');
			onStage(message('publish_' + stage));
			const wait = Number.isFinite(current.next_after_ms) ? Math.max(0, current.next_after_ms) : 0;
			await run.wait(wait);
			if (run.stopped) return current;
			if (run.pauseRequested) return requestedPause(current);
			if (current.next_operation === 'publication_finalize' || current.state === 'unknown') run.pausable(false);
			try {
				current = await request(current.next_operation, { job_id: current.job_id, request_id: requestId(), revision: current.revision });
			} catch (error) {
				// Never infer failure from a lost HTTP response or replay a mutation.
				const state = await sessionStatus(current.job_id).catch(() => null);
				if (state) return paused(state);
				throw error;
			}
		}
		if (!['active', 'paused', 'unknown'].includes(current.state)) return current;
		return { ...current, state: current.state === 'unknown' ? 'unknown' : 'paused' };
	}
	async function resume(initial, onStage = () => {}, onControl = () => {}) {
		return runPublication((run) => continueSession(initial, onStage, run), onStage, onControl, pausable(initial));
	}
	async function publish(receipt, republish, onStage = () => {}, onControl = () => {}) {
		if (!config.session_publication) return legacyPublish(receipt, republish, onStage);
		return runPublication(async (run) => {
			let state;
			const id = requestId();
			try { state = await request('publication_start', { receipt, republish, request_id: id }); }
			catch (error) {
				const found = await sessionStatus('', id).catch(() => null);
				if (found && found.state !== 'idle') return paused(found);
				throw error;
			}
			return continueSession(state, onStage, run);
		}, onStage, onControl, !config.memory_publication);
	}

	function formatChange(prepared) {
		const { format, previous_format: previous } = prepared.publication || {};
		return ['single', 'bundle'].includes(format) && ['single', 'bundle'].includes(previous) && previous !== format ? message('switch_to_' + format) : '';
	}
	function visibilityLabel(target) {
		return message(['public', 'private', 'internal'].includes(target?.visibility) ? 'visibility_' + target.visibility : 'visibility_unknown');
	}
	// Use the same snapshot and presentation in connection results and both editors.
	function repositoryView(el, target, { connected = false, unsaved = false, headingLevel = 4 } = {}) {
		const visibility = ['public', 'private', 'internal'].includes(target?.visibility) ? target.visibility : 'unknown';
		const icons = { private: 'lock', public: 'admin-site-alt3', internal: 'building', unknown: 'editor-help' };
		const icon = (name) => el('span', { className: 'dashicons dashicons-' + name, 'aria-hidden': true });
		const repository = target?.repository;
		return el('div', { className: 'zpsp-repository', role: 'group', 'aria-label': message('confirm_repository') },
			el('h' + headingLevel, { className: 'zpsp-repository-label' }, message('confirm_repository')),
			el('div', { className: 'zpsp-repository-heading' },
				repository && el('a', {
					className: 'zpsp-repository-link', href: 'https://github.com/' + repository.split('/').map(encodeURIComponent).join('/'),
					target: '_blank', rel: 'noopener noreferrer',
				}, repository, ' ', icon('external')),
				el('span', {
					className: 'zpsp-visibility-badge zpsp-visibility-' + visibility,
					'aria-label': message('confirm_visibility') + ': ' + visibilityLabel(target),
				}, icon(icons[visibility]), visibilityLabel(target))),
			(target?.branch || (connected && visibility !== 'unknown')) && el('div', { className: 'zpsp-repository-meta' },
				target?.branch && el('span', null, message('confirm_branch') + ': ', el('span', { className: 'zpsp-repository-branch' }, target.branch)),
				connected && visibility !== 'unknown' && el('span', { className: 'zpsp-repository-connected' }, icon('yes-alt'), message('connected'))),
			visibility === 'public' && el('p', { className: 'zpsp-repository-warning' }, message('visibility_public_notice')),
			unsaved && el('p', { className: 'zpsp-repository-unsaved' }, message('connected_unsaved')));
	}
	function exportView(el, prepared, { headingLevel = 4, onDetails, disabled = false } = {}) {
		const counts = Object.fromEntries(['posts', 'pages', 'menus', 'media'].map((key) => [key, numbers.format(prepared.counts[key] ?? 0)]));
		const detailCount = (kind, label) => onDetails && prepared.counts[kind] > 0
			? el('button', {
				type: 'button', className: 'button-link zpsp-export-count',
				'aria-label': message('review_' + kind, counts), 'aria-haspopup': 'dialog',
				disabled: disabled || !prepared.receipt,
				onClick: (event) => onDetails(kind, event.currentTarget),
			}, label)
			: el('span', { className: 'zpsp-export-count' }, label);
		const { format, part_count: parts } = prepared.publication || {};
		const wxr = format === 'bundle' ? message('wxr_split_files', { parts: numbers.format(parts) })
			: format === 'single' || prepared.state === 'download_only' ? message('wxr_single_file') : '';
		const change = formatChange(prepared);
		const warning = prepared.warnings?.find((item) => item.code === 'CONTENT_BODY_LARGE');
		const hasSize = ['wxr', 'base'].every((key) => Number.isSafeInteger(prepared.bytes?.[key]) && prepared.bytes[key] >= 0);
		const capacity = prepared.memory_capacity;
		const hasCapacity = prepared.runtime?.backend === 'memory' && capacity?.limit_bytes > 0
			&& ['limit_bytes', 'raw_bytes', 'wxr_request_bytes', 'base_request_bytes'].every((key) => Number.isSafeInteger(capacity[key]) && capacity[key] >= 0);
		const used = hasCapacity ? Math.max(capacity.raw_bytes, capacity.wxr_request_bytes, capacity.base_request_bytes) : 0;
		const exceeded = hasCapacity && used > capacity.limit_bytes;
		// Rounding must not show an in-limit value as 100%, or an over-limit value as within the limit.
		const percent = hasCapacity ? (exceeded ? Math.ceil : Math.floor)(used / capacity.limit_bytes * 10000) / 100 : 0;
		const checked = ['ready', 'unchanged', 'download_only'].includes(prepared.state);
		const reason = prepared.connection_error || (exceeded ? 'MEMORY_EXPORT_TOO_LARGE' : '');
		const checkMessage = reason ? 'preparation_blocked' : prepared.state === 'download_only' ? 'preparation_connect' : 'preparation_passed';
		const checkStyle = reason ? 'warning' : prepared.state === 'download_only' ? 'info' : 'success';
		const checkIcon = { success: 'yes-alt', warning: 'warning', info: 'info-outline' }[checkStyle];
		return el('div', { className: 'zpsp-export', role: 'group', 'aria-label': message('export_contents') },
			el('h' + headingLevel, { className: 'zpsp-export-label' }, message('export_contents')),
			el('dl', { className: 'zpsp-export-details' },
				el('dt', null, message('export_content')),
				el('dd', { className: 'zpsp-export-counts' },
					el('span', { className: 'zpsp-export-count' }, message('export_post_count', counts)), ' · ',
					el('span', { className: 'zpsp-export-count' }, message('export_page_count', counts)), ' · ',
					detailCount('menus', message('export_menu_count', counts))),
				el('dt', null, message('export_media_info')),
				el('dd', { className: 'zpsp-export-media' }, detailCount('media', message('export_media_count', counts))),
				wxr && el('dt', null, 'WXR'),
				wxr && el('dd', { className: 'zpsp-export-wxr' }, wxr),
				el('dt', null, message('export_build_settings')),
				el('dd', { className: 'zpsp-export-base' }, message('json_file')),
				hasSize && el('dt', null, message('export_size')),
				hasSize && el('dd', { className: 'zpsp-export-size' }, formatBytes(prepared.bytes.wxr + prepared.bytes.base, true),
					el('span', { className: 'zpsp-export-detail' }, message('export_size_details', { wxr: formatBytes(prepared.bytes.wxr, true), base: formatBytes(prepared.bytes.base, true) })),
					el('span', { className: 'zpsp-export-detail' }, message('export_size_note'))),
				hasCapacity && el('dt', null, message('memory_export_usage')),
				hasCapacity && el('dd', { className: 'zpsp-export-capacity' },
					el('span', { className: exceeded ? 'zpsp-export-limit-exceeded' : undefined }, message('memory_export_percent', { percent: numbers.format(percent), limit: formatBytes(capacity.limit_bytes, true) })),
					exceeded && el('strong', { className: 'zpsp-export-detail zpsp-export-limit-exceeded' }, message('memory_export_exceeded')),
					el('span', { className: 'zpsp-export-detail' }, message('memory_export_note')))),
			format === 'bundle' && el('p', { className: 'zpsp-export-note' }, message('split_download_notice')),
			change && el('p', { className: 'zpsp-export-note' }, change),
			warning && el('p', { className: 'zpsp-export-warning' }, message('body_warning', { count: numbers.format(warning.count), bytes: numbers.format(warning.max_body_bytes), ids: warning.affected.map((id) => numbers.format(id)).join(', ') })),
			checked && el('div', { className: 'zpsp-export-check zpsp-export-check-' + checkStyle, role: 'group', tabIndex: -1, 'aria-label': message('preparation_check') },
				el('span', { className: 'dashicons dashicons-' + checkIcon, 'aria-hidden': true }),
				el('div', null,
					el('strong', { className: 'zpsp-export-check-title' }, message(checkMessage + '_title')),
					el('p', null, message(checkMessage)),
					reason && el('p', { className: 'zpsp-export-detail' }, failureMessage(reason, prepared.connection_error_details)),
					reason && helpUrl(reason) && el('p', null, el('a', { href: helpUrl(reason), target: '_blank', rel: 'noopener noreferrer' }, message('publishing_help'), ' ',
						el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true }))),
					checkMessage === 'preparation_passed' && el('p', { className: 'zpsp-export-detail' }, message('preparation_recheck')))));
	}
	return { request, publish, resume, sessionStatus, cancel, resultMessage, resultKind, statusIcon, exportView, formatChange, visibilityLabel, repositoryView, message, failureMessage, helpUrl };
};

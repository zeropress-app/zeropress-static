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
	const sessionStatus = (jobId = '') => request('publication_status', { job_id: jobId });
	const cancel = (jobId) => request('publication_cancel', { job_id: jobId });
	function resultMessage(result) {
		if (result.state === 'idle') return '';
		if (result.state === 'unknown') return message('unknown');
		if (result.state === 'paused' && result.code === 'REQUEST_TIME_LIMIT_EXCEEDED') return message('session_time_paused');
		if (result.code) return failureMessage(result.code, result.details);
		if (result.state === 'paused' && result.pause_reason === 'requested') return message('session_paused_by_user');
		return message(['active', 'paused', 'expired', 'cancel_pending', 'cancelled', 'busy'].includes(result.state) ? 'session_' + result.state : result.state);
	}
	const pausable = (state) => !!state?.job_id && ['active', 'paused'].includes(state.state) && state.can_continue && !state.busy;
	const paused = (state) => state.state === 'active' ? { ...state, state: 'paused' } : state;
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
			try { state = await request('publication_start', { receipt, republish, request_id: requestId() }); }
			catch (error) {
				const found = await sessionStatus().catch(() => null);
				if (found && found.state !== 'idle') return paused(found);
				throw error;
			}
			return continueSession(state, onStage, run);
		}, onStage, onControl);
	}

	function formatSummary(prepared) {
		const { format, previous_format: previous, part_count: parts } = prepared.publication || {};
		if (!['single', 'bundle'].includes(format)) return '';
		const description = format === 'bundle' ? message('split_summary', { parts: numbers.format(parts) }) : message('single_summary');
		return description + (['single', 'bundle'].includes(previous) && previous !== format ? ' ' + message('switch_to_' + format) : '');
	}
	function summary(prepared) {
		const counts = message('summary', Object.fromEntries(Object.entries(prepared.counts).map(([key, count]) => [key, numbers.format(count)])));
		const warning = prepared.warnings?.find((item) => item.code === 'CONTENT_BODY_LARGE');
		const format = formatSummary(prepared);
		return counts + (format ? ' ' + format : '')
			+ (warning ? ' ' + message('body_warning', { count: numbers.format(warning.count), bytes: numbers.format(warning.max_body_bytes), ids: warning.affected.map((id) => numbers.format(id)).join(', ') }) : '');
	}
	return { request, publish, resume, sessionStatus, cancel, resultMessage, summary, formatSummary, message, failureMessage, helpUrl };
};

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
		if (['CONTENT_BODY_TOO_LARGE', 'SOURCE_READ_UNBOUNDED', 'REQUEST_TIME_LIMIT_EXCEEDED'].includes(code)) return 'https://github.com/zeropress-app/zeropress-static#export-scope-and-limits';
		return ['WXR_SPLIT_REQUIRED', 'WXR_SINGLE_MEMORY_LIMIT', 'WXR_SPLIT_UNSUPPORTED', 'WXR_TARGET_INVALID', 'WXR_RECORD_TOO_LARGE', 'WXR_BUNDLE_LIMIT_EXCEEDED', 'EXPORT_STORAGE_UNAVAILABLE', 'GITHUB_PAYLOAD_TOO_LARGE', 'MEMORY_LIMIT_EXCEEDED'].includes(code)
			? 'https://github.com/zeropress-app/zeropress-starter-wxr#split-wxr-input' : '';
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
	async function publish(receipt, republish, onStage) {
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
	return { request, publish, summary, formatSummary, message, failureMessage, helpUrl };
};

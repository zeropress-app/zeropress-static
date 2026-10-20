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
		let text = message(code);
		const numeric = (keys) => keys.every((key) => Number.isFinite(details?.[key]) && details[key] >= 0);
		if (code === 'MEMORY_LIMIT_EXCEEDED' && numeric(['memory_limit_bytes', 'estimated_memory_bytes'])) {
			text += ' ' + message('memory_details', { limit: formatBytes(details.memory_limit_bytes, true), estimated: formatBytes(details.estimated_memory_bytes, true) });
		} else if (code === 'REQUEST_TIME_LIMIT_EXCEEDED' && numeric(['time_limit_seconds', 'time_budget_seconds', 'elapsed_seconds'])) {
			text += ' ' + message('time_details', { limit: numbers.format(details.time_limit_seconds), budget: numbers.format(details.time_budget_seconds), elapsed: numbers.format(details.elapsed_seconds) });
		}
		return text;
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
	function summary(prepared) {
		return message('summary', Object.fromEntries(Object.entries(prepared.counts).map(([key, count]) => [key, numbers.format(count)])));
	}
	return { request, publish, summary, message, failureMessage };
};

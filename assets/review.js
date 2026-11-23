/* Read-only menu and prepared-export details shared by admin and editor screens. */
window.zeroPressReview = function createReview(config, publication) {
	'use strict';
	const { createElement: el, createRoot, useState, useEffect } = window.wp.element;
	const { Modal } = window.wp.components;
	const { message, request } = publication;
	const numbers = new Intl.NumberFormat(document.documentElement.lang || undefined);
	let active = false;

	function menuItems(items) {
		const ids = new Set(items.map((item) => item.id));
		const seen = new Set();
		function itemView(item, depth = 0) {
			if (seen.has(item.id)) return null;
			seen.add(item.id);
			const children = depth < 10 ? items.filter((child) => child.parent === item.id && !seen.has(child.id)) : [];
			return el('li', { key: item.id },
				el('div', { className: 'zpsp-review-item-heading' },
					el('strong', null, item.label || message('review_untitled')),
					item.new_window && el('span', { className: 'zpsp-review-badge' }, message('review_new_window'))),
				el('span', { className: 'zpsp-review-url' }, item.url ?? message('review_url_pending')),
				children.length > 0 && el('ul', null, children.map((child) => itemView(child, depth + 1))));
		}
		const roots = items.filter((item) => !ids.has(item.parent)).map((item) => itemView(item));
		// Cyclic/deep inputs remain inspectable; never recurse forever or hide a row.
		return el('ul', { className: 'zpsp-review-items' }, roots, items.filter((item) => !seen.has(item.id)).map((item) => itemView(item)));
	}
	function mediaName(item) {
		try { return decodeURIComponent(new URL(item.url).pathname.split('/').at(-1)) || '#' + item.id; }
		catch { return '#' + item.id; }
	}
	function Details({ options, close }) {
		const [page, setPage] = useState(0);
		const [data, setData] = useState(null);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState('');
		const preview = options.kind === 'menu_preview';
		const media = options.kind === 'media';
		useEffect(() => {
			const controller = new AbortController();
			let closed = false;
			setLoading(true); setError('');
			const payload = preview ? { menu_id: options.menu_id, page } : { kind: options.kind, receipt: options.receipt, page };
			request(preview ? 'menu_preview' : 'review_details', payload, false, controller.signal)
				.then((value) => {
					if (!closed) {
						setData(value);
						const content = document.querySelector('.zpsp-review-modal .components-modal__content');
						if (content) content.scrollTop = 0;
					}
				})
				.catch((failure) => { if (!closed) { setData(null); setError(failure.message); } })
				.finally(() => { if (!closed) setLoading(false); });
			return () => { closed = true; controller.abort(); };
		}, [page]);
		function changePage(next) {
			// Move focus before the clicked paging button becomes disabled.
			// Otherwise Escape can stop reaching the dialog after a page change.
			document.querySelector('.zpsp-review-modal')?.focus({ preventScroll: true });
			setPage(next);
		}
		return el(Modal, {
			title: message(preview ? 'menu_preview_title' : media ? 'export_media_info' : 'review_menus_title'),
			className: 'zpsp-review-modal', focusOnMount: true, closeButtonLabel: message('privacy_close'),
			shouldCloseOnClickOutside: true, shouldCloseOnEsc: true, onRequestClose: close,
		},
			el('p', { className: 'zpsp-review-note' }, message(preview ? 'menu_preview_note' : 'review_note')),
			el('p', { role: 'status', 'aria-live': 'polite' }, loading ? message('review_loading') : data && message('review_range', {
				start: numbers.format(data.items.length ? data.offset + 1 : 0), end: numbers.format(data.offset + data.items.length), total: numbers.format(data.total),
			})),
			error && el('p', { role: 'alert', className: 'notice notice-warning' }, error),
			data && el('div', { 'aria-busy': loading, className: 'zpsp-review-results' },
				media ? (data.items.length ? el('ul', { className: 'zpsp-review-items' }, data.items.map((item) => el('li', { key: item.id },
					el('div', { className: 'zpsp-review-item-heading' }, el('strong', null, mediaName(item)),
						el('span', { className: 'zpsp-review-badge' }, message(item.featured ? 'review_featured' : 'review_media_reference'))),
					el('span', { className: 'zpsp-review-url' }, item.url)))) : el('p', null, message('review_empty')))
					: data.menus.filter((menu) => !menu.count || data.items.some((item) => item.menu === menu.id)).map((menu) => el('section', { key: menu.id },
						el('h2', null, menu.name, menu.slots.length > 0 && el('span', { className: 'zpsp-review-slots' }, ' · ' + menu.slots.join(', '))),
						el('p', null, message('review_count', { count: numbers.format(menu.count) })),
						menu.count ? menuItems(data.items.filter((item) => item.menu === menu.id)) : el('p', null, message('review_empty')),
						menu.edit_url && el('p', null, el('a', { href: menu.edit_url, target: '_blank', rel: 'noopener noreferrer' }, message('review_edit_menu')))))),
			el('div', { className: 'zpsp-review-actions' },
				data && data.pages > 1 && el('div', { className: 'zpsp-review-pagination' },
					el('button', { type: 'button', className: 'button', disabled: loading || page === 0, onClick: () => changePage(page - 1) }, message('review_previous')),
					el('button', { type: 'button', className: 'button', disabled: loading || page + 1 >= data.pages, onClick: () => changePage(page + 1) }, message('review_next'))),
				el('button', { type: 'button', className: 'button zpsp-review-close', onClick: close }, message('privacy_close'))));
	}
	function open(options, trigger) {
		if (active || !Modal) return;
		active = true;
		const container = document.createElement('div');
		document.body.append(container);
		const root = createRoot(container);
		function keepFocus(event) {
			const dialog = document.querySelector('.zpsp-review-modal');
			if (dialog && !dialog.contains(event.target)) dialog.focus({ preventScroll: true });
		}
		function close() {
			if (!active) return;
			active = false;
			document.removeEventListener('focusin', keepFocus);
			root.unmount(); container.remove();
			if (trigger?.isConnected) trigger.focus();
		}
		document.addEventListener('focusin', keepFocus);
		root.render(el(Details, { options, close }));
		return close;
	}
	return { open, Details };
};

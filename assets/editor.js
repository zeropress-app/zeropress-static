/* global zeroPressPublisher */
(function initialize() {
	'use strict';
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
		return;
	}
	const config = zeroPressPublisher;
	const { createElement: el, createRoot, createPortal, useState, useEffect, useRef } = window.wp.element;
	const { Modal, Button, Spinner, ProgressBar } = window.wp.components;
	const publication = window.zeroPressPublication(config);
	const reviews = window.zeroPressReview?.(config, publication);
	const { message } = publication;
	const blockEditor = config.editor === 'block';

	function classicState() {
		const form = document.getElementById('post');
		const richBaselines = new Map();
		const originalTitle = document.getElementById('title').value;
		const textBaselines = new Map(['content', 'excerpt'].map((id) => [id, document.getElementById(id)?.value || '']));
		const textEdits = new Map();
		form.addEventListener('input', ({ target }) => {
			if (textBaselines.has(target.id)) textEdits.set(target.id, target.value !== textBaselines.get(target.id));
		});
		let saving = false;
		// Core's autosave guard covers title/content/excerpt. Include the remaining
		// saved post fields, including taxonomy, scheduling and featured-image inputs.
		const fields = () => JSON.stringify([...form.querySelectorAll('input, select, textarea')]
			.filter((input) => /^(post_name|post_status|visibility|post_password|post_author_override|comment_status|ping_status|post_format|sticky|parent_id|menu_order|page_template|post_category\[\]|tax_input\[.*|newtag\[.*|_thumbnail_id|aa|mm|jj|hh|mn|ss|meta\[.*)$/.test(input.name))
			.map((input) => [input.name, input.type === 'checkbox' || input.type === 'radio' ? input.checked : input.value]));
		const baseline = fields();
		const remember = (editor) => {
			if (['content', 'excerpt'].includes(editor.id) && !richBaselines.has(editor.id)) {
				richBaselines.set(editor.id, editor.getContent());
			}
		};
		for (const editor of window.tinymce?.editors || []) if (editor.initialized) remember(editor);
		window.jQuery(document).on('tinymce-editor-init.zpsp', (_, editor) => remember(editor));
		window.jQuery(document).on('heartbeat-send.zpsp', (_, data) => { if (data.wp_autosave) saving = true; })
			.on('heartbeat-tick.zpsp heartbeat-error.zpsp', () => { saving = false; });
		form.addEventListener('submit', () => { saving = true; });
		return () => {
			// TinyMCE clears isDirty after an autosave. Its initial content must still
			// be compared: saving a revision does not update the published post.
			const richDirty = [...richBaselines].some(([id, content]) => {
				const editor = window.tinymce?.get(id);
				return editor && !editor.isHidden() && editor.getContent() !== content;
			});
			const dirty = document.getElementById('title').value !== originalTitle || [...textEdits.values()].some(Boolean) || richDirty || window.wp.autosave.server.postChanged() || fields() !== baseline;
			return saving ? 'editor_saving' : dirty ? 'editor_unsaved' : '';
		};
	}
	const readState = blockEditor ? () => {
		const editor = window.wp.data.select('core/editor');
		if (editor.isSavingPost() || editor.isAutosavingPost() || editor.isSavingNonPostEntityChanges()) return 'editor_saving';
		return editor.isEditedPostDirty() || editor.hasNonPostEntityChanges() ? 'editor_unsaved' : '';
	} : classicState();

	function readPostUrl() {
		// Read the saved permalink: block-editor saves can change it without a page reload.
		const post = blockEditor ? window.wp.data.select('core/editor').getCurrentPost() : config.post;
		const isPublic = blockEditor ? post?.status === 'publish' && !post.password : post?.public;
		if (!isPublic || !post.link || !config.site.public_url) return '';
		try {
			const site = new URL(config.site.public_url);
			const source = new URL(config.site.wordpress_url);
			const link = new URL(post.link);
			if (!['http:', 'https:'].includes(site.protocol) || !['http:', 'https:'].includes(link.protocol) ||
				site.username || site.password || site.pathname !== '/' || site.search || site.hash ||
				link.username || link.password || link.origin !== source.origin) return '';
			// Match Exporter's visitor-link projection, including a WordPress subdirectory.
			const base = source.pathname.replace(/\/+$/, '');
			if (base && link.pathname !== base && !link.pathname.startsWith(base + '/')) return '';
			return site.origin + (link.pathname.slice(base.length) || '/') + link.search + link.hash;
		} catch {
			return '';
		}
	}

	function ExternalLink({ href, children }) {
		return el('a', { href, target: '_blank', rel: 'noopener noreferrer' }, children, ' ',
			el('span', { className: 'dashicons dashicons-external', 'aria-hidden': true }));
	}
	function PublicationModal({ onClose }) {
		const [gate, setGate] = useState(readState);
		const [postUrl, setPostUrl] = useState(readPostUrl);
		const [busy, setBusy] = useState('');
		const [prepared, setPrepared] = useState(null);
		const [review, setReview] = useState(null);
		const [session, setSession] = useState(null);
		const [publicationControl, setPublicationControl] = useState(null);
		const [feedback, setFeedback] = useState(null);
		const running = useRef(false);
		const revision = useRef(0);
		const status = useRef(null);
		const preparation = useRef(null);
		const gateStatus = useRef(null);
		const prepareAction = useRef(null);
		const latestGate = useRef(gate);
		function checkEditor() {
			const next = readState();
			setPostUrl(readPostUrl());
			if (next !== latestGate.current) {
				latestGate.current = next;
				setGate(next);
				if (next) {
					revision.current++;
					setPrepared(null);
					if (!running.current) setFeedback(null);
				}
			}
			return next;
		}
		useEffect(() => {
			checkEditor();
			if (blockEditor) return window.wp.data.subscribe(checkEditor);
			const interval = setInterval(checkEditor, 300);
			document.addEventListener('input', checkEditor);
			document.addEventListener('change', checkEditor);
			return () => {
				clearInterval(interval);
				document.removeEventListener('input', checkEditor);
				document.removeEventListener('change', checkEditor);
			};
		}, []);
		useEffect(() => {
			if (busy) return;
			if (feedback || session) status.current?.focus();
			else if (gate) gateStatus.current?.focus();
			else if (prepared) preparation.current?.querySelector(prepared.state === 'download_only' ? '.zpsp-export-check' : 'h2')?.focus();
			else prepareAction.current?.focus();
		}, [busy, feedback, prepared, session, gate]);
		useEffect(() => { if (busy) status.current?.focus(); }, [busy]);
		function showSession(value) {
			setSession(['active', 'paused', 'unknown', 'expired', 'cancel_pending', 'busy'].includes(value?.state) ? value : null);
		}
		function showResult(result) {
			showSession(result);
			const text = publication.resultMessage(result);
			setFeedback(text ? { kind: publication.resultKind(result), text, state: result.state, code: result.code, commitUrl: result.commit_url } : null);
		}
		useEffect(() => {
			if (!config.session_publication) return;
			let closed = false;
			publication.sessionStatus().then((value) => { if (!closed) showSession(value); }).catch(() => {});
			return () => { closed = true; };
		}, []);
		async function recover(operation) {
			if (running.current || (operation === 'continue' && session.state !== 'unknown' && checkEditor())) return;
			running.current = true; setBusy(operation === 'continue' ? 'publish' : operation);
			setFeedback({ kind: 'info', text: message(operation === 'continue' ? 'publishing' : operation === 'cancel' ? 'session_cancelling' : 'session_refreshing') });
			try {
				const result = operation === 'cancel' ? await publication.cancel(session.job_id) : operation === 'refresh' ? await publication.sessionStatus(session.job_id) : await publication.resume(session, (text) => setFeedback({ kind: 'info', text }), setPublicationControl);
				showResult(result);
			} catch (error) { setFeedback({ kind: publication.resultKind({ state: 'failed', code: error.code }), text: error.message, code: error.code }); }
			finally { running.current = false; setBusy(''); }
		}
		const close = () => { if (!running.current) onClose(); };
		async function execute(operation) {
			if (running.current || checkEditor()) return;
			if (operation === 'publish' && (!prepared?.receipt || prepared.state !== 'ready')) return;
			running.current = true;
			setBusy(operation);
			const version = revision.current;
			setFeedback({ kind: 'info', text: message(operation === 'prepare' ? 'preparing' : 'publishing') });
			const receipt = prepared?.receipt;
			// Every write attempt consumes the UI's receipt, including uncertain results.
			setPrepared(operation === 'prepare' ? null : { ...prepared, receipt: null });
			try {
				if (operation === 'prepare') {
					const data = await publication.request('prepare');
					if (checkEditor() || revision.current !== version) {
						setFeedback({ kind: 'warning', text: message('editor_unsaved') });
						return;
					}
					setPrepared(data);
					// The export card owns the preparation result, including its blocking reason.
					setFeedback(data.state === 'unchanged' && !session ? { kind: 'info', state: 'unchanged', text: message('unchanged') } : null);
				} else {
					const result = await publication.publish(receipt, false, (text) => setFeedback({ kind: 'info', text }), setPublicationControl);
					showResult(result);
				}
			} catch (error) {
				setFeedback({ kind: publication.resultKind({ state: 'failed', code: error.code }), code: error.code, text: error.message });
			} finally {
				running.current = false;
				setBusy('');
			}
		}
		const target = prepared ? prepared.target || config.settings : session?.target || config.settings;
		const canPublish = !session && prepared?.state === 'ready' && !!prepared.receipt;
		const currentFeedback = feedback || (session ? { kind: publication.resultKind(session), text: publication.resultMessage(session), code: session.code } : null);
		const unchanged = currentFeedback?.state === 'unchanged';
		const prepareButton = el('button', { ref: prepareAction, key: 'prepare', type: 'button', className: 'button button-' + (canPublish || session ? 'secondary' : 'primary'), disabled: !!busy || !!gate, onClick: () => execute('prepare') }, message('editor_prepare'));
		const publishButton = el('button', { key: 'publish', type: 'button', className: 'button button-' + (canPublish ? 'primary' : 'secondary'), disabled: !!busy || !!gate || !canPublish, onClick: () => execute('publish') }, message('confirm_title'));
		return el(Modal, {
			title: message('editor_title'), className: 'zpsp-editor-modal', onRequestClose: close,
			shouldCloseOnClickOutside: false, shouldCloseOnEsc: !busy, isDismissible: !busy,
		}, el('div', { className: 'zpsp' },
			el('p', null, message('editor_scope')),
			publication.repositoryView(el, target, { headingLevel: 2 }),
			postUrl && el('dl', { className: 'zpsp-target' },
				el('dt', null, message('editor_site')),
				el('dd', null, el(ExternalLink, { href: postUrl }, postUrl))),
			el('div', { role: 'status', 'aria-live': 'polite' }, gate && el('p', { ref: gateStatus, tabIndex: -1, className: 'notice notice-warning zpsp-feedback' }, message(gate))),
			el('section', { className: 'zpsp-publication-status', hidden: !currentFeedback, 'aria-labelledby': 'zpsp-editor-status-heading' },
				el('h2', { id: 'zpsp-editor-status-heading' }, message('publication_status')),
				el('div', {
					ref: status, tabIndex: -1, role: 'status', 'aria-live': 'polite', 'aria-atomic': true,
					className: currentFeedback ? 'zpsp-feedback notice notice-' + currentFeedback.kind + (unchanged ? ' zpsp-unchanged' : '') : '',
				}, currentFeedback && el('div', null,
					busy && busy !== 'publish' ? el('span', { className: 'zpsp-loading-spinner' }, el(Spinner, { 'aria-hidden': true }))
						: el('span', { className: 'zpsp-status-icon dashicons dashicons-' + publication.statusIcon(currentFeedback.kind), 'aria-hidden': true }),
					el('span', { className: 'zpsp-feedback-message' }, currentFeedback.text),
					unchanged && el('p', null, message('unchanged_description')),
					publication.helpUrl(currentFeedback.code) && el('p', null, el(ExternalLink, { href: publication.helpUrl(currentFeedback.code) }, message('publishing_help'))),
					currentFeedback.commitUrl && el('p', null, el(ExternalLink, { href: currentFeedback.commitUrl }, message('commit'))),
					busy === 'publish' && el('div', { className: 'zpsp-loading-progress' }, el(ProgressBar, { className: 'zpsp-progress-bar', 'aria-label': message('publishing') })))),
				session && !publicationControl && el('div', { className: 'zpsp-publication-recovery' },
					el('div', { className: 'zpsp-actions' },
						(session.state === 'unknown' || session.can_continue) && el('button', { type: 'button', className: 'button button-primary', disabled: !!busy || (!!gate && session.state !== 'unknown'), onClick: () => recover('continue') }, message(session.state === 'unknown' ? 'session_verify' : 'session_continue')),
						session.job_id && session.state !== 'unknown' && el('button', { type: 'button', className: 'button button-secondary', disabled: !!busy, onClick: () => recover('cancel') }, message('session_cancel')),
						el('button', { type: 'button', className: 'button button-secondary', disabled: !!busy, onClick: () => recover('refresh') }, message('session_refresh')))),
				publicationControl && (publicationControl.pause || publicationControl.pausing) && el('button', { type: 'button', className: 'button button-secondary zpsp-pause', disabled: !publicationControl.pause, onClick: () => publicationControl.pause?.() }, message(publicationControl.pausing ? 'session_pausing' : 'session_pause'))),
			prepared && el('section', { ref: preparation, className: 'zpsp-editor-result', 'aria-label': message('review_prepared') },
				el('h2', { tabIndex: -1 }, message('review_prepared')), publication.exportView(el, prepared, {
					headingLevel: 3, disabled: !!busy || !!gate,
					onDetails: reviews && ((kind, trigger) => { if (!running.current && !checkEditor() && prepared?.receipt) setReview({ kind, receipt: prepared.receipt, trigger }); }),
				})),
			el('div', { className: 'zpsp-actions zpsp-editor-footer' },
				...(canPublish ? [publishButton, prepareButton] : [prepareButton, publishButton]),
				el('button', { type: 'button', className: 'button button-secondary', disabled: !!busy, onClick: close }, message('editor_close')))),
			// Keep the detail modal in the parent's React context. WordPress closes
			// sibling modals created in a separate root, losing prepared state.
			review && el(reviews.Details, { options: review, close: () => {
				setReview(null);
				requestAnimationFrame(() => { if (review.trigger?.isConnected) review.trigger.focus(); });
			} }));
	}
	function EditorIcon() {
		// Inherit the WordPress button color, including hover and custom editor accents.
		return el('svg', {
			className: 'zpsp-editor-icon', viewBox: '0 0 24 24', width: 24, height: 24,
			'aria-hidden': true, focusable: false, style: { fill: 'none' },
			stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round',
		},
			el('path', { d: 'M9 18H6.5C4 18 2 16 2 13.5c0-2.2 1.5-4 3.7-4.4A6.5 6.5 0 0 1 18 10.5c2.3-.1 4 1.7 4 4S20.4 18 18.5 18H15' }),
			el('path', { d: 'M12 18V9m-4 4 4-4 4 4' }));
	}
	function HeaderLauncher({ onOpen }) {
		const [container] = useState(() => document.createElement('div'));
		useEffect(() => {
			container.className = 'zpsp-editor-launcher';
			let frame;
			const attach = () => {
				const header = document.querySelector('.editor-header__settings, .edit-post-header__settings');
				let save = header?.querySelector('.editor-post-publish-button, .editor-post-publish-panel__toggle');
				if (!save) {
					container.remove();
					return;
				}
				while (save.parentElement !== header) save = save.parentElement;
				// Only own this container; leave core's buttons and pinned items in place.
				if (container.parentElement !== header) header.insertBefore(container, save);
			};
			const observer = new MutationObserver(() => {
				cancelAnimationFrame(frame);
				frame = requestAnimationFrame(attach);
			});
			observer.observe(document.getElementById('editor') || document.body, { childList: true, subtree: true });
			attach();
			return () => { observer.disconnect(); cancelAnimationFrame(frame); container.remove(); };
		}, [container]);
		return createPortal(el(Button, {
			icon: el(EditorIcon), size: 'compact', label: message('editor_open'), showTooltip: true,
			'aria-haspopup': 'dialog', onClick: (event) => onOpen(event.currentTarget),
		}), container);
	}
	function EditorPublisher() {
		const [open, setOpen] = useState(false);
		const opener = useRef(null);
		useEffect(() => {
			if (blockEditor) return;
			const button = document.getElementById('zpsp-editor-open');
			// Keep our launcher separate so it can wrap without moving Update/Publish.
			const publishingAction = document.getElementById('publishing-action');
			publishingAction?.after(button);
			publishingAction?.parentElement.classList.add('zpsp-editor-actions');
			const launch = () => { opener.current = button; setOpen(true); };
			button.disabled = false;
			button.addEventListener('click', launch);
			return () => button.removeEventListener('click', launch);
		}, []);
		const close = () => { setOpen(false); opener.current?.focus(); };
		const launch = (button) => { opener.current = button; setOpen(true); };
		return el(window.wp.element.Fragment, null,
			blockEditor && el(HeaderLauncher, { onOpen: launch }),
			blockEditor && el(window.wp.editor.PluginMoreMenuItem, {
				icon: el(EditorIcon), 'aria-haspopup': 'dialog',
				// The menu item unmounts when selected. Return focus to its menu toggle.
				onClick: () => launch(document.querySelector('.editor-header .components-dropdown-menu__toggle[aria-expanded="true"], .edit-post-header .components-dropdown-menu__toggle[aria-expanded="true"]')),
			}, message('editor_title')),
			open && el(PublicationModal, { onClose: close }));
	}
	if (blockEditor) {
		window.wp.plugins.registerPlugin('zeropress-static-publication', { render: EditorPublisher, icon: 'upload' });
	} else {
		const root = document.createElement('div');
		document.body.append(root);
		createRoot(root).render(el(EditorPublisher));
	}
}());

/* Claude Site Agent – admin chat UI. No build step; depends on wp.apiFetch. */
( function () {
	'use strict';

	const cfg = window.CSA || {};
	const $ = ( id ) => document.getElementById( id );
	const el = ( tag, attrs = {}, ...kids ) => {
		const n = document.createElement( tag );
		for ( const [ k, v ] of Object.entries( attrs ) ) {
			if ( k === 'class' ) n.className = v;
			else if ( k.startsWith( 'on' ) ) n.addEventListener( k.slice( 2 ), v );
			else if ( v !== false && v !== null && v !== undefined ) n.setAttribute( k, v === true ? '' : v );
		}
		for ( const kid of kids.flat() ) {
			if ( kid === null || kid === undefined || kid === false ) continue;
			n.append( kid instanceof Node ? kid : document.createTextNode( String( kid ) ) );
		}
		return n;
	};

	const state = { session: null, busy: false, stop: false, decisions: {} };

	const api = ( path, method = 'GET', data ) =>
		wp.apiFetch( { path: '/' + cfg.ns + path, method, data } );

	/* ---------------- markdown (small, safe subset) ---------------- */

	const esc = ( s ) => String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

	function inline( s ) {
		return s
			.replace( /`([^`]+)`/g, '<code>$1</code>' )
			.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
			.replace( /\[([^\]]+)\]\(((?:https?:\/\/|\/)[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>' )
			.replace( /(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>' );
	}

	function markdown( src ) {
		const out = [];
		const parts = String( src ).split( /```[\w-]*\n?/ );
		parts.forEach( ( part, i ) => {
			if ( i % 2 === 1 ) {
				out.push( '<pre><code>' + esc( part.replace( /\n$/, '' ) ) + '</code></pre>' );
				return;
			}
			let list = null;
			const flush = () => { if ( list ) { out.push( '</' + list + '>' ); list = null; } };
			for ( const raw of esc( part ).split( '\n' ) ) {
				const line = raw.trimEnd();
				let m;
				if ( ( m = line.match( /^\s*[-*]\s+(.*)/ ) ) ) {
					if ( list !== 'ul' ) { flush(); out.push( '<ul>' ); list = 'ul'; }
					out.push( '<li>' + inline( m[ 1 ] ) + '</li>' );
				} else if ( ( m = line.match( /^\s*\d+[.)]\s+(.*)/ ) ) ) {
					if ( list !== 'ol' ) { flush(); out.push( '<ol>' ); list = 'ol'; }
					out.push( '<li>' + inline( m[ 1 ] ) + '</li>' );
				} else if ( ( m = line.match( /^(#{1,4})\s+(.*)/ ) ) ) {
					flush();
					out.push( '<h4>' + inline( m[ 2 ] ) + '</h4>' );
				} else if ( line === '' ) {
					flush();
				} else {
					flush();
					out.push( '<p>' + inline( line ) + '</p>' );
				}
			}
			flush();
		} );
		return out.join( '' );
	}

	/* ---------------- diff ---------------- */

	function tokens( text ) {
		const lines = text.split( '\n' );
		return lines.length > 3 ? lines.map( ( l, i ) => ( i < lines.length - 1 ? l + '\n' : l ) ) : text.split( /(\s+|(?=<)|(?<=>))/ ).filter( Boolean );
	}

	function diff( a, b ) {
		const x = tokens( a || '' ), y = tokens( b || '' );
		if ( x.length * y.length > 2500000 ) return null;
		const n = x.length, m = y.length;
		const dp = Array.from( { length: n + 1 }, () => new Uint32Array( m + 1 ) );
		for ( let i = n - 1; i >= 0; i-- )
			for ( let j = m - 1; j >= 0; j-- )
				dp[ i ][ j ] = x[ i ] === y[ j ] ? dp[ i + 1 ][ j + 1 ] + 1 : Math.max( dp[ i + 1 ][ j ], dp[ i ][ j + 1 ] );
		const ops = [];
		let i = 0, j = 0;
		while ( i < n && j < m ) {
			if ( x[ i ] === y[ j ] ) { ops.push( [ '=', x[ i ] ] ); i++; j++; }
			else if ( dp[ i + 1 ][ j ] >= dp[ i ][ j + 1 ] ) ops.push( [ '-', x[ i++ ] ] );
			else ops.push( [ '+', y[ j++ ] ] );
		}
		while ( i < n ) ops.push( [ '-', x[ i++ ] ] );
		while ( j < m ) ops.push( [ '+', y[ j++ ] ] );
		return ops;
	}

	function renderDiff( before, after ) {
		const box = el( 'pre', { class: 'csa-diff' } );
		if ( ! before ) {
			box.append( el( 'ins', {}, after ) );
			return box;
		}
		const ops = diff( before, after );
		if ( ! ops ) {
			box.append( el( 'del', {}, before ), '\n', el( 'ins', {}, after ) );
			return box;
		}
		// Collapse long unchanged runs.
		ops.forEach( ( [ op, t ], idx ) => {
			if ( op === '=' ) {
				if ( t.length > 400 && idx > 0 && idx < ops.length - 1 ) box.append( t.slice( 0, 150 ) + ' … ' + t.slice( -150 ) );
				else box.append( t );
			} else box.append( el( op === '+' ? 'ins' : 'del', {}, t ) );
		} );
		return box;
	}

	/* ---------------- rendering ---------------- */

	function toolLabel( name ) {
		return name.replace( /_/g, ' ' );
	}

	function renderTranscript( view ) {
		const t = $( 'csa-transcript' );
		t.innerHTML = '';
		if ( ! view || ! view.items.length ) {
			t.append(
				el( 'div', { class: 'csa-empty' },
					el( 'h2', {}, 'What should we work on?' ),
					el( 'p', {}, 'Examples:' ),
					el( 'ul', {},
						el( 'li', {}, 'Build an “About us” page from this copy using our hero, two-column text/image and testimonial layouts.' ),
						el( 'li', {}, 'Replace “Acme Ltd” with “Acme Group” everywhere, including ACF fields.' ),
						el( 'li', {}, 'Find images with no alt text and write alt text for them.' ),
						el( 'li', {}, 'Check the home page speed and suggest fixes.' )
					),
					cfg.caps && cfg.caps.length ? el( 'p', { class: 'description' }, 'Enabled for you: ' + cfg.caps.join( ', ' ) + '.' ) : null,
					! cfg.hasKey ? el( 'p', { class: 'csa-warn' }, 'No API key yet. ', cfg.settingsUrl ? el( 'a', { href: cfg.settingsUrl }, 'Add it in Settings.' ) : 'Ask an administrator to add one.' ) : null
				)
			);
			return;
		}
		for ( const it of view.items ) {
			if ( it.kind === 'user' ) {
				t.append( el( 'div', { class: 'csa-msg csa-user' }, it.text ) );
			} else if ( it.kind === 'assistant' ) {
				const d = el( 'div', { class: 'csa-msg csa-assistant' } );
				d.innerHTML = markdown( it.text );
				t.append( d );
			} else if ( it.kind === 'tool' ) {
				const status = it.result === null ? 'pending' : it.is_error ? 'error' : 'ok';
				t.append(
					el( 'details', { class: 'csa-tool csa-tool-' + status },
						el( 'summary', {}, el( 'span', { class: 'csa-tool-dot' } ), toolLabel( it.name ), status === 'error' ? ' — not done' : '' ),
						el( 'div', { class: 'csa-tool-body' },
							el( 'div', { class: 'csa-tool-label' }, 'Input' ),
							el( 'pre', {}, it.input ),
							it.result !== null ? el( 'div', { class: 'csa-tool-label' }, 'Result' ) : null,
							it.result !== null ? el( 'pre', {}, it.result ) : null
						)
					)
				);
			}
		}
		t.scrollTop = t.scrollHeight;
	}

	const riskText = {
		single: 'Single change',
		publish: 'Affects live content',
		bulk: 'Bulk change',
		destructive: 'Moves to trash',
		code: 'Front-end code',
		settings: 'Site settings',
	};

	function renderPending( view ) {
		const box = $( 'csa-pending' );
		box.innerHTML = '';
		if ( ! view || ! view.pending.length ) return;
		state.decisions = {};
		const cards = view.pending.map( ( p ) => {
			const note = el( 'input', { type: 'text', class: 'csa-note', placeholder: 'Optional note for Claude (why, or what to change)' } );
			const card = el( 'section', { class: 'csa-card csa-risk-' + ( p.preview.risk || 'single' ) },
				el( 'header', {},
					el( 'span', { class: 'csa-badge' }, riskText[ p.preview.risk ] || p.preview.risk ),
					el( 'strong', {}, p.preview.summary ),
					p.preview.link ? el( 'a', { href: p.preview.link, target: '_blank', rel: 'noopener', class: 'csa-view' }, 'View' ) : null
				),
				p.preview.notes ? el( 'p', { class: 'description' }, p.preview.notes ) : null,
				el( 'div', { class: 'csa-changes' },
					( p.preview.changes || [] ).map( ( c, i ) =>
						el( 'details', { class: 'csa-change', open: i < 3 },
							el( 'summary', {}, c.label, c.link ? el( 'a', { href: c.link, target: '_blank', rel: 'noopener' }, ' ↗' ) : null ),
							renderDiff( c.before || '', c.after || '' )
						)
					)
				),
				el( 'footer', {},
					note,
					el( 'button', { type: 'button', class: 'button', onclick: () => decide( { [ p.id ]: { approve: false, note: note.value } }, view ) }, 'Reject' ),
					el( 'button', { type: 'button', class: 'button button-primary', onclick: () => decide( { [ p.id ]: { approve: true, note: note.value } }, view ) }, 'Approve' )
				)
			);
			card.dataset.id = p.id;
			card._note = note;
			return card;
		} );
		box.append( el( 'h3', {}, view.pending.length === 1 ? 'Review this change' : 'Review ' + view.pending.length + ' changes' ) );
		cards.forEach( ( c ) => box.append( c ) );
		if ( view.pending.length > 1 ) {
			const all = ( approve ) => {
				const d = {};
				cards.forEach( ( c ) => ( d[ c.dataset.id ] = { approve, note: c._note.value } ) );
				decide( d, view, true );
			};
			box.append(
				el( 'div', { class: 'csa-all-actions' },
					el( 'button', { type: 'button', class: 'button', onclick: () => all( false ) }, 'Reject all' ),
					el( 'button', { type: 'button', class: 'button button-primary', onclick: () => all( true ) }, 'Approve all' )
				)
			);
		}
	}

	function renderStatus( view, working ) {
		const s = $( 'csa-status' );
		s.innerHTML = '';
		if ( working ) s.append( el( 'span', { class: 'csa-spinner' } ), working );
		else if ( view && view.notice ) s.append( el( 'span', { class: view.status === 'error' ? 'csa-warn' : '' }, view.notice ) );
		if ( view && ( view.input_tokens || view.output_tokens ) ) {
			s.append( el( 'span', { class: 'csa-tokens' }, `${ Math.round( view.input_tokens / 1000 ) }k in · ${ Math.round( view.output_tokens / 1000 ) }k out` ) );
		}
		const canContinue = view && ! working && [ 'paused', 'error', 'running' ].includes( view.status );
		$( 'csa-continue' ).hidden = ! canContinue;
		$( 'csa-stop' ).hidden = ! working;
		$( 'csa-send' ).disabled = !! working;
	}

	function render( view, working ) {
		state.session = view;
		renderTranscript( view );
		renderPending( view );
		renderStatus( view, working );
	}

	/* ---------------- actions ---------------- */

	async function run( fn, label ) {
		if ( state.busy ) return;
		state.busy = true;
		state.stop = false;
		renderStatus( state.session, label );
		try {
			let view = await fn();
			render( view, view.status === 'running' ? 'Working…' : false );
			while ( view.status === 'running' && ! state.stop ) {
				view = await api( `/sessions/${ view.id }/continue`, 'POST' );
				render( view, view.status === 'running' ? 'Working…' : false );
			}
			if ( state.stop ) render( view, false );
			loadSessions();
		} catch ( e ) {
			renderStatus( state.session, false );
			$( 'csa-status' ).prepend( el( 'span', { class: 'csa-warn' }, ( e && e.message ) || 'Request failed.' ) );
		} finally {
			state.busy = false;
			if ( state.session ) renderStatus( state.session, false );
		}
	}

	async function ensureSession() {
		if ( state.session ) return state.session;
		const v = await api( '/sessions', 'POST' );
		state.session = v;
		return v;
	}

	function send( text ) {
		run( async () => {
			const s = await ensureSession();
			return api( `/sessions/${ s.id }/message`, 'POST', { text } );
		}, 'Thinking…' );
	}

	function decide( decisions, view ) {
		$( 'csa-pending' ).innerHTML = '';
		run( () => api( `/sessions/${ view.id }/decide`, 'POST', { decisions } ), 'Applying…' );
	}

	async function openSession( id ) {
		const v = await api( `/sessions/${ id }` );
		render( v, false );
		markActive( id );
		if ( v.status === 'running' ) run( async () => v, 'Working…' );
	}

	function markActive( id ) {
		document.querySelectorAll( '.csa-sessions li' ).forEach( ( li ) => li.classList.toggle( 'is-active', String( li.dataset.id ) === String( id ) ) );
	}

	async function loadSessions() {
		const list = await api( '/sessions' );
		const ul = $( 'csa-sessions' );
		ul.innerHTML = '';
		list.forEach( ( s ) => {
			const li = el( 'li', { 'data-id': s.id },
				el( 'button', { type: 'button', class: 'csa-session', onclick: () => { showTab( 'chats' ); openSession( s.id ); } },
					el( 'span', { class: 'csa-session-title' }, s.title || 'Untitled' ),
					s.status === 'awaiting_approval' ? el( 'span', { class: 'csa-badge' }, 'needs review' ) : null
				),
				el( 'button', { type: 'button', class: 'csa-del', title: 'Delete conversation', 'aria-label': 'Delete conversation', onclick: async ( e ) => {
					e.stopPropagation();
					if ( ! li.classList.contains( 'confirm' ) ) { li.classList.add( 'confirm' ); e.currentTarget.textContent = 'Delete?'; return; }
					await api( `/sessions/${ s.id }`, 'DELETE' );
					if ( state.session && state.session.id === s.id ) { state.session = null; render( null, false ); }
					loadSessions();
				} }, '×' )
			);
			ul.append( li );
		} );
		if ( state.session ) markActive( state.session.id );
	}

	async function loadHistory() {
		const all = $( 'csa-all' ).checked ? '?all=1' : '';
		const rows = await api( '/changesets' + all );
		const tb = $( 'csa-history' );
		tb.innerHTML = '';
		if ( ! rows.length ) tb.append( el( 'tr', {}, el( 'td', { colspan: 6 }, 'No changes yet.' ) ) );
		rows.forEach( ( r ) => {
			const actions = el( 'td', {} );
			const msg = el( 'div', { class: 'description' } );
			const doUndo = async ( force ) => {
				const res = await api( `/changesets/${ r.id }/undo`, 'POST', { force } );
				msg.textContent = res.message || '';
				if ( res.ok ) return loadHistory();
				if ( res.conflicts && res.conflicts.length ) {
					actions.append( el( 'button', { type: 'button', class: 'button button-link-delete', onclick: () => doUndo( true ) }, 'Undo anyway' ) );
				}
			};
			if ( r.status === 'applied' ) actions.append( el( 'button', { type: 'button', class: 'button', onclick: () => doUndo( false ) }, 'Undo' ) );
			actions.append( msg );
			tb.append(
				el( 'tr', { class: r.status === 'undone' ? 'csa-undone' : '' },
					el( 'td', {}, r.id ),
					el( 'td', {}, new Date( r.created_at.replace( ' ', 'T' ) + 'Z' ).toLocaleString() ),
					el( 'td', {}, r.user ),
					el( 'td', {}, el( 'code', {}, r.tool ), ' ', r.summary ),
					el( 'td', {}, r.status ),
					actions
				)
			);
		} );
	}

	function showTab( tab ) {
		document.querySelectorAll( '.csa-tab' ).forEach( ( b ) => b.classList.toggle( 'is-active', b.dataset.tab === tab ) );
		$( 'csa-chat-view' ).hidden = tab !== 'chats';
		$( 'csa-history-view' ).hidden = tab !== 'history';
		$( 'csa-sessions' ).hidden = tab !== 'chats';
		if ( tab === 'history' ) loadHistory();
	}

	/* ---------------- boot ---------------- */

	document.addEventListener( 'DOMContentLoaded', () => {
		if ( ! $( 'csa-app' ) ) return;
		$( 'csa-model' ).textContent = cfg.model || '';
		$( 'csa-all-wrap' ).hidden = ! cfg.canSeeAll;
		$( 'csa-all' ).addEventListener( 'change', loadHistory );
		document.querySelectorAll( '.csa-tab' ).forEach( ( b ) => b.addEventListener( 'click', () => showTab( b.dataset.tab ) ) );
		$( 'csa-new' ).addEventListener( 'click', () => { showTab( 'chats' ); state.session = null; render( null, false ); markActive( 0 ); $( 'csa-input' ).focus(); } );
		$( 'csa-composer' ).addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const v = $( 'csa-input' ).value.trim();
			if ( ! v || state.busy ) return;
			$( 'csa-input' ).value = '';
			send( v );
		} );
		$( 'csa-input' ).addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
				e.preventDefault();
				$( 'csa-composer' ).requestSubmit();
			}
		} );
		$( 'csa-continue' ).addEventListener( 'click', () => state.session && run( () => api( `/sessions/${ state.session.id }/continue`, 'POST' ), 'Working…' ) );
		$( 'csa-stop' ).addEventListener( 'click', () => { state.stop = true; } );
		render( null, false );
		loadSessions();
	} );
} )();

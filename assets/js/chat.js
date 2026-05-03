if (!window._ocChatLoaded) {
window._ocChatLoaded = true;

// Intercept Mermaid node clicks (href="#oc-cmd:...")
$(document).on('click', 'a[href^="#oc-cmd:"]', function(e) {
	e.preventDefault();
	var cmd = $(this).attr('href').replace('#oc-cmd:', '');
	if (cmd && window._ocSendMessage) {
		window._ocSendMessage(cmd);
	}
});

$(function() {
	var $msgs = $('#oc-messages');
	var $input = $('#oc-input');
	var $send = $('#oc-send');
	var $typing = $('#oc-typing');
	var sessionId = 'web-' + Math.random().toString(36).substr(2, 9);
	var sending = false;
	var commandHistory = [];
	var historyIndex = -1;

	function escapeHtml(s) {
		return $('<div>').text(s).html();
	}

	function formatMarkdown(text) {
		// Mermaid diagrams: ```mermaid ... ```
		text = text.replace(/```mermaid([\s\S]*?)```/g, function(m, code) {
			var id = 'mermaid-' + Math.random().toString(36).substr(2, 8);
			var encoded = btoa(unescape(encodeURIComponent(code.trim())));
			return '<div class="oc-mermaid" id="' + id + '" data-graph="' + encoded + '" style="display:none;"></div>';
		});
		// Regular code blocks
		text = text.replace(/```([\s\S]*?)```/g, function(m, code) {
			return '<pre><code>' + escapeHtml(code.trim()) + '</code></pre>';
		});
		// Download links: {{download:url|filename}}
		text = text.replace(/\{\{download:([^|]+)\|([^}]+)\}\}/g, function(m, url, label) {
			return '<a href="' + url + '" download class="oc-download" target="_blank">📥 ' + escapeHtml(label) + '</a>';
		});
		// Clickable commands: {{cmd:command text|display label}}
		text = text.replace(/\{\{cmd:([^|]+)\|([^}]+)\}\}/g, function(m, cmd, label) {
			return '<span class="oc-clickable" data-cmd="' + escapeHtml(cmd) + '">' + escapeHtml(label) + '</span>';
		});
		text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
		text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		// Markdown links: [text](url)
		text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" class="oc-link">$1</a>');
		text = text.replace(/\n/g, '<br>');
		return text;
	}

	function addMessage(text, type) {
		var time = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
		var bubble = '<div class="oc-msg oc-msg-' + type + '">' +
			'<div><div class="oc-msg-bubble">' + formatMarkdown(text) + '</div>' +
			'<div class="oc-msg-time">' + time + '</div></div></div>';
		$msgs.append(bubble);
		// Render any Mermaid diagrams
		$msgs.find('.oc-mermaid').each(function() {
			var el = $(this);
			if (!el.data('rendered') && typeof mermaid !== 'undefined') {
				el.data('rendered', true);
				var id = el.attr('id') + '-svg';
				var code = decodeURIComponent(escape(atob(el.data('graph'))));
				try {
					mermaid.render(id, code).then(function(result) {
						el.html(result.svg).show();
					}).catch(function(err) {
						el.text('Diagram error: ' + err.message).show();
					});
				} catch(e) {
					el.text('Diagram error: ' + e.message).show();
				}
			}
		});
		$msgs.scrollTop($msgs[0].scrollHeight);
	}

	function sendMessage(text) {
		if (!text.trim() || sending) return;
		commandHistory.unshift(text.trim());
		historyIndex = -1;
		sending = true;
		addMessage(text, 'user');
		$input.val('').focus();
		$send.prop('disabled', true);
		$typing.show();

		$.ajax({
			url: 'ajax.php?module=frogman&command=chat',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({message: text, session_id: sessionId}),
			dataType: 'json',
			success: function(data) {
				$typing.hide();
				$send.prop('disabled', false);
				sending = false;
				addMessage(data.reply || 'No response', 'bot');
				// Hide the red Apply Config bar after a successful reload
				if (data.reply && data.reply.indexOf('reload completed') !== -1) {
					$('#button_reload').hide();
				}
				refreshAudit();
			},
			error: function(xhr) {
				$typing.hide();
				$send.prop('disabled', false);
				sending = false;
				var oops = ['Lost the thread there.', 'Couldn\'t reach the PBX.', 'Something went sideways.'];
				var prefix = oops[Math.floor(Math.random() * oops.length)];
				addMessage('**' + prefix + '** ' + (xhr.statusText || 'Connection failed'), 'bot');
			}
		});
	}

	window._ocSendMessage = sendMessage;

	$send.off('click').on('click', function() {
		sendMessage($input.val());
	});

	$input.off('keydown').on('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage($input.val());
		} else if (e.key === 'ArrowUp') {
			if (commandHistory.length > 0 && historyIndex < commandHistory.length - 1) {
				e.preventDefault();
				historyIndex++;
				$input.val(commandHistory[historyIndex]);
			}
		} else if (e.key === 'ArrowDown') {
			e.preventDefault();
			if (historyIndex > 0) {
				historyIndex--;
				$input.val(commandHistory[historyIndex]);
			} else {
				historyIndex = -1;
				$input.val('');
			}
		}
	});

	$(document).off('click.ocquick').on('click.ocquick', '.oc-quick-btn', function() {
		var paste = $(this).data('paste');
		if (paste) {
			$input.val(paste).focus();
			// Put cursor at end
			var el = $input[0];
			el.setSelectionRange(paste.length, paste.length);
			return;
		}
		var cmd = $(this).data('cmd');
		if (cmd) sendMessage(cmd);
	});

	$(document).off('click.occlick').on('click.occlick', '.oc-clickable', function() {
		var cmd = $(this).data('cmd');
		if (cmd) sendMessage(cmd);
	});

	$(document).off('click.ocsidebar').on('click.ocsidebar', '.oc-sidebar-header', function() {
		$(this).next('.oc-sidebar-body').slideToggle(150);
	});

	function refreshAudit() {
		$.ajax({
			url: 'ajax.php?module=frogman&command=audit-feed',
			method: 'GET',
			dataType: 'json',
			success: function(resp) {
				var $audit = $('#oc-audit-list');
				$audit.empty();
				if (resp.entries) {
					resp.entries.forEach(function(e) {
						var cls = e.status === 'success' ? 'success' : 'error';
						$audit.append(
							'<div class="oc-audit-entry">' +
							'<span class="oc-audit-tool">' + escapeHtml(e.tool) + '</span>' +
							'<span class="oc-audit-time">' + escapeHtml(e.time) + '</span><br>' +
							'<span class="oc-audit-status-' + cls + '">' + escapeHtml(e.status) + '</span>' +
							'</div>'
						);
					});
				}
			}
		});
	}

	// Collapse all sidebar sections on load
	$('.oc-sidebar-body').hide();

	addMessage("Welcome to **Frogman**. Type a command or click a quick action. Type **help** for the full command list.", 'bot');
	sendMessage('status');
	refreshAudit();
});

}

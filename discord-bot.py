#!/usr/bin/env python3
"""
Frogman Discord Bot
"""

import discord
import json
import urllib.request
import os
import sys
import re

CHAT_URL = os.environ.get(
    'OPENCLAW_CHAT_URL',
    'http://localhost/admin/ajax.php?module=frogman&command=chat'
)
BOT_TOKEN = os.environ.get('DISCORD_BOT_TOKEN', '')

ALLOWED_CHANNELS = os.environ.get('OPENCLAW_CHANNELS', '').split(',')
ALLOWED_CHANNELS = [c.strip() for c in ALLOWED_CHANNELS if c.strip()]

# Track which channels have a pending confirm
pending_channels = set()

CONFIRM_WORDS = re.compile(r'^(yes|y|confirm|do it|go|go ahead|ok|sure|yep|yeah)$', re.IGNORECASE)
CANCEL_WORDS = re.compile(r'^(no|n|cancel|nevermind|nope|nah|abort)$', re.IGNORECASE)

intents = discord.Intents.default()
intents.message_content = True
intents.messages = True
intents.guilds = True
client = discord.Client(intents=intents)


def call_frogman(message_text, session_id):
    payload = json.dumps({
        'message': message_text,
        'session_id': session_id,
    }).encode('utf-8')

    req = urllib.request.Request(
        CHAT_URL,
        data=payload,
        headers={'Content-Type': 'application/json'},
        method='POST',
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            return data.get('reply', 'No response from Frogman.')
    except Exception as e:
        return f'**Error connecting to Frogman:** {e}'


@client.event
async def on_ready():
    print(f'Bot connected as {client.user} (ID: {client.user.id})', flush=True)
    print(f'Chat URL: {CHAT_URL}', flush=True)
    print(f'Guilds: {[g.name for g in client.guilds]}', flush=True)
    if not client.guilds:
        print('WARNING: Bot is not in any servers! Use the invite URL to add it.', flush=True)


@client.event
async def on_message(message):
    print(f'MSG [{message.channel}] {message.author}: {message.content}', flush=True)

    if message.author == client.user:
        return

    if ALLOWED_CHANNELS and hasattr(message.channel, 'name') and message.channel.name not in ALLOWED_CHANNELS:
        return

    text = message.content
    is_dm = isinstance(message.channel, discord.DMChannel)
    channel_id = message.channel.id
    is_confirm_reply = CONFIRM_WORDS.match(text.strip()) or CANCEL_WORDS.match(text.strip())

    if not is_dm:
        if client.user.mentioned_in(message):
            text = text.replace(f'<@{client.user.id}>', '').strip()
        elif text.startswith('!'):
            text = text[1:].strip()
        elif channel_id in pending_channels and is_confirm_reply:
            # Bare yes/no when we're waiting for confirmation — let it through
            text = text.strip()
        else:
            return

    if not text:
        return

    session_id = f'discord-{channel_id}'

    print(f'PROCESSING: "{text}" session={session_id}', flush=True)

    async with message.channel.typing():
        reply = call_frogman(text, session_id)

    print(f'REPLY: {reply[:100]}', flush=True)

    # Track pending confirm state
    if 'Reply **yes**' in reply:
        pending_channels.add(channel_id)
    else:
        pending_channels.discard(channel_id)

    if len(reply) > 1900:
        chunks = [reply[i:i+1900] for i in range(0, len(reply), 1900)]
        for chunk in chunks:
            await message.reply(chunk)
    else:
        await message.reply(reply)


if __name__ == '__main__':
    if not BOT_TOKEN:
        print('Error: Set DISCORD_BOT_TOKEN environment variable', file=sys.stderr)
        sys.exit(1)
    client.run(BOT_TOKEN)

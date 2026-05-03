# Voicemail Setup & Troubleshooting

## Enabling Voicemail

### On a new extension
```
create extension 1010 and voicemail for John Smith
```

### On an existing extension
```
enable voicemail on 1010
```

### Disable voicemail
```
disable voicemail on 1010
```

## Checking Voicemail

### From the extension
Dial `*97` from the phone to access your own voicemail.

### From another extension
Dial `*98` then enter the mailbox number and password.

### Check status
```
show voicemail for 1010
```

## Voicemail Settings
```
list voicemail settings
```
Shows global settings: email notification templates, max message length, greeting options, etc.

## Common Issues

### "Mailbox not found"
- Voicemail may not be enabled on the extension
- Check: `show extension <ext>` — look for voicemail setting
- Fix: `enable voicemail on <ext>`

### Voicemail email notifications not sending
- Check: `list voicemail settings` — verify email settings
- Ensure the PBX can send email (check postfix/sendmail)
- Verify the email address on the extension

### Greeting not playing
- The custom greeting may not be recorded
- Check voicemail settings: `forcegreetings` and `forcename`

### Mailbox full
- Check: `show voicemail for <ext>` — message count
- Check: `list voicemail settings` — `maxmsg` sets the limit (default 100)

## Diagnostic Commands
```
show voicemail for <ext>
show extension <ext>
list voicemail settings
enable voicemail on <ext>
```

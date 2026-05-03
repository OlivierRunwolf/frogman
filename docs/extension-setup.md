# Extension Setup Guide

## Creating an Extension

### Basic
```
create extension 1010 for John Smith
```
This creates a PJSIP extension with an auto-generated password.

### With voicemail
```
create extension 1010 and voicemail for John Smith
```
Or create first, then enable:
```
create extension 1010 for John Smith
→ yes (confirm)
→ yes (enable voicemail)
→ yes (apply changes)
```

## Configuring the Phone

After creating the extension, configure the phone with:
- **Server/Registrar:** Your PBX IP address
- **Extension/Username:** The extension number (e.g., 1010)
- **Password/Secret:** The auto-generated secret (shown on creation)
- **Transport:** UDP (default) or TCP/TLS

To see the secret: `show extension 1010`

## Common Post-Setup Tasks

### Add to a ring group
```
add 1010 to ringgroup 600
```

### Set call forwarding
```
forward 1010 to 5551234567
```

### Set Follow Me
```
set followme on 1010 to 1010,5551234567
```

### Enable Do Not Disturb
```
enable dnd on 1010
```

## Verifying Setup

### Check registration
```
diagnose ext 1010
```

### Check if phone is online
```
extension states
```

### Test with a call
```
call 1010 to 1010
```
This rings the extension — useful for testing.

## Troubleshooting
- Phone not registering? See SIP Registration Failures guide
- One-way audio? See NAT Configuration guide
- Can't make outbound calls? Check outbound routes: `list outbound routes`

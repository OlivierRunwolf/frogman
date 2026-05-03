# Inbound Call Routing

## How Inbound Calls Work
1. Call arrives on a trunk from your SIP provider
2. FreePBX matches the DID (dialed number) to an inbound route
3. The inbound route sends the call to a destination (extension, ring group, IVR, etc.)

## Setting Up Inbound Routes

### Route a DID to an extension
```
add inbound route 5551234567 to 101
```

### Route to a ring group
```
add inbound route 5551234567 to 600
```

### View current routes
```
list inbound routes
```

### Check a specific route
```
show inbound route 5551234567
```

## Common Destinations
- **Extension** — rings one phone
- **Ring Group** — rings multiple phones
- **IVR** — plays a menu ("press 1 for sales...")
- **Time Condition** — routes differently based on time of day
- **Queue** — places callers in a queue for the next available agent
- **Voicemail** — goes directly to a mailbox

## Troubleshooting

### Calls not coming in
- Check: `list inbound routes` — is the DID configured?
- The DID number must match exactly what the provider sends
- Some providers send the full number (+15551234567), some send just the last 10 digits
- Check: `list trunks` — is the trunk registered?

### Calls going to wrong destination
- Check: `show inbound route <DID>` — verify destination
- If there's a catch-all route (blank DID), it may be catching calls first

### "Bad destinations" notification
- An inbound route points to something that no longer exists
- Check: `list notifications` — look for BADDEST notification
- Fix: Update the route destination

## Diagnostic Commands
```
list inbound routes
show inbound route <DID>
list trunks
diagnose trunk <id>
list notifications
```
